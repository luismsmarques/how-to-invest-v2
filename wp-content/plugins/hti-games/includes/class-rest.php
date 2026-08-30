<?php
/**
 * The games REST surface, and the line the server never lets the client cross.
 *
 * Namespace `htinvest/v1`, path prefix `/games/`. Permission callbacks are
 * hti-engine's own public statics rather than copies: `check_nonce` for the
 * anonymous routes, `check_auth` for the one that needs an account. Reusing
 * them means a future change to how this site checks a nonce happens once —
 * hti-engine's class-feedback.php registers its route the same way.
 *
 * ---------------------------------------------------------------------------
 * The anti-cheat boundary
 * ---------------------------------------------------------------------------
 *
 * Both games are worthless the moment the answer is in the page. `GET /today`
 * therefore serves a payload built by an explicit WHITELIST — every field the
 * client gets is named in code, one at a time, including the fields inside
 * each candle and each metric. It is never built by taking the full meta and
 * unsetting what must not go out.
 *
 * That choice is the whole design. A blacklist fails OPEN: the day somebody
 * adds `hti_stc_symbol_2` to the scenario CPT, a blacklist ships it to the
 * browser and nobody finds out until a player posts the trick on Reddit. A
 * whitelist fails CLOSED: the same new field simply does not appear, and the
 * worst case is a missing feature somebody notices immediately.
 *
 * The same reasoning applies one level down. A candle is rebuilt as exactly
 * four integers, and a fundamental as exactly three, so a stray `symbol` or
 * `company` riding inside a nested row cannot leak either.
 *
 * The handle the client gets for "today's challenge" is an HMAC of the game
 * and the day under wp_salt('auth'), never the post id — a post id is
 * guessable, enumerable and readable through the ordinary WordPress REST API,
 * and the id of tomorrow's scenario would be a preview of tomorrow.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

use HTI\Engine\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Route registration, the public payload whitelists, and the write path.
 */
class REST {

	/**
	 * The shared namespace. The games are part of the same API surface as the
	 * rest of the site, not a second one.
	 */
	private const NS = 'htinvest/v1';

	/**
	 * How many characters of the day HMAC the client sees. 16 hex characters
	 * is 64 bits — far past anything worth brute-forcing for a handle whose
	 * only power is "name today's challenge".
	 */
	private const REF_LEN = 16;

	/**
	 * Decisions each game accepts. Membership, never a range: the value comes
	 * from the open web.
	 */
	public const STC_DECISIONS    = array( 'buy', 'sell', 'pass' );
	public const REVEAL_DECISIONS = array( 'invest', 'pass' );

	/**
	 * Hook route registration.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/* ---------------------------------------------------------------- */
	/* The route table                                                   */
	/* ---------------------------------------------------------------- */

