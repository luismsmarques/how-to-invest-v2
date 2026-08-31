<?php
/**
 * Who has used the bot, and what the crowd looks like.
 *
 * Two stores with deliberately different privacy properties:
 *
 *  - A row per chat id, so a broadcast has somewhere to go. That is personal
 *    data and it holds nothing beyond what a broadcast needs: the id, the two
 *    display preferences, and two timestamps. No names, no message text, no
 *    balances. /stop deletes the row outright.
 *
 *  - An aggregate counter of balance buckets, with no link to any chat id at
 *    all. This is the audience research the project is missing — after a
 *    fortnight it says whether these are ₹5,000 accounts or ₹5,00,000 ones —
 *    and it costs nothing in privacy because a count of "twelve people are
 *    under ₹2,000" identifies nobody.
 *
 * The deploy runs no activation hook (see DEPLOY.md), so the table is created
 * from init, guarded by a stored schema version. Safe to run on every load.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriber table and aggregate counters.
 */
class Bot_Store {

	/**
	 * Bump to trigger a dbDelta upgrade of the table.
	 */
	private const SCHEMA = 3;

	private const OPTION_SCHEMA  = 'hti_forex_bot_schema';
	private const OPTION_BUCKETS = 'hti_forex_bot_buckets';
	private const OPTION_SOURCES = 'hti_forex_bot_sources';

	/**
	 * How many distinct campaign codes to keep. Codes arrive from the open
	 * web inside a deep link, so the map needs a ceiling; past it everything
	 * lands in one 'other' row, which is still an honest answer.
	 */
	private const MAX_SOURCES = 50;

