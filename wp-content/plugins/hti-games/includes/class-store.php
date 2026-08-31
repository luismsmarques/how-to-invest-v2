<?php
/**
 * The two tables the games own, and the only sanctioned way to write to them.
 *
 * Created from `init`, never from an activation hook. The cPanel deploy is
 * `rm -rf` + `cp -R` (see DEPLOY.md): the plugin is never "activated" in
 * production, so an activation hook is a table that never appears. A stored
 * schema version makes the init check a single autoload-free option read on
 * the overwhelming majority of requests — the same pattern as
 * HTI\Forex\Bot_Store.
 *
 * Two properties of this schema carry the integrity of both games, and both
 * are enforced by MySQL rather than by application code:
 *
 *  - `UNIQUE KEY one_per_day (player_id, game, day_key)` IS the "one game per
 *    day" rule AND the "a decision is final once submitted" rule. Not a
 *    convention, not a check in the REST layer, not something a double-tap or
 *    two parallel requests can race past: the second INSERT fails. Any code
 *    that "fixes" a duplicate-key error by switching to an UPDATE has quietly
 *    removed both rules, which is why the run row is inserted and never
 *    updated, and why there is a test asserting this index by name.
 *
 *  - `UNIQUE KEY nickname_key (nickname_key)` with the column NULL-able. A
 *    UNIQUE index in MySQL permits any number of NULLs but only one '', so a
 *    NOT NULL DEFAULT '' column would let exactly one anonymous player exist
 *    and collide every other one. Players who never choose a nickname store
 *    NULL; players who do store the case-folded form, which is what makes
 *    "Ana" and "ana" the same name on the leaderboard.
 *
 * `ack_at` / `ack_ver` record that the player acknowledged the onboarding
 * screen and which version of it — GDPR Art. 7(1) requires being able to
 * demonstrate consent, and "we showed it to everyone" is not a demonstration.
 *
 * What is NOT here is as deliberate: no email address and no IP address. A
 * player who links an account is joined to `wp_users` by `user_id`, so the
 * email lives in exactly one place and is already covered by the account
 * deletion cascade — deleting the user deletes the identity, and what is left
 * here is a pseudonymous row. An IP column would turn every leaderboard into
 * a personal-data export for no gameplay benefit whatsoever.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Schema owner and write gateway for the two game tables.
 */
class Store {

	/**
	 * Bump to trigger a dbDelta upgrade of both tables.
	 */
	private const SCHEMA = 1;

	/**
	 * Where the applied schema version is remembered.
	 */
	private const OPTION_SCHEMA = 'hti_games_schema';

	/**
	 * Writable player columns and their $wpdb format specifiers.
	 *
	 * `id` is deliberately absent: it is AUTO_INCREMENT and nothing outside
	 * the database may set it. Every write goes through insert()/update()
	 * below, which build the format array from this map — so a new column is
	 * added in one place and can never be written as the wrong type.
	 *
	 * @var array<string,string>
	 */
	public const PLAYER_COLUMNS = array(
		'uuid'            => '%s',
		'user_id'         => '%d',
		'nickname'        => '%s',
		'nickname_key'    => '%s',
		'lang'            => '%s',
		'ack_at'          => '%s',
		'ack_ver'         => '%s',
		'newsletter'      => '%d',
		'stc_capital'     => '%d',
		'stc_streak'      => '%d',
		'stc_best_streak' => '%d',
		'stc_deaths'      => '%d',
		'stc_last_day'    => '%s',
		'rev_capital'     => '%d',
		'rev_index_cap'   => '%d',
		'rev_streak'      => '%d',
		'rev_best_streak' => '%d',
		'rev_deaths'      => '%d',
		'rev_last_day'    => '%s',
		'created_at'      => '%s',
		'last_seen'       => '%s',
	);

	/**
	 * Writable run columns and their $wpdb format specifiers.
	 *
	 * @var array<string,string>
	 */
	public const RUN_COLUMNS = array(
		'player_id'    => '%d',
		'game'         => '%d',
		'day_key'      => '%s',
		'content_id'   => '%d',
		'decision'     => '%s',
		'risk_bp'      => '%d',
		'multiplier'   => '%d',
		'outcome'      => '%s',
		'touch_idx'    => '%d',
		'board_score'  => '%d',
		'pnl'          => '%d',
		'cap_before'   => '%d',
		'cap_after'    => '%d',
		'idx_before'   => '%d',
		'idx_after'    => '%d',
		'died'         => '%d',
		'streak_after' => '%d',
		'lang'         => '%s',
		'created_at'   => '%s',
	);

