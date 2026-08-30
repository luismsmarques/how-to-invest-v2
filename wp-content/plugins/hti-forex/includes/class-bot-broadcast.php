<?php
/**
 * Sending one message to everyone who has used the bot.
 *
 * The bot never speaks first on its own — there is no daily alert and no
 * schedule. The only thing that reaches people unprompted is a message an
 * admin writes in wp-admin and confirms. That keeps the inbox quiet, which is
 * the only reason a bot survives long enough to be worth having.
 *
 * The send walks the subscriber table with a cursor, a batch per cron tick,
 * so it survives the browser being closed and cannot send twice or skip
 * anyone. Batches are far under Telegram's 30-per-second ceiling; at this
 * scale the limit is never the constraint, and pacing costs nothing.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Queued broadcast.
 */
class Bot_Broadcast {

	public const HOOK   = 'hti_forex_bot_broadcast';
	public const OPTION = 'hti_forex_bot_broadcast';

	/**
	 * Recipients per cron tick.
	 */
	private const BATCH = 25;

	/**
	 * Appended to every broadcast, whatever the admin wrote. Someone who came
	 * here for a calculator did not thereby ask for messages, so the way out
	 * travels with each one rather than living in a help page they will not
	 * read.
	 */
	private const FOOTER = "\n\n<i>You're getting this because you used the HowToInvest forex bot. /stop to leave.</i>";

	/**
	 * Microseconds between sends inside a batch — 25 per second, comfortably
	 * inside the free ceiling with room for the API's own jitter.
	 */
	private const GAP_US = 40000;

	/**
	 * How long a send may go without a tick before it counts as dead. Longer
	 * than the worst case for one batch (25 recipients against a Telegram that
	 * is timing out), short enough that nobody waits a day to send again.
	 */
	private const STALL_SECONDS = 900;

	/**
	 * Hook the batch runner.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Current state, with defaults so callers never have to guard.
	 *
	 * Every key `run()` reads has to be listed here. It reads the whole state
	 * through this method, so a key missing from this array is not a default —
	 * it is a fatal, one row into the first batch.
	 *
	 * @return array{text:string,image:string,cursor:int,sent:int,dropped:int,total:int,started:int,updated:int,finished:int}
	 */
	public static function status(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$started = (int) ( $state['started'] ?? 0 );

		return array(
			'text'     => (string) ( $state['text'] ?? '' ),
			'image'    => (string) ( $state['image'] ?? '' ),
			'cursor'   => (int) ( $state['cursor'] ?? 0 ),
			'sent'     => (int) ( $state['sent'] ?? 0 ),
			'dropped'  => (int) ( $state['dropped'] ?? 0 ),
			'total'    => (int) ( $state['total'] ?? 0 ),
			'started'  => $started,
			// Older states predate the heartbeat; treating the start as the
			// last sign of life is what lets one of them be recognised as
			// stalled instead of holding the composer hostage for ever.
			'updated'  => (int) ( $state['updated'] ?? $started ),
			'finished' => (int) ( $state['finished'] ?? 0 ),
		);
	}

	/**
	 * A send that died mid-flight and is never coming back.
	 *
	 * `run()` re-arms the cron at the end of every batch, so a send that is
	 * alive always has a tick waiting. No tick and no ending means the batch
	 * died — a fatal, a killed worker, a site moved mid-send. Without this the
	 * state would read as "sending" for ever and `start()` would refuse every
	 * later broadcast, with nothing on screen saying why.
	 *
	 * The grace period is what separates a dead send from the ordinary gap
	 * while a batch is between its cron firing and its next scheduling.
	 */
	public static function stalled(): bool {
		$state = self::status();

		if ( 0 === $state['started'] || $state['finished'] > 0 ) {
			return false;
		}

		if ( wp_next_scheduled( self::HOOK ) ) {
			return false;
		}

		return ( time() - $state['updated'] ) > self::STALL_SECONDS;
	}

	/**
	 * Whether a send is currently working through the table.
	 */
	public static function running(): bool {
		$state = self::status();
		return $state['started'] > 0 && 0 === $state['finished'] && ! self::stalled();
	}

