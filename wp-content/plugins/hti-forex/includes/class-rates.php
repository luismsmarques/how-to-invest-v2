<?php
/**
 * USD→INR / USD→JPY reference rates for the calculators.
 *
 * Twice-daily WP-Cron fetch from Frankfurter (ECB reference rates, keyless),
 * stored in a non-autoloaded option. Precedence when reading: admin manual
 * override > fetched > shipped fallback. A failed or implausible fetch keeps
 * the previous value untouched (accept() is pure and unit-tested), and the
 * front-end rate input is always editable — a dead API can degrade the
 * caption to "indicative", never break a landing page.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Rates fetch/store/read.
 */
class Rates {

	public const OPTION = 'hti_forex_rates';
	public const HOOK   = 'hti_forex_fetch_rates';

	private const API_URL = 'https://api.frankfurter.dev/v1/latest?base=USD&symbols=INR,JPY,EUR,GBP';

	/**
	 * Plausibility bounds per symbol — a payload outside these is rejected so
	 * a broken API response can never distort every calculation on the site.
	 */
	private const BOUNDS = array(
		'USDINR' => array( 30.0, 300.0 ),
		'USDJPY' => array( 50.0, 400.0 ),
		'EURUSD' => array( 0.5, 2.5 ),
		'GBPUSD' => array( 0.5, 3.0 ),
	);

	/**
	 * Symbols that must be present and in bounds for a payload to be accepted
	 * at all. EUR/GBP are deliberately not here: they arrived later, only the
	 * Telegram bot's margin line needs them, and a Frankfurter response that
	 * omits them must not invalidate the rates the whole site depends on.
	 */
	private const REQUIRED = array( 'USDINR', 'USDJPY' );

	/**
	 * How each stored key is read out of the API payload. Frankfurter quotes
	 * base=USD, so INR/JPY arrive already in pair notation while EUR/GBP
	 * arrive inverted (EUR per USD) and have to be flipped into EUR/USD.
	 */
	private const SYMBOLS = array(
		'USDINR' => array( 'INR', false ),
		'USDJPY' => array( 'JPY', false ),
		'EURUSD' => array( 'EUR', true ),
		'GBPUSD' => array( 'GBP', true ),
	);

	/**
	 * Shipped fallbacks, used only before the first successful fetch (or if
	 * the option is ever lost). Indicative by design — the UI marks them
	 * stale and the user can always edit the rate inline.
	 */
	private const FALLBACK = array(
		'USDINR' => 95.5,
		'USDJPY' => 159.0,
		'EURUSD' => 1.165,
		'GBPUSD' => 1.34,
	);

	private const STALE_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Cron cadence, and where in the day it is anchored.
	 *
	 * `twicedaily` anchored the series to whatever minute the plugin happened
	 * to be activated on — in production, 02:39 UTC and 14:39 UTC. The ECB
	 * publishes its reference rates once per TARGET business day, in the
	 * Frankfurt afternoon: roughly 14:00 UTC while central Europe is on summer
	 * time and roughly 15:00 UTC once it is not. So the afternoon run cleared
	 * publication by 39 minutes for half the year and missed it by 21 for the
	 * other half — from the October clock change the site would have sat a day
	 * behind, every day, with nothing saying so.
	 *
	 * The fix does not depend on knowing that hour precisely. Anchoring the
	 * series at 16:00 UTC and repeating every six hours puts a fetch at 16:00
	 * and 22:00 UTC — both comfortably after publication under either regime —
	 * and two more overnight that cost nothing and cover a failed attempt.
	 *
	 * @link https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/
	 */
	private const ANCHOR_HOUR = 16;
	private const INTERVAL     = 'hti_forex_6h';

	/**
	 * Bump to move every site off the schedule it is already on. Without it
	 * `schedule()` sees an event and leaves the old cadence running for ever.
	 */
	private const SCHEDULE_VERSION = 2;
	private const OPTION_SCHEDULE  = 'hti_forex_rates_schedule';

