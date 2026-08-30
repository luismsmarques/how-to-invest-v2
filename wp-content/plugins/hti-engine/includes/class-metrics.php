<?php
/**
 * First-party, privacy-friendly funnel metrics.
 *
 * A tiny self-hosted alternative to reading GA4: the front-end tracking helper
 * (track.js) sends an anonymous beacon to POST /htinvest/v1/event for each
 * funnel event. We keep only aggregate daily counts — no cookies, no IP, no
 * user id, no personal data — so this is anonymous statistics, not tracking.
 * The counts power the "HTI Funnel" admin screen.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregate event counters + the admin funnel report.
 */
class Metrics {

	/**
	 * Option holding the daily aggregate counters (autoload off).
	 */
	private const OPTION = 'hti_metrics';

	/**
	 * How many days of history to retain.
	 */
	private const KEEP_DAYS = 120;

	/**
	 * Cap on distinct page paths tracked per day (keeps the option bounded on
	 * large content sites). Beyond this, extra new paths fold into "_other".
	 */
	private const MAX_PATHS_PER_DAY = 300;

	/**
	 * Highest questionnaire step index the beacon will record.
	 *
	 * The questionnaire has eight questions. This is deliberately generous —
	 * it is a ceiling on the key space, not a schema — but it IS a ceiling:
	 * without one the step map takes whatever integer the request sends, and
	 * the beacon is public, unauthenticated and writes into a single option
	 * row that every later beacon has to read and write whole.
	 */
	private const MAX_STEP = 32;

	/**
	 * Ordered latency histogram buckets (seconds) for /recommend timing, so a
	 * p95 can be estimated from cumulative counts without storing samples.
	 *
	 * @var array<int,string>
	 */
	private const LAT_BUCKETS = array( '0-1', '1-2', '2-4', '4-8', '8-16', '16+' );

	/**
	 * Countable events (anything else is ignored).
	 *
	 * @return array<int,string>
	 */
	public static function events(): array {
		/**
		 * Filter the countable-event allowlist.
		 *
		 * Sibling plugins own their own vocabulary: hti-games adds the game
		 * events here rather than editing this array, so a section can be
		 * removed by deactivating one plugin. Names are always fixed in code
		 * and never derived from anything a visitor types.
		 *
		 * @param array<int,string> $events Countable event names.
		 */
		return (array) apply_filters(
			'hti_metrics_events',
			array(
				'page_view',
				'quiz_start',
				'quiz_step_complete',
				'quiz_submit',
				'result_view',
				'result_pdf_export',
				'result_email_request',
				'result_retake',
				'save_profile_start',
				'save_profile',
				'sign_up',
				'login',
				'onboarding_complete',
				'newsletter_subscribe_submit',
				'newsletter_confirmed',
				'newsletter_unsubscribe',
				'ebook_lead',
				'contact_submit',
				'forex_bot_start',
				'forex_bot_calc',
				'forex_bot_stop',
				'account_delete_request',
				'cta_click',
				'forex_tool_use',
				'feedback_widget_open',
				'feedback_submitted',
				'feedback_invite_click',
				'data_export',
				'preferred_source_click',
				'broker_click',
				'broker_compare_view',
				'broker_review_view',
				'broker_guide_view',
				'result_broker_view',
			)
		);
	}

