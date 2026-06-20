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
	 * Countable events (anything else is ignored).
	 *
	 * @return array<int,string>
	 */
	public static function events(): array {
		return array(
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
			'contact_submit',
			'account_delete_request',
			'cta_click',
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

		if ( 'quiz_step_complete' === $event && isset( $params['step_index'] ) ) {
			$s = max( 0, (int) $params['step_index'] );
			$data[ $day ]['step'][ $s ] = ( $data[ $day ]['step'][ $s ] ?? 0 ) + 1;
		}
		if ( 'result_view' === $event && isset( $params['archetype'] ) ) {
			$a = (int) $params['archetype'];
			$data[ $day ]['arch'][ $a ] = ( $data[ $day ]['arch'][ $a ] ?? 0 ) + 1;
		}
		if ( 'cta_click' === $event && isset( $params['location'] ) ) {
			$loc = (string) $params['location'];
			$data[ $day ]['cta'][ $loc ] = ( $data[ $day ]['cta'][ $loc ] ?? 0 ) + 1;
		}

		// Keep only the most recent KEEP_DAYS days.
		if ( count( $data ) > self::KEEP_DAYS ) {
			ksort( $data );
			$data = array_slice( $data, -self::KEEP_DAYS, null, true );
		}

		update_option( self::OPTION, $data, false );
	}

	/**
	 * Aggregate the counters over the last $days days.
	 *
	 * @param int $days Window size in days.
	 * @return array{e:array<string,int>,step:array<int,int>,arch:array<int,int>,cta:array<string,int>}
	 */
	public static function totals( int $days ): array {
		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$cutoff = gmdate( 'Y-m-d', time() - ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS );

		$out = array(
			'e'    => array(),
			'step' => array(),
			'arch' => array(),
			'cta'  => array(),
		);
		foreach ( $data as $day => $buckets ) {
			if ( (string) $day < $cutoff ) {
				continue;
			}
			foreach ( array( 'e', 'step', 'arch', 'cta' ) as $group ) {
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

		$t    = self::totals( $days );
		$e    = $t['e'];
		$base = admin_url( 'options-general.php?page=hti-funnel' );

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
					<?php if ( $d === $days ) : ?>
						<strong style="margin:0 .4em;"><?php echo esc_html( sprintf( '%d days', $d ) ); ?></strong>
					<?php else : ?>
						<a style="margin:0 .4em;" href="<?php echo esc_url( add_query_arg( 'days', $d, $base ) ); ?>"><?php echo esc_html( sprintf( '%d days', $d ) ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
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

			<p style="margin-top:1.5em;color:#646970;font-size:12px;">
				<?php esc_html_e( 'Counts come from anonymous first-party beacons; visitors who block scripts are not counted (same as any client-side analytics). Approximate under heavy concurrent traffic.', 'hti-engine' ); ?>
			</p>
		</div>
		<?php
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
