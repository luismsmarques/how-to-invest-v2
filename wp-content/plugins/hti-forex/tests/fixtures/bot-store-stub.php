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
	 * Drop a chat that can never receive anything again.
	 *
	 * @param int $chat_id Chat id.
	 */
	public static function forget( int $chat_id ): void {
		$subs = $GLOBALS['__hti_subs'] ?? array();
		$GLOBALS['__hti_subs'] = array_values(
			array_filter( $subs, static fn( $id ): bool => (int) $id !== $chat_id )
		);
	}
}
