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
