<?php
/**
 * Telegram Bot API client.
 *
 * Thin on purpose: one call() that talks to the API, and one send() that
 * turns the two failures that actually matter into outcomes the callers can
 * act on — the user blocked us (403, so drop them) and we are going too fast
 * (429, so wait the number of seconds Telegram names).
 *
 * The bot token lives in wp-config.php as HTI_TELEGRAM_BOT_TOKEN, never in
 * the options table — the same rule the Gemini key follows. Anyone with the
 * token can impersonate the bot to every user who ever started it.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Bot API transport.
 */
class Telegram {

	/**
	 * Where the webhook secret lives. Not a credential the admin ever needs
	 * to read — it exists so we can prove an inbound request came from
	 * Telegram and not from someone who guessed the endpoint.
	 */
	public const OPTION_SECRET = 'hti_forex_bot_secret';

	/**
	 * Telegram's free broadcast ceiling. Paid broadcasting lifts it to 1000/s
	 * but requires 100,000 Stars on the bot balance and 100,000 monthly
	 * active users, so it is not a thing that will ever apply here.
	 */
	public const RATE_PER_SECOND = 30;

	/**
	 * Telegram's caption limit. A message is 4096 characters; a photo caption
	 * is a quarter of that, and going over fails the send for every recipient
	 * rather than truncating.
	 */
	public const CAPTION_MAX = 1024;

	/**
	 * Where the cached health check lives, and how long it stands.
	 */
	public const TRANSIENT_HEALTH = 'hti_forex_bot_health';

	/**
	 * Five minutes: long enough that reloading the panel is free, short enough
	 * that someone fixing a webhook sees it fixed without wondering.
	 */
	public const HEALTH_TTL = 300;

	/**
	 * The bot token, or '' when the site has not been given one.
	 */
	public static function token(): string {
		return defined( 'HTI_TELEGRAM_BOT_TOKEN' ) ? trim( (string) HTI_TELEGRAM_BOT_TOKEN ) : '';
	}

	/**
	 * Whether the bot can talk at all.
	 */
	public static function configured(): bool {
		return '' !== self::token();
	}

	/**
	 * The webhook secret, generated once and then stable.
	 */
	public static function secret(): string {
		$secret = (string) get_option( self::OPTION_SECRET, '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 48, false, false );
			update_option( self::OPTION_SECRET, $secret, false );
		}
		return $secret;
	}

	/**
	 * The URL Telegram should post updates to.
	 */
	public static function webhook_url(): string {
		return rest_url( 'htinvest/v1/forex/telegram' );
	}

	/**
	 * Call a Bot API method.
	 *
	 * @param string              $method Bot API method name.
	 * @param array<string,mixed> $args   Arguments.
	 * @return array{ok:bool,result:mixed,error_code:int,description:string,retry_after:int}
	 */
	public static function call( string $method, array $args = array() ): array {
		$fail = array(
			'ok'          => false,
			'result'      => null,
			'error_code'  => 0,
			'description' => '',
			'retry_after' => 0,
		);

		if ( ! self::configured() ) {
			$fail['description'] = 'No bot token configured.';
			return $fail;
		}

		$response = wp_remote_post(
			'https://api.telegram.org/bot' . self::token() . '/' . $method,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $args ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$fail['description'] = $response->get_error_message();
			return $fail;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$fail['error_code']  = (int) wp_remote_retrieve_response_code( $response );
			$fail['description'] = 'Unreadable response.';
			return $fail;
		}

		return array(
			'ok'          => ! empty( $body['ok'] ),
			'result'      => $body['result'] ?? null,
			'error_code'  => (int) ( $body['error_code'] ?? 0 ),
			'description' => (string) ( $body['description'] ?? '' ),
			'retry_after' => (int) ( $body['parameters']['retry_after'] ?? 0 ),
		);
	}

	/**
	 * Send a message.
	 *
	 * @param int                      $chat_id  Recipient.
	 * @param string                   $text     Message body (HTML parse mode).
	 * @param array<int,array<int,array<string,string>>>|null $keyboard Inline keyboard rows.
	 * @return array{status:string,retry_after:int} status: sent|blocked|slow_down|failed.
	 */
	public static function send( int $chat_id, string $text, ?array $keyboard = null ): array {
		$args = array(
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		);

		if ( null !== $keyboard ) {
			$args['reply_markup'] = array( 'inline_keyboard' => $keyboard );
		}

		$result = self::call( 'sendMessage', $args );

		if ( $result['ok'] ) {
			return array(
				'status'      => 'sent',
				'retry_after' => 0,
				'code'        => 0,
				'description' => '',
			);
		}

		return self::outcome( $result );
	}

	/**
	 * Turn an API failure into the two outcomes a caller can act on: the user
	 * is gone, or we are going too fast. Shared by send() and send_photo() so
	 * a photo that fails is handled exactly like a message that fails.
	 *
	 * The API's own code and wording ride along: `status` is what a caller
	 * acts on, but "failed" with nothing attached is unloggable, and a send
	 * that fails silently is the hardest kind of bug to be told about.
	 *
	 * @param array<string,mixed> $result Result from call().
	 * @return array{status:string,retry_after:int,code:int,description:string}
	 */
	private static function outcome( array $result ): array {
		// 403 is "bot was blocked by the user" or "chat not found" — either
		// way that chat id is dead and keeping it only wastes future sends.
		if ( 403 === $result['error_code'] || 400 === $result['error_code'] ) {
			return array(
				'status'      => 'blocked',
				'retry_after' => 0,
				'code'        => (int) $result['error_code'],
				'description' => (string) $result['description'],
			);
		}

		if ( 429 === $result['error_code'] ) {
			return array(
				'status'      => 'slow_down',
				'retry_after' => max( 1, (int) $result['retry_after'] ),
				'code'        => (int) $result['error_code'],
				'description' => (string) $result['description'],
			);
		}

		return array(
			'status'      => 'failed',
			'retry_after' => 0,
			'code'        => (int) $result['error_code'],
			'description' => (string) $result['description'],
		);
	}

