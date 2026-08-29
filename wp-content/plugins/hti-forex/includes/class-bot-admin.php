<?php
/**
 * The bot's screen inside Settings → HTI Forex.
 *
 * Three jobs: say whether the bot is wired up, show what the crowd of users
 * looks like, and let an admin write one message to all of them. The send is
 * deliberately awkward — a preview and an explicit confirmation — because it
 * is irreversible and lands in a private inbox.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Bot admin panel and its handlers.
 */
class Bot_Admin {

	/**
	 * Nag when the last broadcast was more recent than this. Not a lock —
	 * an admin can still send — but frequency is what kills a bot list, and
	 * the number deserves to be in front of you when you decide.
	 */
	private const QUIET_DAYS = 3;

	/**
	 * Hook the panel and the two admin-post handlers.
	 */
	public static function init(): void {
		add_action( 'hti_forex_settings_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'admin_post_hti_forex_bot_webhook', array( __CLASS__, 'handle_webhook' ) );
		add_action( 'admin_post_hti_forex_bot_broadcast', array( __CLASS__, 'handle_broadcast' ) );
	}

	/**
	 * Register (or remove) the webhook.
	 */
	public static function handle_webhook(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-forex' ) );
		}
		check_admin_referer( 'hti_forex_bot_webhook' );

		$remove = isset( $_POST['remove'] );
		$result = $remove
			? array( 'ok' => Telegram::remove_webhook() )
			: Telegram::register_webhook();

		wp_safe_redirect(
			add_query_arg(
				'hti_forex_bot',
				$result['ok'] ? 'webhook-ok' : 'webhook-fail',
				admin_url( 'options-general.php?page=hti-forex' )
			)
		);
		exit;
	}

	/**
	 * Queue a broadcast.
	 */
	public static function handle_broadcast(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-forex' ) );
		}
		check_admin_referer( 'hti_forex_bot_broadcast' );

		if ( isset( $_POST['cancel'] ) ) {
			Bot_Broadcast::cancel();
			wp_safe_redirect( add_query_arg( 'hti_forex_bot', 'cancelled', admin_url( 'options-general.php?page=hti-forex' ) ) );
			exit;
		}

		// Telegram's HTML mode accepts a small tag set; anything else would be
		// rejected by the API for every single recipient, so it is stripped
		// here rather than discovered one failed send at a time.
		$raw  = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '';
		$text = wp_kses(
			(string) $raw,
			array(
				'b'    => array(),
				'i'    => array(),
				'u'    => array(),
				's'    => array(),
				'code' => array(),
				'pre'  => array(),
				'a'    => array( 'href' => array() ),
			)
		);

		$image = isset( $_POST['image'] ) ? sanitize_key( wp_unslash( $_POST['image'] ) ) : '';
		$image = isset( Bot_Images::files()[ $image ] ) ? $image : '';
		$text  = trim( $text );

		if ( '' !== $image && ! Bot_Broadcast::fits_caption( $text, $image ) ) {
			wp_safe_redirect( add_query_arg( 'hti_forex_bot', 'too-long', admin_url( 'options-general.php?page=hti-forex' ) ) );
			exit;
		}

		$queued = Bot_Broadcast::start( $text, $image );