	/**
	 * Hook the beacon endpoint and the admin screen.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	/**
	 * Register the public, anonymous beacon route.
	 */
	public static function register_route(): void {
		register_rest_route(
			'htinvest/v1',
			'/event',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'record' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Beacon handler: increment the aggregate counters. Always answers 204 (no
	 * body) so it never surfaces errors to the client or signals to abusers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function record( \WP_REST_Request $request ): \WP_REST_Response {
		if ( RateLimit::exceeded( 'event' ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$name   = sanitize_key( (string) $request->get_param( 'name' ) );
		$params = array();

		$step = $request->get_param( 'step' );
		if ( is_numeric( $step ) ) {
			$params['step_index'] = (int) $step;
		}
		$arch = $request->get_param( 'archetype' );
		if ( is_numeric( $arch ) ) {
			$params['archetype'] = (int) $arch;
		}
		$loc = $request->get_param( 'location' );
		if ( is_string( $loc ) && '' !== $loc ) {
			$params['location'] = sanitize_key( $loc );
		}
		$path = $request->get_param( 'path' );
		if ( is_string( $path ) && '' !== $path ) {
			$params['path'] = self::norm_path( $path );
		}
		$lang = $request->get_param( 'lang' );
		if ( is_string( $lang ) && '' !== $lang ) {
			$params['lang'] = self::norm_lang( $lang );
		}
		$ref = $request->get_param( 'ref' );
		if ( is_string( $ref ) && '' !== $ref ) {
			$params['ref'] = self::norm_ref( $ref );
		}
		$campaign = $request->get_param( 'campaign' );
		if ( is_string( $campaign ) && '' !== $campaign ) {
			$campaign = self::norm_campaign( $campaign );
			if ( '' !== $campaign ) {
				$params['campaign'] = $campaign;
			}
		}

		self::bump( $name, $params );

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Increment the counters for one event (+ low-cardinality breakdowns).
	 *
	 * @param string               $event  Event name (must be whitelisted).
	 * @param array<string,scalar> $params Optional step_index / archetype / location.
	 */
	public static function bump( string $event, array $params = array() ): void {
		if ( ! in_array( $event, self::events(), true ) ) {
			return;
		}

		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$day = gmdate( 'Y-m-d' );
		if ( ! isset( $data[ $day ] ) || ! is_array( $data[ $day ] ) ) {
			$data[ $day ] = array();
		}

		$data[ $day ]['e'][ $event ] = ( $data[ $day ]['e'][ $event ] ?? 0 ) + 1;

		// Both of these were the only maps an anonymous request could add an
		// unbounded number of keys to. The others are capped by cardinality;
		// these two have something better available — a known key space — so
		// a value outside it is not counted at all rather than bucketed.
		if ( 'quiz_step_complete' === $event && isset( $params['step_index'] ) ) {
			$s = (int) $params['step_index'];
			if ( $s >= 0 && $s <= self::MAX_STEP ) {
				$data[ $day ]['step'][ $s ] = ( $data[ $day ]['step'][ $s ] ?? 0 ) + 1;
			}
		}
		if ( 'result_view' === $event && isset( $params['archetype'] ) ) {
			$a = (int) $params['archetype'];
			// The five curated archetypes are the whole key space; the admin
			// screen already looks the label up in exactly this array, so an
			// id it cannot name was never worth storing.
			if ( isset( Config::archetypes()[ $a ] ) ) {
				$data[ $day ]['arch'][ $a ] = ( $data[ $day ]['arch'][ $a ] ?? 0 ) + 1;
			}
		}
		if ( 'cta_click' === $event && isset( $params['location'] ) ) {
			$loc = (string) $params['location'];
			if ( ! isset( $data[ $day ]['cta'] ) || ! is_array( $data[ $day ]['cta'] ) ) {
				$data[ $day ]['cta'] = array();
			}
			// Bounded like the path map. This one was the exception for a long
			// time, on the reasoning that locations are written by us — but the
			// beacon is public and unauthenticated, so the value arrives from
			// the open web like any other and grows the option just as freely.
			if ( isset( $data[ $day ]['cta'][ $loc ] ) || count( $data[ $day ]['cta'] ) < self::MAX_PATHS_PER_DAY ) {
				$data[ $day ]['cta'][ $loc ] = ( $data[ $day ]['cta'][ $loc ] ?? 0 ) + 1;
			} else {
				$data[ $day ]['cta']['_other'] = ( $data[ $day ]['cta']['_other'] ?? 0 ) + 1;
			}
		}
		if ( 'forex_tool_use' === $event && isset( $params['location'] ) ) {
			$tool = (string) $params['location'];
			if ( ! isset( $data[ $day ]['tool'] ) || ! is_array( $data[ $day ]['tool'] ) ) {
				$data[ $day ]['tool'] = array();
			}
			// Which calculator, not which CTA — kept apart so neither table
			// has to be read with the other's meaning in mind. Bounded like
			// the rest, because the beacon is public.
			if ( isset( $data[ $day ]['tool'][ $tool ] ) || count( $data[ $day ]['tool'] ) < self::MAX_PATHS_PER_DAY ) {
				$data[ $day ]['tool'][ $tool ] = ( $data[ $day ]['tool'][ $tool ] ?? 0 ) + 1;
			} else {
				$data[ $day ]['tool']['_other'] = ( $data[ $day ]['tool']['_other'] ?? 0 ) + 1;
			}
		}
		if ( str_starts_with( $event, 'game_' ) && isset( $params['location'] ) ) {
			$g = (string) $params['location'];
			if ( ! isset( $data[ $day ]['game'] ) || ! is_array( $data[ $day ]['game'] ) ) {
				$data[ $day ]['game'] = array();
			}
			// One map for both games: the location already carries the game
			// ("stc_risk_200", "reveal_size_50"), so a second dimension would
			// only split the same rows in two. Bounded like the rest — the
			// beacon is public, so this value arrives from the open web.
			if ( isset( $data[ $day ]['game'][ $g ] ) || count( $data[ $day ]['game'] ) < self::MAX_PATHS_PER_DAY ) {
				$data[ $day ]['game'][ $g ] = ( $data[ $day ]['game'][ $g ] ?? 0 ) + 1;
			} else {
				$data[ $day ]['game']['_other'] = ( $data[ $day ]['game']['_other'] ?? 0 ) + 1;
			}
		}
		if ( 'broker_click' === $event ) {
			// Per-broker and per-location breakdowns (server-side, from /go/).
			if ( isset( $params['broker'] ) && '' !== (string) $params['broker'] ) {
				$b = (string) $params['broker'];
				if ( ! isset( $data[ $day ]['bkr'] ) || ! is_array( $data[ $day ]['bkr'] ) ) {
					$data[ $day ]['bkr'] = array();
				}
				if ( isset( $data[ $day ]['bkr'][ $b ] ) || count( $data[ $day ]['bkr'] ) < self::MAX_PATHS_PER_DAY ) {
					$data[ $day ]['bkr'][ $b ] = ( $data[ $day ]['bkr'][ $b ] ?? 0 ) + 1;
				} else {
					$data[ $day ]['bkr']['_other'] = ( $data[ $day ]['bkr']['_other'] ?? 0 ) + 1;
				}
			}
			if ( isset( $params['location'] ) && '' !== (string) $params['location'] ) {
				$bl = (string) $params['location'];
				if ( ! isset( $data[ $day ]['bkr_loc'] ) || ! is_array( $data[ $day ]['bkr_loc'] ) ) {
					$data[ $day ]['bkr_loc'] = array();
				}
				// Bounded like the slug map: the caller passes an allowlisted
				// location today, but the cap keeps that a property of this
				// store rather than of one caller.
				if ( isset( $data[ $day ]['bkr_loc'][ $bl ] ) || count( $data[ $day ]['bkr_loc'] ) < self::MAX_PATHS_PER_DAY ) {
					$data[ $day ]['bkr_loc'][ $bl ] = ( $data[ $day ]['bkr_loc'][ $bl ] ?? 0 ) + 1;
				} else {
					$data[ $day ]['bkr_loc']['_other'] = ( $data[ $day ]['bkr_loc']['_other'] ?? 0 ) + 1;
				}
			}
		}
		if ( 'result_broker_view' === $event && isset( $params['archetype'] ) ) {
			$ba = (int) $params['archetype'];
			$data[ $day ]['bkr_arch'][ $ba ] = ( $data[ $day ]['bkr_arch'][ $ba ] ?? 0 ) + 1;
		}
		if ( 'page_view' === $event && isset( $params['path'] ) ) {
			$path = (string) $params['path'];
			if ( ! isset( $data[ $day ]['page'] ) || ! is_array( $data[ $day ]['page'] ) ) {
				$data[ $day ]['page'] = array();
			}
			// Keep the per-day path map bounded: new paths beyond the cap fold
			// into "_other" so counts stay complete without unbounded growth.
			if ( isset( $data[ $day ]['page'][ $path ] ) || count( $data[ $day ]['page'] ) < self::MAX_PATHS_PER_DAY ) {
				$data[ $day ]['page'][ $path ] = ( $data[ $day ]['page'][ $path ] ?? 0 ) + 1;
			} else {
				$data[ $day ]['page']['_other'] = ( $data[ $day ]['page']['_other'] ?? 0 ) + 1;
			}
		}
		if ( 'page_view' === $event && isset( $params['lang'] ) ) {
			$lg = (string) $params['lang'];
			$data[ $day ]['lang'][ $lg ] = ( $data[ $day ]['lang'][ $lg ] ?? 0 ) + 1;
		}
		if ( 'page_view' === $event && isset( $params['ref'] ) ) {
			$rf = (string) $params['ref'];
			if ( ! isset( $data[ $day ]['ref'] ) || ! is_array( $data[ $day ]['ref'] ) ) {
				$data[ $day ]['ref'] = array();
			}
			// Bounded like the path map (overflow → "_other").
			if ( isset( $data[ $day ]['ref'][ $rf ] ) || count( $data[ $day ]['ref'] ) < self::MAX_PATHS_PER_DAY ) {
				$data[ $day ]['ref'][ $rf ] = ( $data[ $day ]['ref'][ $rf ] ?? 0 ) + 1;
			} else {
				$data[ $day ]['ref']['_other'] = ( $data[ $day ]['ref']['_other'] ?? 0 ) + 1;
			}
		}

		if ( 'page_view' === $event && isset( $params['campaign'] ) ) {
			$cp = (string) $params['campaign'];
			if ( ! isset( $data[ $day ]['camp'] ) || ! is_array( $data[ $day ]['camp'] ) ) {
				$data[ $day ]['camp'] = array();
			}
			// Bounded like the path map (overflow → "_other"): the value comes
			// from a URL, so an abuser could otherwise grow the option freely.
			if ( isset( $data[ $day ]['camp'][ $cp ] ) || count( $data[ $day ]['camp'] ) < self::MAX_PATHS_PER_DAY ) {
				$data[ $day ]['camp'][ $cp ] = ( $data[ $day ]['camp'][ $cp ] ?? 0 ) + 1;
			} else {
				$data[ $day ]['camp']['_other'] = ( $data[ $day ]['camp']['_other'] ?? 0 ) + 1;
			}
		}

		// Keep only the most recent KEEP_DAYS days.
		if ( count( $data ) > self::KEEP_DAYS ) {
			ksort( $data );
			$data = array_slice( $data, -self::KEEP_DAYS, null, true );
		}

		update_option( self::OPTION, $data, false );
	}

	/**
	 * Record a /recommend outcome + latency (PRD §7 KPIs: engine-success-rate
	 * and time-to-result p95). Counts only — the latency histogram lets us
	 * estimate a percentile without storing individual samples.
	 *
	 * @param string $outcome ok_llm|ok_fallback|error.
	 * @param int    $ms      Wall-clock duration in milliseconds.
	 */
	public static function record_recommend( string $outcome, int $ms ): void {
		$outcome = in_array( $outcome, array( 'ok_llm', 'ok_fallback', 'error' ), true ) ? $outcome : 'error';

		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$day = gmdate( 'Y-m-d' );
		if ( ! isset( $data[ $day ] ) || ! is_array( $data[ $day ] ) ) {
			$data[ $day ] = array();
		}

		$data[ $day ]['rec'][ $outcome ] = ( $data[ $day ]['rec'][ $outcome ] ?? 0 ) + 1;

		$bucket                          = self::latency_bucket( $ms );
		$data[ $day ]['lat'][ $bucket ]  = ( $data[ $day ]['lat'][ $bucket ] ?? 0 ) + 1;

		if ( count( $data ) > self::KEEP_DAYS ) {
			ksort( $data );
			$data = array_slice( $data, -self::KEEP_DAYS, null, true );
		}

		update_option( self::OPTION, $data, false );
	}

	/**
	 * Histogram bucket key for a latency in milliseconds.
	 *
	 * @param int $ms Milliseconds.
	 */
	private static function latency_bucket( int $ms ): string {
		if ( $ms < 1000 ) {
			return '0-1';
		}
		if ( $ms < 2000 ) {
			return '1-2';
		}
		if ( $ms < 4000 ) {
			return '2-4';
		}
		if ( $ms < 8000 ) {
			return '4-8';
		}
		if ( $ms < 16000 ) {
			return '8-16';
		}
		return '16+';
	}

	/**
	 * Estimate the p95 latency bucket label from a histogram (bucket => count),
	 * or null when there is no data.
	 *
	 * @param array<string,int> $lat Latency histogram.
	 */
	public static function latency_p95( array $lat ): ?string {
		$total = 0;
		foreach ( $lat as $n ) {
			$total += (int) $n;
		}
		if ( $total <= 0 ) {
			return null;
		}
		$threshold = $total * 0.95;
		$cum       = 0;
		foreach ( self::LAT_BUCKETS as $bucket ) {
			$cum += (int) ( $lat[ $bucket ] ?? 0 );
			if ( $cum >= $threshold ) {
				return $bucket;
			}
		}
		return self::LAT_BUCKETS[ count( self::LAT_BUCKETS ) - 1 ];
	}

	/**
	 * Normalise a page path to an anonymous, low-cardinality, storage-safe key:
	 * drops the query string/fragment, lowercases, strips anything outside a
	 * safe URL-path charset, removes the trailing slash (root stays "/") and
	 * caps the length. Carries no personal data — a public page URL only.
	 *
	 * @param string $path Raw pathname from the client.
	 * @return string
	 */
	private static function norm_path( string $path ): string {
		$path = (string) preg_replace( '/[?#].*$/', '', $path );
		$path = rawurldecode( $path );
		$path = strtolower( $path );
		$path = (string) preg_replace( '#[^a-z0-9\-_/]#', '', $path );
		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
		}
		if ( '' === $path ) {
			$path = '/';
		}
		if ( strlen( $path ) > 120 ) {
			$path = substr( $path, 0, 120 );
		}
		return $path;
	}

	/**
	 * Normalise a page language to a two-letter, lowercase code (e.g. "pt-PT"
	 * → "pt"). Non-language input collapses to "other".
	 *
	 * @param string $lang Raw lang attribute.
	 * @return string
	 */
	private static function norm_lang( string $lang ): string {
		$lang = strtolower( substr( $lang, 0, 2 ) );
		$lang = (string) preg_replace( '/[^a-z]/', '', $lang );
		return 2 === strlen( $lang ) ? $lang : 'other';
	}

	/**
	 * Normalise a referrer to its bare host (e.g. "www.Google.com" →
	 * "google.com"). Keeps the sentinels "direct" and "internal". Carries no
	 * personal data — a host only, never the referrer's path or query.
	 *
	 * @param string $ref Raw referrer host or sentinel.
	 * @return string
	 */
	private static function norm_ref( string $ref ): string {
		$ref = strtolower( $ref );
		$ref = (string) preg_replace( '/^www\./', '', $ref );
		$ref = (string) preg_replace( '/[^a-z0-9.\-]/', '', $ref );
		if ( '' === $ref ) {
			$ref = 'direct';
		}
		if ( strlen( $ref ) > 100 ) {
			$ref = substr( $ref, 0, 100 );
		}
		return $ref;
	}

	/**
	 * Normalize a campaign name: lowercase, [a-z0-9_-] only, 32 characters.
	 *
	 * A campaign NAME is shared by everyone who arrives through the same ad, so
	 * it is not personal data. The client never sends a per-click id, and the
	 * character class here would strip most of one anyway.
	 *
	 * @param string $campaign Raw campaign name from the landing URL.
	 */
	private static function norm_campaign( string $campaign ): string {
		$campaign = strtolower( $campaign );
		$campaign = (string) preg_replace( '/[^a-z0-9_\-]/', '', $campaign );
		return substr( $campaign, 0, 32 );
	}

	/**
	 * Validate a 'Y-m-d' date, returning '' when it is not a real calendar day
	 * (so '2026-02-31' is rejected, not silently rolled over).
	 *
	 * @param string $date Raw date string.
	 */
	private static function norm_date( string $date ): string {
		$date = trim( $date );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		$parts = explode( '-', $date );
		if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
			return '';
		}
		return $date;
	}

	/**
	 * Aggregate the counters over the last $days days.
	 *
	 * @param int $days Window size in days.
	 * @return array{e:array<string,int>,step:array<int,int>,arch:array<int,int>,cta:array<string,int>,page:array<string,int>,lang:array<string,int>,ref:array<string,int>,camp:array<string,int>,rec:array<string,int>,lat:array<string,int>,bkr:array<string,int>,bkr_loc:array<string,int>,bkr_arch:array<int,int>}
	 */
	public static function totals( int $days ): array {
		$days = max( 1, $days );
		return self::totals_between(
			gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d' )
		);
	}

	/**
	 * Aggregate the counters over an inclusive date range.
	 *
	 * Days are stored keyed by 'Y-m-d', so a plain string comparison is the
	 * whole filter — no date parsing per bucket. Both bounds are inclusive, and
	 * a reversed range is swapped rather than returning nothing.
	 *
	 * @param string $from Start date, 'Y-m-d'.
	 * @param string $to   End date, 'Y-m-d'.
	 * @return array<string,array<string|int,int>>
	 */
	public static function totals_between( string $from, string $to ): array {
		$from = self::norm_date( $from );
		$to   = self::norm_date( $to );
		if ( '' === $from || '' === $to ) {
			$from = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
			$to   = gmdate( 'Y-m-d' );
		}
		if ( $from > $to ) {
			$swap = $from;
			$from = $to;
			$to   = $swap;
		}

		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$out = array(
			'e'        => array(),
			'step'     => array(),
			'arch'     => array(),
			'cta'      => array(),
			'tool'     => array(),
			'page'     => array(),
			'lang'     => array(),
			'ref'      => array(),
			'camp'     => array(),
			'rec'      => array(),
			'lat'      => array(),
			'bkr'      => array(),
			'bkr_loc'  => array(),
			'bkr_arch' => array(),
		);
		foreach ( $data as $day => $buckets ) {
			$day = (string) $day;
			if ( $day < $from || $day > $to ) {
				continue;
			}
			foreach ( array( 'e', 'step', 'arch', 'cta', 'tool', 'page', 'lang', 'ref', 'camp', 'rec', 'lat', 'bkr', 'bkr_loc', 'bkr_arch' ) as $group ) {
				if ( empty( $buckets[ $group ] ) || ! is_array( $buckets[ $group ] ) ) {
					continue;
				}
				foreach ( $buckets[ $group ] as $k => $n ) {
					$out[ $group ][ $k ] = ( $out[ $group ][ $k ] ?? 0 ) + (int) $n;
				}
			}
		}
		return $out;
	}

	/**
	 * Register the admin screen (Settings → HTI Funnel).
	 */
	public static function admin_menu(): void {
		add_options_page(
			__( 'HTI Funnel', 'hti-engine' ),
			__( 'HTI Funnel', 'hti-engine' ),
			'manage_options',
			'hti-funnel',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the funnel report.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$allowed = array( 7, 30, 90 );
		$days    = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filter.
		if ( ! in_array( $days, $allowed, true ) ) {
			$days = 30;
		}

		// A custom range wins over the preset window. Both bounds are optional
		// on their own: one date alone reports that single day.
		$from = isset( $_GET['from'] ) ? self::norm_date( sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filter.
		$to   = isset( $_GET['to'] ) ? self::norm_date( sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filter.
		if ( '' !== $from || '' !== $to ) {
			if ( '' === $from ) {
				$from = $to;
			}
			if ( '' === $to ) {
				$to = $from;
			}
			if ( $from > $to ) {
				$swap = $from;
				$from = $to;
				$to   = $swap;
			}
			$custom = true;
			$t      = self::totals_between( $from, $to );
		} else {
			$custom = false;
			$from   = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
			$to     = gmdate( 'Y-m-d' );
			$t      = self::totals( $days );
		}

		$e    = $t['e'];
		$base = admin_url( 'options-general.php?page=hti-funnel' );

		// Counts are kept for KEEP_DAYS; anything older simply has no data.
		$oldest = gmdate( 'Y-m-d', time() - ( self::KEEP_DAYS - 1 ) * DAY_IN_SECONDS );

		// Map archetype ids → labels for readability.
		$archetypes = Config::archetypes();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HTI Funnel', 'hti-engine' ); ?></h1>
			<p style="margin:.2em 0 1em;color:#646970;">
				<?php esc_html_e( 'First-party, anonymous counts (no cookies, no personal data) — independent of Google Analytics.', 'hti-engine' ); ?>
			</p>

			<p>
				<?php esc_html_e( 'Window:', 'hti-engine' ); ?>
				<?php foreach ( $allowed as $d ) : ?>
					<?php if ( ! $custom && $d === $days ) : ?>
						<strong style="margin:0 .4em;"><?php echo esc_html( sprintf( '%d days', $d ) ); ?></strong>
					<?php else : ?>
						<a style="margin:0 .4em;" href="<?php echo esc_url( add_query_arg( 'days', $d, $base ) ); ?>"><?php echo esc_html( sprintf( '%d days', $d ) ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>

			<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" style="margin:0 0 1em;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
				<input type="hidden" name="page" value="hti-funnel" />
				<label for="hti-from"><?php esc_html_e( 'From', 'hti-engine' ); ?></label>
				<input type="date" id="hti-from" name="from" value="<?php echo esc_attr( $custom ? $from : '' ); ?>"
					min="<?php echo esc_attr( $oldest ); ?>" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
				<label for="hti-to"><?php esc_html_e( 'to', 'hti-engine' ); ?></label>
				<input type="date" id="hti-to" name="to" value="<?php echo esc_attr( $custom ? $to : '' ); ?>"
					min="<?php echo esc_attr( $oldest ); ?>" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'hti-engine' ); ?></button>
				<?php if ( $custom ) : ?>
					<a class="button-link" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'hti-engine' ); ?></a>
				<?php endif; ?>
				<span style="color:#646970;font-size:12px;">
					<?php esc_html_e( 'Leave one side empty for a single day. Counts are kept for 120 days.', 'hti-engine' ); ?>
				</span>
			</form>

			<p style="margin:.2em 0 1em;color:#646970;">
				<?php
				printf(
					/* translators: 1: start date, 2: end date. */
					esc_html__( 'Showing %1$s to %2$s (inclusive, UTC days).', 'hti-engine' ),
					esc_html( $from ),
					esc_html( $to )
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Traffic (page views)', 'hti-engine' ); ?></h2>
			<?php
			$pv = (int) ( $e['page_view'] ?? 0 );
			?>
			<p style="margin:.2em 0 1em;">
				<span style="font-size:26px;font-weight:600;font-variant-numeric:tabular-nums;"><?php echo esc_html( number_format_i18n( $pv ) ); ?></span>
				<span style="color:#646970;">&nbsp;<?php esc_html_e( 'page views in this window (anonymous, cookieless)', 'hti-engine' ); ?></span>
			</p>
			<h3 style="margin:.5em 0;"><?php esc_html_e( 'Top pages', 'hti-engine' ); ?></h3>
			<?php
			$page_rows = array();
			$pages     = $t['page'];
			arsort( $pages );
			$shown = 0;
			foreach ( $pages as $path => $n ) {
				if ( $shown >= 25 ) {
					break;
				}
				++$shown;
				$label       = '_other' === $path ? __( '(other pages)', 'hti-engine' ) : (string) $path;
				$page_rows[] = array( $label, (int) $n );
			}
			if ( $page_rows ) {
				self::bar_table( $page_rows, $pv );
			} else {
				echo '<p>' . esc_html__( 'No data yet.', 'hti-engine' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'Where visitors come from', 'hti-engine' ); ?></h2>
			<p style="margin:.2em 0 1em;color:#646970;font-size:12px;">
				<?php esc_html_e( 'Referrer host of each page view (internal navigation excluded). "direct" = typed/bookmarked or no referrer.', 'hti-engine' ); ?>
			</p>
			<?php
			$refs = $t['ref'];
			unset( $refs['internal'] ); // Internal navigation is not an acquisition source.
			arsort( $refs );
			$ref_total = array_sum( $refs );
			$ref_rows  = array();
			$shown     = 0;
			foreach ( $refs as $host => $n ) {
				if ( $shown >= 20 ) {
					break;
				}
				++$shown;
				$label      = '_other' === $host ? __( '(other sources)', 'hti-engine' ) : (string) $host;
				$ref_rows[] = array( $label, (int) $n );
			}
			if ( $ref_rows ) {
				self::bar_table( $ref_rows, $ref_total );
			} else {
				echo '<p>' . esc_html__( 'No data yet.', 'hti-engine' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'Campaigns (paid traffic)', 'hti-engine' ); ?></h2>
			<p style="margin:.2em 0 1em;color:#646970;font-size:12px;">
				<?php esc_html_e( 'Page views whose landing URL carried utm_campaign (or campaign / utm_source). Counted for every visitor, with or without consent — which is why paid traffic shows here even when Google Analytics reports nothing. Tag your ad landing URLs with ?utm_campaign=name to see them.', 'hti-engine' ); ?>
			</p>
			<?php
			$camps = $t['camp'];
			arsort( $camps );
			$camp_total = array_sum( $camps );
			$camp_rows  = array();
			$shown      = 0;
			foreach ( $camps as $camp => $n ) {
				if ( $shown >= 20 ) {
					break;
				}
				++$shown;
				$label       = '_other' === $camp ? __( '(other campaigns)', 'hti-engine' ) : (string) $camp;
				$camp_rows[] = array( $label, (int) $n );
			}
			if ( $camp_rows ) {
				self::bar_table( $camp_rows, $camp_total );
			} else {
				echo '<p>' . esc_html__( 'No campaign traffic recorded yet.', 'hti-engine' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'Language', 'hti-engine' ); ?></h2>
			<?php
			$lang_labels = array(
				'pt'    => __( 'Portuguese (PT)', 'hti-engine' ),
				'en'    => __( 'English (EN)', 'hti-engine' ),
				'other' => __( 'Other', 'hti-engine' ),
			);
			$langs = $t['lang'];
			arsort( $langs );
			$lang_rows = array();
			foreach ( $langs as $code => $n ) {
				$lang_rows[] = array( $lang_labels[ $code ] ?? (string) $code, (int) $n );
			}
			if ( $lang_rows ) {
				self::bar_table( $lang_rows, (int) array_sum( $langs ) );
			} else {
				echo '<p>' . esc_html__( 'No data yet.', 'hti-engine' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'Newsletter & lead funnel', 'hti-engine' ); ?></h2>
			<p style="margin:.2em 0 1em;color:#646970;font-size:12px;">
				<?php esc_html_e( 'First-party, cookieless — the complete newsletter funnel without depending on GA4. Confirmations are counted on the double-opt-in landing page.', 'hti-engine' ); ?>
			</p>
			<?php
			$nl_submit  = (int) ( $e['newsletter_subscribe_submit'] ?? 0 );
			$nl_ebook   = (int) ( $e['ebook_lead'] ?? 0 );
			$nl_leads   = $nl_submit + $nl_ebook;
			$nl_confirm = (int) ( $e['newsletter_confirmed'] ?? 0 );
			$nl_unsub   = (int) ( $e['newsletter_unsubscribe'] ?? 0 );
			// Funnel from traffic → leads → confirmed subscribers. The bar_table's
			// second column shows each step as a % of the first (page views).
			$nl_steps = array(
				array( __( 'Page views', 'hti-engine' ), $pv ),
				array( __( 'Newsletter form submits', 'hti-engine' ), $nl_submit ),
				array( __( 'Ebook-gate leads', 'hti-engine' ), $nl_ebook ),
				array( __( 'Confirmed (double opt-in)', 'hti-engine' ), $nl_confirm ),
			);
			self::bar_table( $nl_steps, $pv > 0 ? $pv : max( 1, $nl_leads ) );
			$confirm_rate = $nl_leads > 0 ? round( $nl_confirm / $nl_leads * 100, 1 ) : null;
			?>
			<p style="margin:.6em 0 0;color:#1d2327;">
				<?php
				printf(
					/* translators: 1: confirmed count, 2: total leads, 3: confirm rate, 4: unsubscribes. */
					esc_html__( 'Confirmed %1$s of %2$s leads%3$s · %4$s unsubscribed in this window.', 'hti-engine' ),
					'<strong>' . esc_html( number_format_i18n( $nl_confirm ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( $nl_leads ) ) . '</strong>',
					null !== $confirm_rate ? ' (<strong>' . esc_html( (string) $confirm_rate ) . '%</strong>)' : '',
					'<strong>' . esc_html( number_format_i18n( $nl_unsub ) ) . '</strong>'
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Activation funnel', 'hti-engine' ); ?></h2>
			<?php
			$starts = (int) ( $e['quiz_start'] ?? 0 );
			$steps  = array(
				array( __( 'Quiz started', 'hti-engine' ), (int) ( $e['quiz_start'] ?? 0 ) ),
				array( __( 'Answered all', 'hti-engine' ), (int) ( $e['quiz_submit'] ?? 0 ) ),
				array( __( 'Saw result', 'hti-engine' ), (int) ( $e['result_view'] ?? 0 ) ),
				array( __( 'Exported PDF', 'hti-engine' ), (int) ( $e['result_pdf_export'] ?? 0 ) ),
				array( __( 'Emailed result', 'hti-engine' ), (int) ( $e['result_email_request'] ?? 0 ) ),
			);
			self::bar_table( $steps, $starts );
			?>

			<h2><?php esc_html_e( 'Engine health (KPIs)', 'hti-engine' ); ?></h2>
			<?php
			$rec       = is_array( $t['rec'] ?? null ) ? $t['rec'] : array();
			$ok_llm    = (int) ( $rec['ok_llm'] ?? 0 );
			$ok_fb     = (int) ( $rec['ok_fallback'] ?? 0 );
			$rec_err   = (int) ( $rec['error'] ?? 0 );
			$rec_ok    = $ok_llm + $ok_fb;
			$rec_total = $rec_ok + $rec_err;
			$flow_rate = $rec_total > 0 ? round( $rec_ok / $rec_total * 100, 1 ) : null;
			$llm_rate  = $rec_ok > 0 ? round( $ok_llm / $rec_ok * 100, 1 ) : null;
			$p95       = self::latency_p95( is_array( $t['lat'] ?? null ) ? $t['lat'] : array() );

			if ( $rec_total < 1 ) {
				echo '<p style="color:#646970;">' . esc_html__( 'No recommendations recorded in this window yet.', 'hti-engine' ) . '</p>';
			} else {
				printf(
					'<p style="margin:.6em 0 0;color:#1d2327;">%1$s <strong>%2$s%%</strong> (%3$s) &middot; %4$s <strong>%5$s%%</strong> &middot; %6$s <strong>%7$s</strong> &middot; %8$s <strong>%9$s</strong></p>',
					esc_html__( 'Engine success (flow, target ≥98%):', 'hti-engine' ),
					esc_html( null !== $flow_rate ? (string) $flow_rate : '—' ),
					esc_html( sprintf( /* translators: %s: number of recommendations. */ __( '%s recommendations', 'hti-engine' ), number_format_i18n( $rec_total ) ) ),
					esc_html__( 'LLM-explained:', 'hti-engine' ),
					esc_html( null !== $llm_rate ? (string) $llm_rate : '—' ),
					esc_html__( 'fallback:', 'hti-engine' ),
					esc_html( number_format_i18n( $ok_fb ) ),
					esc_html__( 'errors:', 'hti-engine' ),
					esc_html( number_format_i18n( $rec_err ) )
				);
				printf(
					'<p style="margin:.3em 0 0;color:#1d2327;">%1$s <strong>%2$s</strong></p>',
					esc_html__( 'Time-to-result p95 (target <8s):', 'hti-engine' ),
					esc_html( null !== $p95 ? $p95 . 's' : '—' )
				);
			}
			?>

			<h2><?php esc_html_e( 'Drop-off by question', 'hti-engine' ); ?></h2>
			<?php
			$step_rows = array();
			$max_step  = 0;
			foreach ( array_keys( $t['step'] ) as $k ) {
				$max_step = max( $max_step, (int) $k );
			}
			for ( $i = 1; $i <= $max_step; $i++ ) {
				$step_rows[] = array(
					/* translators: %d: question number. */
					sprintf( __( 'Question %d', 'hti-engine' ), $i ),
					(int) ( $t['step'][ $i ] ?? 0 ),
				);
			}
			if ( $step_rows ) {
				self::bar_table( $step_rows, (int) ( $t['step'][1] ?? $starts ) );
			} else {
				echo '<p>' . esc_html__( 'No data yet.', 'hti-engine' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'Results by archetype', 'hti-engine' ); ?></h2>
			<?php
			$arch_rows = array();
			arsort( $t['arch'] );
			foreach ( $t['arch'] as $id => $n ) {
				$label = $archetypes[ $id ]['label']['en'] ?? ( 'Archetype ' . (int) $id );
				$arch_rows[] = array( $label, (int) $n );
			}
			if ( $arch_rows ) {
				self::bar_table( $arch_rows, (int) ( $e['result_view'] ?? 0 ) );
			} else {
				echo '<p>' . esc_html__( 'No data yet.', 'hti-engine' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'CTA clicks by location', 'hti-engine' ); ?></h2>
			<?php
			$cta_rows = array();
			arsort( $t['cta'] );
			$cta_total = array_sum( $t['cta'] );
			foreach ( $t['cta'] as $loc => $n ) {
				$cta_rows[] = array( $loc, (int) $n );
			}
			if ( $cta_rows ) {
				self::bar_table( $cta_rows, (int) $cta_total );
			} else {
				echo '<p>' . esc_html__( 'No data yet.', 'hti-engine' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'Brokers (partner section)', 'hti-engine' ); ?></h2>
			<?php
			$clicks  = (int) ( $e['broker_click'] ?? 0 );
			$mod     = (int) ( $e['result_broker_view'] ?? 0 );
			$compare = (int) ( $e['broker_compare_view'] ?? 0 );
			$reviews = (int) ( $e['broker_review_view'] ?? 0 );
			$guides  = (int) ( $e['broker_guide_view'] ?? 0 );
			?>
			<table class="widefat striped" style="max-width:520px;margin-bottom:1em;">
				<tbody>
					<tr><td><?php esc_html_e( 'Outbound clicks (/go/)', 'hti-engine' ); ?></td><td style="text-align:right;font-variant-numeric:tabular-nums;"><strong><?php echo esc_html( number_format_i18n( $clicks ) ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Comparison views', 'hti-engine' ); ?></td><td style="text-align:right;font-variant-numeric:tabular-nums;"><strong><?php echo esc_html( number_format_i18n( $compare ) ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Review views', 'hti-engine' ); ?></td><td style="text-align:right;font-variant-numeric:tabular-nums;"><strong><?php echo esc_html( number_format_i18n( $reviews ) ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Guide views', 'hti-engine' ); ?></td><td style="text-align:right;font-variant-numeric:tabular-nums;"><strong><?php echo esc_html( number_format_i18n( $guides ) ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Result partner-module views', 'hti-engine' ); ?></td><td style="text-align:right;font-variant-numeric:tabular-nums;"><strong><?php echo esc_html( number_format_i18n( $mod ) ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Click-through: comparison → click', 'hti-engine' ); ?></td><td style="text-align:right;font-variant-numeric:tabular-nums;"><strong><?php echo esc_html( $compare > 0 ? number_format_i18n( $clicks / $compare * 100, 1 ) . '%' : '—' ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Click-through: partner module → click', 'hti-engine' ); ?></td><td style="text-align:right;font-variant-numeric:tabular-nums;"><strong><?php echo esc_html( $mod > 0 ? number_format_i18n( $clicks / $mod * 100, 1 ) . '%' : '—' ); ?></strong></td></tr>
				</tbody>
			</table>
			<h3 style="margin:.5em 0;"><?php esc_html_e( 'Clicks by broker', 'hti-engine' ); ?></h3>
			<?php
			$bkr_rows = array();
			arsort( $t['bkr'] );
			foreach ( $t['bkr'] as $slug => $n ) {
				$bkr_rows[] = array( (string) $slug, (int) $n );
			}
			if ( $bkr_rows ) {
				self::bar_table( $bkr_rows, $clicks );
			} else {
				echo '<p>' . esc_html__( 'No data yet.', 'hti-engine' ) . '</p>';
			}
			?>
			<h3 style="margin:.5em 0;"><?php esc_html_e( 'Clicks by location', 'hti-engine' ); ?></h3>
			<?php
			$bl_rows = array();
			arsort( $t['bkr_loc'] );
			foreach ( $t['bkr_loc'] as $loc => $n ) {
				$bl_rows[] = array( (string) $loc, (int) $n );
			}
			if ( $bl_rows ) {
				self::bar_table( $bl_rows, $clicks );
			} else {
				echo '<p>' . esc_html__( 'No data yet.', 'hti-engine' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'Growth & accounts', 'hti-engine' ); ?></h2>
			<table class="widefat striped" style="max-width:520px;">
				<tbody>
				<?php
				$growth = array(
					__( 'Newsletter sign-up (submitted)', 'hti-engine' ) => 'newsletter_subscribe_submit',
					__( 'Newsletter confirmed', 'hti-engine' )           => 'newsletter_confirmed',
					__( 'Unsubscribed', 'hti-engine' )                    => 'newsletter_unsubscribe',
					__( 'Account sign-up', 'hti-engine' )                 => 'sign_up',
					__( 'Login', 'hti-engine' )                           => 'login',
					__( 'Onboarding completed', 'hti-engine' )            => 'onboarding_complete',
					__( 'Profile saved', 'hti-engine' )                   => 'save_profile',
					__( 'Contact message', 'hti-engine' )                 => 'contact_submit',
					__( 'Deletion requested', 'hti-engine' )              => 'account_delete_request',
				);
				foreach ( $growth as $label => $key ) :
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td style="text-align:right;font-variant-numeric:tabular-nums;"><strong><?php echo esc_html( number_format_i18n( (int) ( $e[ $key ] ?? 0 ) ) ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Forex bot & tools', 'hti-engine' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'What the paid campaigns are buying. A bot start is someone opening the bot; a calculation is someone actually using it — the gap between the two is the campaign\'s real quality.', 'hti-engine' ); ?>
			</p>
			<?php
			$bot = array(
				__( 'Bot opened (/start)', 'hti-engine' ) => 'forex_bot_start',
				__( 'Calculation answered', 'hti-engine' ) => 'forex_bot_calc',
				__( 'Left the bot (/stop)', 'hti-engine' ) => 'forex_bot_stop',
				__( 'Calculator used on the site', 'hti-engine' ) => 'forex_tool_use',
			);
			$bot_rows = array();
			foreach ( $bot as $label => $key ) {
				$bot_rows[] = array( $label, (int) ( $e[ $key ] ?? 0 ) );
			}
			self::bar_table( $bot_rows, (int) ( $e['forex_bot_start'] ?? 0 ) );

			$tool = $t['tool'] ?? array();
			arsort( $tool );
			if ( array() !== $tool ) :
				?>
				<h3><?php esc_html_e( 'Which calculator', 'hti-engine' ); ?></h3>
				<?php
				$tool_rows = array();
				foreach ( array_slice( $tool, 0, 20, true ) as $name => $n ) {
					$tool_rows[] = array( (string) $name, (int) $n );
				}
				self::bar_table( $tool_rows, 0 );
			endif;
			?>

			<h2><?php esc_html_e( 'Every event counted', 'hti-engine' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Every event this site knows how to count, whether or not it has a chart of its own above. A zero here means either nothing happened or nothing is firing it — both worth knowing, and neither visible while an event has no screen.', 'hti-engine' ); ?>
			</p>
			<table class="widefat striped" style="max-width:520px;">
				<thead><tr>
					<th><?php esc_html_e( 'Event', 'hti-engine' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Count', 'hti-engine' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( self::event_rows( $e ) as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row[0] ); ?></code></td>
						<td style="text-align:right;font-variant-numeric:tabular-nums;<?php echo 0 === $row[1] ? 'color:#646970;' : ''; ?>">
							<?php echo esc_html( number_format_i18n( $row[1] ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:1.5em;color:#646970;font-size:12px;">
				<?php esc_html_e( 'Counts come from anonymous first-party beacons; visitors who block scripts are not counted (same as any client-side analytics). Approximate under heavy concurrent traffic.', 'hti-engine' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * One row per countable event, busiest first.
	 *
	 * Built from `events()` rather than from a hand-written list, which is the
	 * whole point: eleven events were once counted for months without a single
	 * screen showing them, because surfacing an event was a second, separate
	 * act of remembering. Here, adding a name to the allowlist is enough.
	 *
	 * @param array<string,int> $e Event totals for the period.
	 * @return array<int,array{0:string,1:int}> Rows of [event, count].
	 */
	public static function event_rows( array $e ): array {
		$rows = array();
		foreach ( self::events() as $name ) {
			$rows[] = array( $name, (int) ( $e[ $name ] ?? 0 ) );
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b[1] <=> $a[1] ?: strcmp( $a[0], $b[0] );
			}
		);

		return $rows;
	}

	/**
	 * Render a labelled horizontal-bar table. The first column is the label,
	 * the second a count + a bar sized relative to $max (or the first row).
	 *
	 * @param array<int,array{0:string,1:int}> $rows Rows of [label, count].
	 * @param int                               $max  Reference for 100% width.
	 */
	private static function bar_table( array $rows, int $max ): void {
		$ref = $max > 0 ? $max : 1;
		foreach ( $rows as $row ) {
			if ( (int) $row[1] > $ref ) {
				$ref = (int) $row[1];
			}
		}
		echo '<table class="widefat striped" style="max-width:640px;"><tbody>';
		foreach ( $rows as $row ) {
			$label = (string) $row[0];
			$count = (int) $row[1];
			$pct   = (int) round( ( $count / $ref ) * 100 );
			$conv = $max > 0 ? round( ( $count / $max ) * 100, 1 ) : null;
			echo '<tr>';
			echo '<td style="width:42%;">' . esc_html( $label ) . '</td>';
			echo '<td>';
			echo '<div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:18px;min-width:80px;">';
			echo '<div style="background:#FF6B5E;height:18px;width:' . esc_attr( (string) $pct ) . '%;"></div>';
			echo '</div>';
			echo '</td>';
			echo '<td style="text-align:right;width:24%;font-variant-numeric:tabular-nums;"><strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>';
			if ( null !== $conv ) {
				echo ' <span style="color:#646970;">(' . esc_html( (string) $conv ) . '%)</span>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