	/**
	 * Every route this plugin serves, as data. Pure.
	 *
	 * A table rather than nine register_rest_route() calls because the rate
	 * limiter fails OPEN — RateLimit::exceeded() returns false for a key that
	 * is not in the limits table — so a typo'd or unregistered key silently
	 * removes a limit rather than breaking anything. tests/test-rest-contract
	 * reads this table and asserts every `rate` value here is actually
	 * registered by the plugin bootstrap, which is only possible because the
	 * routes are readable data.
	 *
	 * @return array<int,array{path:string,methods:string,callback:array{0:string,1:string},permission:string,rate:string,args:array<string,mixed>}>
	 */
	public static function routes(): array {
		$game_arg = array(
			'game' => array(
				'type'     => 'string',
				'required' => true,
			),
		);

		return array(
			array(
				'path'       => '/games/session',
				'methods'    => 'POST',
				'callback'   => array( __CLASS__, 'session' ),
				'permission' => 'check_nonce',
				'rate'       => 'game_session',
				'args'       => array(
					'ack'        => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'lang'       => array( 'type' => 'string' ),
					'newsletter' => array( 'type' => 'boolean' ),
				),
			),
			array(
				'path'       => '/games/(?P<game>stc|reveal)/today',
				'methods'    => 'GET',
				'callback'   => array( __CLASS__, 'today' ),
				'permission' => 'check_nonce',
				'rate'       => 'game_today',
				'args'       => $game_arg,
			),
			array(
				'path'       => '/games/(?P<game>stc|reveal)/decision',
				'methods'    => 'POST',
				'callback'   => array( __CLASS__, 'decision' ),
				'permission' => 'check_nonce',
				'rate'       => 'game_decision',
				'args'       => $game_arg + array(
					'decision' => array(
						'type'     => 'string',
						'required' => true,
					),
					'day'      => array( 'type' => 'string' ),
					'risk_bp'  => array( 'type' => 'integer' ),
					'size'     => array( 'type' => 'integer' ),
					'double'   => array( 'type' => 'boolean' ),
					'lang'     => array( 'type' => 'string' ),
				),
			),
			array(
				'path'       => '/games/leaderboard',
				'methods'    => 'GET',
				'callback'   => array( __CLASS__, 'leaderboard' ),
				'permission' => 'check_nonce',
				'rate'       => 'game_board',
				'args'       => array(
					'game'  => array( 'type' => 'string' ),
					'board' => array( 'type' => 'string' ),
					'day'   => array( 'type' => 'string' ),
				),
			),
			array(
				'path'       => '/games/profile',
				'methods'    => 'GET',
				'callback'   => array( __CLASS__, 'profile' ),
				'permission' => 'check_nonce',
				'rate'       => 'game_profile',
				'args'       => array(),
			),
			array(
				'path'       => '/games/nickname',
				'methods'    => 'POST',
				'callback'   => array( __CLASS__, 'nickname' ),
				'permission' => 'check_nonce',
				'rate'       => 'game_nick',
				'args'       => array(
					'nickname' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
			array(
				'path'       => '/games/link',
				'methods'    => 'POST',
				'callback'   => array( Auth::class, 'rest_link' ),
				'permission' => 'check_nonce',
				'rate'       => 'game_link',
				'args'       => array(
					'email'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'consent'    => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'newsletter' => array( 'type' => 'boolean' ),
					'game'       => array( 'type' => 'string' ),
					'lang'       => array( 'type' => 'string' ),
					'hti_hp'     => array( 'type' => 'string' ),
				),
			),
			array(
				'path'       => '/games/claim',
				'methods'    => 'POST',
				'callback'   => array( __CLASS__, 'claim' ),
				'permission' => 'check_auth',
				// No rate key: the route requires an authenticated session and
				// a valid nonce, and it is idempotent — the second call finds
				// the row already bound and does nothing.
				'rate'       => '',
				'args'       => array(),
			),
			array(
				'path'       => '/games/me',
				'methods'    => 'DELETE',
				'callback'   => array( Privacy::class, 'rest_forget' ),
				'permission' => 'check_nonce',
				'rate'       => 'game_forget',
				'args'       => array(),
			),
		);
	}

	/**
	 * Register the table.
	 */
	public static function register_routes(): void {
		foreach ( self::routes() as $route ) {
			register_rest_route(
				self::NS,
				$route['path'],
				array(
					'methods'             => $route['methods'],
					'callback'            => $route['callback'],
					// hti-engine's public statics, not copies of them.
					'permission_callback' => array( \HTI\Engine\REST::class, $route['permission'] ),
					'args'                => $route['args'],
				)
			);
		}
	}

	/* ---------------------------------------------------------------- */
	/* The public payload whitelists (pure)                              */
	/* ---------------------------------------------------------------- */

	/**
	 * The opaque, day-scoped handle for a challenge.
	 *
	 * Never the post id. An id is small, sequential and readable through the
	 * ordinary WordPress REST API, so handing one out is handing out a way to
	 * fetch the scenario — and, by adding one, tomorrow's.
	 *
	 * @param string $game    Game id.
	 * @param string $day_key Day key.
	 */
	public static function ref( string $game, string $day_key ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
		return substr( hash_hmac( 'sha256', $game . '|' . $day_key, $salt ), 0, self::REF_LEN );
	}

	/**
	 * What a Survive the Charts player may see BEFORE they decide. Pure.
	 *
	 * Named field by field. Nothing that describes the outcome is here: not a
	 * single tick past index 80, not the outcome, not where price touched, not
	 * the instrument, not its asset class, not whether passing was right, not
	 * the lesson, not the post id. Adding a meta key to the scenario CPT
	 * cannot change that — a new key has to be named here to be served.
	 *
	 * @param array<string,mixed> $meta   The scenario's meta IN FULL, secrets
	 *                                    included; this function is what makes
	 *                                    that safe.
	 * @param array<string,mixed> $player Player::public_row() for the visitor.
	 * @return array<string,mixed>
	 */
	public static function public_challenge_stc( array $meta, array $player ): array {
		$day     = Day::key();
		$visible = self::visible_ticks( $meta );

		return array(
			'game'       => Config::GAME_STC,
			'day'        => $day,
			'ref'        => self::ref( Config::GAME_STC, $day ),
			'reset_in'   => Day::seconds_until_reset(),
			// Exactly the visible window. The slice is the anti-cheat: the
			// outcome ticks live in the same meta value and simply never get
			// past this line.
			'candles'    => $visible,
			// Derived, never read from meta. There is no stored ATR — a second
			// copy of a number computed from the candles is a number that can
			// disagree with them — so this is the same call, over the same
			// slice, that STC_Engine::resolve() will make when the decision
			// arrives. The player draws their stop where the server will put it.
			'atr'        => STC_Engine::atr( self::assoc_ticks( $visible ), Config::STC_ATR_PERIOD ),
			'tick_scale' => (int) ( $meta['hti_stc_scale'] ?? 0 ) > 0 ? (int) $meta['hti_stc_scale'] : Config::TICK_SCALE,
			'risk_tiers' => Config::STC_RISK_BP,
			'multiplier' => Config::STC_DOUBLE,
			'target'     => array(
				'num' => Config::STC_TARGET_NUM,
				'den' => Config::STC_TARGET_DEN,
			),
			'decisions'  => self::STC_DECISIONS,
			'capital'    => (int) ( $player['stc']['capital'] ?? Config::CAPITAL_START ),
			'streak'     => (int) ( $player['stc']['streak'] ?? 0 ),
			'floor'      => Config::CAPITAL_FLOOR,
			'played'     => false,
		);
	}

	/**
	 * What a Reveal player may see BEFORE they decide. Pure.
	 *
	 * The dossier only: sector, region, size band, the six fundamentals
	 * against their sector average, and the three period headlines. Not the
	 * company, not the year, not the five-year return, not the index's return,
	 * not the outcome headline, not the lesson, not the sources — the sources
	 * especially, since a URL names the company in the slug.
	 *
	 * @param array<string,mixed> $meta   The case's meta IN FULL.
	 * @param array<string,mixed> $player Player::public_row() for the visitor.
	 * @return array<string,mixed>
	 */
	public static function public_challenge_reveal( array $meta, array $player ): array {
		$day = Day::key();
		// The dossier is bilingual in storage and monolingual on the wire:
		// shipping both languages would double the payload and hand a reader
		// of one of them nothing they can use.
		$lang = 'pt' === ( $player['lang'] ?? 'en' ) ? 'pt' : 'en';

		return array(
			'game'         => Config::GAME_REVEAL,
			'day'          => $day,
			'ref'          => self::ref( Config::GAME_REVEAL, $day ),
			'reset_in'     => Day::seconds_until_reset(),
			'lang'         => $lang,
			'sector'       => self::text( $meta[ 'hti_rev_sector_' . $lang ] ?? '', 240 ),
			'revenue_band' => self::text( $meta[ 'hti_rev_revenue_band_' . $lang ] ?? '', 240 ),
			'fundamentals' => self::fundamentals( $meta['hti_rev_fundamentals'] ?? '', $lang ),
			'headlines'    => self::headlines( $meta['hti_rev_headlines'] ?? '', $lang ),
			'sizes'        => Config::REVEAL_SIZES,
			'decisions'    => self::REVEAL_DECISIONS,
			'capital'      => (int) ( $player['reveal']['capital'] ?? Config::CAPITAL_START ),
			'index_cap'    => (int) ( $player['reveal']['index_cap'] ?? Config::CAPITAL_START ),
			'streak'       => (int) ( $player['reveal']['streak'] ?? 0 ),
			'floor'        => Config::CAPITAL_FLOOR,
			'played'       => false,
		);
	}

	/**
	 * The first 80 candles, each rebuilt as exactly four integers. Pure.
	 *
	 * Two independent guarantees, both load-bearing:
	 *   - the slice drops every tick from index 80 on, which is the outcome;
	 *   - the per-candle rebuild drops every field that is not o/h/l/c, so a
	 *     candle carrying a timestamp, a symbol or a label cannot leak one.
	 *
	 * Accepts a JSON string as well as an array because meta written by an
	 * importer and meta written by the admin screen do not always arrive the
	 * same way.
	 *
	 * @param mixed $raw Candles as stored.
	 * @return array<int,array<int,int>>
	 */
	public static function visible_candles( $raw ): array {
		$list = self::to_list( $raw );
		$out  = array();

		foreach ( array_slice( $list, 0, Config::STC_VISIBLE ) as $candle ) {
			$out[] = self::candle( $candle );
		}

		return $out;
	}

	/**
	 * One candle as [open, high, low, close], in integer ticks. Pure.
	 *
	 * Positional list or keyed map, both accepted; anything else becomes four
	 * zeroes rather than an exception, because a malformed candle is a content
	 * problem and must not be a 500 on a public page.
	 *
	 * @param mixed $candle One candle as stored.
	 * @return array<int,int>
	 */
	private static function candle( $candle ): array {
		if ( ! is_array( $candle ) ) {
			return array( 0, 0, 0, 0 );
		}

		if ( isset( $candle['o'], $candle['h'], $candle['l'], $candle['c'] ) ) {
			return array( (int) $candle['o'], (int) $candle['h'], (int) $candle['l'], (int) $candle['c'] );
		}

		$list = array_values( $candle );
		return array(
			(int) ( $list[0] ?? 0 ),
			(int) ( $list[1] ?? 0 ),
			(int) ( $list[2] ?? 0 ),
			(int) ( $list[3] ?? 0 ),
		);
	}

	/**
	 * The six fundamentals, each rebuilt as exactly key/value/average. Pure.
	 *
	 * Same recursion of the whitelist principle as the candles: whatever else
	 * a stored metric row carries — an editor's note, the company name, the
	 * year the figure is from — is not copied, because only three fields are.
	 *
	 * @param mixed $raw Metrics as stored.
	 * @return array<int,array<string,mixed>>
	 */
	public static function metrics( $raw ): array {
		$out = array();

		foreach ( array_slice( self::to_list( $raw ), 0, 6 ) as $metric ) {
			if ( ! is_array( $metric ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $metric['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$out[] = array(
				'key'   => $key,
				'value' => (int) ( $metric['value'] ?? 0 ),
				'avg'   => (int) ( $metric['avg'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * The three period headlines, as plain text. Pure.
	 *
	 * Their anonymity is an editorial invariant enforced where the case is
	 * published — a headline naming the company would give the answer away and
	 * no amount of filtering here could reliably catch it. What this function
	 * guarantees is the narrower thing it can: three strings, tags stripped,
	 * capped, and nothing else from the row they came in.
	 *
	 * @param mixed $raw Headlines as stored.
	 * @return array<int,string>
	 */
	public static function headlines( $raw ): array {
		$out = array();

		foreach ( array_slice( self::to_list( $raw ), 0, 3 ) as $line ) {
			$text = self::text( is_array( $line ) ? ( $line['text'] ?? '' ) : $line, 200 );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------- */
	/* Handlers                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * POST /games/session — onboarding, and the only place a player is made.
	 *
	 * The `ack` box is an acknowledgement of the simulation, not a consent
	 * basis (see class-player.php): it is required to play, so it could not be
	 * freely given even if we wanted to call it one. Its version is stamped
	 * server-side from Player::ACK_VERSION — the record has to say which words
	 * WE showed, and a client-supplied version would be a client-supplied
	 * account of what it agreed to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function session( WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'game_session' ) ) {
			return self::too_many();
		}

		if ( true !== rest_sanitize_boolean( $request->get_param( 'ack' ) ) ) {
			return new WP_Error(
				'hti_game_ack_required',
				__( 'Please confirm you understand this is a simulation with virtual money.', 'hti-games' ),
				array( 'status' => 422 )
			);
		}

		$player = Player::ensure(
			array(
				'uuid'       => Player::read_uuid( $request ),
				'lang'       => (string) $request->get_param( 'lang' ),
				'newsletter' => true === rest_sanitize_boolean( $request->get_param( 'newsletter' ) ),
				'ack'        => true,
				'ack_ver'    => Player::ACK_VERSION,
				'user_id'    => get_current_user_id(),
			)
		);

		if ( ! $player ) {
			return new WP_Error( 'hti_game_session_failed', __( 'Could not start a session. Please try again.', 'hti-games' ), array( 'status' => 500 ) );
		}

		self::bump( 'game_start', 'onboard' );

		return new WP_REST_Response(
			array(
				'player'   => Player::public_row( $player ),
				'day'      => Day::key(),
				'reset_in' => Day::seconds_until_reset(),
			),
			200
		);
	}

	/**
	 * GET /games/{game}/today — the challenge, minus everything that answers it.
	 *
	 * A player who has already decided today gets their recorded result back
	 * as well, so a reload returns to the result screen rather than to a
	 * chart they can no longer play.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function today( WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'game_today' ) ) {
			return self::too_many();
		}

		$game = sanitize_key( (string) $request->get_param( 'game' ) );
		if ( ! Config::is_game( $game ) ) {
			return self::unknown_game();
		}

		$day        = Day::key();
		$content_id = Library::for_day( $game, $day );
		if ( $content_id <= 0 ) {
			return new WP_Error( 'hti_game_no_content', __( 'Today’s challenge is not available yet.', 'hti-games' ), array( 'status' => 503 ) );
		}

		$row     = Player::resolve( $request );
		$public  = Player::public_row( $row );
		$meta    = self::meta( $content_id );
		$payload = Config::GAME_STC === $game
			? self::public_challenge_stc( $meta, $public )
			: self::public_challenge_reveal( $meta, $public );

		$payload['player'] = $public;
		$payload['real']   = Library::is_real( $game );

		// Already decided? Then the answer is no longer secret from them.
		$run = $row ? self::find_run( (int) $row['id'], $game, $day ) : null;
		if ( $run ) {
			$payload['played'] = true;
			$payload['result'] = self::run_result( $game, $run, $meta, Player::lang( (string) ( $row['lang'] ?? '' ) ) );
		}

		self::bump( 'game_view', $game . '_view' );

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * POST /games/{game}/decision — the write path.
	 *
	 * The order below is the design, not an accident, and there is deliberately
	 * no check-then-write anywhere in it. Nothing asks "has this player played
	 * today?" and then inserts: two simultaneous POSTs would both pass that
	 * question and both score. Instead the UNIQUE key `one_per_day` is the
	 * only arbiter — one INSERT wins, the other comes back as a duplicate — and
	 * the capital is applied by an UPDATE whose WHERE clause carries its own
	 * guard, so even a retry that somehow got past the index could not apply
	 * the same P&L twice.
	 *
	 * The insert happens BEFORE the capital moves. If the process dies between
	 * the two the run exists and the money did not move, which is the harmless
	 * direction to fail in; the opposite order would let a crash charge
	 * somebody for a run that was never recorded.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function decision( WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'game_decision' ) ) {
			return self::too_many();
		}

		$game = sanitize_key( (string) $request->get_param( 'game' ) );
		if ( ! Config::is_game( $game ) ) {
			return self::unknown_game();
		}

		// 1. Who is this. No session, no run — the row is what a run is
		//    recorded against, and it only exists after onboarding.
		$row = Player::resolve( $request );
		if ( ! $row ) {
			return new WP_Error( 'hti_game_no_session', __( 'Start a session before playing.', 'hti-games' ), array( 'status' => 403 ) );
		}

		// 2. The day is the server's, always. The client's copy is compared,
		//    never used: a tab left open across 00:00 IST would otherwise post
		//    yesterday's decision into today's challenge.
		$day        = Day::key();
		$client_day = sanitize_text_field( (string) $request->get_param( 'day' ) );
		if ( '' !== $client_day && $client_day !== $day ) {
			return new WP_Error(
				'hti_game_day_moved',
				__( 'A new day started while this page was open. Reload for today’s challenge.', 'hti-games' ),
				array(
					'status' => 409,
					'day'    => $day,
				)
			);
		}

		// 3. Today's content, and the engine's verdict on it.
		$content_id = Library::for_day( $game, $day );
		if ( $content_id <= 0 ) {
			return new WP_Error( 'hti_game_no_content', __( 'Today’s challenge is not available yet.', 'hti-games' ), array( 'status' => 503 ) );
		}

		$meta      = self::meta( $content_id );
		$player_id = (int) $row['id'];
		$lang      = Player::lang( (string) ( $request->get_param( 'lang' ) ?: ( $row['lang'] ?? '' ) ) );
		$decision  = sanitize_key( (string) $request->get_param( 'decision' ) );

		$outcome = Config::GAME_STC === $game
			? self::resolve_stc( $request, $meta, $row, $decision )
			: self::resolve_reveal( $request, $meta, $row, $decision );

		if ( $outcome instanceof WP_Error ) {
			return $outcome;
		}

		// 4. The record. One INSERT, and the UNIQUE key decides.
		$run = array(
			'player_id'    => $player_id,
			'game'         => Config::game_id( $game ),
			'day_key'      => $day,
			'content_id'   => $content_id,
			'decision'     => $outcome['decision'],
			'risk_bp'      => $outcome['risk_bp'],
			'multiplier'   => $outcome['multiplier'],
			'outcome'      => $outcome['outcome'],
			'touch_idx'    => $outcome['touch_idx'],
			'board_score'  => $outcome['board_score'],
			'pnl'          => $outcome['pnl'],
			'cap_before'   => $outcome['cap_before'],
			'cap_after'    => $outcome['cap_after'],
			'idx_before'   => $outcome['idx_before'],
			'idx_after'    => $outcome['idx_after'],
			'died'         => $outcome['died'] ? 1 : 0,
			'streak_after' => $outcome['streak_after'],
			'lang'         => $lang,
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		);

		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API.
		$inserted = $wpdb->insert( Store::runs_table(), $run, self::run_formats( $run ) );
		$error    = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( ! $inserted ) {
			if ( Player::is_duplicate( $error ) ) {
				// A double-submit — a double tap, a retry after a timeout, two
				// tabs. From the player's side that is not an error, it is the
				// same run, so it comes back with the result they already have
				// rather than a message about a database constraint.
				$existing = self::find_run( $player_id, $game, $day );
				return new WP_Error(
					'hti_game_already_played',
					__( 'You have already played today. Come back after the reset.', 'hti-games' ),
					array(
						'status'   => 409,
						'result'   => $existing ? self::run_result( $game, $existing, $meta, $lang ) : null,
						'reset_in' => Day::seconds_until_reset(),
					)
				);
			}

			return new WP_Error( 'hti_game_save_failed', __( 'Could not record your decision. Please try again.', 'hti-games' ), array( 'status' => 500 ) );
		}

		// 5. Only now, and only once. The `<> day` guard in the WHERE clause is
		//    what makes that second part true: a retry that reached this line
		//    twice would match zero rows the second time.
		self::apply_capital( $game, $player_id, $day, $outcome );

		$fresh  = Player::by_id( $player_id );
		$result = self::run_result( $game, $run, $meta, $lang );

		self::bump( 'game_decision', $game . '_' . $outcome['decision'] );
		self::bump( 'game_result', $game . '_' . $outcome['outcome'] );
		if ( Config::GAME_STC === $game && $outcome['risk_bp'] > 0 ) {
			self::bump( 'game_decision', 'stc_risk_' . $outcome['risk_bp'] );
		}
		if ( Config::GAME_REVEAL === $game && $outcome['risk_bp'] > 0 ) {
			self::bump( 'game_decision', 'reveal_size_' . intdiv( $outcome['risk_bp'], 100 ) );
		}
		if ( $outcome['died'] ) {
			self::bump( 'game_death', $game . '_death' );
		}

		return new WP_REST_Response(
			array(
				'result'   => $result,
				'player'   => Player::public_row( $fresh ),
				'day'      => $day,
				'reset_in' => Day::seconds_until_reset(),
			),
			200
		);
	}

	/**
	 * GET /games/leaderboard — a board, plus the caller's own pinned row.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function leaderboard( WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'game_board' ) ) {
			return self::too_many();
		}

		$board = sanitize_key( (string) $request->get_param( 'board' ) );
		$board = Leaderboard::is_board( $board ) ? $board : Leaderboard::BOARD_DAILY;

		$game = sanitize_key( (string) $request->get_param( 'game' ) );
		$game = Config::is_game( $game ) ? $game : Config::GAME_STC;

		$row       = Player::resolve( $request );
		$player_id = $row ? (int) $row['id'] : 0;

		// The day is only ever today's or a past one, and it is validated
		// before it reaches a query — a board is public, so its parameters are
		// as untrusted as any other.
		$day = sanitize_text_field( (string) $request->get_param( 'day' ) );
		if ( '' === $day || ! Day::valid( $day ) || $day > Day::key() ) {
			$day = Day::key();
		}

		self::bump( 'game_board_view', $game . '_' . $board );

		$data = Leaderboard::BOARD_SURVIVAL === $board
			? Leaderboard::survival( $player_id )
			: Leaderboard::daily( $game, $day, $player_id );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /games/profile — one player's own numbers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function profile( WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'game_profile' ) ) {
			return self::too_many();
		}

		$row = Player::resolve( $request );
		if ( ! $row ) {
			// Not an error: a visitor who has not played has an empty profile,
			// and saying so is more useful than a 404.
			return new WP_REST_Response(
				array(
					'player' => Player::public_row( null ),
					'runs'   => array(),
				),
				200
			);
		}

		$runs = self::recent_runs( (int) $row['id'] );

		return new WP_REST_Response(
			array(
				'player'       => Player::public_row( $row ),
				'runs'         => $runs,
				'calendar'     => Scoring::calendar( $runs ),
				'risk_by_week' => Scoring::risk_by_week( $runs ),
				'badges'       => Scoring::badges( $row, $runs ),
				'day'          => Day::key(),
				'reset_in'     => Day::seconds_until_reset(),
			),
			200
		);
	}

	/**
	 * POST /games/nickname — claim a name for the public board.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function nickname( WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'game_nick' ) ) {
			return self::too_many();
		}

		$row = Player::resolve( $request );
		if ( ! $row ) {
			return new WP_Error( 'hti_game_no_session', __( 'Start a session before choosing a name.', 'hti-games' ), array( 'status' => 403 ) );
		}

		$result = Player::set_nickname( (int) $row['id'], (string) $request->get_param( 'nickname' ) );

		if ( ! $result['ok'] ) {
			if ( 'taken' === $result['code'] ) {
				return new WP_Error( 'hti_game_nickname_taken', __( 'That name is taken. Try another.', 'hti-games' ), array( 'status' => 409 ) );
			}
			if ( 'failed' === $result['code'] ) {
				return new WP_Error( 'hti_game_nickname_failed', __( 'Could not save that name. Please try again.', 'hti-games' ), array( 'status' => 500 ) );
			}
			return new WP_Error(
				'hti_game_nickname_invalid',
				__( '3–24 characters, letters, numbers, - and _ only.', 'hti-games' ),
				array(
					'status' => 422,
					'reason' => $result['code'],
				)
			);
		}

		self::bump( 'game_nickname_set', 'nickname' );

		return new WP_REST_Response(
			array(
				'nickname' => $result['nickname'],
				'player'   => Player::public_row( Player::by_id( (int) $row['id'] ) ),
			),
			200
		);
	}

	/**
	 * POST /games/claim — bind the anonymous run to the signed-in account.
	 *
	 * Called after an ordinary WordPress sign-in, where nothing else would
	 * connect the cookie in the browser to the account that just authenticated.
	 * The magic-link flow does the same thing for itself (see class-auth).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function claim( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$row     = Player::claim_for_user( Player::read_uuid( $request ), $user_id );

		if ( ! $row ) {
			return new WP_REST_Response(
				array(
					'claimed' => false,
					'player'  => Player::public_row( null ),
				),
				200
			);
		}

		// The surviving row may not be the one the cookie named — a merge keeps
		// the account's row — so the cookie is re-pointed at whatever survived.
		Player::set_cookie( (string) $row['uuid'] );

		return new WP_REST_Response(
			array(
				'claimed' => true,
				'player'  => Player::public_row( $row ),
			),
			200
		);
	}

	/* ---------------------------------------------------------------- */
	/* Engine adapters                                                   */
	/* ---------------------------------------------------------------- */

	/**
	 * Validate a Survive the Charts decision and run the engine over it.
	 *
	 * Contract with STC_Engine (hard dependency):
	 *   resolve( array $visible, array $after, string $direction, int $risk_bp,
	 *            bool $double, int $capital ): array{outcome,touch_idx,pnl}
	 *   apply( int $capital, int $pnl ): array{capital:int,died:bool}
	 *
	 * @param WP_REST_Request     $request  Request.
	 * @param array<string,mixed> $meta     Scenario meta.
	 * @param array<string,mixed> $row      Player row.
	 * @param string              $decision Sanitised decision.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function resolve_stc( WP_REST_Request $request, array $meta, array $row, string $decision ) {
		if ( ! in_array( $decision, self::STC_DECISIONS, true ) ) {
			return self::invalid_decision();
		}

		$pass    = 'pass' === $decision;
		$risk_bp = (int) $request->get_param( 'risk_bp' );
		$double  = true === rest_sanitize_boolean( $request->get_param( 'double' ) );

		if ( $pass ) {
			// A pass commits nothing, so it carries no risk and no multiplier.
			// Normalising here rather than trusting the client keeps the runs
			// table honest: "pass at 25%" is not a thing that can be recorded.
			$risk_bp = 0;
			$double  = false;
		} elseif ( ! Config::is_risk_bp( $risk_bp ) ) {
			return new WP_Error( 'hti_game_invalid_risk', __( 'That risk level is not one of the options.', 'hti-games' ), array( 'status' => 422 ) );
		}

		$candles = self::to_list( $meta['hti_stc_candles'] ?? array() );
		$visible = array_slice( $candles, 0, Config::STC_VISIBLE );
		$after   = array_slice( $candles, Config::STC_VISIBLE, Config::STC_OUTCOME );

		$cap_before = (int) $row['stc_capital'];
		$verdict    = STC_Engine::resolve( $visible, $after, $decision, $risk_bp, $double, $cap_before );

		$pnl     = (int) ( $verdict['pnl'] ?? 0 );
		$applied = STC_Engine::apply( $cap_before, $pnl );
		$died    = ! empty( $applied['died'] );

		return array(
			'decision'     => $decision,
			'risk_bp'      => $risk_bp,
			'multiplier'   => $double ? Config::STC_DOUBLE : 1,
			'outcome'      => substr( (string) ( $verdict['outcome'] ?? '' ), 0, 8 ),
			'touch_idx'    => (int) ( $verdict['touch_idx'] ?? -1 ),
			'pnl'          => $pnl,
			'cap_before'   => $cap_before,
			'cap_after'    => (int) ( $applied['capital'] ?? $cap_before ),
			// The Reveal's index columns mean nothing for this game. Written
			// as an explicit zero rather than left to the column default, so
			// an export of a chart run never shows a phantom index run.
			'idx_before'   => 0,
			'idx_after'    => 0,
			'died'         => $died,
			'streak_after' => self::next_streak( (int) $row['stc_streak'], $decision, $pnl, $died ),
			'board_score'  => $risk_bp > 0 ? Scoring::board_score( $pnl, $risk_bp ) : 0,
		);
	}

	/**
	 * Validate a Reveal decision and run the engine over it.
	 *
	 * Contract with Reveal_Engine (hard dependency):
	 *   resolve( array $case, string $decision, int $size_pct, int $capital,
	 *            int $index_cap ): array{outcome,pnl,capital,index_cap,died}
	 *
	 * The committed share is stored in `risk_bp` — the column is the same
	 * shape for both games and 25% is 2500 basis points either way, which
	 * keeps one leaderboard query able to normalise both.
	 *
	 * @param WP_REST_Request     $request  Request.
	 * @param array<string,mixed> $meta     Case meta.
	 * @param array<string,mixed> $row      Player row.
	 * @param string              $decision Sanitised decision.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function resolve_reveal( WP_REST_Request $request, array $meta, array $row, string $decision ) {
		if ( ! in_array( $decision, self::REVEAL_DECISIONS, true ) ) {
			return self::invalid_decision();
		}

		$pass = 'pass' === $decision;
		$size = (int) $request->get_param( 'size' );

		if ( $pass ) {
			$size = 0;
		} elseif ( ! Config::is_size( $size ) ) {
			return new WP_Error( 'hti_game_invalid_size', __( 'That amount is not one of the options.', 'hti-games' ), array( 'status' => 422 ) );
		}

		$cap_before = (int) $row['rev_capital'];
		$idx_before = (int) $row['rev_index_cap'];

		$verdict = Reveal_Engine::resolve( $meta, $decision, $size, $cap_before, $idx_before );

		$pnl     = (int) ( $verdict['pnl'] ?? 0 );
		$risk_bp = $size * 100;
		$died    = ! empty( $verdict['died'] );

		return array(
			'decision'     => $decision,
			'risk_bp'      => $risk_bp,
			'multiplier'   => 1,
			'outcome'      => substr( (string) ( $verdict['outcome'] ?? '' ), 0, 8 ),
			'touch_idx'    => -1,
			'pnl'          => $pnl,
			'cap_before'   => $cap_before,
			'cap_after'    => (int) ( $verdict['capital'] ?? $cap_before ),
			'idx_before'   => $idx_before,
			'idx_after'    => (int) ( $verdict['index_cap'] ?? $idx_before ),
			'died'         => $died,
			'streak_after' => self::next_streak( (int) $row['rev_streak'], $decision, $pnl, $died ),
			'board_score'  => $risk_bp > 0 ? Scoring::board_score( $pnl, $risk_bp ) : 0,
		);
	}

	/**
	 * The streak after a run. Pure.
	 *
	 * A winning day extends it, a losing day and a death end it — and a PASS
	 * leaves it exactly where it was. That last rule is deliberate and is the
	 * one worth defending: if passing broke the streak, the game would be
	 * telling players that the way to keep a streak alive is to trade every
	 * single day, which is precisely the habit both of these games exist to
	 * argue against. Sitting out costs nothing here, as it should.
	 *
	 * @param int    $current  Streak before.
	 * @param string $decision The decision taken.
	 * @param int    $pnl      Profit or loss.
	 * @param bool   $died     Whether the account blew up.
	 */
	public static function next_streak( int $current, string $decision, int $pnl, bool $died ): int {
		if ( $died ) {
			return 0;
		}
		if ( 'pass' === $decision ) {
			return $current;
		}
		return $pnl > 0 ? $current + 1 : 0;
	}

	/* ---------------------------------------------------------------- */
	/* Persistence helpers                                               */
	/* ---------------------------------------------------------------- */

	/**
	 * Apply a decided run to the player's standing numbers.
	 *
	 * One UPDATE, guarded. `*_last_day <> today` is the idempotency key: the
	 * insert already made a second run impossible, and this makes a second
	 * APPLICATION of the same run impossible too — which is the failure a
	 * retried request, a duplicated webhook or a double-clicked button would
	 * actually produce.
	 *
	 * A dead run resets the account to the starting capital and clears the
	 * streak; the run row keeps the capital it actually ended on, because the
	 * record of what happened and the state of the game are different things.
	 *
	 * @param string              $game      Game id.
	 * @param int                 $player_id Player row id.
	 * @param string              $day       Day key.
	 * @param array<string,mixed> $outcome   Resolved outcome.
	 */
	private static function apply_capital( string $game, int $player_id, string $day, array $outcome ): void {
		global $wpdb;

		// 'stc' or 'rev', chosen from a two-value map keyed by a validated
		// game id — never interpolated from anything a request carries.
		$p       = Config::GAME_STC === $game ? 'stc' : 'rev';
		$table   = Store::players_table();
		$died    = ! empty( $outcome['died'] );
		$capital = $died ? Config::CAPITAL_START : (int) $outcome['cap_after'];
		$streak  = (int) $outcome['streak_after'];

		if ( 'stc' === $p ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; the table name comes from $wpdb->prefix and cannot be a placeholder, every value is prepared.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}`
					 SET stc_capital = %d,
					     stc_streak = %d,
					     stc_best_streak = GREATEST(stc_best_streak, %d),
					     stc_deaths = stc_deaths + %d,
					     stc_last_day = %s,
					     last_seen = %s
					 WHERE id = %d AND ( stc_last_day IS NULL OR stc_last_day <> %s )",
					$capital,
					$streak,
					$streak,
					$died ? 1 : 0,
					$day,
					gmdate( 'Y-m-d H:i:s' ),
					$player_id,
					$day
				)
			);
			return;
		}

		$index = $died ? Config::CAPITAL_START : (int) $outcome['idx_after'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}`
				 SET rev_capital = %d,
				     rev_index_cap = %d,
				     rev_streak = %d,
				     rev_best_streak = GREATEST(rev_best_streak, %d),
				     rev_deaths = rev_deaths + %d,
				     rev_last_day = %s,
				     last_seen = %s
				 WHERE id = %d AND ( rev_last_day IS NULL OR rev_last_day <> %s )",
				$capital,
				$index,
				$streak,
				$streak,
				$died ? 1 : 0,
				$day,
				gmdate( 'Y-m-d H:i:s' ),
				$player_id,
				$day
			)
		);
	}

	/**
	 * One player's run for a game and day, or null.
	 *
	 * @param int    $player_id Player row id.
	 * @param string $game      Game id.
	 * @param string $day       Day key.
	 * @return array<string,mixed>|null
	 */
	private static function find_run( int $player_id, string $game, string $day ): ?array {
		global $wpdb;
		$runs = Store::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; per-player and must never be served stale.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$runs}` WHERE player_id = %d AND game = %d AND day_key = %s",
				$player_id,
				Config::game_id( $game ),
				$day
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A player's recent runs, newest first, for the profile page.
	 *
	 * @param int $player_id Player row id.
	 * @param int $limit     How many.
	 * @return array<int,array<string,mixed>>
	 */
	private static function recent_runs( int $player_id, int $limit = 120 ): array {
		global $wpdb;
		$runs = Store::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; one player's own history, never cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT game, day_key, decision, risk_bp, multiplier, outcome, board_score, pnl, cap_before, cap_after, died, streak_after
				 FROM `{$runs}` WHERE player_id = %d ORDER BY day_key DESC, id DESC LIMIT %d",
				$player_id,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'game'         => (int) $row['game'] === Config::game_id( Config::GAME_STC ) ? Config::GAME_STC : Config::GAME_REVEAL,
				'day'          => (string) $row['day_key'],
				'decision'     => (string) $row['decision'],
				'risk_bp'      => (int) $row['risk_bp'],
				'multiplier'   => (int) $row['multiplier'],
				'outcome'      => (string) $row['outcome'],
				'board_score'  => (int) $row['board_score'],
				'pnl'          => (int) $row['pnl'],
				'cap_before'   => (int) $row['cap_before'],
				'cap_after'    => (int) $row['cap_after'],
				'died'         => (bool) (int) $row['died'],
				'streak_after' => (int) $row['streak_after'],
			);
		}

		return $out;
	}

	/**
	 * The placeholder formats for a runs row. See Player::formats() for why
	 * this is derived rather than hand-listed.
	 *
	 * @param array<string,mixed> $row Column => value, in write order.
	 * @return array<int,string>
	 */
	private static function run_formats( array $row ): array {
		$strings = array( 'day_key', 'decision', 'outcome', 'lang', 'created_at' );

		$out = array();
		foreach ( array_keys( $row ) as $column ) {
			$out[] = in_array( $column, $strings, true ) ? '%s' : '%d';
		}
		return $out;
	}

	/* ---------------------------------------------------------------- */
	/* The post-decision payload                                         */
	/* ---------------------------------------------------------------- */

	/**
	 * Everything that was secret, now that the decision is recorded.
	 *
	 * This is the only function in the plugin allowed to read the fields
	 * public_challenge_*() refuses to touch, and it is only ever called with a
	 * run row in hand — a row that only exists because an INSERT succeeded.
	 * That is the invariant: the answer is a function of the record, so it
	 * cannot be served before the record exists.
	 *
	 * The crowd stat is here for the same reason. "68% of players went long
	 * today" is a hint before a decision and a lesson after one, and the only
	 * thing separating those two is which side of the INSERT it is served on.
	 *
	 * @param string              $game Game id.
	 * @param array<string,mixed> $run  The recorded run.
	 * @param array<string,mixed> $meta Content meta.
	 * @param string              $lang Language for the lesson.
	 * @return array<string,mixed>
	 */
	private static function run_result( string $game, array $run, array $meta, string $lang ): array {
		$day = (string) $run['day_key'];

		$base = array(
			'game'        => $game,
			'day'         => $day,
			'decision'    => (string) $run['decision'],
			'risk_bp'     => (int) $run['risk_bp'],
			'multiplier'  => (int) $run['multiplier'],
			'outcome'     => (string) $run['outcome'],
			'pnl'         => (int) $run['pnl'],
			'cap_before'  => (int) $run['cap_before'],
			'cap_after'   => (int) $run['cap_after'],
			'died'        => (bool) (int) $run['died'],
			'streak'      => (int) $run['streak_after'],
			'board_score' => (int) $run['board_score'],
			'survival'    => 0.0,
			'crowd'       => Leaderboard::day_stats( $game, $day ),
			'reset_in'    => Day::seconds_until_reset(),
		);

		if ( Config::GAME_STC === $game ) {
			$candles = self::to_list( $meta['hti_stc_candles'] ?? array() );

			$base['touch_idx'] = (int) $run['touch_idx'];
			$base['outcome_candles'] = array_map(
				array( __CLASS__, 'candle' ),
				array_slice( $candles, Config::STC_VISIBLE, Config::STC_OUTCOME )
			);
			$base['symbol']     = self::text( $meta['hti_stc_symbol'] ?? '', 40 );
			$base['asset_class'] = self::text( $meta['hti_stc_class'] ?? '', 40 );
			$base['pass_right'] = ! empty( $meta['hti_stc_pass_right'] );
			$base['lesson']     = self::lesson( $meta, 'hti_stc_lesson', $lang );
			$base['survival']   = STC_Engine::survival( (int) $run['cap_after'] );

			return $base;
		}

		$base['idx_before'] = (int) $run['idx_before'];
		$base['idx_after']  = (int) $run['idx_after'];
		$base['company']    = self::text( $meta['hti_rev_company'] ?? '', 120 );
		$base['year']       = (int) ( $meta['hti_rev_year'] ?? 0 );
		$base['return_5y_bp']       = (int) ( $meta['hti_rev_return_5y_bp'] ?? 0 );
		$base['index_return_5y_bp'] = (int) ( $meta['hti_rev_index_return_5y_bp'] ?? 0 );
		$base['headline']   = self::text( $meta['hti_rev_outcome_headline'] ?? '', 200 );
		$base['lesson']     = self::lesson( $meta, 'hti_rev_lesson', $lang );
		$base['sources']    = self::sources( $meta['hti_rev_sources'] ?? array() );
		$base['lines']      = Reveal_Engine::three_lines( $meta, $base, $lang );

		return $base;
	}

	/**
	 * The bilingual lesson, falling back to English.
	 *
	 * WordPress does not fall back between locales on this site (pt_PT_ao90
	 * with real translation files), so a missing PT string renders as nothing
	 * rather than as English — hence doing it by hand here.
	 *
	 * @param array<string,mixed> $meta   Content meta.
	 * @param string              $prefix Meta key prefix.
	 * @param string              $lang   'en' or 'pt'.
	 */
	private static function lesson( array $meta, string $prefix, string $lang ): string {
		$text = (string) ( $meta[ $prefix . '_' . $lang ] ?? '' );
		if ( '' === trim( $text ) ) {
			$text = (string) ( $meta[ $prefix . '_en' ] ?? '' );
		}
		return wp_kses_post( $text );
	}

	/**
	 * Source links for a revealed case, as {label, url} pairs.
	 *
	 * @param mixed $raw Sources as stored.
	 * @return array<int,array<string,string>>
	 */
	private static function sources( $raw ): array {
		$out = array();

		foreach ( array_slice( self::to_list( $raw ), 0, 5 ) as $source ) {
			$url   = is_array( $source ) ? (string) ( $source['url'] ?? '' ) : (string) $source;
			$label = is_array( $source ) ? self::text( $source['label'] ?? '', 120 ) : '';
			$url   = esc_url_raw( $url );
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'label' => '' !== $label ? $label : wp_parse_url( $url, PHP_URL_HOST ),
				'url'   => $url,
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------- */
	/* Small shared helpers                                              */
	/* ---------------------------------------------------------------- */

	/**
	 * A content post's meta, flattened to key => value.
	 *
	 * get_post_meta( $id ) hands back every value wrapped in an array of
	 * serialised strings; the whitelists want plain values.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	private static function meta( int $post_id ): array {
		$raw = get_post_meta( $post_id );
		$out = array();

		foreach ( (array) $raw as $key => $values ) {
			$out[ $key ] = maybe_unserialize( is_array( $values ) ? ( $values[0] ?? '' ) : $values );
		}

		return $out;
	}

	/**
	 * Anything list-shaped as a PHP list. Pure.
	 *
	 * @param mixed $raw Array, JSON string, or nonsense.
	 * @return array<int,mixed>
	 */
	private static function to_list( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	/**
	 * A stored string on its way out: tags stripped, length capped. Pure.
	 *
	 * @param mixed $raw Value.
	 * @param int   $max Character cap.
	 */
	private static function text( $raw, int $max ): string {
		return mb_substr( sanitize_text_field( (string) $raw ), 0, $max );
	}

	/**
	 * Count a game event, if hti-engine's metrics are around.
	 *
	 * The vocabulary is fixed in code — the bootstrap registers the event
	 * names and this file writes the locations — and is never derived from
	 * anything a visitor sends. Guarded with class_exists() the same way
	 * hti-forex's bot does it, so metrics stay a cross-plugin nicety rather
	 * than a dependency that can 500 a game.
	 *
	 * @param string $event    Event name from the registered allowlist.
	 * @param string $location Fixed detail label, e.g. 'stc_risk_200'.
	 */
	private static function bump( string $event, string $location = '' ): void {
		if ( ! class_exists( '\\HTI\\Engine\\Metrics' ) ) {
			return;
		}
		\HTI\Engine\Metrics::bump( $event, '' !== $location ? array( 'location' => $location ) : array() );
	}

	/**
	 * The house 429.
	 */
	private static function too_many(): WP_Error {
		return new WP_Error( 'hti_rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'hti-games' ), array( 'status' => 429 ) );
	}

	/**
	 * The house 404 for a game id that is not one of the two.
	 */
	private static function unknown_game(): WP_Error {
		return new WP_Error( 'hti_game_unknown', __( 'No such game.', 'hti-games' ), array( 'status' => 404 ) );
	}

	/**
	 * The house 422 for a decision that is not on the offered list.
	 */
	private static function invalid_decision(): WP_Error {
		return new WP_Error( 'hti_game_invalid_decision', __( 'That is not one of the choices.', 'hti-games' ), array( 'status' => 422 ) );
	}
}