	/**
	 * Hook the idempotent table check.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );
	}

	/**
	 * The players table name.
	 */
	public static function players_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hti_games_players';
	}

	/**
	 * The runs table name.
	 */
	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hti_games_runs';
	}

	/**
	 * The two CREATE TABLE statements, as a pure array.
	 *
	 * Pure by design: with both arguments supplied this function touches no
	 * global and no database, which is what lets tests/test-store-schema.php
	 * assert the shape of the schema — the one_per_day index above all — on a
	 * harness that has neither WordPress nor MySQL.
	 *
	 * @param string|null $prefix  Table prefix; defaults to $wpdb's.
	 * @param string|null $collate Charset/collation clause; defaults to $wpdb's.
	 * @return array{players:string,runs:string}
	 */
	public static function create_sql( ?string $prefix = null, ?string $collate = null ): array {
		if ( null === $prefix || null === $collate ) {
			global $wpdb;
			$prefix  = $prefix ?? ( isset( $wpdb ) ? $wpdb->prefix : 'wp_' );
			$collate = $collate ?? ( isset( $wpdb ) ? $wpdb->get_charset_collate() : '' );
		}

		$players = $prefix . 'hti_games_players';
		$runs    = $prefix . 'hti_games_runs';

		return array(
			// One row per player. Pseudonymous: no email, no IP; an account,
			// when there is one, is the user_id join and nothing more.
			'players' => "CREATE TABLE {$players} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	uuid char(36) NOT NULL,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	nickname varchar(24) NOT NULL DEFAULT '',
	nickname_key varchar(24) NULL DEFAULT NULL,
	lang char(2) NOT NULL DEFAULT 'en',
	ack_at datetime NULL DEFAULT NULL,
	ack_ver varchar(8) NOT NULL DEFAULT '',
	newsletter tinyint(1) NOT NULL DEFAULT 0,
	stc_capital int(11) NOT NULL DEFAULT 10000,
	stc_streak smallint(5) unsigned NOT NULL DEFAULT 0,
	stc_best_streak smallint(5) unsigned NOT NULL DEFAULT 0,
	stc_deaths smallint(5) unsigned NOT NULL DEFAULT 0,
	stc_last_day char(10) NOT NULL DEFAULT '',
	rev_capital int(11) NOT NULL DEFAULT 10000,
	rev_index_cap int(11) NOT NULL DEFAULT 10000,
	rev_streak smallint(5) unsigned NOT NULL DEFAULT 0,
	rev_best_streak smallint(5) unsigned NOT NULL DEFAULT 0,
	rev_deaths smallint(5) unsigned NOT NULL DEFAULT 0,
	rev_last_day char(10) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	last_seen datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY uuid (uuid),
	UNIQUE KEY nickname_key (nickname_key),
	KEY user_id (user_id),
	KEY stc_board (stc_capital, stc_streak),
	KEY rev_board (rev_capital),
	KEY last_seen (last_seen)
) {$collate};",

			// One row per player per game per day — enforced, not assumed.
			'runs'    => "CREATE TABLE {$runs} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	player_id bigint(20) unsigned NOT NULL,
	game tinyint(3) unsigned NOT NULL,
	day_key char(10) NOT NULL,
	content_id bigint(20) unsigned NOT NULL DEFAULT 0,
	decision varchar(8) NOT NULL DEFAULT '',
	risk_bp smallint(5) unsigned NOT NULL DEFAULT 0,
	multiplier tinyint(3) unsigned NOT NULL DEFAULT 1,
	outcome varchar(8) NOT NULL DEFAULT '',
	touch_idx smallint(6) NOT NULL DEFAULT -1,
	board_score int(11) NOT NULL DEFAULT 0,
	pnl int(11) NOT NULL DEFAULT 0,
	cap_before int(11) NOT NULL DEFAULT 0,
	cap_after int(11) NOT NULL DEFAULT 0,
	idx_before int(11) NOT NULL DEFAULT 0,
	idx_after int(11) NOT NULL DEFAULT 0,
	died tinyint(1) NOT NULL DEFAULT 0,
	streak_after smallint(5) unsigned NOT NULL DEFAULT 0,
	lang char(2) NOT NULL DEFAULT 'en',
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY one_per_day (player_id, game, day_key),
	KEY board (game, day_key, board_score),
	KEY player_hist (player_id, game, created_at)
) {$collate};",
		);
	}

	/**
	 * Create or upgrade both tables when the stored version is behind.
	 *
	 * Safe to call on every request: the option read is the whole cost in the
	 * steady state, and dbDelta is idempotent when it does run.
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::OPTION_SCHEMA, 0 ) === self::SCHEMA ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::create_sql() as $sql ) {
			dbDelta( $sql );
		}

		// Autoload off: this is read once from init and never from a template.
		update_option( self::OPTION_SCHEMA, self::SCHEMA, false );
	}

	/* ---------------------------------------------------------------------
	 * Writes. Everything that changes a row goes through here so that the
	 * format array is derived from the column map rather than hand-written at
	 * each call site — an unlisted column is dropped, never guessed at.
	 * ------------------------------------------------------------------- */

	/**
	 * Insert a row and return its id.
	 *
	 * @param string              $which 'players' or 'runs'.
	 * @param array<string,mixed> $data  Column => value.
	 * @return int New row id, or 0 when the write was refused (a duplicate
	 *             day, above all — which is the expected answer to a second
	 *             submission and must be handled, not logged as a failure).
	 */
	public static function insert( string $which, array $data ): int {
		global $wpdb;

		$data = self::filter_columns( $which, $data );
		if ( array() === $data ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table; there is no WordPress API for it, and a game row must not be cached.
		$ok = $wpdb->insert( self::table( $which ), $data, self::formats( $which, $data ) );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update rows matching $where.
	 *
	 * @param string              $which 'players' or 'runs'.
	 * @param array<string,mixed> $data  Column => new value.
	 * @param array<string,mixed> $where Column => value.
	 * @return int Rows affected.
	 */
	public static function update( string $which, array $data, array $where ): int {
		global $wpdb;

		$data  = self::filter_columns( $which, $data );
		$where = self::filter_columns( $which, $where, true );
		if ( array() === $data || array() === $where ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table; no WordPress API for it.
		$rows = $wpdb->update(
			self::table( $which ),
			$data,
			$where,
			self::formats( $which, $data ),
			self::formats( $which, $where, true )
		);

		return false === $rows ? 0 : (int) $rows;
	}

	/**
	 * The columns a table accepts for writing, plus `id` for WHERE clauses.
	 *
	 * @param string $which  'players' or 'runs'.
	 * @param bool   $with_id Whether `id` is allowed (true for WHERE).
	 * @return array<string,string>
	 */
	public static function columns( string $which, bool $with_id = false ): array {
		$map = 'runs' === $which ? self::RUN_COLUMNS : self::PLAYER_COLUMNS;
		return $with_id ? array( 'id' => '%d' ) + $map : $map;
	}

	/**
	 * The $wpdb format array for a set of values, in the same order.
	 *
	 * Pure — the reason a wrong format specifier is a thing a test can catch
	 * rather than something discovered when a capital is silently stored as 0.
	 *
	 * @param string              $which   'players' or 'runs'.
	 * @param array<string,mixed> $data    Column => value.
	 * @param bool                $with_id Whether `id` is allowed.
	 * @return array<int,string>
	 */
	public static function formats( string $which, array $data, bool $with_id = false ): array {
		$map = self::columns( $which, $with_id );
		$out = array();
		foreach ( array_keys( $data ) as $column ) {
			$out[] = $map[ $column ] ?? '%s';
		}
		return $out;
	}

	/**
	 * Drop anything that is not a real column of the table. Pure.
	 *
	 * @param string              $which   'players' or 'runs'.
	 * @param array<string,mixed> $data    Column => value.
	 * @param bool                $with_id Whether `id` is allowed.
	 * @return array<string,mixed>
	 */
	public static function filter_columns( string $which, array $data, bool $with_id = false ): array {
		return array_intersect_key( $data, self::columns( $which, $with_id ) );
	}

	/**
	 * Resolve a table nickname to its real name.
	 *
	 * @param string $which 'players' or 'runs'.
	 */
	private static function table( string $which ): string {
		return 'runs' === $which ? self::runs_table() : self::players_table();
	}
}
