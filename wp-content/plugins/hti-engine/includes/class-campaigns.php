<?php
/**
 * Marketing campaigns: weekly newsletter + daily digest (from the `news` CPT)
 * and a manual "platform notice" broadcast. Sent to the Brevo list via the
 * Campaigns API; scheduled by WP-Cron. Admin UI under Settings → HTI Newsletter.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends the newsletter/digest/notice campaigns.
 */
class Campaigns {

	private const PAGE        = 'hti-newsletter';
	public const WEEKLY_HOOK  = 'hti_weekly_newsletter';
	public const DAILY_HOOK   = 'hti_daily_digest';

	/**
	 * Hook cron, the admin page and the admin-post handlers.
	 */
	public static function init(): void {
		add_action( self::WEEKLY_HOOK, array( __CLASS__, 'send_weekly' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'send_daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_hti_campaign', array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Ensure the recurring events exist.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::WEEKLY_HOOK ) ) {
			wp_schedule_event( strtotime( 'next monday 9:00' ), 'weekly', self::WEEKLY_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 7:00' ), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Clear the scheduled events (called on deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	/**
	 * The language newsletters are sent in (Polylang default, else site locale).
	 */
	private static function locale(): string {
		if ( function_exists( 'pll_default_language' ) ) {
			$slug = (string) pll_default_language( 'slug' );
			if ( '' !== $slug ) {
				return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt' : 'en';
			}
		}
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Latest published `news` posts within a window, in the campaign language.
	 *
	 * @param string $locale Language.
	 * @param int    $days   Look-back window in days.
	 * @param int    $max    Max items.
	 * @return array<int,array{title:string,url:string,excerpt:string}>
	 */
	private static function recent_news( string $locale, int $days, int $max ): array {
		$args = array(
			'post_type'      => 'news',
			'post_status'    => 'publish',
			'posts_per_page' => $max,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => array( array( 'after' => $days . ' days ago' ) ),
			'no_found_rows'  => true,
		);
		if ( function_exists( 'pll_default_language' ) ) {
			$args['lang'] = $locale;
		}
		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'title'   => (string) get_the_title( $post ),
				'url'     => (string) get_permalink( $post ),
				'excerpt' => wp_strip_all_tags( (string) get_the_excerpt( $post ) ),
			);
		}
		wp_reset_postdata();
		return $items;
	}

	/**
	 * Build a newsletter/digest campaign for the given window.
	 *
	 * @param string $locale Language.
	 * @param string $kind   'weekly' | 'daily'.
	 * @return array{subject:string,html:string,count:int}
	 */
	public static function build( string $locale, string $kind ): array {
		$pt    = 'pt' === $locale;
		$daily = 'daily' === $kind;
		$items = self::recent_news( $locale, $daily ? 1 : 7, $daily ? 8 : 10 );
		$cta   = function_exists( 'get_post_type_archive_link' ) ? (string) get_post_type_archive_link( 'news' ) : home_url( '/financial-news/' );

		if ( $daily ) {
			$subject = $pt ? 'O teu resumo diário — HowToInvest' : 'Your daily roundup — HowToInvest';
			$title   = $pt ? 'O resumo de hoje' : 'Today’s roundup';
			$intro   = $pt ? 'As leituras do dia sobre os mercados, sem jargão.' : 'Today’s reads on the markets, jargon-free.';
		} else {
			$subject = $pt ? 'A tua newsletter semanal — HowToInvest' : 'Your weekly newsletter — HowToInvest';
			$title   = $pt ? 'Esta semana na HowToInvest' : 'This week at HowToInvest';
			$intro   = $pt ? 'Uma seleção do que aconteceu nos mercados, sem jargão.' : 'A roundup of what happened in the markets, jargon-free.';
		}

		return array(
			'subject' => $subject,
			'html'    => Emails::campaign( $locale, $title, $intro, $items, $cta ),
			'count'   => count( $items ),
		);
	}

	/**
	 * Send the weekly newsletter (cron + manual). No-op without articles/list.
	 */
	public static function send_weekly(): bool {
		return self::dispatch( 'weekly' );
	}

	/**
	 * Send the daily digest (cron + manual). No-op without articles/list.
	 */
	public static function send_daily(): bool {
		return self::dispatch( 'daily' );
	}

	/**
	 * Build + send a newsletter/digest. Skips when there's nothing to send.
	 *
	 * @param string $kind 'weekly' | 'daily'.
	 */
	private static function dispatch( string $kind ): bool {
		$list = Brevo::list_id();
		if ( ! Brevo::configured() || $list <= 0 ) {
			return false;
		}
		$locale   = self::locale();
		$campaign = self::build( $locale, $kind );
		if ( $campaign['count'] < 1 ) {
			return false; // Nothing new — don't send an empty email.
		}
		$name = sprintf( 'HTI %s %s', $kind, gmdate( 'Y-m-d' ) );
		return Brevo::send_campaign( $name, $campaign['subject'], $campaign['html'], $list );
	}

	/**
	 * Send a manual platform notice to the list.
	 *
	 * @param string $subject Subject.
	 * @param string $title   Heading.
	 * @param string $body    Body (raw text from the admin form).
	 */
	public static function send_notice( string $subject, string $title, string $body ): bool {
		$list = Brevo::list_id();
		if ( ! Brevo::configured() || $list <= 0 ) {
			return false;
		}
		$body_html = wpautop( wp_kses_post( $body ) );
		$html      = Emails::notice( self::locale(), $title, $body_html );
		return Brevo::send_campaign( 'HTI notice ' . gmdate( 'Y-m-d H:i' ), $subject, $html, $list );
	}

	/* ---------- admin ---------- */

	/**
	 * Register the admin page under Settings.
	 */
	public static function menu(): void {
		add_options_page(
			__( 'HTI Newsletter', 'hti-engine' ),
			__( 'HTI Newsletter', 'hti-engine' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the admin page: status, send-now buttons, preview links, notice form.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$list      = Brevo::list_id();
		$ready     = Brevo::configured() && $list > 0;
		$preview_w = wp_nonce_url( admin_url( 'admin-post.php?action=hti_campaign&do=preview&kind=weekly' ), 'hti_campaign' );
		$preview_d = wp_nonce_url( admin_url( 'admin-post.php?action=hti_campaign&do=preview&kind=daily' ), 'hti_campaign' );
		$sent      = isset( $_GET['hti_sent'] ) ? sanitize_key( wp_unslash( $_GET['hti_sent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HTI Newsletter', 'hti-engine' ); ?></h1>
			<?php if ( '' !== $sent ) : ?>
				<div class="notice notice-<?php echo 'ok' === $sent ? 'success' : 'error'; ?> is-dismissible"><p>
					<?php echo 'ok' === $sent ? esc_html__( 'Campaign sent.', 'hti-engine' ) : esc_html__( 'Could not send — check the Brevo key, sender and list ID (or there were no new articles).', 'hti-engine' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! $ready ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Set the Brevo API key, a verified sender and the Newsletter list ID in Settings → HowToInvest before sending.', 'hti-engine' ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Newsletter & digest', 'hti-engine' ); ?></h2>
			<p><?php esc_html_e( 'Sent automatically (weekly on Mondays, daily each morning) to the Brevo list. You can also send now or preview:', 'hti-engine' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( $preview_w ); ?>" target="_blank"><?php esc_html_e( 'Preview weekly', 'hti-engine' ); ?></a>
				<a class="button" href="<?php echo esc_url( $preview_d ); ?>" target="_blank"><?php esc_html_e( 'Preview daily', 'hti-engine' ); ?></a>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'hti_campaign' ); ?>
				<input type="hidden" name="action" value="hti_campaign">
				<input type="hidden" name="do" value="send">
				<input type="hidden" name="kind" value="weekly">
				<?php submit_button( __( 'Send weekly now', 'hti-engine' ), 'primary', 'submit', false, $ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px">
				<?php wp_nonce_field( 'hti_campaign' ); ?>
				<input type="hidden" name="action" value="hti_campaign">
				<input type="hidden" name="do" value="send">
				<input type="hidden" name="kind" value="daily">
				<?php submit_button( __( 'Send daily now', 'hti-engine' ), 'secondary', 'submit', false, $ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<hr style="margin:28px 0">

			<h2><?php esc_html_e( 'Platform notice', 'hti-engine' ); ?></h2>
			<p><?php esc_html_e( 'A one-off message to all subscribers (maintenance, news).', 'hti-engine' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'hti_campaign' ); ?>
				<input type="hidden" name="action" value="hti_campaign">
				<input type="hidden" name="do" value="notice">
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><label for="hti-notice-subject"><?php esc_html_e( 'Subject', 'hti-engine' ); ?></label></th>
						<td><input name="subject" id="hti-notice-subject" type="text" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-notice-title"><?php esc_html_e( 'Heading', 'hti-engine' ); ?></label></th>
						<td><input name="title" id="hti-notice-title" type="text" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="hti-notice-body"><?php esc_html_e( 'Message', 'hti-engine' ); ?></label></th>
						<td><textarea name="body" id="hti-notice-body" rows="6" class="large-text" required></textarea></td>
					</tr>
				</tbody></table>
				<?php submit_button( __( 'Send notice to subscribers', 'hti-engine' ), 'primary', 'submit', true, $ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle send/preview/notice admin-post actions.
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hti_campaign' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-engine' ) );
		}
		$do   = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		$kind = isset( $_REQUEST['kind'] ) && 'daily' === $_REQUEST['kind'] ? 'daily' : 'weekly';

		if ( 'preview' === $do ) {
			$campaign = self::build( self::locale(), $kind );
			// Admin-only HTML preview (escaped at build time; trusted template).
			echo $campaign['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if ( 'notice' === $do ) {
			$ok = self::send_notice(
				sanitize_text_field( (string) wp_unslash( $_POST['subject'] ?? '' ) ),
				sanitize_text_field( (string) wp_unslash( $_POST['title'] ?? '' ) ),
				(string) wp_unslash( $_POST['body'] ?? '' )
			);
		} else {
			$ok = 'daily' === $kind ? self::send_daily() : self::send_weekly();
		}

		wp_safe_redirect( add_query_arg( 'hti_sent', $ok ? 'ok' : 'fail', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}
}
