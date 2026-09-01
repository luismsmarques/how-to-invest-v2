<?php
/**
 * A subscriber table that lives in memory.
 *
 * Bot_Broadcast talks to Bot_Store, and Bot_Store talks to $wpdb — which the
 * harness deliberately does not have. This stands in for it so the broadcast
 * state machine can be exercised end to end without a database.
 *
 * Set $GLOBALS['__hti_subs'] to an array of chat ids before the code under
 * test runs.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

/**
 * In-memory stand-in for the subscriber table.
 */
class Bot_Store {

	/**
	 * How many people the bot can reach.
	 */
	public static function total(): int {
		return count( $GLOBALS['__hti_subs'] ?? array() );
	}

	/**
	 * One page of recipients, ordered by id, exactly as the real one is.
	 *
	 * @param int $after_id Exclusive lower bound.
	 * @param int $limit    How many.
	 * @return array<int,array{id:int,chat_id:int}>
	 */
	public static function page( int $after_id, int $limit ): array {
		$out = array();
		foreach ( array_values( $GLOBALS['__hti_subs'] ?? array() ) as $i => $chat_id ) {
			$id = $i + 1;
			if ( $id > $after_id ) {
				$out[] = array(
					'id'      => $id,
					'chat_id' => (int) $chat_id,
				);
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Remember a chat; true when it is one we had never seen.
	 *
	 * @param int $chat_id Chat id.
	 */
	public static function remember( int $chat_id ): bool {
		$subs = $GLOBALS['__hti_subs'] ?? array();
		if ( in_array( $chat_id, $subs, true ) ) {
			return false;
		}
		$subs[]                = $chat_id;
		$GLOBALS['__hti_subs'] = $subs;
		return true;
	}

	/**
	 * Stored pair and leverage for a chat.
	 *
	 * @param int $chat_id Chat id.
	 * @return array{pair:string,leverage:int}
	 */
	public static function prefs( int $chat_id ): array {
		return $GLOBALS['__hti_prefs'][ $chat_id ] ?? array(
			'pair'     => 'EURUSD',
			'leverage' => 500,
		);
	}

	/**
	 * Change one or both preferences.
	 *
	 * @param int         $chat_id  Chat id.
	 * @param string|null $pair     Pair, or null to leave it.
	 * @param int|null    $leverage Leverage, or null to leave it.
	 */
	public static function set_prefs( int $chat_id, ?string $pair, ?int $leverage ): void {
		$current = self::prefs( $chat_id );
		if ( null !== $pair ) {
			$current['pair'] = $pair;
		}
		if ( null !== $leverage ) {
			$current['leverage'] = $leverage;
		}
		$GLOBALS['__hti_prefs'][ $chat_id ] = $current;
	}

	/**
	 * Aggregate counters — nothing here is stored against anyone.
	 *
	 * @param float $inr Balance in rupees.
	 */
	public static function count_balance( float $inr ): void {
		$GLOBALS['__hti_balances'][] = $inr;
	}

	/**
	 * Count a campaign code once per new person.
	 *
	 * @param string $code Campaign code.
	 */
	public static function count_source( string $code ): void {
		if ( '' !== $code ) {
			$GLOBALS['__hti_sources'][] = $code;
		}
	}

	/**
	 * Remember where a chat came from — first touch only, as the real one is.
	 *
	 * @param int    $chat_id Chat id.
	 * @param string $source  Campaign code.
	 */
	public static function set_source( int $chat_id, string $source ): void {
		if ( '' === $source || '' !== ( $GLOBALS['__hti_chat_sources'][ $chat_id ] ?? '' ) ) {
			return;
		}
		$GLOBALS['__hti_chat_sources'][ $chat_id ] = $source;
	}

	/**
	 * Where a chat came from, or ''.
	 *
	 * @param int $chat_id Chat id.
	 */
	public static function source( int $chat_id ): string {
		return (string) ( $GLOBALS['__hti_chat_sources'][ $chat_id ] ?? '' );
	}

	/**
	 * Drop a chat that can never receive anything again.
	 *
	 * @param int $chat_id Chat id.
	 */
	public static function forget( int $chat_id ): void {
		$subs = $GLOBALS['__hti_subs'] ?? array();
		$GLOBALS['__hti_subs'] = array_values(
			array_filter( $subs, static fn( $id ): bool => (int) $id !== $chat_id )
		);
		unset( $GLOBALS['__hti_nudges'][ $chat_id ] );
	}

	/* -------------------------------------------------------------------------
	 * Nudge state, with the same conditional semantics as the real columns.
	 *
	 * $GLOBALS['__hti_nudges'] is chat_id => ['due' => int|null, 'nudged' => bool].
	 * The conditions matter more than the storage: "arm only what was never
	 * armed and never spent" and "claim only what is unspent" are the two rules
	 * that make at-most-one-ever a property of the table, so the stub enforces
	 * them rather than just recording values.
	 * ---------------------------------------------------------------------- */

	/**
	 * Row id for a chat — position in the subscriber list, as the real one is.
	 *
	 * @param int $chat_id Chat id.
	 */
	private static function id_of( int $chat_id ): int {
		$i = array_search( $chat_id, array_values( $GLOBALS['__hti_subs'] ?? array() ), true );
		return false === $i ? 0 : $i + 1;
	}

	/**
	 * Chat id for a row id.
	 *
	 * @param int $id Row id.
	 */
	private static function chat_of( int $id ): int {
		return (int) ( array_values( $GLOBALS['__hti_subs'] ?? array() )[ $id - 1 ] ?? 0 );
	}

	/**
	 * Arm a pending nudge, if nothing is pending and none was ever spent.
	 *
	 * @param int $chat_id Chat id.
	 * @param int $delay   Seconds from now.
	 */
	public static function arm_nudge( int $chat_id, int $delay ): void {
		$row = $GLOBALS['__hti_nudges'][ $chat_id ] ?? array(
			'due'    => null,
			'nudged' => false,
		);
		if ( $row['nudged'] || null !== $row['due'] ) {
			return;
		}
		$GLOBALS['__hti_nudges'][ $chat_id ] = array(
			'due'    => time() + $delay,
			'nudged' => false,
		);
	}

	/**
	 * Spend the nudge without sending it.
	 *
	 * @param int $chat_id Chat id.
	 */
	public static function disarm_nudge( int $chat_id ): void {
		$GLOBALS['__hti_nudges'][ $chat_id ] = array(
			'due'    => null,
			'nudged' => true,
		);
	}

	/**
	 * Pending nudges that are due and not too stale, oldest first.
	 *
	 * @param int $limit   How many.
	 * @param int $max_age Ignore anything due longer ago than this.
	 * @return array<int,array{id:int,chat_id:int}>
	 */
	public static function due_nudges( int $limit, int $max_age ): array {
		$now  = time();
		$rows = array();

		foreach ( $GLOBALS['__hti_nudges'] ?? array() as $chat_id => $row ) {
			if ( $row['nudged'] || null === $row['due'] ) {
				continue;
			}
			if ( $row['due'] > $now || $row['due'] < $now - $max_age ) {
				continue;
			}
			$rows[] = array(
				'id'      => self::id_of( (int) $chat_id ),
				'chat_id' => (int) $chat_id,
				'due'     => $row['due'],
			);
		}

		usort( $rows, static fn( array $a, array $b ): int => $a['due'] <=> $b['due'] );

		return array_map(
			static fn( array $r ): array => array(
				'id'      => $r['id'],
				'chat_id' => $r['chat_id'],
			),
			array_slice( $rows, 0, $limit )
		);
	}

	/**
	 * Claim one pending nudge; true only for the caller that got it.
	 *
	 * @param int $id Row id.
	 */
	public static function claim_nudge( int $id ): bool {
		$chat_id = self::chat_of( $id );
		$row     = $GLOBALS['__hti_nudges'][ $chat_id ] ?? null;

		if ( null === $row || $row['nudged'] ) {
			return false;
		}

		$GLOBALS['__hti_nudges'][ $chat_id ] = array(
			'due'    => null,
			'nudged' => true,
		);
		return true;
	}

	/**
	 * Timestamp of the earliest pending nudge, or 0.
	 */
	public static function next_nudge_due(): int {
		$due = array();
		foreach ( $GLOBALS['__hti_nudges'] ?? array() as $row ) {
			if ( ! $row['nudged'] && null !== $row['due'] ) {
				$due[] = (int) $row['due'];
			}
		}
		return array() === $due ? 0 : min( $due );
	}
}
