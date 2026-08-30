<?php
/**
 * Who is playing: the anonymous identity behind a run, and the account it may
 * later be bound to.
 *
 * ---------------------------------------------------------------------------
 * The legal framing, because it decides the code
 * ---------------------------------------------------------------------------
 *
 * The onboarding checkbox is an ACKNOWLEDGEMENT, not a consent basis. Its
 * text is "I understand this is an educational simulation with virtual money
 * and no real trading" — it exists so nobody can later claim they thought the
 * numbers were real. A box you are required to tick before you may play is by
 * definition not freely given, so under Art. 4(11) / Art. 7(4) RGPD it cannot
 * be leaned on as consent, and this codebase must never treat it as one. What
 * we store is the fact of the acknowledgement (`ack_at`, `ack_ver`) — the
 * record that the warning was shown and read, which is a different thing from
 * permission to process.
 *
 * The identity cookie (`hti_gp`) is likewise not consent-based. It is
 * *strictly necessary* for the service the visitor explicitly asked for: a
 * once-per-person-per-day game cannot exist without a per-person handle, so it
 * falls under the ePrivacy Art. 5(3) exemption and needs no banner. That
 * exemption is only true while the cookie stays what it is here — one opaque
 * random value, no profiling, no third party, no ad tech — which is why the
 * row it points at holds no email and no IP.
 *
 * The newsletter box is separate, unticked by default, and genuinely
 * optional: nothing about playing depends on it. That is the box that carries
 * a real consent, and the subscription itself lives at Brevo (see class-auth),
 * not here.
 *
 * The cookie is only ever written AFTER onboarding completes. Someone who
 * merely loads the page leaves with nothing at all — no cookie, no row, no
 * record. Setting an identifier on page view would be both a worse privacy
 * posture and a table full of rows for people who never played.
 *
 * ---------------------------------------------------------------------------
 * Safari, ITP and the header fallback
 * ---------------------------------------------------------------------------
 *
 * Safari's Intelligent Tracking Prevention caps script-written cookies at
 * seven days and, in Private Browsing, drops them on the way out. Ours is
 * server-set, which survives more of that, but not all of it. So the same
 * uuid is handed back in the JSON body once, the client keeps it, and sends
 * it as `X-HTI-Player` on later calls. The header is only ever used to LOOK
 * UP an existing row — never to create one — so a client cannot choose its own
 * identity, only present one it was given.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Anonymous player identity, nickname and account binding.
 */
class Player {

	/**
	 * The identity cookie. Short name because it is sent on every request to
	 * the games pages and there is nothing to read in it anyway.
	 */
	public const COOKIE = 'hti_gp';

	/**
	 * The Safari/ITP fallback header carrying the same value.
	 */
	public const HEADER = 'X-HTI-Player';

	/**
	 * Cookie lifetime. 400 days is the ceiling Chrome enforces since 2022 —
	 * asking for more is silently truncated, so we ask for exactly it.
	 */
	public const COOKIE_DAYS = 400;

	/**
	 * The version of the acknowledgement text that was shown. Bumping this
	 * string is how a reworded warning becomes a re-acknowledgement rather
	 * than a silent substitution: `ack_ver` records which words they read.
	 */
	public const ACK_VERSION = '1';

	/**
	 * Nickname bounds. Three is short enough for initials, 24 fits a
	 * leaderboard row on a phone without truncation.
	 */
	private const NICK_MIN = 3;
	private const NICK_MAX = 24;

	/**
	 * Names nobody gets to take on a public board.
	 *
	 * Deliberately small and about impersonation rather than profanity: a
	 * word filter is an arms race nobody wins, while "admin" / "howtoinvest"
	 * on a leaderboard is a specific, cheap-to-prevent harm — it makes a
	 * stranger look like us. Matched on the normalised key with the
	 * separators stripped, so `h_t_i` and `HTI` are the same entry.
	 *
	 * @var array<int,string>
	 */
	private const BLOCKED = array(
		'admin',
		'administrator',
		'moderator',
		'mod',
		'staff',
		'support',
		'system',
		'root',
		'owner',
		'official',
		'howtoinvest',
		'hti',
		'htiadmin',
		'null',
		'undefined',
		'anonymous',
	);

