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
	 * The visible window of a scenario, each candle exactly four integers. Pure.
	 *
	 * Three independent guarantees, all load-bearing:
	 *   - the slice drops every tick from the visible count on, which is the
	 *     outcome — the single most valuable secret either game has;
	 *   - the window is never wider than Config::STC_VISIBLE even if the
	 *     scenario declares a wider one, so a mis-imported `hti_stc_visible`
	 *     cannot open the door the slice exists to close. Fail closed;
	 *   - the per-candle rebuild drops every field that is not o/h/l/c, so a
	 *     candle carrying a timestamp, a symbol or a label cannot leak one.
	 *
	 * @param array<string,mixed> $meta Scenario meta.
	 * @return array<int,array<int,int>>
	 */
	public static function visible_ticks( array $meta ): array {
		$declared = (int) ( $meta['hti_stc_visible'] ?? 0 );
		$count    = $declared > 0 ? min( $declared, Config::STC_VISIBLE ) : Config::STC_VISIBLE;

		$out = array();
		foreach ( array_slice( self::to_list( $meta['hti_stc_ticks'] ?? '' ), 0, $count ) as $candle ) {
			$out[] = self::candle( $candle );
		}

		return $out;
	}

	/**
	 * The outcome window: the ticks the engine walks, never the client. Pure.
	 *
	 * @param array<string,mixed> $meta Scenario meta.
	 * @return array<int,array<int,int>>
	 */
	private static function outcome_ticks( array $meta ): array {
		$declared = (int) ( $meta['hti_stc_visible'] ?? 0 );
		$from     = $declared > 0 ? min( $declared, Config::STC_VISIBLE ) : Config::STC_VISIBLE;

		$out = array();
		foreach ( array_slice( self::to_list( $meta['hti_stc_ticks'] ?? '' ), $from, Config::STC_OUTCOME ) as $candle ) {
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
	 * Positional quads as the {o,h,l,c} maps STC_Engine reads. Pure.
	 *
	 * The two shapes exist for good reasons that do not agree: storage and the
	 * wire use quads (a candle is four numbers and a JSON object of one-letter
	 * keys is three times the bytes), while the engine reads them by name (a
	 * comparison of `$candle['h']` against a stop is legible and `$candle[1]`
	 * is not). Converting at this one boundary is cheaper than making either
	 * side wrong.
	 *
	 * @param array<int,array<int,int>> $quads Positional candles.
	 * @return array<int,array{o:int,h:int,l:int,c:int}>
	 */
	public static function assoc_ticks( array $quads ): array {
		$out = array();

		foreach ( $quads as $quad ) {
			$quad  = array_values( (array) $quad );
			$out[] = array(
				'o' => (int) ( $quad[0] ?? 0 ),
				'h' => (int) ( $quad[1] ?? 0 ),
				'l' => (int) ( $quad[2] ?? 0 ),
				'c' => (int) ( $quad[3] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * The six fundamentals, rebuilt in one language and nothing else. Pure.
	 *
	 * The stored row is `{key, label_en, label_pt, value_en, value_pt,
	 * sector_avg_en, sector_avg_pt, tint}`; five fields come out. Same
	 * recursion of the whitelist principle as the candles: whatever else a row
	 * acquires later — an editor's note, a company name, the year the figure
	 * is from — is not copied, because only these five are.
	 *
	 * @param mixed  $raw  Fundamentals as stored (JSON string or array).
	 * @param string $lang 'en' or 'pt'.
	 * @return array<int,array<string,string>>
	 */
	public static function fundamentals( $raw, string $lang = 'en' ): array {
		$lang = 'pt' === $lang ? 'pt' : 'en';
		$out  = array();

		foreach ( array_slice( self::to_list( $raw ), 0, 6 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$tint = sanitize_key( (string) ( $row['tint'] ?? '' ) );
			$out[] = array(
				'key'        => $key,
				'label'      => self::text( $row[ 'label_' . $lang ] ?? '', 240 ),
				'value'      => self::text( $row[ 'value_' . $lang ] ?? '', 240 ),
				'sector_avg' => self::text( $row[ 'sector_avg_' . $lang ] ?? '', 240 ),
				// The tint is what the dossier colours the row by, and it is
				// checked against the closed vocabulary rather than passed
				// through: it lands in a class attribute at the other end.
				'tint'       => in_array( $tint, array( 'good', 'warn', 'bad' ), true ) ? $tint : 'warn',
			);
		}

		return $out;
	}

	/**
	 * The three period headlines, in one language, as plain text. Pure.
	 *
	 * Their anonymity is an editorial invariant enforced where the case is
	 * published — a headline naming the company would give the answer away and
	 * no amount of filtering here could reliably catch it. What this function
	 * guarantees is the narrower thing it can: at most three strings, tags
	 * stripped, capped, and nothing else from the row they came in.
	 *
	 * @param mixed  $raw  Headlines as stored (JSON string or array).
	 * @param string $lang 'en' or 'pt'.
	 * @return array<int,string>
	 */
	public static function headlines( $raw, string $lang = 'en' ): array {
		$lang = 'pt' === $lang ? 'pt' : 'en';
		$out  = array();

		foreach ( array_slice( self::to_list( $raw ), 0, 3 ) as $line ) {
			$text = self::text( is_array( $line ) ? ( $line[ $lang ] ?? '' ) : $line, 240 );
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
		$lang = self::req_lang( $request );

		if ( RateLimit::exceeded( 'game_session' ) ) {
			return self::too_many( $lang );
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
				'lang'       => $lang,
				'newsletter' => true === rest_sanitize_boolean( $request->get_param( 'newsletter' ) ),
				'ack'        => true,
				'ack_ver'    => Player::ACK_VERSION,
				'user_id'    => get_current_user_id(),
			)
		);

		if ( ! $player ) {
			return new WP_Error(
				'hti_game_session_failed',
				self::msg( 'st_error', $lang, __( 'Could not start a session. Please try again.', 'hti-games' ) ),
				array( 'status' => 500 )
			);
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
		$lang = self::req_lang( $request );

		if ( RateLimit::exceeded( 'game_today' ) ) {
			return self::too_many( $lang );
		}

		$game = sanitize_key( (string) $request->get_param( 'game' ) );
		if ( ! Config::is_game( $game ) ) {
			return self::unknown_game();
		}
		if ( ! self::game_enabled( $game ) ) {
			return self::switched_off( $lang );
		}

		$day        = Day::key();
		$content_id = Library::for_day( $game, $day );
		if ( $content_id <= 0 ) {
			return new WP_Error(
				'hti_game_no_content',
				self::msg( 'st_no_content', $lang, __( 'Today’s challenge is not available yet.', 'hti-games' ) ),
				array( 'status' => 503 )
			);
		}

		$row    = Player::resolve( $request );
		$public = Player::public_row( $row );
		// The dossier is served in the request's language, not the stored one:
		// a player reading the Portuguese page gets the Portuguese dossier
		// even if they onboarded in English.
		$public['lang'] = $lang;
		$meta    = self::meta( $content_id );
		$payload = Config::GAME_STC === $game
			? self::public_challenge_stc( $meta, $public )
			: self::public_challenge_reveal( $meta, $public );

		$payload['player'] = $public;
		$payload['real']   = Library::is_real( $game );

		// "Still here." last_seen is what the 180-day retention prune measures
		// against, so a player who opens the chart every day and decides on
		// half of them must not look idle to it.
		if ( $row ) {
			Player::touch( (int) $row['id'], $lang );
		}

		// Already decided? Then the answer is no longer secret from them.
		$run = $row ? self::find_run( (int) $row['id'], $game, $day ) : null;
		if ( $run ) {
			$payload['played'] = true;
			$payload['result'] = self::run_result( $game, $run, $meta, $lang );
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
		$lang = self::req_lang( $request );

		if ( RateLimit::exceeded( 'game_decision' ) ) {
			return self::too_many( $lang );
		}

		$game = sanitize_key( (string) $request->get_param( 'game' ) );
		if ( ! Config::is_game( $game ) ) {
			return self::unknown_game();
		}
		if ( ! self::game_enabled( $game ) ) {
			return self::switched_off( $lang );
		}

		// 1. Who is this. No session, no run — the row is what a run is
		//    recorded against, and it only exists after onboarding.
		$row = Player::resolve( $request );
		if ( ! $row ) {
			return new WP_Error( 'hti_game_no_session', __( 'Start a session before playing.', 'hti-games' ), array( 'status' => 403 ) );
		}
		Player::touch( (int) $row['id'], $lang );

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
			return new WP_Error(
				'hti_game_no_content',
				self::msg( 'st_no_content', $lang, __( 'Today’s challenge is not available yet.', 'hti-games' ) ),
				array( 'status' => 503 )
			);
		}

		$meta      = self::meta( $content_id );
		$player_id = (int) $row['id'];
		$decision  = sanitize_key( (string) $request->get_param( 'decision' ) );

		$outcome = Config::GAME_STC === $game
			? self::resolve_stc( $request, $meta, $row, $decision, $day )
			: self::resolve_reveal( $request, $meta, $row, $decision, $day );

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

		// $wpdb prints a MySQL error when show_errors is on, and a duplicate
		// day is an EXPECTED answer here, not a fault — so the noise is
		// suppressed around the one statement that is allowed to fail.
		$suppress = $wpdb->suppress_errors( true );
		$run_id   = Store::insert( 'runs', $run );
		$wpdb->suppress_errors( $suppress );

		if ( $run_id <= 0 ) {
			// Refused. Rather than parse the driver's error string, ask the
			// question the answer depends on: is there already a run? If the
			// UNIQUE key turned this away — a double tap, a retry after a
			// timeout, two tabs, or a genuinely simultaneous second POST — the
			// row that beat us is there to be read, and from the player's side
			// that is not an error at all, it is the same run.
			$existing = self::find_run( $player_id, $game, $day );
			if ( $existing ) {
				return new WP_Error(
					'hti_game_already_played',
					__( 'You have already played today. Come back after the reset.', 'hti-games' ),
					array(
						'status'   => 409,
						'result'   => self::run_result( $game, $existing, $meta, $lang ),
						'reset_in' => Day::seconds_until_reset(),
					)
				);
			}

			return new WP_Error(
				'hti_game_save_failed',
				self::msg( 'st_error', $lang, __( 'Could not record your decision. Please try again.', 'hti-games' ) ),
				array( 'status' => 500 )
			);
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
		$lang = self::req_lang( $request );

		if ( RateLimit::exceeded( 'game_board' ) ) {
			return self::too_many( $lang );
		}

		// The kill-switch, server-side. The shortcode already refuses to render
		// the board when it is off, but a public endpoint that keeps serving
		// player-chosen nicknames after the owner switched the board off is a
		// takedown that did not take anything down.
		if ( ! self::board_enabled() ) {
			return self::switched_off( $lang );
		}

		$board = sanitize_key( (string) $request->get_param( 'board' ) );
		$board = Leaderboard::is_board( $board ) ? $board : Leaderboard::BOARD_DAILY;

		$game = sanitize_key( (string) $request->get_param( 'game' ) );
		$game = Config::is_game( $game ) ? $game : Config::GAME_STC;

		$row       = Player::resolve( $request );
		$player_id = $row ? (int) $row['id'] : 0;

		// The day is only ever today's or a recent past one, and it is checked
		// against a closed window before it reaches a query OR a cache key — a
		// board is public, so its parameters are as untrusted as any other, and
		// an unbounded day would let a stranger choose an unbounded number of
		// transient keys. See Leaderboard::MAX_BACK_DAYS.
		$day = sanitize_text_field( (string) $request->get_param( 'day' ) );
		if ( ! Leaderboard::is_servable_day( $day, Day::key() ) ) {
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
			return self::too_many( self::req_lang( $request ) );
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

		$today  = Day::key();
		$public = Player::public_row( $row );
		$games  = array();

		// Per game, never pooled. A calendar built from both games at once
		// would put two runs on one day and read as a streak the player never
		// had; the two accounts are independent and so are their histories.
		foreach ( array( Config::GAME_STC, Config::GAME_REVEAL ) as $game ) {
			$rows  = self::recent_runs( (int) $row['id'], $game );
			$state = Config::GAME_STC === $game ? $public['stc'] : $public['reveal'];

			$games[ $game ] = array(
				'runs'         => $rows,
				'calendar'     => Scoring::calendar( $rows, $today, Scoring::MONTH ),
				'risk_by_week' => Scoring::risk_by_week( $rows, 8 ),
				'badges'       => Scoring::badges( $rows, $state ),
				'average_risk_bp' => Scoring::average_risk_bp( $rows ),
			);
		}

		return new WP_REST_Response(
			array(
				'player'   => $public,
				'games'    => $games,
				'day'      => $today,
				'reset_in' => Day::seconds_until_reset(),
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
		$lang = self::req_lang( $request );

		if ( RateLimit::exceeded( 'game_nick' ) ) {
			return self::too_many( $lang );
		}

		$row = Player::resolve( $request );
		if ( ! $row ) {
			return new WP_Error( 'hti_game_no_session', __( 'Start a session before choosing a name.', 'hti-games' ), array( 'status' => 403 ) );
		}

		$result = Player::set_nickname( (int) $row['id'], (string) $request->get_param( 'nickname' ) );

		if ( ! $result['ok'] ) {
			if ( 'taken' === $result['code'] ) {
				return new WP_Error(
					'hti_game_nickname_taken',
					self::msg( 'nick_taken', $lang, __( 'That name is taken. Try another.', 'hti-games' ) ),
					array( 'status' => 409 )
				);
			}
			if ( 'failed' === $result['code'] ) {
				return new WP_Error(
					'hti_game_nickname_failed',
					self::msg( 'st_error', $lang, __( 'Could not save that name. Please try again.', 'hti-games' ) ),
					array( 'status' => 500 )
				);
			}
			return new WP_Error(
				'hti_game_nickname_invalid',
				self::msg( 'nick_invalid', $lang, __( '3–24 characters: letters, digits, hyphens and underscores.', 'hti-games' ) ),
				array(
					'status' => 422,
					// Which rule it broke, so the field can point at it.
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
	 * STC_Engine::resolve() reads candles by name and storage keeps them as
	 * quads, so the two windows are converted on the way in; it answers with
	 * `candle` (1-based, 0 when nothing was touched), which is what the runs
	 * table calls `touch_idx`.
	 *
	 * @param WP_REST_Request     $request  Request.
	 * @param array<string,mixed> $meta     Scenario meta.
	 * @param array<string,mixed> $row      Player row.
	 * @param string              $decision Sanitised decision.
	 * @param string              $today    The day being played.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function resolve_stc( WP_REST_Request $request, array $meta, array $row, string $decision, string $today ) {
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

		$cap_before = (int) $row['stc_capital'];
		$verdict    = STC_Engine::resolve(
			self::assoc_ticks( self::visible_ticks( $meta ) ),
			self::assoc_ticks( self::outcome_ticks( $meta ) ),
			$decision,
			$risk_bp,
			$double,
			$cap_before
		);

		$pnl     = (int) ( $verdict['pnl'] ?? 0 );
		$applied = STC_Engine::apply( $cap_before, $pnl );
		$died    = ! empty( $applied['died'] );

		return array(
			'decision'     => $decision,
			'risk_bp'      => $risk_bp,
			'multiplier'   => $double ? Config::STC_DOUBLE : 1,
			'outcome'      => substr( (string) ( $verdict['outcome'] ?? '' ), 0, 8 ),
			'touch_idx'    => (int) ( $verdict['touch_idx'] ?? $verdict['candle'] ?? 0 ),
			'pnl'          => $pnl,
			'cap_before'   => $cap_before,
			'cap_after'    => (int) ( $applied['capital'] ?? $cap_before ),
			// The Reveal's index columns mean nothing for this game. Written
			// as an explicit zero rather than left to the column default, so
			// an export of a chart run never shows a phantom index run.
			'idx_before'   => 0,
			'idx_after'    => 0,
			'died'         => $died,
			'streak_after' => self::next_streak( (int) $row['stc_streak'], (string) $row['stc_last_day'], $today, $died ),
			'board_score'  => Scoring::board_score( $pnl, $risk_bp ),
			// Not stored — handed to the result screen so it can draw the
			// levels the engine actually used rather than recompute them.
			'entry'        => (int) ( $verdict['entry'] ?? 0 ),
			'atr'          => (int) ( $verdict['atr'] ?? 0 ),
			'stop'         => (int) ( $verdict['stop'] ?? 0 ),
			'target'       => (int) ( $verdict['target'] ?? 0 ),
			'r_bp'         => (int) ( $verdict['r_bp'] ?? 0 ),
			'would'        => $verdict['would'] ?? null,
		);
	}

	/**
	 * Validate a Reveal decision and run the engine over it.
	 *
	 * Reveal_Engine::resolve() takes the two returns rather than the case, so
	 * the secrets are read here and nowhere near a public payload. It does not
	 * decide death: the floor is one rule for both games and lives in
	 * STC_Engine::apply(), which is run over the capital it hands back.
	 *
	 * The committed share is stored in `risk_bp` — the column is the same
	 * shape for both games and 25% is 2500 basis points either way, which
	 * keeps one leaderboard query able to normalise both.
	 *
	 * @param WP_REST_Request     $request  Request.
	 * @param array<string,mixed> $meta     Case meta.
	 * @param array<string,mixed> $row      Player row.
	 * @param string              $decision Sanitised decision.
	 * @param string              $today    The day being played.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function resolve_reveal( WP_REST_Request $request, array $meta, array $row, string $decision, string $today ) {
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

		$verdict = Reveal_Engine::resolve(
			(int) ( $meta['hti_rev_return_5y_bp'] ?? 0 ),
			(int) ( $meta['hti_rev_index_return_5y_bp'] ?? 0 ),
			$decision,
			$size,
			$cap_before,
			$idx_before
		);

		$pnl     = (int) ( $verdict['pnl'] ?? 0 );
		$applied = STC_Engine::apply( $cap_before, $pnl );
		$died    = ! empty( $applied['died'] );
		$risk_bp = $size * 100;

		return array(
			'decision'     => (string) ( $verdict['decision'] ?? $decision ),
			'risk_bp'      => $risk_bp,
			'multiplier'   => 1,
			// The Reveal has no touch/stop/target vocabulary of its own, so the
			// outcome column records what the decision turned out to be worth.
			'outcome'      => $pass ? 'pass' : ( $pnl > 0 ? 'up' : ( $pnl < 0 ? 'down' : 'flat' ) ),
			'touch_idx'    => 0,
			'pnl'          => $pnl,
			'cap_before'   => $cap_before,
			'cap_after'    => (int) ( $applied['capital'] ?? $cap_before ),
			'idx_before'   => $idx_before,
			'idx_after'    => (int) ( $verdict['index_cap'] ?? $idx_before ),
			'died'         => $died,
			'streak_after' => self::next_streak( (int) $row['rev_streak'], (string) $row['rev_last_day'], $today, $died ),
			'board_score'  => Scoring::board_score( $pnl, $risk_bp ),
			'committed'    => (int) ( $verdict['committed'] ?? 0 ),
			'index_pnl'    => (int) ( $verdict['index_pnl'] ?? 0 ),
		);
	}

	/**
	 * The streak after a run. Pure.
	 *
	 * The streak measures SHOWING UP, not winning — the definition
	 * Scoring::streak_from() computes from the run history, restated here as
	 * the O(1) increment so the write path does not have to read a player's
	 * whole history to advance a counter. The two must agree, and they do:
	 * consecutive days played, a pass counts, a gap restarts at one, a death
	 * ends it at zero.
	 *
	 * Deliberately NOT a winning streak. A counter that only a profitable day
	 * could extend would be a daily nudge to take a position and to size it up
	 * — precisely the habit both games exist to argue against.
	 *
	 * @param int    $current  Streak before this run.
	 * @param string $last_day The day of the previous run, '' if none.
	 * @param string $today    The day being played.
	 * @param bool   $died     Whether the account blew up today.
	 */
	public static function next_streak( int $current, string $last_day, string $today, bool $died ): int {
		if ( $died ) {
			return 0;
		}
		if ( '' !== $last_day && Day::valid( $last_day ) && Day::valid( $today )
			&& Day::index( $today ) === Day::index( $last_day ) + 1 ) {
			return $current + 1;
		}
		return 1;
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
	 * The row shape is the one Scoring reads — `day`, `decision`, `risk_bp`,
	 * `pnl`, `died` — so the calendar, the badges and the risk chart all take
	 * this list unchanged.
	 *
	 * @param int    $player_id Player row id.
	 * @param string $game      Game id.
	 * @param int    $limit     How many.
	 * @return array<int,array<string,mixed>>
	 */
	private static function recent_runs( int $player_id, string $game, int $limit = 180 ): array {
		global $wpdb;
		$runs = Store::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API; one player's own history, never cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day_key, decision, risk_bp, multiplier, outcome, board_score, pnl, cap_before, cap_after, died, streak_after
				 FROM `{$runs}` WHERE player_id = %d AND game = %d ORDER BY day_key DESC, id DESC LIMIT %d",
				$player_id,
				Config::game_id( $game ),
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'game'         => $game,
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
	 * The crowd stat is here for the same reason. "68% of players lost on this
	 * one" is a hint before a decision and a lesson after one, and the only
	 * thing separating those two is which side of the INSERT it is served on.
	 * Leaderboard::public_stats() withholds the same counts from the board,
	 * which is the other way they could have reached a player mid-decision.
	 *
	 * @param string              $game Game id.
	 * @param array<string,mixed> $run  The recorded run.
	 * @param array<string,mixed> $meta Content meta.
	 * @param string              $lang Language for the lesson.
	 * @return array<string,mixed>
	 */
	private static function run_result( string $game, array $run, array $meta, string $lang ): array {
		$day   = (string) $run['day_key'];
		$lang  = 'pt' === $lang ? 'pt' : 'en';
		$stats = Leaderboard::day_stats( $game, $day );

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
			// The counts, plus which of the four bilingual sentences goes over
			// them and what percentage sits beside it. The choice is made here
			// rather than in the browser because it is a rule about what the
			// section is allowed to claim, and rules live on this side.
			'crowd'       => $stats + Leaderboard::crowd( $stats, $game, (string) $run['decision'] ),
			'reset_in'    => Day::seconds_until_reset(),
		);

		if ( Config::GAME_STC === $game ) {
			$visible = self::visible_ticks( $meta );

			$base['touch_idx']       = (int) $run['touch_idx'];
			$base['outcome_candles'] = self::outcome_ticks( $meta );
			$base['entry']           = $visible ? (int) $visible[ count( $visible ) - 1 ][3] : 0;
			$base['atr']             = STC_Engine::atr( self::assoc_ticks( $visible ), Config::STC_ATR_PERIOD );
			$base['tick_scale']      = (int) ( $meta['hti_stc_scale'] ?? 0 ) > 0 ? (int) $meta['hti_stc_scale'] : Config::TICK_SCALE;
			$base['symbol']          = self::text( $meta['hti_stc_symbol'] ?? '', 240 );
			$base['asset_class']     = self::text( $meta['hti_stc_class'] ?? '', 40 );
			$base['pass_right']      = '1' === (string) ( $meta['hti_stc_pass_right'] ?? '0' );
			$base['real']            = '1' === (string) ( $meta['hti_stc_real'] ?? '0' );
			$base['source']          = self::text( $meta['hti_stc_source'] ?? '', 240 );
			$base['lesson']          = self::block( $meta, 'hti_stc_lesson', $lang );
			$base['survival']        = STC_Engine::survival( (int) $run['cap_after'] );

			return $base;
		}

		$index_pnl = (int) $run['idx_after'] - (int) $run['idx_before'];

		$base['idx_before']         = (int) $run['idx_before'];
		$base['idx_after']          = (int) $run['idx_after'];
		$base['index_pnl']          = $index_pnl;
		$base['company']            = self::text( $meta['hti_rev_company'] ?? '', 240 );
		$base['year']               = (int) ( $meta['hti_rev_year'] ?? 0 );
		$base['return_5y_bp']       = (int) ( $meta['hti_rev_return_5y_bp'] ?? 0 );
		$base['index_return_5y_bp'] = (int) ( $meta['hti_rev_index_return_5y_bp'] ?? 0 );
		$base['context']            = self::block( $meta, 'hti_rev_context', $lang );
		$base['lesson']             = self::block( $meta, 'hti_rev_lesson', $lang );
		// What the figures ARE, so the result screen can say so. The rule is
		// CPT::san_provenance's and is repeated rather than imported: this
		// file must not depend on the admin or the CPT class to answer a
		// question on a public request, and the direction is the important
		// half — anything that does not say 'illustrative' is treated as a
		// verified case, which is the one that had to carry a source to be
		// published at all.
		$base['provenance']         = 'illustrative' === (string) ( $meta['hti_rev_provenance'] ?? '' ) ? 'illustrative' : 'verified';
		$base['source']             = self::source( $meta );
		// Keys, not sentences: the three lines are worded bilingually in
		// Strings, and the engine only decides which numbers sit under them.
		$base['lines']              = Reveal_Engine::three_lines( (int) $run['pnl'], $index_pnl );
		$base['survival']           = STC_Engine::survival( (int) $run['cap_after'] );

		return $base;
	}

	/**
	 * A bilingual block of copy, falling back to English.
	 *
	 * WordPress does not fall back between locales on this site (pt_PT_ao90
	 * with real translation files), so a missing PT string renders as nothing
	 * rather than as English — hence doing it by hand here.
	 *
	 * @param array<string,mixed> $meta   Content meta.
	 * @param string              $prefix Meta key prefix, without the language.
	 * @param string              $lang   'en' or 'pt'.
	 */
	private static function block( array $meta, string $prefix, string $lang ): string {
		$text = (string) ( $meta[ $prefix . '_' . $lang ] ?? '' );
		if ( '' === trim( $text ) ) {
			$text = (string) ( $meta[ $prefix . '_en' ] ?? '' );
		}
		return wp_kses_post( $text );
	}

	/**
	 * The case's source, revealed with the answer.
	 *
	 * Three scalars rather than a list: a verified case has exactly one
	 * source, and the publish gate in class-cpt is built on that being true.
	 * Kept out of the pre-decision payload because a URL names the company in
	 * its own slug.
	 *
	 * @param array<string,mixed> $meta Case meta.
	 * @return array<string,string>
	 */
	private static function source( array $meta ): array {
		$url = esc_url_raw( (string) ( $meta['hti_rev_source_url'] ?? '' ) );
		if ( '' === $url ) {
			return array();
		}

		$label = self::text( $meta['hti_rev_source_label'] ?? '', 240 );

		return array(
			'url'      => $url,
			'label'    => '' !== $label ? $label : (string) wp_parse_url( $url, PHP_URL_HOST ),
			'accessed' => self::text( $meta['hti_rev_source_accessed'] ?? '', 40 ),
		);
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
	 * The language this request is in.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function req_lang( WP_REST_Request $request ): string {
		$lang = (string) $request->get_param( 'lang' );
		if ( '' === $lang && function_exists( 'determine_locale' ) ) {
			$lang = (string) determine_locale();
		}
		return Player::lang( $lang );
	}

	/**
	 * A user-facing message for an error response.
	 *
	 * Strings, not __(): the site runs pt_PT_ao90 against pt_PT translation
	 * files and WordPress does not fall back between them, so a missing
	 * translation would render silently in English rather than warn (see the
	 * class-strings docblock).
	 *
	 * Where the copy table has no key — a stale tab, a risk tier that is not
	 * on the list, conditions the interface makes unreachable — the
	 * machine-readable `code` is the contract the front end keys off and the
	 * message here is the developer-facing fallback behind it.
	 *
	 * @param string $key      Strings key, or '' to use the fallback outright.
	 * @param string $lang     'en' or 'pt'.
	 * @param string $fallback Text when the table has no such key.
	 */
	private static function msg( string $key, string $lang, string $fallback ): string {
		$copy = '' !== $key ? Strings::get( $key, $lang ) : '';
		return '' !== $copy ? $copy : $fallback;
	}

	/**
	 * The house 429.
	 *
	 * @param string $lang Request language.
	 */
	private static function too_many( string $lang = 'en' ): WP_Error {
		return new WP_Error(
			'hti_rate_limited',
			self::msg( 'st_rate_limited', $lang, __( 'Too many requests. Please wait a moment and try again.', 'hti-games' ) ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Whether a game is switched on, when the settings class is loaded.
	 *
	 * Fails OPEN on purpose, and only in the one case where open is right: if
	 * Settings has not landed there is no switch to read and the games are on,
	 * which is what a fresh install does. Every other path here fails closed.
	 *
	 * @param string $game Validated game id.
	 */
	private static function game_enabled( string $game ): bool {
		return ! class_exists( __NAMESPACE__ . '\\Settings' ) || Settings::game_enabled( $game );
	}

	/**
	 * Whether the public boards are switched on. See game_enabled().
	 */
	private static function board_enabled(): bool {
		return ! class_exists( __NAMESPACE__ . '\\Settings' ) || ! empty( Settings::settings()['leaderboard_enabled'] );
	}

	/**
	 * The answer a switched-off surface gives.
	 *
	 * Deliberately the same 503 and the same sentence a day with no published
	 * content gets: from the player's side the two are the same fact — there is
	 * nothing here today — and the shortcode already renders `st_no_content` for
	 * exactly this state, so the API and the page say one thing.
	 *
	 * @param string $lang Request language.
	 */
	private static function switched_off( string $lang ): WP_Error {
		return new WP_Error(
			'hti_game_no_content',
			self::msg( 'st_no_content', $lang, __( 'This part of the games section is paused.', 'hti-games' ) ),
			array( 'status' => 503 )
		);
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