	/**
	 * Send a photo with a caption.
	 *
	 * `$photo` is either a public URL (Telegram fetches it once) or a file_id
	 * it gave us earlier — see Bot_Images. On success the caller gets the
	 * file_id back so it can stop sending the URL.
	 *
	 * @param int                      $chat_id  Recipient.
	 * @param string                   $photo    URL or file_id.
	 * @param string                   $caption  Caption (HTML parse mode).
	 * @param array<int,array<int,array<string,string>>>|null $keyboard Inline keyboard rows.
	 * @return array{status:string,retry_after:int,file_id:string} status: sent|blocked|slow_down|failed.
	 */
	public static function send_photo( int $chat_id, string $photo, string $caption = '', ?array $keyboard = null ): array {
		$args = array(
			'chat_id'    => $chat_id,
			'photo'      => $photo,
			'parse_mode' => 'HTML',
		);

		if ( '' !== $caption ) {
			$args['caption'] = $caption;
		}
		if ( null !== $keyboard ) {
			$args['reply_markup'] = array( 'inline_keyboard' => $keyboard );
		}

		$result = self::call( 'sendPhoto', $args );

		if ( $result['ok'] ) {
			return array(
				'status'      => 'sent',
				'retry_after' => 0,
				'code'        => 0,
				'description' => '',
				'file_id'     => Bot_Images::file_id_from( $result['result'] ),
			);
		}

		$out = self::outcome( $result );
		$out['file_id'] = '';

		return $out;
	}

	/**
	 * Point Telegram at this site.
	 *
	 * @return array{ok:bool,description:string}
	 */
	public static function register_webhook(): array {
		$result = self::call(
			'setWebhook',
			array(
				'url'             => self::webhook_url(),
				'secret_token'    => self::secret(),
				'allowed_updates' => array( 'message', 'callback_query' ),
				// Shared hosting caps how many PHP processes may run at once,
				// and each update holds one for as long as the answer takes.
				// Ten of those at a time is how a bot takes a site down with
				// it; Telegram queues the rest rather than dropping them.
				'max_connections' => 3,
			)
		);

		// Whoever just changed the webhook is about to look at the screen that
		// reports it. A five-minute cache would show them the old answer and
		// leave them wondering whether it worked.
		delete_transient( self::TRANSIENT_HEALTH );

		return array(
			'ok'          => $result['ok'],
			'description' => $result['ok'] ? 'Webhook registered.' : $result['description'],
		);
	}

	/**
	 * Stop Telegram sending updates here.
	 */
	public static function remove_webhook(): bool {
		$ok = self::call( 'deleteWebhook' )['ok'];
		delete_transient( self::TRANSIENT_HEALTH );

		return $ok;
	}

	/**
	 * Who the token belongs to, for the settings screen.
	 *
	 * @return string Bot username, or '' when unknown.
	 */
	public static function username(): string {
		$me = self::call( 'getMe' );
		return $me['ok'] && isset( $me['result']['username'] )
			? (string) $me['result']['username']
			: '';
	}

	/**
	 * What Telegram thinks of our webhook.
	 *
	 * Telegram remembers the last delivery it could not complete, which is the
	 * one diagnostic this side cannot produce for itself: if our endpoint is
	 * 500ing, or the certificate went bad, or another site claimed the webhook,
	 * nothing here would know — the updates simply stop arriving, silently.
	 *
	 * @return array{ok:bool,url:string,pending:int,error:string,error_at:int,description:string}
	 */
	public static function webhook_info(): array {
		$out = array(
			'ok'          => false,
			'url'         => '',
			'pending'     => 0,
			'error'       => '',
			'error_at'    => 0,
			'description' => '',
		);

		$result = self::call( 'getWebhookInfo' );

		if ( ! $result['ok'] || ! is_array( $result['result'] ) ) {
			$out['description'] = '' !== $result['description']
				? $result['description']
				: 'Telegram did not answer.';
			return $out;
		}

		$info = $result['result'];

		$out['ok']       = true;
		$out['url']      = (string) ( $info['url'] ?? '' );
		$out['pending']  = (int) ( $info['pending_update_count'] ?? 0 );
		$out['error']    = (string) ( $info['last_error_message'] ?? '' );
		$out['error_at'] = (int) ( $info['last_error_date'] ?? 0 );

		return $out;
	}

	/**
	 * The bot's health, cached, for the settings screen.
	 *
	 * Two HTTP round trips, so it sits behind a short transient: the panel is
	 * reloaded far more often than a webhook changes, and nobody should pay a
	 * trip to Telegram for opening a settings page.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array{username:string,webhook:array<string,mixed>,ours:bool,checked:int}
	 */
	public static function health( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::TRANSIENT_HEALTH );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$webhook = self::webhook_info();

		$health = array(
			'username' => self::username(),
			'webhook'  => $webhook,
			// A webhook pointing somewhere else is not a small thing: Telegram
			// allows one per bot, so whoever registered last is receiving the
			// messages this site thinks it is answering. Staging is the usual
			// culprit and it is invisible from every other angle.
			'ours'     => $webhook['ok'] && self::webhook_url() === $webhook['url'],
			'checked'  => time(),
		);

		set_transient( self::TRANSIENT_HEALTH, $health, self::HEALTH_TTL );

		return $health;
	}
}
