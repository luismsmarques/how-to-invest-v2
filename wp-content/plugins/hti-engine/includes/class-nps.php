<?php
/**
 * NPS survey (template 14): send a 0–10 survey email to verified users and
 * record their click as a response. Results (count, average, NPS) are shown in
 * the admin. Responses live in a single option; Brevo isn't involved.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * NPS survey sending, recording and reporting.
 */
class Nps {

	private const PAGE        = 'hti-nps';
	private const OPTION      = 'hti_nps_responses';
	private const META_DONE   = 'hti_nps_done';
	private const MAX_STORED  = 2000;

	/**
	 * Hook the recording link, the thank-you toast and the admin page.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'record' ) );
		add_action( 'wp_footer', array( __CLASS__, 'thanks_toast' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_hti_nps_send', array( __CLASS__, 'handle_send' ) );
	}

	/**
	 * Per-user token for the survey links.
	 *
	 * @param int $uid User id.
	 */
	private static function token( int $uid ): string {
		return substr( hash_hmac( 'sha256', $uid . '|nps', wp_salt( 'auth' ) ), 0, 40 );
	}

	/* ---------- recording ---------- */

	/**
	 * Record an NPS click: verify the token, store the score (once per user).
	 */
	public static function record(): void {
		if ( ! isset( $_GET['hti_nps'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
			return;
		}
		$uid   = isset( $_GET['u'] ) ? absint( wp_unslash( $_GET['u'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$score = isset( $_GET['score'] ) ? (int) $_GET['score'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$valid = $uid > 0 && $score >= 0 && $score <= 10 && hash_equals( self::token( $uid ), $token );
		if ( ! $valid ) {
			wp_safe_redirect( add_query_arg( 'hti_nps_thanks', 'error', home_url( '/' ) ) );
			exit;
		}

		// One response per user: overwrite their previous score if they re-click.
		$responses = get_option( self::OPTION, array() );
		$responses = is_array( $responses ) ? $responses : array();
		$responses = array_values( array_filter( $responses, static fn( $r ) => (int) ( $r['uid'] ?? 0 ) !== $uid ) );
		$responses[] = array( 'uid' => $uid, 'score' => $score, 'at' => time() );
		if ( count( $responses ) > self::MAX_STORED ) {
			$responses = array_slice( $responses, -self::MAX_STORED );
		}
		update_option( self::OPTION, $responses, false );
		update_user_meta( $uid, self::META_DONE, $score );

		wp_safe_redirect( add_query_arg( 'hti_nps_thanks', '1', home_url( '/' ) ) );
		exit;
	}

	/**
	 * Thank-you toast after a recorded vote.
	 */
	public static function thanks_toast(): void {
		$state = isset( $_GET['hti_nps_thanks'] ) ? sanitize_key( wp_unslash( $_GET['hti_nps_thanks'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $state ) {
			return;
		}
		$pt  = str_starts_with( strtolower( (string) get_locale() ), 'pt' );
		$msg = 'error' === $state
			? ( $pt ? 'Esse link já não é válido.' : 'That link is no longer valid.' )
			: ( $pt ? 'Obrigado pela tua resposta!' : 'Thanks for your feedback!' );
		$bg  = 'error' === $state ? '#C0392B' : '#147A57';
		printf(
			'<div role="status" style="position:fixed;left:50%%;bottom:24px;transform:translateX(-50%%);z-index:9999;background:%s;color:#fff;font:600 14px system-ui,Arial,sans-serif;padding:12px 20px;border-radius:999px;box-shadow:0 6px 24px rgba(0,0,0,.18);">%s</div>',
			esc_attr( $bg ),
			esc_html( $msg )
		);
	}

	/**
	 * Erase a user's stored NPS response (RGPD Art. 17). The user meta is
	 * removed with the account; this drops the entry from the global option too.
	 *
	 * @param int $uid User id.
	 */
	public static function forget( int $uid ): void {
		if ( $uid <= 0 ) {
			return;
		}
		$responses = get_option( self::OPTION, array() );
		if ( ! is_array( $responses ) ) {
			return;
		}
		$filtered = array_values( array_filter( $responses, static fn( $r ) => (int) ( $r['uid'] ?? 0 ) !== $uid ) );
		if ( count( $filtered ) !== count( $responses ) ) {
			update_option( self::OPTION, $filtered, false );
		}
	}

	/* ---------- sending ---------- */

	/**
	 * Send the survey to verified users (batched). Returns how many were sent.
	 *
	 * @param int $limit Max recipients per run.
	 */
	public static function send_survey( int $limit = 500 ): int {
		$users = get_users(
			array(
				'number' => $limit,
				'fields' => array( 'ID', 'user_email' ),
			)
		);
		$sent = 0;
		foreach ( $users as $row ) {
			$uid = (int) $row->ID;
			if ( Verification::is_unverified( $uid ) ) {
				continue;
			}
			$locale  = str_starts_with( strtolower( (string) get_user_locale( $uid ) ), 'pt' ) ? 'pt' : 'en';
			$subject = 'pt' === $locale ? 'Uma pergunta rápida — HowToInvest' : 'One quick question — HowToInvest';
			if ( Mailer::send( $row->user_email, $subject, Emails::nps( $locale, $uid, self::token( $uid ) ) ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/* ---------- results ---------- */

	/**
	 * Aggregate NPS metrics from the stored responses.
	 *
	 * @return array{count:int,avg:float,nps:int,promoters:int,passives:int,detractors:int}
	 */
	public static function results(): array {
		$responses = get_option( self::OPTION, array() );
		$responses = is_array( $responses ) ? $responses : array();
		$count     = count( $responses );
		if ( 0 === $count ) {
			return array( 'count' => 0, 'avg' => 0.0, 'nps' => 0, 'promoters' => 0, 'passives' => 0, 'detractors' => 0 );
		}
		$sum = 0;
		$pro = 0;
		$det = 0;
		foreach ( $responses as $r ) {
			$s    = (int) ( $r['score'] ?? 0 );
			$sum += $s;
			if ( $s >= 9 ) {
				++$pro;
			} elseif ( $s <= 6 ) {
				++$det;
			}
		}
		return array(
			'count'      => $count,
			'avg'        => round( $sum / $count, 1 ),
			'nps'        => (int) round( ( $pro - $det ) / $count * 100 ),
			'promoters'  => $pro,
			'passives'   => $count - $pro - $det,
			'detractors' => $det,
		);
	}

	/* ---------- admin ---------- */

	/**
	 * Register the admin page.
	 */
	public static function menu(): void {
		add_options_page( __( 'HTI NPS', 'hti-engine' ), __( 'HTI NPS', 'hti-engine' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * Render the NPS admin page: results + send button.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$r    = self::results();
		$sent = isset( $_GET['hti_nps_sent'] ) ? absint( wp_unslash( $_GET['hti_nps_sent'] ) ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HTI NPS', 'hti-engine' ); ?></h1>
			<?php if ( $sent >= 0 ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php /* translators: %d: number of emails. */ printf( esc_html__( 'Survey sent to %d people.', 'hti-engine' ), (int) $sent ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Results', 'hti-engine' ); ?></h2>
			<table class="widefat striped" style="max-width:480px">
				<tbody>
					<tr><td><strong><?php esc_html_e( 'NPS score', 'hti-engine' ); ?></strong></td><td><?php echo esc_html( (string) $r['nps'] ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Responses', 'hti-engine' ); ?></td><td><?php echo esc_html( (string) $r['count'] ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Average score', 'hti-engine' ); ?></td><td><?php echo esc_html( (string) $r['avg'] ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Promoters / Passives / Detractors', 'hti-engine' ); ?></td><td><?php echo esc_html( $r['promoters'] . ' / ' . $r['passives'] . ' / ' . $r['detractors'] ); ?></td></tr>
				</tbody>
			</table>

			<h2 style="margin-top:24px"><?php esc_html_e( 'Send survey', 'hti-engine' ); ?></h2>
			<p><?php esc_html_e( 'Emails the 0–10 survey to verified account holders, in their language.', 'hti-engine' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Send the NPS survey to all verified users?', 'hti-engine' ) ); ?>');">
				<?php wp_nonce_field( 'hti_nps_send' ); ?>
				<input type="hidden" name="action" value="hti_nps_send">
				<?php submit_button( __( 'Send NPS survey now', 'hti-engine' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the send action.
	 */
	public static function handle_send(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hti_nps_send' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-engine' ) );
		}
		$sent = self::send_survey();
		wp_safe_redirect( add_query_arg( 'hti_nps_sent', $sent, admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}
}