	/**
	 * Hook cron + admin handler + settings panel.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'fetch' ) );
		// The interval has to exist before anything schedules against it, so
		// the filter is registered ahead of the `init` that does the queuing.
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- six hours, well above the 15-minute guidance.
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_post_hti_forex_fetch_rates', array( __CLASS__, 'handle_fetch_now' ) );
		add_action( 'hti_forex_settings_panels', array( __CLASS__, 'render_panel' ) );
	}

	/**
	 * Ensure the twice-daily event is scheduled.
	 */
	public static function schedule(): void {
		// A site already running the old cadence keeps it for ever unless the
		// event is cleared first — wp_next_scheduled() cannot tell the two
		// apart, and only sees that something is queued.
		if ( (int) get_option( self::OPTION_SCHEDULE, 0 ) !== self::SCHEDULE_VERSION ) {
			wp_clear_scheduled_hook( self::HOOK );
			update_option( self::OPTION_SCHEDULE, self::SCHEDULE_VERSION, false );
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( self::next_slot( time() ), self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Register the six-hour interval the schedule runs on.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function cron_interval( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules[ self::INTERVAL ] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every six hours (HTI Forex rates)', 'hti-forex' ),
		);

		return $schedules;
	}

	/**
	 * The next slot in the series anchored at ANCHOR_HOUR UTC, every six hours.
	 *
	 * Pure and injectable, because "does a fetch always land after the ECB has
	 * published" is exactly the kind of thing that is true when written and
	 * quietly false after a clock change. With a 16:00 anchor the series is
	 * 04:00, 10:00, 16:00 and 22:00 UTC.
	 *
	 * Returning the *next* slot rather than a fixed 16:00 keeps the first fetch
	 * after a deploy at most six hours away instead of up to a day.
	 *
	 * @param int $now Unix time.
	 */
	public static function next_slot( int $now ): int {
		$step   = 6 * HOUR_IN_SECONDS;
		$offset = ( self::ANCHOR_HOUR * HOUR_IN_SECONDS ) % $step;

		// Round the current instant up to the next point congruent to the
		// anchor, so the series always contains ANCHOR_HOUR itself.
		return (int) ( ( (int) floor( ( $now - $offset ) / $step ) + 1 ) * $step + $offset );
	}

	/**
	 * How many business days the stored ECB fixing is behind today.
	 *
	 * The ECB publishes on TARGET business days only, so a Monday reading
	 * Friday's fixing is not behind at all — it is the newest one there is.
	 * Counting weekdays strictly between the two dates makes that come out as
	 * zero, which is the only way an alert about this can avoid crying wolf
	 * every Saturday.
	 *
	 * Public holidays are not modelled: TARGET closes on a handful of days a
	 * year and the caller's threshold absorbs them rather than this needing a
	 * calendar it would have to maintain.
	 *
	 * @param string $date ECB fixing date, `Y-m-d` ('' when none is stored).
	 * @param int    $now  Unix time.
	 * @return int Weekdays between the fixing and today; 0 when unknown.
	 */
	public static function weekdays_behind( string $date, int $now ): int {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}

		$from = strtotime( $date . ' 00:00:00 UTC' );
		$to   = strtotime( gmdate( 'Y-m-d', $now ) . ' 00:00:00 UTC' );

		if ( false === $from || false === $to || $to <= $from ) {
			return 0;
		}

		$behind = 0;
		for ( $day = $from + DAY_IN_SECONDS; $day < $to; $day += DAY_IN_SECONDS ) {
			if ( (int) gmdate( 'N', $day ) < 6 ) {
				++$behind;
			}
		}

		return $behind;
	}

	/**
	 * Clear the scheduled event (deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron/admin entry point: fetch from the API and store what accept() lets
	 * through. Quietly keeps the previous option on any failure.
	 *
	 * @return bool Whether a fresh payload was stored.
	 */
	public static function fetch(): bool {
		$response = wp_remote_get( self::API_URL, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return false;
		}

		$current  = get_option( self::OPTION, array() );
		$accepted = self::accept( $body, is_array( $current ) ? $current : array() );

		if ( $accepted === $current ) {
			return false;
		}

		update_option( self::OPTION, $accepted, false );
		return true;
	}

	/**
	 * Pure validation: turn an API payload into the stored shape, or return
	 * the current stored value unchanged when the payload is unusable.
	 *
	 * @param array<string,mixed> $api     Decoded API payload.
	 * @param array<string,mixed> $current Currently stored option value.
	 * @param int|null            $now     Unix time (injectable for tests).
	 * @return array<string,mixed>
	 */
	public static function accept( array $api, array $current, ?int $now = null ): array {
		$rates = array();

		foreach ( self::SYMBOLS as $key => $spec ) {
			list( $symbol, $invert ) = $spec;
			$raw                     = $api['rates'][ $symbol ] ?? null;
			$required                = in_array( $key, self::REQUIRED, true );

			if ( ! is_numeric( $raw ) || (float) $raw <= 0 ) {
				if ( $required ) {
					return $current;
				}
				continue;
			}

			$value = $invert ? 1 / (float) $raw : (float) $raw;

			if ( $value < self::BOUNDS[ $key ][0] || $value > self::BOUNDS[ $key ][1] ) {
				if ( $required ) {
					return $current;
				}
				continue;
			}

			$rates[ $key ] = round( $value, 4 );
		}

		$date = isset( $api['date'] ) && is_string( $api['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $api['date'] )
			? $api['date']
			: gmdate( 'Y-m-d', $now ?? time() );

		return array(
			'rates'      => $rates,
			'date'       => $date,
			'fetched_at' => $now ?? time(),
			'source'     => 'frankfurter',
		);
	}

	/**
	 * The rates the calculators should use right now.
	 *
	 * @param int|null $now Unix time (injectable for tests).
	 * @return array{rates:array{USDINR:float,USDJPY:float},date:string,stale:bool,source:string}
	 */
	public static function effective( ?int $now = null ): array {
		$now    = $now ?? time();
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( isset( $stored['rates']['USDINR'], $stored['rates']['USDJPY'], $stored['fetched_at'] ) ) {
			// Any symbol the stored payload lacks (EUR/GBP on a site that last
			// fetched before they were added) falls back to the shipped value,
			// so callers can always read every key without guarding.
			$rates = self::FALLBACK;
			foreach ( self::FALLBACK as $key => $default ) {
				if ( isset( $stored['rates'][ $key ] ) && (float) $stored['rates'][ $key ] > 0 ) {
					$rates[ $key ] = (float) $stored['rates'][ $key ];
				}
			}

			$out = array(
				'rates'  => $rates,
				'date'   => (string) ( $stored['date'] ?? '' ),
				'stale'  => ( $now - (int) $stored['fetched_at'] ) > self::STALE_AFTER,
				'source' => 'frankfurter',
			);
		} else {
			$out = array(
				'rates'  => self::FALLBACK,
				'date'   => '',
				'stale'  => true,
				'source' => 'fallback',
			);
		}

		// Admin manual overrides win, and are never stale — the admin chose them.
		$settings = Settings::settings();
		$override = false;
		if ( ! empty( $settings['rate_override_usdinr'] ) && (float) $settings['rate_override_usdinr'] > 0 ) {
			$out['rates']['USDINR'] = (float) $settings['rate_override_usdinr'];
			$override               = true;
		}
		if ( ! empty( $settings['rate_override_usdjpy'] ) && (float) $settings['rate_override_usdjpy'] > 0 ) {
			$out['rates']['USDJPY'] = (float) $settings['rate_override_usdjpy'];
			$override               = true;
		}
		if ( $override ) {
			$out['source'] = 'override';
			$out['stale']  = false;
		}

		return $out;
	}

	/**
	 * "Fetch now" admin-post handler.
	 */
	public static function handle_fetch_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-forex' ) );
		}
		check_admin_referer( 'hti_forex_fetch_rates' );

		$stored = self::fetch();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'hti-forex',
					'hti_forex_fetch'  => $stored ? '1' : '0',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Rates status panel on the settings screen.
	 */
	public static function render_panel(): void {
		$eff    = self::effective();
		$stored = get_option( self::OPTION, array() );
		?>
		<h2><?php esc_html_e( 'Exchange rates', 'hti-forex' ); ?></h2>
		<?php if ( isset( $_GET['hti_forex_fetch'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-<?php echo '1' === sanitize_key( wp_unslash( $_GET['hti_forex_fetch'] ) ) ? 'success' : 'warning'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>"><p>
				<?php
				if ( '1' === sanitize_key( wp_unslash( $_GET['hti_forex_fetch'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					esc_html_e( 'Fresh rates fetched and stored.', 'hti-forex' );
				} else {
					esc_html_e( 'Fetch did not store anything (API unreachable, implausible payload, or unchanged) — the previous rates are still in use.', 'hti-forex' );
				}
				?>
			</p></div>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:640px;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'USD/INR in use', 'hti-forex' ); ?></td>
					<td><code><?php echo esc_html( number_format( $eff['rates']['USDINR'], 4 ) ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'USD/JPY in use', 'hti-forex' ); ?></td>
					<td><code><?php echo esc_html( number_format( $eff['rates']['USDJPY'], 4 ) ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Source', 'hti-forex' ); ?></td>
					<td>
						<?php echo esc_html( $eff['source'] ); ?>
						<?php if ( $eff['date'] ) : ?>
							(<?php echo esc_html( $eff['date'] ); ?>)
						<?php endif; ?>
						<?php if ( $eff['stale'] ) : ?>
							— <strong><?php esc_html_e( 'stale', 'hti-forex' ); ?></strong>
						<?php endif; ?>
					</td>
				</tr>
				<?php
				// A fixing date on its own cannot be judged: on a Monday the
				// newest one there is dates from Friday, and the panel used to
				// show that with nothing saying whether it was fine.
				$behind = self::weekdays_behind( (string) $eff['date'], time() );
				?>
				<tr>
					<td><?php esc_html_e( 'ECB fixing', 'hti-forex' ); ?></td>
					<td>
						<?php if ( '' === (string) $eff['date'] ) : ?>
							<?php esc_html_e( 'none stored yet — the shipped fallback is in use.', 'hti-forex' ); ?>
						<?php elseif ( $behind < 2 ) : ?>
							<?php esc_html_e( 'current. The ECB publishes once per business day, in the Frankfurt afternoon, and nothing at weekends — so a Monday showing Friday is the newest fixing there is.', 'hti-forex' ); ?>
						<?php else : ?>
							<strong>
								<?php
								printf(
									/* translators: %d: number of business days. */
									esc_html( _n( '%d business day behind.', '%d business days behind.', $behind, 'hti-forex' ) ),
									(int) $behind
								);
								?>
							</strong>
							<?php esc_html_e( 'A public holiday explains one; more than that means the fetch is not landing. Check the last successful fetch below, then use "Fetch now".', 'hti-forex' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( isset( $stored['fetched_at'] ) ) : ?>
					<tr>
						<td><?php esc_html_e( 'Last successful fetch', 'hti-forex' ); ?></td>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $stored['fetched_at'] ) ); ?> UTC</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<input type="hidden" name="action" value="hti_forex_fetch_rates" />
			<?php wp_nonce_field( 'hti_forex_fetch_rates' ); ?>
			<?php submit_button( __( 'Fetch now', 'hti-forex' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
