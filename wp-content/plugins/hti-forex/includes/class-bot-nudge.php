<?php
/**
 * The follow-up nudge: one message to someone who opened the bot and then
 * never asked it anything.
 *
 * Why it exists. Of 1,699 people who arrived on a campaign link, 94 ever sent
 * a balance — the one thing the bot does. The other 1,605 read a greeting and
 * closed the app. The greeting already leads with the ask, in bold, on the
 * first line, so this is not a copy problem: it is that a person taps an ad,
 * gets interrupted, and never comes back to a chat they have no reason to
 * remember. A single message half an hour later is the cheapest thing that
 * addresses it.
 *
 * A deliberate departure, recorded. Bot_Broadcast opens by saying the bot
 * never speaks first on its own and that the only unprompted message is one an
 * admin writes and confirms. This is automatic, so it narrows that promise —
 * to: at most one follow-up, only inside a conversation the person themselves
 * opened, only to someone who never got an answer, and never again. Four
 * guarantees hold it there:
 *
 *  - **Off by default.** Nothing is armed until the setting is switched on, so
 *    deploying this messages nobody.
 *  - **Arming happens only at /start**, and only for someone new. Turning the
 *    setting on therefore starts the clock from that moment; there is no
 *    backlog to burst through, because rows that predate it are never armed.
 *  - **Answering a balance spends the nudge.** The people it worked on are
 *    exactly the people it must not message.
 *  - **The claim precedes the send**, so a crash costs a nudge rather than
 *    sending a second one.
 *
 * /stop needs no handling here: it deletes the row, and a deleted row cannot
 * be due for anything.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled one-shot follow-up.
 */
class Bot_Nudge {

	public const HOOK = 'hti_forex_bot_nudge';

	/**
	 * How long after /start the nudge comes due.
	 *
	 * Long enough that it never lands on someone still reading the greeting,
	 * short enough that the ad they tapped is still why they are holding the
	 * phone.
	 */
	public const DELAY = 30 * MINUTE_IN_SECONDS;

	/**
	 * Recipients per tick, and the pause between sends inside one — the same
	 * pacing Bot_Broadcast uses, comfortably inside Telegram's free ceiling.
	 */
	private const BATCH  = 25;
	private const GAP_US = 40000;

	/**
	 * How stale a due nudge may be and still go out.
	 *
	 * If the switch was off for a fortnight, or cron stopped, the backlog is
	 * not a mailing list — it is people who have long since forgotten the bot.
	 * They age out rather than all being messaged the moment it resumes.
	 */
	private const MAX_AGE = 2 * DAY_IN_SECONDS;

	/**
	 * The way out travels with the message, as it does on a broadcast. Someone
	 * who opened a calculator did not thereby ask to be followed up.
	 */
	private const FOOTER = "\n\n<i>One-off reminder because you opened this bot. /stop to leave.</i>";

	/**
	 * Hook the runner.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Whether the follow-up is switched on.
	 */
	public static function enabled(): bool {
		return ! empty( Settings::settings()['bot_nudge_enabled'] );
	}

	/**
	 * Arm the nudge for someone who has just opened the bot for the first time.
	 *
	 * Arming is gated on the setting rather than sending, so that switching the
	 * feature on can never produce a burst: it starts the clock for people who
	 * arrive afterwards, and no one else.
	 *
	 * @param int $chat_id Telegram chat id.
	 */
	public static function arm( int $chat_id ): void {
		if ( ! self::enabled() ) {
			return;
		}

		Bot_Store::arm_nudge( $chat_id, self::DELAY );
		self::schedule( self::DELAY + 60 );
	}

	/**
	 * Send the nudges that have come due.
	 */
	public static function run(): void {
		if ( ! self::enabled() || ! Telegram::configured() ) {
			return;
		}

		$rows = Bot_Store::due_nudges( self::BATCH, self::MAX_AGE );

		if ( array() === $rows ) {
			self::reschedule_for_next();
			return;
		}

		$backoff = 0;

		foreach ( $rows as $row ) {
			// Whoever wins this claim owns the send. A tick that overlaps with
			// another finds the row already spent and moves on.
			if ( ! Bot_Store::claim_nudge( $row['id'] ) ) {
				continue;
			}

			$result = Telegram::send( $row['chat_id'], self::text() . self::FOOTER );

			if ( 'blocked' === $result['status'] ) {
				// Blocked or gone. Same as a broadcast: drop the row, because
				// it can never receive anything again and keeping personal data
				// with no purpose is the thing we promised not to do.
				Bot_Store::forget( $row['chat_id'] );
			} elseif ( 'slow_down' === $result['status'] ) {
				// This one nudge is spent without arriving — the claim is
				// already written. At one message per person per lifetime and
				// twenty-five a second, this is a case that should never occur;
				// paying for it with a lost nudge rather than a risk of two is
				// the trade this class makes everywhere.
				$backoff = $result['retry_after'];
				break;
			} elseif ( 'sent' === $result['status'] ) {
				self::track( 'forex_bot_nudge' );
			}

			usleep( self::GAP_US );
		}

		// A full batch means there is probably more behind it.
		if ( $backoff > 0 || count( $rows ) >= self::BATCH ) {
			self::schedule( $backoff > 0 ? $backoff : 60 );
			return;
		}

		self::reschedule_for_next();
	}

	/**
	 * The message. Pure, so the harness can assert on it.
	 *
	 * It repeats the ask and nothing else: no offer, no broker, no second
	 * reason to open the chat. The only job is to get a number typed, and the
	 * closing line is the same honest one the calculators lead with — it speaks
	 * to the two thirds of this audience whose balance cannot hold a micro lot,
	 * which no signals channel will ever tell them.
	 */
	public static function text(): string {
		return "<b>Still there? It takes one number.</b>\n\n"
			. "Send your account balance and you'll get back the smallest position it can actually carry — the margin it locks, what one pip is worth, and what a 20-pip stop would cost. All in rupees.\n\n"
			. "<code>5000</code>   <code>50k</code>   <code>₹1,00,000</code>   <code>\$100</code>\n\n"
			. 'Some accounts turn out to be too small to hold even one micro lot at a sensible risk. That is worth knowing before a trade, not after.';
	}

	/**
	 * Queue a tick, unless one is already queued.
	 *
	 * @param int $delay Seconds from now.
	 */
	private static function schedule( int $delay ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK );
		}
	}

	/**
	 * Queue the next tick for when something is actually due, or not at all.
	 */
	private static function reschedule_for_next(): void {
		$next = Bot_Store::next_nudge_due();
		if ( $next > 0 ) {
			self::schedule( max( 60, $next - time() ) );
		}
	}

	/**
	 * Count an event, when hti-engine is present to count it.
	 *
	 * @param string $event Event key.
	 */
	private static function track( string $event ): void {
		if ( class_exists( '\\HTI\\Engine\\Metrics' ) ) {
			\HTI\Engine\Metrics::bump( $event );
		}
	}
}
