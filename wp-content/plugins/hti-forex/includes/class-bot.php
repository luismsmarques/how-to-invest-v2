<?php
/**
 * The Telegram bot: one question in, the whole risk picture out.
 *
 * The bot asks a single thing — how much is in the account — and answers with
 * what the smallest available position costs to hold and costs when it moves,
 * in rupees. There is no command syntax to learn, because the people arriving
 * here mostly do not yet know what a risk percentage or a stop in pips is;
 * asking them for those would be asking for the answer.
 *
 * The webhook is a REST route, which is what lets this exist without touching
 * rewrite rules — the deploy never flushes them.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook, router and replies.
 */
class Bot {

	/**
	 * Register the webhook route.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'route' ) );
	}

	/**
	 * The update endpoint. Public by design — Telegram cannot send a nonce —
	 * and authenticated instead by the secret header set when the webhook was
	 * registered.
	 */
	public static function route(): void {
		register_rest_route(
			'htinvest/v1',
			'/forex/telegram',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle one update.
	 *
	 * Always answers 200 once the caller is authenticated: a non-200 makes
	 * Telegram retry the same update, and an update we could not parse will
	 * not parse the second time either.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$secret = (string) $request->get_header( 'x_telegram_bot_api_secret_token' );
		if ( ! Telegram::configured() || ! hash_equals( Telegram::secret(), $secret ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$update = $request->get_json_params();
		$reply  = is_array( $update ) ? self::dispatch( $update ) : null;

		// Telegram accepts a method call as the body of this very response, so
		// the answer travels back on the connection it already has. What used
		// to be "reply 200, then open a socket and wait up to ten seconds" is
		// now one request that ends here — which is the difference between
		// holding a PHP process the site needs and not holding it.
		return new \WP_REST_Response( $reply ?? array( 'ok' => true ), 200 );
	}

	/**
	 * Route one update to the thing that answers it.
	 *
	 * @param array<string,mixed> $update Decoded update.
	 * @return array<string,mixed>|null Answer to carry back, or null.
	 */
	private static function dispatch( array $update ): ?array {
		// A tapped button.
		if ( isset( $update['callback_query'] ) ) {
			$query   = $update['callback_query'];
			$chat_id = (int) ( $query['message']['chat']['id'] ?? 0 );
			$data    = (string) ( $query['data'] ?? '' );

			$reply = $chat_id > 0 ? self::on_button( $chat_id, $data ) : null;

			// The spinner on the button has to be cleared, but nothing here
			// reads the answer — so it goes out without waiting for one, and
			// the reply itself travels back in the response.
			Telegram::call(
				'answerCallbackQuery',
				array( 'callback_query_id' => $query['id'] ?? '' ),
				false
			);

			return $reply;
		}

		$message = $update['message'] ?? null;
		if ( ! is_array( $message ) ) {
			return null;
		}

		$chat_id = (int) ( $message['chat']['id'] ?? 0 );
		$text    = trim( (string) ( $message['text'] ?? '' ) );

		if ( $chat_id <= 0 || '' === $text ) {
			return null;
		}

		return self::on_message( $chat_id, $text );
	}

	/**
	 * Handle a text message.
	 *
	 * @param int    $chat_id Chat.
	 * @param string $text    Message text.
	 * @return array<string,mixed>|null Answer to carry back in the response.
	 */
	private static function on_message( int $chat_id, string $text ): ?array {
		// strtok's ' @' handles /start@TheBotName; the split keeps whatever
		// followed the command, which for /start is the deep-link payload.
		$command = strtolower( strtok( $text, ' @' ) );
		$rest    = preg_split( '/\s+/', $text, 2 )[1] ?? '';

		if ( '/stop' === $command ) {
			Bot_Store::forget( $chat_id );
			self::track( 'forex_bot_stop' );
			return Telegram::reply_message( $chat_id, self::stop_text() );
		}

		$is_new = Bot_Store::remember( $chat_id );

		if ( '/start' === $command ) {
			// Count the campaign only for someone we have never seen. Opening
			// the same ad twice is one person, and a number that says otherwise
			// would flatter whichever creative people tap at more than once.
			$source = Bot_Math::source_code( $rest );
			if ( $is_new ) {
				Bot_Store::count_source( $source );
			}

			// Keep it on the row too, not just in the counter. The counter can
			// say how many arrived from each campaign; only the row can travel
			// with the click that leaves for the broker, which is what turns
			// "454 people came from b2" into "b2 paid for this account".
			// First touch wins — set_source() never overwrites.
			Bot_Store::set_source( $chat_id, $source );
			self::track( 'forex_bot_start' );
			return self::send_illustrated( $chat_id, 'start', self::start_text() );
		}

		if ( '/help' === $command ) {
			return Telegram::reply_message( $chat_id, self::help_text() );
		}

		$rates  = Rates::effective()['rates'];
		$parsed = Bot_Math::parse_amount( $text, (float) ( $rates['USDINR'] ?? 0 ) );

		if ( null === $parsed ) {
			return Telegram::reply_message( $chat_id, self::confused_text() );
		}

		Bot_Store::count_balance( $parsed['inr'] );
		self::track( 'forex_bot_calc' );

		return self::answer( $chat_id, $parsed['inr'] );
	}

	/**
	 * Handle a button tap. The last balance is not stored — buttons re-ask
	 * with the balance carried in the callback data, which keeps the
	 * subscriber row free of anything financial.
	 *
	 * @param int    $chat_id Chat.
	 * @param string $data    Callback data, "p:PAIR:BALANCE" | "l:LEV:BALANCE" | "x:pip".
	 * @return array<string,mixed>|null Answer to carry back in the response.
	 */
	private static function on_button( int $chat_id, string $data ): ?array {
		$parts = explode( ':', $data );
		$kind  = $parts[0] ?? '';

		if ( 'x' === $kind ) {
			return self::send_illustrated( $chat_id, 'pip', self::pip_explainer() );
		}

		$value   = $parts[1] ?? '';
		$balance = (float) ( $parts[2] ?? 0 );

		if ( 'p' === $kind ) {
			Bot_Store::set_prefs( $chat_id, $value, null );
		} elseif ( 'l' === $kind ) {
			Bot_Store::set_prefs( $chat_id, null, (int) $value );
		} else {
			return null;
		}

		return $balance > 0 ? self::answer( $chat_id, $balance ) : null;
	}

	/**
	 * Send a message with its illustration, falling back to plain text.
	 *
	 * Two things can go wrong and neither should silence the bot: the asset
	 * may be missing after a partial deploy, and Telegram may refuse to fetch
	 * it. In both cases the words are what matter, so they go out anyway.
	 *
	 * A successful send hands back a file_id, which is cached so the next
	 * person's copy costs Telegram a lookup instead of costing us the file.
	 *
	 * @param int    $chat_id Chat.
	 * @param string $slug    Image slug (Bot_Images).
	 * @param string $caption Caption — must fit Telegram::CAPTION_MAX.
	 * @return array<string,mixed>|null Answer to carry back in the response.
	 */
	private static function send_illustrated( int $chat_id, string $slug, string $caption ): ?array {
		$photo = Bot_Images::photo( $slug );

		if ( '' === $photo || mb_strlen( $caption ) > Telegram::CAPTION_MAX ) {
			return Telegram::reply_message( $chat_id, $caption );
		}

		// Once Telegram has told us the file_id, the picture costs nothing to
		// send again and can ride back in the webhook response like any text.
		if ( Bot_Images::is_file_id( $slug, $photo ) ) {
			return Telegram::reply_photo( $chat_id, $photo, $caption );
		}

		// First time out: the file has to be uploaded, and the file_id only
		// comes back in the API's answer — which a webhook reply never sees.
		// So this one send stays a real call, and pays for every one after it.
		$result = Telegram::send_photo( $chat_id, $photo, $caption );

		if ( 'sent' === $result['status'] ) {
			Bot_Images::remember( $slug, $result['file_id'] );
			return null;
		}
		if ( 'blocked' === $result['status'] ) {
			return null;
		}

		return Telegram::reply_message( $chat_id, $caption );
	}

	/**
	 * Compute and send the picture for a balance.
	 *
	 * @param int   $chat_id Chat.
	 * @param float $balance Balance in rupees.
	 */
	private static function answer( int $chat_id, float $balance ): ?array {
		$prefs   = Bot_Store::prefs( $chat_id );
		$rates   = Rates::effective()['rates'];
		$picture = Bot_Math::picture( $balance, $prefs['pair'], (float) $prefs['leverage'], $rates );

		if ( null === $picture ) {
			return Telegram::reply_message( $chat_id, self::confused_text() );
		}

		return Telegram::reply_message(
			$chat_id,
			self::reply_text( $picture, self::ad_line( (bool) $picture['tight'], Bot_Store::source( $chat_id ) ) ),
			self::keyboard( $picture )
		);
	}

	/**
	 * The answer itself.
	 *
	 * Pure: everything it needs arrives as arguments, so the harness can
	 * assert on the exact text without WordPress. The <pre> block is what
	 * makes the columns line up on a phone, which is where all of this is
	 * being read.
	 *
	 * @param array<string,mixed> $p       Picture from Bot_Math::picture().
	 * @param string              $ad_line Optional labelled partner line.
	 * @return string
	 */
	public static function reply_text( array $p, string $ad_line = '' ): string {
		$rows = array();

		$rows[] = sprintf( '%-16s %s', 'Smallest trade', $p['lots'] . ' lots' );

		if ( null !== $p['margin_inr'] ) {
			$rows[] = sprintf( '%-16s ₹%s', 'Margin locked', Bot_Math::inr( $p['margin_inr'] ) );
		}

		$rows[] = sprintf( '%-16s ₹%s', '1 pip moves', Bot_Math::inr( $p['pip_inr'], 2 ) );

		foreach ( $p['stops'] as $stop ) {
			$rows[] = sprintf(
				'%-16s ₹%s  (%s%%)',
				$stop['pips'] . '-pip stop',
				Bot_Math::inr( $stop['cost'] ),
				number_format( $stop['percent'], $stop['percent'] < 10 ? 1 : 0 )
			);
		}

		$out  = '<b>₹' . Bot_Math::inr( $p['balance'] ) . ' · ' . esc_html( $p['pair_label'] ) . ' · 1:' . (int) $p['leverage'] . "</b>\n\n";
		$out .= '<pre>' . esc_html( implode( "\n", $rows ) ) . "</pre>\n";

		foreach ( $p['room'] as $room ) {
			$out .= sprintf(
				"Risking %s%% (₹%s) leaves room for about a %s-pip stop.\n",
				(int) $room['risk_pct'],
				Bot_Math::inr( $room['risk_inr'] ),
				number_format( $room['pips'], $room['pips'] < 10 ? 1 : 0 )
			);
		}

		if ( $p['tight'] ) {
			$out .= "\n⚠️ At this balance the smallest position most platforms allow already risks more than 2% on an ordinary stop. That is the arithmetic describing the account, not a reason to trade larger.\n";
		}

		$disclosure = '';
		if ( '' !== $ad_line ) {
			$out .= "\n" . $ad_line . "\n";

			// The link above reads as a plain suggestion, which is what makes
			// it worth clicking. It is still a paid link, and a reader is
			// entitled to know that — so the disclosure rides in the footer
			// that every answer already carries, instead of interrupting the
			// sentence. Present whenever the link is, absent when it isn't.
			$disclosure = ' Links to partners are paid.';
		}

		$out .= "\n<i>Educational only, not advice. Forex and CFDs are leveraged, high-risk products; most retail accounts lose money." . $disclosure . '</i>';

		return $out;
	}

	/**
	 * The buttons under an answer: the other two pairs, the three leverages,
	 * and a way out of the jargon. The balance rides along in the callback
	 * data so a tap can recompute without anything financial being stored.
	 *
	 * @param array<string,mixed> $p Picture.
	 * @return array<int,array<int,array<string,string>>>
	 */
	public static function keyboard( array $p ): array {
		$pairs    = Config::pairs();
		$balance  = (string) round( (float) $p['balance'], 2 );
		$pair_row = array();

		foreach ( Bot_Math::PAIRS as $pair ) {
			if ( $pair === $p['pair'] ) {
				continue;
			}
			$pair_row[] = array(
				'text'          => $pairs[ $pair ]['label'],
				'callback_data' => 'p:' . $pair . ':' . $balance,
			);
		}

		$lev_row = array();
		foreach ( Bot_Math::LEVERAGES as $leverage ) {
			if ( (int) $leverage === (int) $p['leverage'] ) {
				continue;
			}
			$lev_row[] = array(
				'text'          => '1:' . $leverage,
				'callback_data' => 'l:' . $leverage . ':' . $balance,
			);
		}

		return array(
			$pair_row,
			$lev_row,
			array(
				array(
					'text'          => "What's a pip?",
					'callback_data' => 'x:pip',
				),
			),
		);
	}

	/**
	 * The labelled partner line, or '' when the CTA is switched off.
	 *
	 * It sits at the foot of the answer, after the arithmetic, never inside
	 * it. Someone who asked a question gets the answer first; anything else
	 * is the thing that makes people block a bot.
	 *
	 * The offer follows the answer. On an account where the smallest position
	 * available already risks more than 2% on an ordinary stop, the honest
	 * thing to point at is a demo — and it is also the only offer that does
	 * not argue with the warning printed directly above it. Larger accounts
	 * get the live-account line. The two are counted separately, so which one
	 * earns its place is a question the funnel answers rather than us.
	 *
	 * @param bool   $tight  Whether the smallest lot already risks more than 2%.
	 * @param string $source Where this chat came from, or '' — carried to the
	 *                       broker as the campaign id so a conversion can be
	 *                       traced back to the ad that paid for the person.
	 * @return string
	 */
	private static function ad_line( bool $tight, string $source = '' ): string {
		$settings = Settings::settings();
		if ( empty( $settings['cta_enabled'] ) || empty( $settings['bot_ad_enabled'] ) ) {
			return '';
		}

		$url  = (string) ( $settings[ $tight ? 'bot_ad_demo_url' : 'bot_ad_real_url' ] ?? '' );
		$text = (string) ( $settings[ $tight ? 'bot_ad_demo_text' : 'bot_ad_real_text' ] ?? '' );

		if ( '' === $url || '' === $text ) {
			return '';
		}

		// Closed vocabulary, fixed in code: `loc` is echoed into a counter map
		// and must never come from anything a visitor controls.
		$url = add_query_arg( 'loc', $tight ? 'telegram_bot_demo' : 'telegram_bot_real', $url );

		// And the campaign the person arrived on, which /go/ re-attaches as
		// the network's sub-id. Without this the money spent bringing someone
		// into the bot can never be matched to the account they open.
		if ( '' !== $source ) {
			$url = add_query_arg( 'cid', rawurlencode( $source ), $url );
		}

		// Bold inside the link: Telegram renders links blue and underlined
		// already, and the weight is what pulls the eye down to it past the
		// table. The emoji lives in the editable text rather than here, so the
		// whole visible line is one field somebody can tune without a deploy.
		return '<a href="' . esc_url( $url ) . '"><b>' . esc_html( $text ) . '</b></a>';
	}

	/**
	 * Count a bot event, if the engine's metrics are around. The vocabulary
	 * is fixed in code and never derived from anything a user types.
	 *
	 * @param string $event Event name, already on the engine's allowlist.
	 */
	private static function track( string $event ): void {
		if ( class_exists( '\\HTI\\Engine\\Metrics' ) ) {
			\HTI\Engine\Metrics::bump( $event );
		}
	}

	/**
	 * First contact.
	 */
	public static function start_text(): string {
		return "<b>Send me your account balance.</b>\n\n"
			. "I'll show you what the smallest trade you can place actually costs — the margin it locks, what one pip is worth, and what a stop would cost you. All in rupees.\n\n"
			. "Just type a number:\n"
			. "<code>5000</code>   <code>₹1,00,000</code>   <code>50k</code>   <code>$100</code>\n\n"
			. "No sign-up. Nothing about you is stored beyond this chat, and /stop erases it.\n\n"
			. "I may occasionally send something worth knowing — rarely, and /stop ends it for good.\n\n"
			. "What this isn't: signals, trade calls or tips. Never has been, never will be.";
	}

	/**
	 * Help.
	 */
	public static function help_text(): string {
		return "<b>How this works</b>\n\n"
			. "Send a balance — <code>5000</code>, <code>₹1,00,000</code>, <code>50k</code>, or <code>$100</code> if your account is in dollars — and I'll price up the smallest position you can open.\n\n"
			. "Buttons under the answer switch the pair and the leverage.\n\n"
			. "<b>Commands</b>\n"
			. "/start — what this is\n"
			. "/help — this message\n"
			. "/stop — delete everything and stop\n\n"
			. "The same calculators, with more options: howtoinvest.pro/forex\n"
			. "The channel: t.me/howtoinvestpro";
	}

	/**
	 * Farewell, after /stop.
	 */
	public static function stop_text(): string {
		return "Done — your record is deleted and I won't message you again.\n\n"
			. 'Send a balance any time to start over. The calculators stay free at howtoinvest.pro/forex';
	}

	/**
	 * When the message wasn't a number.
	 */
	public static function confused_text(): string {
		return "I only understand account balances — one number.\n\n"
			. "Try <code>5000</code>, <code>₹1,00,000</code>, <code>50k</code>, or <code>$100</code>.\n\n"
			. 'Type /help if you want the longer version.';
	}

	/**
	 * The one jargon button.
	 */
	public static function pip_explainer(): string {
		return "<b>A pip is the smallest standard price move in a pair.</b>\n\n"
			. "On EUR/USD and GBP/USD that's 0.0001. On USD/JPY it's 0.01, because the yen is quoted with fewer decimals.\n\n"
			. "What matters isn't the definition, it's the price: one pip on one micro lot (0.01) of EUR/USD is worth about ₹9.55. Multiply by your stop distance and you have what the trade costs when it goes wrong — twenty pips, as above, is about ₹191.\n\n"
			. 'Send a balance and I\'ll do that multiplication for you.';
	}
}
