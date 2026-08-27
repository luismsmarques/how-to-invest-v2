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

	private const API_URL = 'https://api.frankfurter.dev/v1/latest?base=USD&symbols=INR,JPY';

	/**
	 * Plausibility bounds per symbol — a payload outside these is rejected so
	 * a broken API response can never distort every calculation on the site.
	 */
	private const BOUNDS = array(
		'USDINR' => array( 30.0, 300.0 ),
		'USDJPY' => array( 50.0, 400.0 ),
	);

	/**
	 * Shipped fallbacks, used only before the first successful fetch (or if
	 * the option is ever lost). Indicative by design — the UI marks them
	 * stale and the user can always edit the rate inline.
	 */
	private const FALLBACK = array(
		'USDINR' => 95.5,
		'USDJPY' => 159.0,
	);

	private const STALE_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Hook cron + admin handler + settings panel.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'fetch' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_post_hti_forex_fetch_rates', array( __CLASS__, 'handle_fetch_now' ) );
		add_action( 'hti_forex_settings_panels', array( __CLASS__, 'render_panel' ) );
	}

	/**
	 * Ensure the twice-daily event is scheduled.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::HOOK );
		}
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
		$inr = $api['rates']['INR'] ?? null;
		$jpy = $api['rates']['JPY'] ?? null;

		if ( ! is_numeric( $inr ) || ! is_numeric( $jpy ) ) {
			return $current;
		}

		$inr = (float) $inr;
		$jpy = (float) $jpy;

		if ( $inr < self::BOUNDS['USDINR'][0] || $inr > self::BOUNDS['USDINR'][1] ) {
			return $current;
		}
		if ( $jpy < self::BOUNDS['USDJPY'][0] || $jpy > self::BOUNDS['USDJPY'][1] ) {
			return $current;
		}

		$date = isset( $api['date'] ) && is_string( $api['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $api['date'] )
			? $api['date']
			: gmdate( 'Y-m-d', $now ?? time() );

		return array(
			'rates'      => array(
				'USDINR' => round( $inr, 4 ),
				'USDJPY' => round( $jpy, 4 ),
			),
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
			$out = array(
				'rates'  => array(
					'USDINR' => (float) $stored['rates']['USDINR'],
					'USDJPY' => (float) $stored['rates']['USDJPY'],
				),
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