	/**
	 * Hook the idempotent table check.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );
	}

	/**
	 * The table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hti_forex_bot_subs';
	}

	/**
	 * Create or upgrade the table when the stored schema version is behind.
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::OPTION_SCHEMA, 0 ) === self::SCHEMA ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				chat_id bigint(20) NOT NULL,
				pair varchar(8) NOT NULL DEFAULT 'EURUSD',
				leverage smallint(5) unsigned NOT NULL DEFAULT 500,
				source varchar(32) NOT NULL DEFAULT '',
				nudge_due datetime DEFAULT NULL,
				nudged tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				last_seen datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY chat_id (chat_id),
				KEY nudge_due (nudge_due)
			) {$collate};"
		);

		update_option( self::OPTION_SCHEMA, self::SCHEMA, false );
	}

	/**
	 * Record that a chat is using the bot, or refresh its last_seen.
	 *
	 * @param int $chat_id Telegram chat id.
	 * @return bool Whether this is the first time we have seen this chat.
	 */
	public static function remember( int $chat_id ): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, no WP API for it.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO `' . self::table() . '` (chat_id, created_at, last_seen)
				 VALUES (%d, %s, %s)
				 ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)',
				$chat_id,
				$now,
				$now
			)
		);

		// MySQL reports 1 affected row for an insert and 2 for an update, so
		// this distinguishes a person we have never seen from one coming back.
		// A repeat inside the same second reports 0 (nothing changed), which
		// also correctly reads as "not new".
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Remember where a chat came from — first touch only.
	 *
	 * First touch, not last: the campaign that paid to bring someone here is
	 * the one that earned the account they open later. Someone who taps a
	 * second ad, or re-opens the bot from the channel, does not retroactively
	 * change who paid for them.
	 *
	 * Stored per chat because that is the only way the tag can travel with the
	 * click that leaves for the broker — the aggregate counter in
	 * OPTION_SOURCES can say how many arrived, never which one converted. It
	 * is a campaign code and never anything the person typed:
	 * Bot_Math::source_code() has already reduced it to [a-z0-9_-]{1,32} or
	 * thrown it away.
	 *
	 * @param int    $chat_id Telegram chat id.
	 * @param string $source  Normalized source code ('' does nothing).
	 */
	public static function set_source( int $chat_id, string $source ): void {
		if ( '' === $source ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . "` SET source = %s WHERE chat_id = %d AND source = ''",
				$source,
				$chat_id
			)
		);
	}

	/**
	 * Where a chat came from, or '' when we never knew.
	 *
	 * @param int $chat_id Telegram chat id.
	 */
	public static function source( int $chat_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT source FROM `' . self::table() . '` WHERE chat_id = %d', $chat_id )
		);
	}

	/**
	 * Delete a chat's row. This is what /stop does, and what a 403 from
	 * Telegram does — in both cases the person is gone and keeping the row
	 * would be storing personal data for no purpose.
	 *
	 * @param int $chat_id Telegram chat id.
	 */
	public static function forget( int $chat_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->delete( self::table(), array( 'chat_id' => $chat_id ), array( '%d' ) );
	}

	/* -------------------------------------------------------------------------
	 * The follow-up nudge.
	 *
	 * Two columns carry it: `nudge_due` (when it becomes due, NULL when nothing
	 * is pending) and `nudged` (1 once it has been spent, and never unset). The
	 * pair is what makes "at most one, ever" a property of the table rather
	 * than a rule the caller has to remember.
	 *
	 * Existing rows upgrade with `nudge_due` NULL, so deploying this arms
	 * nobody. That is deliberate: a feature that mass-messages an existing list
	 * the moment it ships is the kind of accident there is no undo for.
	 * ---------------------------------------------------------------------- */

	/**
	 * Arm the nudge for a chat, due `$delay` seconds from now.
	 *
	 * Only ever arms a row that has never been nudged and has nothing pending,
	 * so calling it twice cannot move a due date or resurrect a spent nudge.
	 *
	 * @param int $chat_id Telegram chat id.
	 * @param int $delay   Seconds from now.
	 */
	public static function arm_nudge( int $chat_id, int $delay ): void {
		global $wpdb;

		$due = gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . '` SET nudge_due = %s
				 WHERE chat_id = %d AND nudged = 0 AND nudge_due IS NULL',
				$due,
				$chat_id
			)
		);
	}

	/**
	 * Spend the nudge without sending it — what answering a balance does.
	 *
	 * Somebody who used the bot does not need to be told how to use the bot,
	 * and this is the only thing standing between a working feature and
	 * messaging the very people it worked on.
	 *
	 * @param int $chat_id Telegram chat id.
	 */
	public static function disarm_nudge( int $chat_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . '` SET nudge_due = NULL, nudged = 1
				 WHERE chat_id = %d AND nudged = 0',
				$chat_id
			)
		);
	}

	/**
	 * Nudges that are due now, oldest first.
	 *
	 * `$max_age` drops rows that came due long ago and were never sent — the
	 * kill-switch having been off for a week, or cron not having run. Waking up
	 * and messaging a month-old backlog all at once is worse than sending
	 * nothing.
	 *
	 * @param int $limit   How many.
	 * @param int $max_age Ignore rows whose due time is older than this many seconds.
	 * @return array<int,array{id:int,chat_id:int}>
	 */
	public static function due_nudges( int $limit, int $max_age ): array {
		global $wpdb;

		$now    = time();
		$oldest = gmdate( 'Y-m-d H:i:s', $now - max( 0, $max_age ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, chat_id FROM `' . self::table() . '`
				 WHERE nudged = 0 AND nudge_due IS NOT NULL AND nudge_due <= %s AND nudge_due >= %s
				 ORDER BY nudge_due ASC LIMIT %d',
				gmdate( 'Y-m-d H:i:s', $now ),
				$oldest,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'id'      => (int) $row['id'],
				'chat_id' => (int) $row['chat_id'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Take ownership of one pending nudge. True when this caller got it.
	 *
	 * The claim happens BEFORE the message is sent, not after. If the process
	 * dies between the two, one person misses a nudge; if it were the other way
	 * round, one person gets it twice. On a bot where a second unwanted message
	 * is what makes someone block for good, those two outcomes are not close.
	 *
	 * Being a conditional UPDATE, it is also what makes two overlapping cron
	 * runs safe: the second one's claim matches no row and it skips.
	 *
	 * @param int $id Row id.
	 */
	public static function claim_nudge( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . self::table() . '` SET nudged = 1, nudge_due = NULL WHERE id = %d AND nudged = 0',
				$id
			)
		);

		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * When the next pending nudge comes due, as a timestamp, or 0 when none is.
	 *
	 * Lets the runner schedule its next tick for the moment there is something
	 * to do instead of waking every minute to find an empty table.
	 */
	public static function next_nudge_due(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$due = $wpdb->get_var(
			'SELECT MIN(nudge_due) FROM `' . self::table() . '` WHERE nudged = 0 AND nudge_due IS NOT NULL'
		);

		return null === $due ? 0 : (int) strtotime( (string) $due . ' UTC' );
	}

	/**
	 * A chat's display preferences, falling back to the defaults.
	 *
	 * @param int $chat_id Telegram chat id.
	 * @return array{pair:string,leverage:int}
	 */
	public static function prefs( int $chat_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT pair, leverage FROM `' . self::table() . '` WHERE chat_id = %d', $chat_id ),
			ARRAY_A
		);

		$pair     = is_array( $row ) ? (string) $row['pair'] : '';
		$leverage = is_array( $row ) ? (int) $row['leverage'] : 0;

		return array(
			'pair'     => in_array( $pair, Bot_Math::PAIRS, true ) ? $pair : Bot_Math::PAIRS[0],
			'leverage' => in_array( $leverage, Bot_Math::LEVERAGES, true ) ? $leverage : 500,
		);
	}

	/**
	 * Store a chat's display preferences. Values outside the offered sets are
	 * ignored rather than stored — the buttons are the only way in, so
	 * anything else is someone poking at the webhook.
	 *
	 * @param int         $chat_id  Telegram chat id.
	 * @param string|null $pair     Pair key, or null to leave alone.
	 * @param int|null    $leverage Leverage, or null to leave alone.
	 */
	public static function set_prefs( int $chat_id, ?string $pair = null, ?int $leverage = null ): void {
		global $wpdb;

		$data = array();
		if ( null !== $pair && in_array( $pair, Bot_Math::PAIRS, true ) ) {
			$data['pair'] = $pair;
		}
		if ( null !== $leverage && in_array( $leverage, Bot_Math::LEVERAGES, true ) ) {
			$data['leverage'] = $leverage;
		}
		if ( array() === $data ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$wpdb->update( self::table(), $data, array( 'chat_id' => $chat_id ) );
	}

	/**
	 * How many people the bot can reach.
	 */
	public static function total(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . self::table() . '`' );
	}

	/**
	 * One page of recipients, ordered by id so a broadcast can walk the table
	 * with a cursor and never send twice or skip anyone.
	 *
	 * @param int $after_id Exclusive lower bound on id.
	 * @param int $limit    How many rows.
	 * @return array<int,array{id:int,chat_id:int}>
	 */
	public static function page( int $after_id, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, chat_id FROM `' . self::table() . '` WHERE id > %d ORDER BY id ASC LIMIT %d',
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'id'      => (int) $row['id'],
				'chat_id' => (int) $row['chat_id'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Count one balance into its bucket. No chat id is involved, by design.
	 *
	 * @param float $balance_inr Balance in rupees.
	 */
	public static function count_balance( float $balance_inr ): void {
		$key     = Bot_Math::bucket( $balance_inr );
		$buckets = get_option( self::OPTION_BUCKETS, array() );
		$buckets = is_array( $buckets ) ? $buckets : array();

		$buckets[ $key ] = (int) ( $buckets[ $key ] ?? 0 ) + 1;

		update_option( self::OPTION_BUCKETS, $buckets, false );
	}

	/**
	 * Count one acquisition against the campaign code that brought it.
	 *
	 * Called only for chats we have never seen, so this counts people the
	 * campaign actually delivered rather than taps on the link — someone
	 * opening the same ad twice is one user, and should read as one.
	 *
	 * No chat id is involved: a count of "eleven people came from px_a1"
	 * identifies nobody.
	 *
	 * @param string $code Campaign code, already normalized.
	 */
	public static function count_source( string $code ): void {
		if ( '' === $code ) {
			return;
		}

		$sources = get_option( self::OPTION_SOURCES, array() );
		$sources = is_array( $sources ) ? $sources : array();

		if ( ! isset( $sources[ $code ] ) && count( $sources ) >= self::MAX_SOURCES ) {
			$code = 'other';
		}

		$sources[ $code ] = (int) ( $sources[ $code ] ?? 0 ) + 1;

		update_option( self::OPTION_SOURCES, $sources, false );
	}

	/**
	 * Acquisitions per campaign code, biggest first.
	 *
	 * @return array<string,int>
	 */
	public static function sources(): array {
		$sources = get_option( self::OPTION_SOURCES, array() );
		$sources = is_array( $sources ) ? array_map( 'intval', $sources ) : array();

		arsort( $sources );

		return $sources;
	}

	/**
	 * The balance distribution, in bucket order, for the settings screen.
	 *
	 * @return array<int,array{key:string,label:string,count:int,share:float}>
	 */
	public static function distribution(): array {
		$buckets = get_option( self::OPTION_BUCKETS, array() );
		$buckets = is_array( $buckets ) ? $buckets : array();
		$total   = array_sum( array_map( 'intval', $buckets ) );

		$out = array();
		foreach ( Bot_Math::buckets() as $bucket ) {
			$count = (int) ( $buckets[ $bucket['key'] ] ?? 0 );
			$out[] = array(
				'key'   => $bucket['key'],
				'label' => $bucket['label'],
				'count' => $count,
				'share' => $total > 0 ? $count / $total * 100 : 0.0,
			);
		}

		return $out;
	}

	/**
	 * Total answers counted, so the screen can say how much to trust the shape.
	 */
	public static function answered(): int {
		$buckets = get_option( self::OPTION_BUCKETS, array() );
		return is_array( $buckets ) ? (int) array_sum( array_map( 'intval', $buckets ) ) : 0;
	}
}