		wp_safe_redirect(
			add_query_arg(
				'hti_forex_bot',
				$queued ? 'queued' : 'queue-fail',
				admin_url( 'options-general.php?page=hti-forex' )
			)
		);
		exit;
	}

	/**
	 * The panel.
	 */
	public static function render_panel(): void {
		$configured = Telegram::configured();
		$status     = Bot_Broadcast::status();
		$running    = Bot_Broadcast::running();
		$total      = $configured ? Bot_Store::total() : 0;
		$answered   = Bot_Store::answered();
		$notice     = isset( $_GET['hti_forex_bot'] ) ? sanitize_key( wp_unslash( $_GET['hti_forex_bot'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<h2><?php esc_html_e( 'Telegram bot', 'hti-forex' ); ?></h2>

		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-<?php echo in_array( $notice, array( 'webhook-ok', 'queued', 'cancelled' ), true ) ? 'success' : 'warning'; ?>"><p>
				<?php
				$messages = array(
					'webhook-ok'   => __( 'Webhook registered — Telegram will now deliver updates here.', 'hti-forex' ),
					'webhook-fail' => __( 'Telegram would not accept the webhook. Check the token and that the site is reachable over HTTPS.', 'hti-forex' ),
					'queued'       => __( 'Broadcast queued. It sends in batches and continues even if you close this page.', 'hti-forex' ),
					'queue-fail'   => __( 'Nothing queued — the message was empty, a broadcast is already running, or there is no bot token.', 'hti-forex' ),
					'cancelled'    => __( 'Broadcast stopped where it was.', 'hti-forex' ),
					'too-long'     => __( 'Too long to send with an image attached. A caption is capped at 1,024 characters including the /stop footer — shorten it, or send it without the image.', 'hti-forex' ),
				);
				echo esc_html( $messages[ $notice ] ?? '' );
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( ! $configured ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				printf(
					/* translators: %s: the wp-config.php constant name. */
					esc_html__( 'No bot token. Create a bot with @BotFather, then add %s to wp-config.php. The token never goes in the database — anyone holding it can message every user the bot has.', 'hti-forex' ),
					'<code>define( \'HTI_TELEGRAM_BOT_TOKEN\', \'...\' );</code>'
				);
				?>
			</p></div>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped" style="max-width:640px;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'People the bot can reach', 'hti-forex' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Webhook URL', 'hti-forex' ); ?></td>
					<td><code><?php echo esc_html( Telegram::webhook_url() ); ?></code></td>
				</tr>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0;">
			<?php wp_nonce_field( 'hti_forex_bot_webhook' ); ?>
			<input type="hidden" name="action" value="hti_forex_bot_webhook" />
			<button type="submit" class="button"><?php esc_html_e( 'Register webhook', 'hti-forex' ); ?></button>
			<button type="submit" name="remove" value="1" class="button-link-delete button-link"><?php esc_html_e( 'Remove', 'hti-forex' ); ?></button>
			<p class="description"><?php esc_html_e( 'Register once, and again whenever the site URL changes. Never point a live bot at staging — Telegram allows one webhook per bot, so staging would silently take over the real one. Use a second test bot instead.', 'hti-forex' ); ?></p>
		</form>

		<h3><?php esc_html_e( 'Where they came from', 'hti-forex' ); ?></h3>
		<?php $sources = Bot_Store::sources(); ?>
		<?php if ( array() === $sources ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: an example deep link. */
					esc_html__( 'Nothing yet. Put a code on the link an ad or a post uses — %s — and Telegram hands it to the bot on /start. Each new person is counted once against the code that brought them, so a campaign test says which creative paid rather than just how many arrived.', 'hti-forex' ),
					'<code>t.me/YourBot?start=px_a1</code>'
				);
				?>
			</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<?php foreach ( $sources as $code => $count ) : ?>
						<tr>
							<td><code><?php echo esc_html( $code ); ?></code></td>
							<td style="width:90px;text-align:right;"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'New people only — someone opening the same link twice counts once. People who arrive without a code are not listed.', 'hti-forex' ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'The partner line', 'hti-forex' ); ?></h3>
		<?php
		$s     = Settings::settings();
		$on    = ! empty( $s['cta_enabled'] ) && ! empty( $s['bot_ad_enabled'] );
		$demo  = (string) ( $s['bot_ad_demo_url'] ?? '' );
		$real  = (string) ( $s['bot_ad_real_url'] ?? '' );
		?>
		<p class="description">
			<?php esc_html_e( 'One line at the foot of an answer, after the arithmetic. Which offer appears follows the answer itself: an account where the smallest position already risks more than 2% on an ordinary stop gets the demo line — the only offer that does not argue with the warning printed above it — and larger accounts get the live-account line. The two are counted separately in the funnel as telegram_bot_demo and telegram_bot_real, so you can see which one earns its place. Edit the wording and the destinations in the main settings above; both must be links on this site (the /go/ redirector).', 'hti-forex' ); ?>
		</p>
		<table class="widefat striped" style="max-width:640px;">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Showing', 'hti-forex' ); ?></td>
					<td><strong><?php echo $on ? esc_html__( 'yes', 'hti-forex' ) : esc_html__( 'no — switched off', 'hti-forex' ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Small accounts →', 'hti-forex' ); ?></td>
					<td><?php echo '' === $demo ? '<em>' . esc_html__( 'not set', 'hti-forex' ) . '</em>' : '<code>' . esc_html( $demo ) . '</code>'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Larger accounts →', 'hti-forex' ); ?></td>
					<td><?php echo '' === $real ? '<em>' . esc_html__( 'not set', 'hti-forex' ) . '</em>' : '<code>' . esc_html( $real ) . '</code>'; ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'What the audience looks like', 'hti-forex' ); ?></h3>
		<?php if ( 0 === $answered ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing yet. Every balance someone sends is counted into a band here — counts only, never linked to a person — so after a couple of weeks this says whether these are small accounts or large ones.', 'hti-forex' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:640px;">
				<tbody>
					<?php foreach ( Bot_Store::distribution() as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td style="width:80px;text-align:right;"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
							<td style="width:70px;text-align:right;"><?php echo esc_html( number_format( $row['share'], 1 ) ); ?>%</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of answers counted. */
					esc_html__( '%s balances counted. Aggregate only — no balance is stored against anyone.', 'hti-forex' ),
					esc_html( number_format_i18n( $answered ) )
				);
				?>
			</p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Send a message to everyone', 'hti-forex' ); ?></h3>

		<?php if ( $running ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: sent count, 2: total recipients, 3: dropped count. */
					esc_html__( 'Sending: %1$s of %2$s delivered, %3$s dropped (blocked the bot).', 'hti-forex' ),
					esc_html( number_format_i18n( $status['sent'] ) ),
					esc_html( number_format_i18n( $status['total'] ) ),
					esc_html( number_format_i18n( $status['dropped'] ) )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'hti_forex_bot_broadcast' ); ?>
				<input type="hidden" name="action" value="hti_forex_bot_broadcast" />
				<button type="submit" name="cancel" value="1" class="button"><?php esc_html_e( 'Stop this broadcast', 'hti-forex' ); ?></button>
			</form>
			<?php
			return;
		endif;

		if ( $status['finished'] > 0 ) {
			$ago = time() - $status['finished'];
			?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: human-readable time difference, 2: sent count, 3: dropped count. */
					esc_html__( 'Last broadcast %1$s ago: %2$s delivered, %3$s dropped.', 'hti-forex' ),
					esc_html( human_time_diff( $status['finished'] ) ),
					esc_html( number_format_i18n( $status['sent'] ) ),
					esc_html( number_format_i18n( $status['dropped'] ) )
				);
				?>
			</p>
			<?php if ( $ago < self::QUIET_DAYS * DAY_IN_SECONDS ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'That was recent. Frequency is what makes people block a bot, and a blocked user is gone for good — there is no re-subscribing.', 'hti-forex' ); ?>
				</p></div>
				<?php
			endif;
		}
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Send this to every person who has used the bot? This cannot be undone.', 'hti-forex' ) ) ); ?> );">
			<?php wp_nonce_field( 'hti_forex_bot_broadcast' ); ?>
			<input type="hidden" name="action" value="hti_forex_bot_broadcast" />
			<textarea name="message" rows="6" class="large-text code" placeholder="<?php esc_attr_e( 'Plain text. &lt;b&gt;, &lt;i&gt;, &lt;code&gt; and links are allowed; everything else is stripped.', 'hti-forex' ); ?>"></textarea>
			<p>
				<label for="hti-bot-image"><strong><?php esc_html_e( 'Attach an image', 'hti-forex' ); ?></strong></label>
				<select name="image" id="hti-bot-image">
					<option value=""><?php esc_html_e( 'None — text only', 'hti-forex' ); ?></option>
					<?php foreach ( array_keys( Bot_Images::files() ) as $slug ) : ?>
						<?php if ( Bot_Images::exists( $slug ) ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $slug ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php printf( /* translators: %d: caption character limit. */ esc_html__( 'With an image the whole message becomes a caption, capped at %d characters including the /stop footer.', 'hti-forex' ), (int) Telegram::CAPTION_MAX ); ?></span>
			</p>
			<p>
				<button type="submit" class="button button-primary">
					<?php
					printf(
						/* translators: %s: recipient count. */
						esc_html__( 'Send to %s people', 'hti-forex' ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</button>
			</p>
			<p class="description"><?php esc_html_e( 'Goes out in batches over a few minutes and continues after you close the page. Anyone who has blocked the bot is removed automatically as it goes.', 'hti-forex' ); ?></p>
		</form>
		<?php
	}
}