	/* ---------------------------------------------------------------- */
	/* Tables                                                            */
	/* ---------------------------------------------------------------- */

	/**
	 * The players table.
	 */
	public static function table(): string {
		return Store::players_table();
	}

	/**
	 * The runs table (needed when a merge or an erasure has to move rows).
	 */
	private static function runs_table(): string {
		return Store::runs_table();
	}

	/* ---------------------------------------------------------------- */
	/* Identity                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * Whether a string is a well-formed v4 uuid as wp_generate_uuid4() makes
	 * them. Pure.
	 *
	 * Strict on purpose: the value arrives from the open web on every single
	 * request, and rejecting it here means the shape is guaranteed long
	 * before it reaches a query. It is still passed as a %s placeholder — this
	 * is the belt, prepare() is the braces.
	 *
	 * @param string $value Candidate.
	 */
	public static function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			strtolower( $value )
		);
	}

	/**
	 * The uuid this request presents: cookie first, then the ITP header.
	 *
	 * Returns '' when neither is present or either is malformed. An unknown
	 * uuid is not an error here — it simply means "no player yet".
	 *
	 * @param \WP_REST_Request|null $request Request, when there is one.
	 */
	public static function read_uuid( $request = null ): string {
		$raw = '';

		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}

		if ( '' === $raw && $request && method_exists( $request, 'get_header' ) ) {
			$raw = sanitize_text_field( (string) $request->get_header( self::HEADER ) );
		}

		return self::is_uuid( $raw ) ? strtolower( $raw ) : '';
	}

	/**
	 * Write the identity cookie.
	 *
	 * HttpOnly so a stray XSS cannot read it out of document.cookie; SameSite
	 * Lax so it rides an ordinary navigation back into the game but not a
	 * cross-site POST; Secure tied to is_ssl() rather than hard-coded true —
	 * production is HTTPS-only so it is Secure in practice, while a literal
	 * `true` would make the cookie vanish on a plain-HTTP dev host and the
	 * game unplayable there for no security gain.
	 *
	 * @param string $uuid Player uuid.
	 */
	public static function set_cookie( string $uuid ): void {
		if ( ! self::is_uuid( $uuid ) || headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$uuid,
			array(
				'expires'  => time() + ( self::COOKIE_DAYS * DAY_IN_SECONDS ),
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		// So the rest of THIS request already sees the player it just created.
		$_COOKIE[ self::COOKIE ] = $uuid;
	}

	/**
	 * Drop the identity cookie (self-serve erasure, and after a merge whose
	 * surviving row has a different uuid).
	 */
	public static function clear_cookie(): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			'',
			array(
				'expires'  => time() - DAY_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		unset( $_COOKIE[ self::COOKIE ] );
	}

	/* ---------------------------------------------------------------- */
	/* Reads                                                             */
	/* ---------------------------------------------------------------- */

	/**
	 * One player row by uuid, or null.
	 *
	 * @param string $uuid Player uuid.
	 * @return array<string,mixed>|null
	 */
	public static function by_uuid( string $uuid ): ?array {
		if ( ! self::is_uuid( $uuid ) ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API; the identity lookup on every game request must not be cached, a stale capital would let a run be replayed.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE uuid = %s", strtolower( $uuid ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * One player row by primary key, or null.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public static function by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API; see by_uuid().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The player row bound to a WordPress account, or null.
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|null
	 */
	public static function by_user( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API; see by_uuid().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY id ASC LIMIT 1", $user_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * EVERY player row bound to a WordPress account, as row ids.
	 *
	 * There is deliberately no UNIQUE index on `user_id` — the column is 0 for
	 * every anonymous row and MySQL would collapse them into one — so nothing
	 * in the schema promises that one account owns exactly one row. In practice
	 * it does: ensure() looks the account up before it inserts, and
	 * claim_for_user() merges rather than duplicates. But both of those are
	 * check-then-write, and two simultaneous requests can pass the check
	 * together.
	 *
	 * That is a tolerable duplicate for a game and an intolerable one for an
	 * erasure: Privacy::erase_user() reading a single row would leave the
	 * second one — with its runs — behind, keyed to a user id that no longer
	 * exists. So erasure asks for all of them, and gets a list.
	 *
	 * @param int $user_id User id.
	 * @return array<int,int> Row ids, oldest first.
	 */
	public static function ids_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API; an erasure must read the live rows, never a cache.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE user_id = %d ORDER BY id ASC", $user_id ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The player behind the current request, without creating one.
	 *
	 * Cookie/header first because that is the identity that has been playing;
	 * the signed-in account is the fallback for a new device, where the
	 * cookie is absent but the account row already exists.
	 *
	 * @param \WP_REST_Request|null $request Request.
	 * @return array<string,mixed>|null
	 */
	public static function resolve( $request = null ): ?array {
		$uuid = self::read_uuid( $request );
		if ( '' !== $uuid ) {
			$row = self::by_uuid( $uuid );
			if ( $row ) {
				return $row;
			}
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id > 0 ) {
			$row = self::by_user( $user_id );
			if ( $row ) {
				// Re-issue the cookie so the next request is one query lighter
				// and the ITP fallback has a value to echo back.
				self::set_cookie( (string) $row['uuid'] );
				return $row;
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------- */
	/* Writes                                                            */
	/* ---------------------------------------------------------------- */

	/**
	 * Onboarding: record the acknowledgement, create the row if needed, set
	 * the cookie, return the player.
	 *
	 * This is the ONLY place a player row is created and the only place the
	 * cookie is first written — which is what makes "nothing is stored about
	 * someone who merely loaded the page" a property of the code rather than
	 * a promise in a privacy policy.
	 *
	 * @param array{uuid?:string,lang?:string,newsletter?:bool,ack?:bool,ack_ver?:string,user_id?:int} $ctx Context.
	 * @return array<string,mixed>|null The player row, or null if the
	 *                                  acknowledgement was missing or the
	 *                                  insert failed.
	 */
	public static function ensure( array $ctx ): ?array {
		// No acknowledgement, no row. The checkbox is not a consent basis, but
		// it IS the condition under which we agreed to run the simulation at
		// all, so nothing is written before it is ticked.
		if ( true !== ( $ctx['ack'] ?? false ) ) {
			return null;
		}

		$lang       = self::lang( (string) ( $ctx['lang'] ?? '' ) );
		$newsletter = ! empty( $ctx['newsletter'] ) ? 1 : 0;
		$ack_ver    = substr( (string) ( $ctx['ack_ver'] ?? self::ACK_VERSION ), 0, 8 );
		$user_id    = max( 0, (int) ( $ctx['user_id'] ?? 0 ) );
		$now        = self::now();

		// A uuid presented by the client is only ever a lookup key. If it does
		// not match a row we mint a fresh one server-side; accepting a
		// client-chosen uuid would let anyone seed an identity of their
		// choosing (and collide with somebody else's).
		$row = self::by_uuid( (string) ( $ctx['uuid'] ?? '' ) );

		if ( ! $row && $user_id > 0 ) {
			$row = self::by_user( $user_id );
		}

		if ( $row ) {
			$fields = array(
				'lang'      => $lang,
				'last_seen' => $now,
			);

			// The newsletter tick is only ever turned ON here: unticking it in
			// a later session is not an unsubscribe (that lives at Brevo), and
			// silently clearing the flag would lose the record that it was
			// once given.
			if ( 1 === $newsletter ) {
				$fields['newsletter'] = 1;
			}

			// First acknowledgement wins; a re-onboarding does not rewrite when
			// it happened, only fills it in if it is somehow missing.
			if ( empty( $row['ack_at'] ) ) {
				$fields['ack_at']  = $now;
				$fields['ack_ver'] = $ack_ver;
			}

			Store::update( 'players', $fields, array( 'id' => (int) $row['id'] ) );

			self::set_cookie( (string) $row['uuid'] );
			return self::by_id( (int) $row['id'] );
		}

		$uuid = wp_generate_uuid4();

		$fresh = array(
			'uuid'            => $uuid,
			'user_id'         => $user_id,
			'nickname'        => '',
			'nickname_key'    => null,
			'lang'            => $lang,
			'ack_at'          => $now,
			'ack_ver'         => $ack_ver,
			'newsletter'      => $newsletter,
			'stc_capital'     => Config::CAPITAL_START,
			'stc_streak'      => 0,
			'stc_best_streak' => 0,
			'stc_deaths'      => 0,
			'stc_last_day'    => '',
			'rev_capital'     => Config::CAPITAL_START,
			'rev_index_cap'   => Config::CAPITAL_START,
			'rev_streak'      => 0,
			'rev_best_streak' => 0,
			'rev_deaths'      => 0,
			'rev_last_day'    => '',
			'created_at'      => $now,
			'last_seen'       => $now,
		);

		$id = Store::insert( 'players', $fresh );
		if ( $id <= 0 ) {
			return null;
		}

		self::set_cookie( $uuid );
		return self::by_id( $id );
	}

	/**
	 * Stamp last_seen (and the language, which can change mid-session).
	 *
	 * Cheap, and it is what the 180-day retention prune measures against —
	 * without it every anonymous row would look idle from the day it was made.
	 *
	 * @param int    $player_id Row id.
	 * @param string $lang      Language, when known.
	 */
	public static function touch( int $player_id, string $lang = '' ): void {
		if ( $player_id <= 0 ) {
			return;
		}
		$fields = array( 'last_seen' => self::now() );
		if ( '' !== $lang ) {
			$fields['lang'] = self::lang( $lang );
		}

		Store::update( 'players', $fields, array( 'id' => $player_id ) );
	}

	/* ---------------------------------------------------------------- */
	/* Nickname                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * Validate a nickname. Pure — no database, no WordPress state.
	 *
	 * The nickname is the one free-text field a player can put on a public
	 * page, so it is validated at input (this) and escaped at output (the
	 * renderer). Restricting the charset to letters, digits, `_` and `-`
	 * means there is nothing left to escape in the first place — but the
	 * board still escapes, because "there is nothing to escape" is a claim
	 * about today's validator, not about the row that is already in the table.
	 *
	 * Length is measured in characters, not bytes: `mb_strlen` so an accented
	 * name is not shorter than it looks. Letters are ASCII only for now, which
	 * is a real limitation (no `João`) and a deliberate one — accepting
	 * arbitrary Unicode on a public board opens homoglyph impersonation, and
	 * the fix for that is a confusables table, not a wider regex.
	 *
	 * @param string $raw Candidate as typed.
	 * @return array{ok:bool,nickname:string,code:string} Code is '' when ok,
	 *               otherwise one of short|long|chars|edges|blocked.
	 */
	public static function validate_nickname( string $raw ): array {
		// Trimmed, never rewritten: silently deleting the space out of
		// "my name" and accepting "myname" hands somebody a name they did not
		// choose. Internal whitespace simply fails the charset check below.
		$nick = trim( (string) $raw );

		$fail = static fn( string $code ): array => array(
			'ok'       => false,
			'nickname' => '',
			'code'     => $code,
		);

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $nick ) ) {
			return $fail( 'chars' );
		}
		if ( mb_strlen( $nick ) < self::NICK_MIN ) {
			return $fail( 'short' );
		}
		if ( mb_strlen( $nick ) > self::NICK_MAX ) {
			return $fail( 'long' );
		}
		// A leading or trailing separator reads as a rendering glitch on the
		// board and is the cheapest way to make two names look identical.
		if ( 1 === preg_match( '/^[_-]|[_-]$/', $nick ) ) {
			return $fail( 'edges' );
		}
		if ( in_array( self::blocklist_key( $nick ), self::BLOCKED, true ) ) {
			return $fail( 'blocked' );
		}

		return array(
			'ok'       => true,
			'nickname' => $nick,
			'code'     => '',
		);
	}

	/**
	 * The case-insensitive uniqueness key stored in `nickname_key`. Pure.
	 *
	 * Uniqueness is enforced by the UNIQUE index on that column, not by a
	 * SELECT-then-INSERT: two people claiming the same name in the same
	 * second must not both win, and only the index can promise that.
	 *
	 * @param string $nick Validated nickname.
	 */
	public static function nickname_key( string $nick ): string {
		return mb_strtolower( $nick );
	}

	/**
	 * The form a name takes before it meets the blocklist. Pure.
	 *
	 * Separators are stripped so `a-d-m-i-n` cannot walk past an entry, and
	 * the digit-for-letter substitutions people actually use are folded.
	 *
	 * @param string $nick Nickname.
	 */
	private static function blocklist_key( string $nick ): string {
		$key = str_replace( array( '_', '-' ), '', mb_strtolower( $nick ) );
		return strtr( $key, array( '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's' ) );
	}

	/**
	 * Claim a nickname for a player.
	 *
	 * @param int    $player_id Row id.
	 * @param string $raw       Nickname as typed.
	 * @return array{ok:bool,nickname:string,code:string} Code is '' when ok,
	 *               otherwise a validate_nickname() code or 'taken'.
	 */
	public static function set_nickname( int $player_id, string $raw ): array {
		$check = self::validate_nickname( $raw );
		if ( ! $check['ok'] ) {
			return $check;
		}
		if ( $player_id <= 0 ) {
			return array(
				'ok'       => false,
				'nickname' => '',
				'code'     => 'failed',
			);
		}

		global $wpdb;

		// Write straight into the UNIQUE index and read the failure, rather
		// than asking "is it free?" and then taking it — between those two
		// statements somebody else can take it, and the second writer would
		// win silently under a check-then-write.
		$fields = array(
			'nickname'     => $check['nickname'],
			'nickname_key' => self::nickname_key( $check['nickname'] ),
			'last_seen'    => self::now(),
		);

		// Not Store::update(): that one folds a refusal into "0 rows", and
		// here the refusal IS the answer the caller needs.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API.
		$ok = $wpdb->update(
			self::table(),
			$fields,
			array( 'id' => $player_id ),
			Store::formats( 'players', $fields ),
			array( '%d' )
		);
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( false === $ok ) {
			return array(
				'ok'       => false,
				'nickname' => '',
				'code'     => self::is_duplicate( $error ) ? 'taken' : 'failed',
			);
		}

		return array(
			'ok'       => true,
			'nickname' => $check['nickname'],
			'code'     => '',
		);
	}

	/* ---------------------------------------------------------------- */
	/* Binding an anonymous run to an account                            */
	/* ---------------------------------------------------------------- */

	/**
	 * Merge two players' progress into one set of column values. Pure.
	 *
	 * The union model hti-engine's Learn uses for guest → account progress:
	 * signing in must never cost somebody a run. Learn can take a literal set
	 * union because chapters read is a set; a game run is not — capital,
	 * streak and last day only mean anything together — so the union here is
	 * "the better run survives intact, per game", and the two games are
	 * merged independently because they are two independent runs.
	 *
	 * Better means more capital; ties go to the longer streak, and then to
	 * $keep, so the result does not depend on argument order for equal rows.
	 *
	 * Totals that are histories rather than states — best streak, deaths —
	 * are combined instead of chosen: a death that happened, happened, on
	 * whichever identity, and a personal best is a personal best.
	 *
	 * `*_last_day` takes the LATER of the two, not the winning row's, because
	 * that column is the guard that stops today's P&L being applied twice.
	 * After a merge the survivor owns the union of both rows' runs, so it has
	 * played on the later of the two days by definition.
	 *
	 * @param array<string,mixed> $keep  The row that will survive (the account row).
	 * @param array<string,mixed> $other The row being folded in and deleted.
	 * @return array<string,mixed> Column => value for the surviving row.
	 */
	public static function merge_rows( array $keep, array $other ): array {
		$out = array();

		foreach ( array( 'stc', 'rev' ) as $g ) {
			$cap_k = (int) ( $keep[ $g . '_capital' ] ?? 0 );
			$cap_o = (int) ( $other[ $g . '_capital' ] ?? 0 );
			$str_k = (int) ( $keep[ $g . '_streak' ] ?? 0 );
			$str_o = (int) ( $other[ $g . '_streak' ] ?? 0 );

			$winner = ( $cap_o > $cap_k || ( $cap_o === $cap_k && $str_o > $str_k ) ) ? $other : $keep;

			$out[ $g . '_capital' ]     = (int) ( $winner[ $g . '_capital' ] ?? Config::CAPITAL_START );
			$out[ $g . '_streak' ]      = (int) ( $winner[ $g . '_streak' ] ?? 0 );
			$out[ $g . '_best_streak' ] = max( (int) ( $keep[ $g . '_best_streak' ] ?? 0 ), (int) ( $other[ $g . '_best_streak' ] ?? 0 ) );
			$out[ $g . '_deaths' ]      = (int) ( $keep[ $g . '_deaths' ] ?? 0 ) + (int) ( $other[ $g . '_deaths' ] ?? 0 );

			// 'Y-m-d' sorts lexicographically, so max() is the later day.
			$out[ $g . '_last_day' ] = (string) max(
				(string) ( $keep[ $g . '_last_day' ] ?? '' ),
				(string) ( $other[ $g . '_last_day' ] ?? '' )
			);

			if ( 'rev' === $g ) {
				// The index the Reveal player is measured against travels with
				// the capital it is measured against — pairing this run's
				// capital with the other run's index would invent a result
				// neither person played.
				$out['rev_index_cap'] = (int) ( $winner['rev_index_cap'] ?? Config::CAPITAL_START );
			}
		}

		// A nickname is worth more than none; if both have one the account's
		// wins, because that is the name already on the board.
		$nick                = '' !== (string) ( $keep['nickname'] ?? '' ) ? (string) $keep['nickname'] : (string) ( $other['nickname'] ?? '' );
		$out['nickname']     = $nick;
		$out['nickname_key'] = '' !== $nick ? self::nickname_key( $nick ) : null;

		$out['newsletter'] = ( ! empty( $keep['newsletter'] ) || ! empty( $other['newsletter'] ) ) ? 1 : 0;
		$out['lang']       = self::lang( (string) ( $keep['lang'] ?? '' ) );

		// The earliest acknowledgement is the true one — it is when the person
		// was actually shown the warning.
		$ack_k = (string) ( $keep['ack_at'] ?? '' );
		$ack_o = (string) ( $other['ack_at'] ?? '' );
		if ( '' === $ack_k || ( '' !== $ack_o && $ack_o < $ack_k ) ) {
			$out['ack_at']  = $ack_o;
			$out['ack_ver'] = (string) ( $other['ack_ver'] ?? self::ACK_VERSION );
		} else {
			$out['ack_at']  = $ack_k;
			$out['ack_ver'] = (string) ( $keep['ack_ver'] ?? self::ACK_VERSION );
		}

		return $out;
	}

	/**
	 * Bind an anonymous player to a WordPress account.
	 *
	 * Four cases, and only the last is interesting:
	 *   - the uuid is unknown            → the account row (or null) as-is;
	 *   - it is already this account's   → nothing to do;
	 *   - it belongs to another account  → never stolen; the account row wins;
	 *   - both rows exist                → merged, and the anonymous row goes.
	 *
	 * The account row is the survivor. Its id is the one anything account-shaped
	 * already points at, and its uuid is the one a second device will resolve to.
	 *
	 * @param string $uuid    The anonymous uuid from the cookie/header.
	 * @param int    $user_id The signed-in account.
	 * @return array<string,mixed>|null The surviving player row.
	 */
	public static function claim_for_user( string $uuid, int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		global $wpdb;

		$anon = self::by_uuid( $uuid );
		$acct = self::by_user( $user_id );

		if ( $anon && (int) $anon['user_id'] === $user_id ) {
			return $anon; // Already bound; nothing to merge.
		}
		if ( $anon && (int) $anon['user_id'] > 0 ) {
			$anon = null; // Somebody else's row. A uuid is not a claim on it.
		}

		if ( ! $anon ) {
			return $acct;
		}

		if ( ! $acct ) {
			Store::update(
				'players',
				array(
					'user_id'   => $user_id,
					'last_seen' => self::now(),
				),
				array(
					'id'      => (int) $anon['id'],
					'user_id' => 0,
				)
			);
			return self::by_id( (int) $anon['id'] );
		}

		$merged  = self::merge_rows( $acct, $anon );
		$keep_id = (int) $acct['id'];
		$drop_id = (int) $anon['id'];
		$runs    = self::runs_table();

		// Move the anonymous history onto the surviving player. UPDATE IGNORE
		// because `one_per_day` may already hold a run for the same game and
		// day on both rows — one person, two identities, one day. The ignored
		// rows are then deleted with the row they belong to: the surviving
		// run is the one whose capital the merge just kept, so keeping the
		// loser's duplicate would put two runs for one day in the export.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; the table name is built from $wpdb->prefix and cannot be a placeholder, every value is.
		$wpdb->query( $wpdb->prepare( "UPDATE IGNORE `{$runs}` SET player_id = %d WHERE player_id = %d", $keep_id, $drop_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$runs}` WHERE player_id = %d", $drop_id ) );

		// Delete BEFORE updating, not after: `nickname_key` is UNIQUE, so if
		// the merge carries the anonymous row's nickname across while that row
		// still holds it, the update fails on its own key and the name is
		// silently lost.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API.
		$wpdb->delete( self::table(), array( 'id' => $drop_id ), array( '%d' ) );

		$merged['last_seen'] = self::now();

		Store::update( 'players', $merged, array( 'id' => $keep_id ) );

		return self::by_id( $keep_id );
	}

	/* ---------------------------------------------------------------- */
	/* Shapes                                                            */
	/* ---------------------------------------------------------------- */

	/**
	 * The player as the client is allowed to see them.
	 *
	 * A whitelist, like every other payload in this plugin: the row picks up
	 * columns over time and none of them should reach the browser by default.
	 *
	 * @param array<string,mixed>|null $row Player row, or null when not onboarded.
	 * @return array<string,mixed>
	 */
	public static function public_row( ?array $row ): array {
		if ( ! $row ) {
			return array(
				'uuid'       => '',
				'nickname'   => '',
				'lang'       => 'en',
				'linked'     => false,
				'onboarded'  => false,
				'stc'        => self::blank_game(),
				'reveal'     => self::blank_game(),
			);
		}

		return array(
			// Handed back once so the client can hold it for the ITP header
			// fallback. HttpOnly stops a passive XSS reading document.cookie;
			// it cannot stop one reading a response this same page fetched, and
			// pretending otherwise would be the wrong kind of comfort. What
			// bounds the damage is scope: this value names a virtual-money game
			// row that holds no email, no IP and no ability to act on an
			// account.
			'uuid'      => (string) $row['uuid'],
			'nickname'  => (string) $row['nickname'],
			'lang'      => self::lang( (string) $row['lang'] ),
			'linked'    => (int) $row['user_id'] > 0,
			'onboarded' => ! empty( $row['ack_at'] ),
			'stc'       => array(
				'capital'     => (int) $row['stc_capital'],
				'streak'      => (int) $row['stc_streak'],
				'best_streak' => (int) $row['stc_best_streak'],
				'deaths'      => (int) $row['stc_deaths'],
				'last_day'    => (string) $row['stc_last_day'],
			),
			'reveal'    => array(
				'capital'     => (int) $row['rev_capital'],
				'index_cap'   => (int) $row['rev_index_cap'],
				'streak'      => (int) $row['rev_streak'],
				'best_streak' => (int) $row['rev_best_streak'],
				'deaths'      => (int) $row['rev_deaths'],
				'last_day'    => (string) $row['rev_last_day'],
			),
		);
	}

	/**
	 * A fresh run's numbers, for a visitor who has not onboarded yet.
	 *
	 * @return array<string,mixed>
	 */
	private static function blank_game(): array {
		return array(
			'capital'     => Config::CAPITAL_START,
			'index_cap'   => Config::CAPITAL_START,
			'streak'      => 0,
			'best_streak' => 0,
			'deaths'      => 0,
			'last_day'    => '',
		);
	}

	/* ---------------------------------------------------------------- */
	/* Small shared helpers                                              */
	/* ---------------------------------------------------------------- */

	/**
	 * Reduce anything language-shaped to the two we serve.
	 *
	 * @param string $raw Locale or slug.
	 */
	public static function lang( string $raw ): string {
		return str_starts_with( strtolower( $raw ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Now, in UTC, as MySQL writes it.
	 *
	 * Every timestamp this plugin writes is UTC. The day key is a UTC-derived
	 * value (see class-day.php), so storing site-local time in the same table
	 * would make a retention window and a day boundary disagree by a few
	 * hours — twice a year by an hour more.
	 */
	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Whether a MySQL error is a unique-key collision. Pure.
	 *
	 * @param string $error $wpdb->last_error.
	 */
	public static function is_duplicate( string $error ): bool {
		return false !== stripos( $error, 'duplicate entry' );
	}
}