	/**
	 * Start a broadcast.
	 *
	 * @param string $text  Message body, already sanitised by the caller.
	 * @param string $image Image slug to attach, or '' for a plain message.
	 * @return bool Whether it was queued.
	 */
	public static function start( string $text, string $image = '' ): bool {
		if ( '' === trim( $text ) || self::running() || ! Telegram::configured() ) {
			return false;
		}

		if ( '' !== $image && ! self::fits_caption( $text, $image ) ) {
			return false;
		}

		update_option(
			self::OPTION,
			array(
				'text'     => $text,
				'image'    => Bot_Images::exists( $image ) ? $image : '',
				'cursor'   => 0,
				'sent'     => 0,
				'dropped'  => 0,
				'total'    => Bot_Store::total(),
				'started'  => time(),
				'updated'  => time(),
				'finished' => 0,
			),
			false
		);

		self::schedule( 0 );
		return true;
	}

	/**
	 * Whether a message still fits once the image and the footer are on it.
	 *
	 * A caption is a quarter of a message, and going over does not truncate —
	 * it fails the send, for every recipient. Better to refuse while someone
	 * is composing than to discover it one bounced batch at a time.
	 *
	 * @param string $text  Message body.
	 * @param string $image Image slug, or '' for none.
	 * @return bool
	 */
	public static function fits_caption( string $text, string $image ): bool {
		if ( '' === $image ) {
			return true;
		}
		return mb_strlen( $text . self::FOOTER ) <= Telegram::CAPTION_MAX;
	}

	/**
	 * Abandon a running broadcast where it stands.
	 */
	public static function cancel(): void {
		$state = self::status();
		if ( 0 === $state['started'] ) {
			return;
		}

		$state['updated']  = time();
		$state['finished'] = time();
		update_option( self::OPTION, $state, false );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Queue the next tick.
	 *
	 * @param int $delay Seconds from now.
	 */
	private static function schedule( int $delay ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK );
		}
	}

	/**
	 * Send one batch, then either queue the next or finish.
	 */
	public static function run(): void {
		$state = self::status();

		if ( 0 === $state['started'] || $state['finished'] > 0 || '' === $state['text'] ) {
			return;
		}

		$rows = Bot_Store::page( $state['cursor'], self::BATCH );

		if ( array() === $rows ) {
			$state['updated']  = time();
			$state['finished'] = time();
			update_option( self::OPTION, $state, false );
			return;
		}

		$backoff = 0;

		foreach ( $rows as $row ) {
			$body  = $state['text'] . self::FOOTER;
			$photo = '' === $state['image'] ? '' : Bot_Images::photo( $state['image'] );

			if ( '' !== $photo ) {
				$result = Telegram::send_photo( $row['chat_id'], $photo, $body );
				if ( 'sent' === $result['status'] && '' !== $result['file_id'] ) {
					// The first recipient pays for the upload; everyone after them
					// is sent the id Telegram handed back for it.
					Bot_Images::remember( $state['image'], $result['file_id'] );
				}
			} else {
				$result = Telegram::send( $row['chat_id'], $body );
			}

			if ( 'slow_down' === $result['status'] ) {
				// Stop the batch where it is; the cursor is not advanced past
				// this row, so it goes out on the next tick.
				$backoff = $result['retry_after'];
				break;
			}

			$state['cursor'] = $row['id'];

			if ( 'blocked' === $result['status'] ) {
				// They blocked the bot or the chat is gone. Delete the row —
				// it can never receive anything again, and keeping personal
				// data with no purpose is the thing we promised not to do.
				Bot_Store::forget( $row['chat_id'] );
				++$state['dropped'];
			} elseif ( 'sent' === $result['status'] ) {
				++$state['sent'];
			}

			usleep( self::GAP_US );
		}

		$state['updated'] = time();
		update_option( self::OPTION, $state, false );
		self::schedule( $backoff > 0 ? $backoff : 1 );
	}
}
