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

	public const HOOK = 'hti_forex_bot_broadcast';

	/**
	 * Where the running broadcast lives.
	 *
	 * The name carries a suffix because the one before it became unwritable on
	 * the live site: the row was gone from the database while WordPress still
	 * believed the option existed, so every write took the UPDATE path, matched
	 * no row, and reported failure — permanently, and identically to a message
	 * that had been sent. A name with no history anywhere resolves that without
	 * having to characterise it: no cached blob mentions it, no `notoptions`
	 * entry hides it, and `add_option()` simply creates it.
	 */
	public const OPTION = 'hti_forex_broadcast_state';

	/**
	 * The memory the state itself does not keep.
	 *
	 * `OPTION` holds one broadcast, and every new one writes over it. That is
	 * fine for running a send and useless for answering the question anybody
	 * actually asks afterwards — "did my message go out, or am I looking at
	 * last night's test?". This option keeps what happened.
	 */
	public const OPTION_LOG = 'hti_forex_bot_broadcast_log';

	/**
	 * How many past broadcasts to remember, and how many distinct send errors.
	 */
	private const KEEP_HISTORY = 10;
	private const KEEP_ERRORS  = 10;

	/**
	 * How recent an acceptance has to be to still be worth confirming on screen.
	 */
	private const FRESH_SECONDS = 120;

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
	 * What has happened to broadcasts, and what went wrong.
	 *
	 * @return array{history:array<int,array<string,mixed>>,refused:array<string,mixed>,errors:array<int,array<string,mixed>>}
	 */
	public static function log(): array {
		$log = get_option( self::OPTION_LOG, array() );
		$log = is_array( $log ) ? $log : array();

		return array(
			'history' => isset( $log['history'] ) && is_array( $log['history'] ) ? $log['history'] : array(),
			'refused' => isset( $log['refused'] ) && is_array( $log['refused'] ) ? $log['refused'] : array(),
			// A refusal may carry what the database said; older records have no
			// such field, so readers must not assume it.

			'errors'  => isset( $log['errors'] ) && is_array( $log['errors'] ) ? $log['errors'] : array(),
			// When a send was last accepted. The screen needs this to tell a
			// real confirmation from a stale one: "queued" used to be drawn
			// from a URL parameter, so any page load carrying it — a restored
			// tab, a back button, a pasted address — claimed a broadcast had
			// been queued when none had.
			'started' => (int) ( $log['started'] ?? 0 ),
		);
	}

	/**
	 * File a finished broadcast, newest first.
	 *
	 * @param array<string,mixed> $state The state as it ended.
	 * @param string              $how   'finished' | 'cancelled' | 'stalled'.
	 */
	private static function remember_end( array $state, string $how ): void {
		$log = self::log();

		array_unshift(
			$log['history'],
			array(
				'started'  => (int) ( $state['started'] ?? 0 ),
				'ended'    => time(),
				'how'      => $how,
				'sent'     => (int) ( $state['sent'] ?? 0 ),
				'dropped'  => (int) ( $state['dropped'] ?? 0 ),
				'total'    => (int) ( $state['total'] ?? 0 ),
				'image'    => (string) ( $state['image'] ?? '' ),
				// Enough of the message to recognise which one it was, which is
				// the entire point of keeping the row.
				'excerpt'  => mb_substr( wp_strip_all_tags( (string) ( $state['text'] ?? '' ) ), 0, 80 ),
			)
		);

		$log['history'] = array_slice( $log['history'], 0, self::KEEP_HISTORY );

		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * Record why a broadcast was refused, and say no.
	 *
	 * The reason used to live only in an admin notice, which is gone on the
	 * next page load — so someone who did not read it in time was left with a
	 * screen that looked exactly as it had before they pressed send.
	 *
	 * @param string $reason Machine-readable reason.
	 * @return false
	 */
	private static function refuse( string $reason, string $detail = '' ): bool {
		$log            = self::log();
		$log['refused'] = array(
			'reason' => $reason,
			'at'     => time(),
			'detail' => $detail,
		);
		update_option( self::OPTION_LOG, $log, false );

		return false;
	}

	/**
	 * Whether the text carries anything outside the Basic Multilingual Plane.
	 *
	 * Emoji live there, at four bytes each, and a column on three-byte `utf8`
	 * refuses the whole write rather than the character.
	 *
	 * @param string $text Message body.
	 */
	public static function has_four_byte_characters( string $text ): bool {
		return 1 === preg_match( '/[\x{10000}-\x{10FFFF}]/u', $text );
	}

	/**
	 * Whatever the database last complained about, if anything.
	 *
	 * A failed write is reported by WordPress as a bare false, and the reason
	 * MySQL gave is thrown away one layer down. Carrying it up is the
	 * difference between "the database refused" and knowing why — which, when
	 * a broadcast will not go out, is the whole question.
	 */
	private static function db_error(): string {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return '';
		}

		return trim( (string) ( $wpdb->last_error ?? '' ) );
	}

	/**
	 * Record a send that failed for a reason we can neither retry nor explain
	 * away, grouped by API error code so one bad message cannot fill the log.
	 *
	 * @param int    $code        Telegram error code.
	 * @param string $description What Telegram said.
	 */
	private static function note_failure( int $code, string $description ): void {
		$log = self::log();
		$key = (string) $code;

		$seen  = isset( $log['errors'][ $key ] ) && is_array( $log['errors'][ $key ] );
		$count = $seen ? (int) $log['errors'][ $key ]['count'] + 1 : 1;

		// Position means recency, so a code that fires again moves to the end
		// rather than staying where it first appeared. Timestamps cannot carry
		// this on their own: a batch fails many times within one second, and
		// sorting on equal values evicts arbitrarily.
		unset( $log['errors'][ $key ] );

		if ( count( $log['errors'] ) >= self::KEEP_ERRORS ) {
			// Keep the most recent, from the end. `array_shift` would renumber
			// the keys — an error code is a numeric key and PHP reindexes those
			// — quietly destroying the grouping this structure exists for.
			$log['errors'] = array_slice( $log['errors'], 1 - self::KEEP_ERRORS, null, true );
		}

		$log['errors'][ $key ] = array(
			'code'        => $code,
			'description' => $description,
			'count'       => $count,
			'at'          => time(),
		);

		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * Write down that a send was accepted, and forget the last refusal.
	 *
	 * This is the fact the confirmation on screen is checked against. Without
	 * it the only evidence a broadcast had been queued was a word in the
	 * address bar, which survives long after the thing it described.
	 */
	private static function remember_start(): void {
		$log            = self::log();
		$log['refused'] = array();
		$log['started'] = time();
		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * Whether a send was accepted just now.
	 *
	 * "Just now" because a confirmation is about what the person in front of
	 * the screen has this moment done; an acceptance from yesterday explains
	 * nothing about the page they are looking at.
	 */
	public static function just_started(): bool {
		$started = self::log()['started'];
		return $started > 0 && ( time() - $started ) <= self::FRESH_SECONDS;
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
		if ( ! Telegram::configured() ) {
			return self::refuse( 'no-token' );
		}

		if ( '' === trim( $text ) ) {
			return self::refuse( 'empty' );
		}

		if ( self::running() ) {
			return self::refuse( 'already-running' );
		}

		if ( '' !== $image && ! self::fits_caption( $text, $image ) ) {
			return self::refuse( 'caption-too-long' );
		}

		// Whatever is in the state now is about to be overwritten. If it never
		// reached an ending it died mid-flight, and this is the last moment it
		// can be written down.
		$previous = self::status();
		if ( $previous['started'] > 0 && 0 === $previous['finished'] ) {
			self::remember_end( $previous, 'stalled' );
		}

		// The state is written first and the acceptance recorded second. The
		// other way round — which is how this shipped — leaves a note saying a
		// broadcast began when none exists, and the screen then reports a send
		// that is not there. That is not a cosmetic ordering: `just_started()`
		// is what the confirmation is checked against.
		$state = array(
			'text'     => $text,
			'image'    => Bot_Images::exists( $image ) ? $image : '',
			'cursor'   => 0,
			'sent'     => 0,
			'dropped'  => 0,
			'total'    => Bot_Store::total(),
			'started'  => time(),
			'updated'  => time(),
			'finished' => 0,
		);

		$written = update_option( self::OPTION, $state, false );

		if ( ! $written ) {
			// A stale object cache is a known way for this to fail permanently
			// rather than once: WordPress asks whether the option exists, the
			// cache answers with something the database no longer holds, and
			// the write goes down a path that matches no row — every time, for
			// ever. Both places have to be dropped: an autoloaded option is
			// served from the `alloptions` blob, which clearing the individual
			// entry leaves untouched.
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$written = update_option( self::OPTION, $state, false );
		}

		// The value written here is always new, so a second failure means the
		// database really did refuse it — and a broadcast whose state was never
		// stored has not been queued, however far the code got.
		if ( ! $written ) {
			// Ask the database what it objected to. Five guesses at this from
			// the outside were five wrong ones; MySQL knows, and until now
			// nothing carried its answer as far as the screen.
			$error = self::db_error();

			// One cause is common enough, and specific enough to act on, that
			// it deserves its own answer: an emoji is four bytes, and a column
			// still on three-byte utf8 cannot hold one. WordPress reports that
			// as "invalid data", which reads like a fault in the message rather
			// than a limit of the table it is being written to.
			if ( self::has_four_byte_characters( $text ) ) {
				return self::refuse( 'emoji-unsupported', $error );
			}

			return self::refuse( 'write-failed', $error );
		}

		self::remember_start();
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
		self::remember_end( $state, 'cancelled' );
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
			self::remember_end( $state, 'finished' );
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
			} elseif ( 'failed' === $result['status'] ) {
				// Neither a dead chat nor a rate limit: a rejected HTML tag, a
				// revoked token, Telegram being down. Counted nowhere and, until
				// now, reported nowhere either — the cursor simply moved on.
				self::note_failure(
					(int) ( $result['code'] ?? 0 ),
					(string) ( $result['description'] ?? '' )
				);
			}

			usleep( self::GAP_US );
		}

		$state['updated'] = time();
		update_option( self::OPTION, $state, false );
		self::schedule( $backoff > 0 ? $backoff : 1 );
	}
}
