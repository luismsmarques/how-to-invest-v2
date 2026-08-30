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
		$stalled    = Bot_Broadcast::stalled();
		$log        = Bot_Broadcast::log();
		$health     = $configured ? Telegram::health() : array(
			'username' => '',
			'webhook'  => array( 'ok' => false, 'url' => '', 'pending' => 0, 'error' => '', 'error_at' => 0, 'description' => '' ),
			'ours'     => false,
			'checked'  => 0,
		);
		$total      = $configured ? Bot_Store::total() : 0;
		$answered   = Bot_Store::answered();
		$notice     = isset( $_GET['hti_forex_bot'] ) ? sanitize_key( wp_unslash( $_GET['hti_forex_bot'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<h2><?php esc_html_e( 'Telegram bot', 'hti-forex' ); ?></h2>

		<?php
		// A notice drawn from the address bar is not evidence. `?hti_forex_bot=
		// queued` survives a restored tab, a back button and a pasted link, and
		// said "Broadcast queued" on every one of them — which is exactly how a
		// send that never happened was believed to have happened. The parameter
		// now only says which button was pressed; whether there is anything true
		// to report is decided by the state.
		$claims = array(
			'queued'    => Bot_Broadcast::running() || Bot_Broadcast::just_started(),
			'cancelled' => $status['finished'] > 0 && ( time() - $status['finished'] ) <= 120,
			'webhook-ok' => '' !== $health['username'],
		);
		if ( isset( $claims[ $notice ] ) && ! $claims[ $notice ] ) {
			$notice = '';
		}
		?>

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
				<tr>
					<td><?php esc_html_e( 'Bot', 'hti-forex' ); ?></td>
					<td>
						<?php if ( '' !== $health['username'] ) : ?>
							<strong>@<?php echo esc_html( $health['username'] ); ?></strong>
						<?php else : ?>
							<span style="color:#b32d2e;"><?php esc_html_e( 'Telegram does not recognise this token.', 'hti-forex' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Webhook registered with Telegram', 'hti-forex' ); ?></td>
					<td>
						<?php
						if ( ! $health['webhook']['ok'] ) {
							echo '<span style="color:#b32d2e;">' . esc_html( $health['webhook']['description'] ) . '</span>';
						} elseif ( '' === $health['webhook']['url'] ) {
							echo '<span style="color:#b32d2e;">' . esc_html__( 'None. Telegram is not sending anything here — register it below.', 'hti-forex' ) . '</span>';
						} elseif ( $health['ours'] ) {
							echo '<strong style="color:#008a20;">' . esc_html__( 'Yes, and it points here.', 'hti-forex' ) . '</strong>';
						} else {
							// One webhook per bot: whoever registered last is
							// receiving the messages this site thinks it answers.
							echo '<span style="color:#b32d2e;">' . esc_html__( 'Another site holds it:', 'hti-forex' ) . ' <code>' . esc_html( $health['webhook']['url'] ) . '</code></span>';
						}
						?>
					</td>
				</tr>
				<?php if ( $health['webhook']['ok'] ) : ?>
					<tr>
						<td><?php esc_html_e( 'Updates waiting', 'hti-forex' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $health['webhook']['pending'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Last delivery error', 'hti-forex' ); ?></td>
						<td>
							<?php
							if ( '' === $health['webhook']['error'] ) {
								esc_html_e( 'None reported.', 'hti-forex' );
							} else {
								printf(
									/* translators: 1: error text from Telegram, 2: human-readable time difference. */
									esc_html__( '%1$s — %2$s ago', 'hti-forex' ),
									'<span style="color:#b32d2e;">' . esc_html( $health['webhook']['error'] ) . '</span>',
									esc_html( human_time_diff( $health['webhook']['error_at'] ) )
								);
							}
							?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'The bot line and the webhook state come from Telegram itself, cached for five minutes. The last delivery error is the one thing this site cannot know on its own: if our endpoint fails, updates just stop arriving.', 'hti-forex' ); ?>
		</p>

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
		// Four conditions have to hold, and when the line does not appear the
		// useful question is which one is missing — so the screen answers it
		// rather than leaving someone to guess across two settings pages.
		$s     = Settings::settings();
		$demo  = (string) ( $s['bot_ad_demo_url'] ?? '' );
		$real  = (string) ( $s['bot_ad_real_url'] ?? '' );
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$checks = array(
			array(
				'label' => __( 'Global CTA switch is on', 'hti-forex' ),
				'ok'    => ! empty( $s['cta_enabled'] ),
				'hint'  => __( 'Settings above: "Affiliate CTA".', 'hti-forex' ),
			),
			array(
				'label' => __( 'Bot partner line is on', 'hti-forex' ),
				'ok'    => ! empty( $s['bot_ad_enabled'] ),
				'hint'  => __( 'Settings above: "Telegram bot: partner line".', 'hti-forex' ),
			),
			array(
				'label' => __( 'Small-account destination is valid', 'hti-forex' ),
				'ok'    => '' !== Settings::normalize_go_url( $demo ),
				'hint'  => '' === $demo
					? __( 'Empty.', 'hti-forex' )
					/* translators: 1: the URL, 2: this site's host. */
					: sprintf( __( '%1$s — must be https on %2$s.', 'hti-forex' ), $demo, $host ),
			),
			array(
				'label' => __( 'Larger-account destination is valid', 'hti-forex' ),
				'ok'    => '' !== Settings::normalize_go_url( $real ),
				'hint'  => '' === $real
					? __( 'Empty.', 'hti-forex' )
					/* translators: 1: the URL, 2: this site's host. */
					: sprintf( __( '%1$s — must be https on %2$s.', 'hti-forex' ), $real, $host ),
			),
		);

		$blocked = array_filter( $checks, static fn( array $c ): bool => ! $c['ok'] );
		?>
		<p class="description">
			<?php esc_html_e( 'One line at the foot of a calculator answer, after the arithmetic — not on /start, /help or the pip explainer. Which offer appears follows the answer: an account where the smallest position already risks more than 2% on an ordinary stop gets the demo line, and larger accounts get the live-account one. Counted separately in the funnel as telegram_bot_demo and telegram_bot_real.', 'hti-forex' ); ?>
		</p>

		<?php if ( array() === $blocked ) : ?>
			<div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Showing on answers.', 'hti-forex' ); ?></strong></p></div>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'Not showing. Everything below has to be true:', 'hti-forex' ); ?>
			</p></div>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:720px;">
			<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td style="width:26px;"><?php echo $check['ok'] ? '✅' : '⚠️'; ?></td>
						<td><?php echo esc_html( $check['label'] ); ?></td>
						<td><span class="description"><?php echo esc_html( $check['ok'] ? '' : $check['hint'] ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			printf(
				/* translators: %s: the two /go/ slugs. */
				esc_html__( 'The destinations point at our own /go/ redirector, so the slugs have to exist under Settings → outbound /go/ links — for the defaults that is %s. A slug with no destination sends the click to /forex/ instead.', 'hti-forex' ),
				'<code>xm-demo</code>, <code>open-account-xm</code>'
			);
			?>
		</p>

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

		<?php if ( array() !== $log['refused'] ) : ?>
			<?php
			$why = array(
				'no-token'         => __( 'there is no bot token', 'hti-forex' ),
				'empty'            => __( 'the message was empty', 'hti-forex' ),
				'already-running'  => __( 'another broadcast was already running', 'hti-forex' ),
				'caption-too-long' => __( 'the message was too long to send as an image caption', 'hti-forex' ),
			);
			?>
			<div class="notice notice-warning inline"><p>
				<?php
				printf(
					/* translators: 1: reason, 2: human-readable time difference. */
					esc_html__( 'The last attempt to send was refused %2$s ago because %1$s. Nothing was queued and nobody received anything.', 'hti-forex' ),
					esc_html( $why[ (string) $log['refused']['reason'] ] ?? (string) $log['refused']['reason'] ),
					esc_html( human_time_diff( (int) $log['refused']['at'] ) )
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( $running && false === wp_next_scheduled( Bot_Broadcast::HOOK ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'No batch is queued right now. Sending runs on WP-Cron, which only fires when somebody visits the site — on a quiet site a broadcast waits, and that is normal. Opening the site in another tab moves it along.', 'hti-forex' ); ?>
			</p></div>
		<?php endif; ?>

		<?php if ( $stalled ) : ?>
			<div class="notice notice-error inline"><p>
				<?php
				printf(
					/* translators: 1: sent count, 2: total recipients. */
					esc_html__( 'The last broadcast stopped before it finished: %1$s of %2$s delivered. Whatever it was in the middle of, it is not coming back — nothing more will be sent from it. You can compose a new one below; everyone it never reached will get that.', 'hti-forex' ),
					esc_html( number_format_i18n( $status['sent'] ) ),
					esc_html( number_format_i18n( $status['total'] ) )
				);
				?>
			</p></div>
		<?php endif; ?>

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
			self::render_log( $log );
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
			<p class="description">
				<strong><?php esc_html_e( 'How to know it went in:', 'hti-forex' ); ?></strong>
				<?php esc_html_e( 'this box disappears and a progress line takes its place. That is the confirmation to trust — it is drawn from the send itself. If the box is still here, nothing was queued, whatever any message at the top of the page says.', 'hti-forex' ); ?>
			</p>
		</form>

		self::render_log( $log );
		<?php
	}

	/**
	 * What already happened: past broadcasts, and sends that failed.
	 *
	 * Rendered from both branches of the panel — a broadcast in flight returns
	 * early, and that is the moment someone most wants to know whether the last
	 * one worked.
	 *
	 * @param array<string,mixed> $log Bot_Broadcast::log().
	 */
	private static function render_log( array $log ): void {
		?>
		<h3><?php esc_html_e( 'Broadcasts already sent', 'hti-forex' ); ?></h3>

		<?php if ( array() === $log['history'] ) : ?>
			<p class="description">
				<?php esc_html_e( 'None yet. A broadcast appears here once it ends, whether it reached everyone or stopped early. An empty table is worth as much as a full one: it means nothing has gone out, which is not something a screen that draws nothing can tell you.', 'hti-forex' ); ?>
			</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:860px;">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'hti-forex' ); ?></th>
					<th><?php esc_html_e( 'Message', 'hti-forex' ); ?></th>
					<th style="text-align:right;"><?php esc_html_e( 'Delivered', 'hti-forex' ); ?></th>
					<th><?php esc_html_e( 'How it ended', 'hti-forex' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$endings = array(
					'finished'  => __( 'reached everyone', 'hti-forex' ),
					'cancelled' => __( 'stopped by hand', 'hti-forex' ),
					'stalled'   => __( 'died part-way', 'hti-forex' ),
				);
				foreach ( $log['history'] as $row ) :
					?>
					<tr>
						<td>
							<?php
							printf(
								/* translators: %s: human-readable time difference. */
								esc_html__( '%s ago', 'hti-forex' ),
								esc_html( human_time_diff( (int) $row['ended'] ) )
							);
							?>
						</td>
						<td>
							<?php echo esc_html( (string) $row['excerpt'] ); ?>
							<?php if ( '' !== (string) $row['image'] ) : ?>
								<code><?php echo esc_html( (string) $row['image'] ); ?></code>
							<?php endif; ?>
						</td>
						<td style="text-align:right;font-variant-numeric:tabular-nums;">
							<?php
							printf(
								/* translators: 1: delivered count, 2: recipients at the time. */
								esc_html__( '%1$s of %2$s', 'hti-forex' ),
								esc_html( number_format_i18n( (int) $row['sent'] ) ),
								esc_html( number_format_i18n( (int) $row['total'] ) )
							);
							?>
						</td>
						<td><?php echo esc_html( $endings[ (string) $row['how'] ] ?? (string) $row['how'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'The last ten. Without this the screen showed one broadcast at a time, so a message sent today and a test sent last night looked identical.', 'hti-forex' ); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Sends that failed', 'hti-forex' ); ?></h3>

		<?php if ( array() === $log['errors'] ) : ?>
			<p class="description">
				<?php esc_html_e( 'None recorded. Recipients who blocked the bot are not errors and never appear here — they are removed as a send goes.', 'hti-forex' ); ?>
			</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:860px;">
				<tbody>
				<?php // Position means recency in the log, so the newest reads first here. ?>
				<?php foreach ( array_reverse( $log['errors'], true ) as $err ) : ?>
					<tr>
						<td style="width:80px;"><code><?php echo esc_html( (string) $err['code'] ); ?></code></td>
						<td><?php echo esc_html( (string) $err['description'] ); ?></td>
						<td style="width:120px;text-align:right;">
							<?php
							printf(
								/* translators: %s: number of times this error happened. */
								esc_html( _n( '%s time', '%s times', (int) $err['count'], 'hti-forex' ) ),
								esc_html( number_format_i18n( (int) $err['count'] ) )
							);
							?>
						</td>
						<td style="width:140px;text-align:right;">
							<?php
							printf(
								/* translators: %s: human-readable time difference. */
								esc_html__( '%s ago', 'hti-forex' ),
								esc_html( human_time_diff( (int) $err['at'] ) )
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Recipients who blocked the bot are not errors and are not listed — they are removed as the send goes. These are the rest: a rejected tag, a revoked token, Telegram refusing. They used to leave no trace at all.', 'hti-forex' ); ?></p>
		<?php endif; ?>
		<?php
	}
}
