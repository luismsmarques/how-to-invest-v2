<?php
/**
 * Learn progress storage for signed-in users (course system, phase 1).
 *
 * Guests keep their progress anonymously in the browser (localStorage); for a
 * signed-in account we persist the set of completed chapter slugs in user meta
 * so it follows them across devices. The client merges the two on login (union),
 * which is why save() always merges rather than overwrites.
 *
 * Quiz scores and badges build on this same record in later phases.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Account-bound Learn progress (completed chapters).
 */
class Learn {

	/**
	 * User-meta key holding the progress record.
	 */
	public const META = 'hti_learn_progress';

	/**
	 * Safety cap on stored chapter slugs.
	 */
	private const MAX = 200;

	/**
	 * Current progress for a user.
	 *
	 * @param int $uid User id.
	 * @return array{done:array<int,string>}
	 */
	public static function get( int $uid ): array {
		$p    = get_user_meta( $uid, self::META, true );
		$done = ( is_array( $p ) && isset( $p['done'] ) && is_array( $p['done'] ) ) ? $p['done'] : array();
		return array( 'done' => array_values( array_unique( array_map( 'strval', $done ) ) ) );
	}

	/**
	 * Merge a set of completed slugs into the stored progress (union) and save.
	 *
	 * @param int               $uid   User id.
	 * @param array<int,string> $slugs Completed chapter slugs to add.
	 * @return array{done:array<int,string>}
	 */
	public static function merge( int $uid, array $slugs ): array {
		$add    = self::clean( $slugs );
		$cur    = self::get( $uid )['done'];
		$merged = array_values( array_unique( array_merge( $cur, $add ) ) );
		if ( count( $merged ) > self::MAX ) {
			$merged = array_slice( $merged, -self::MAX );
		}
		update_user_meta( $uid, self::META, array( 'done' => $merged, 'updated' => time() ) );
		return array( 'done' => $merged );
	}

	/**
	 * Sanitize an arbitrary list into safe chapter slugs.
	 *
	 * @param array<int,mixed> $slugs Raw slugs.
	 * @return array<int,string>
	 */
	private static function clean( array $slugs ): array {
		$out = array();
		foreach ( $slugs as $s ) {
			$s = sanitize_key( (string) $s );
			if ( '' !== $s && strlen( $s ) <= 80 ) {
				$out[] = $s;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Delete a user's Learn progress (RGPD erasure helper).
	 *
	 * @param int $uid User id.
	 */
	public static function erase( int $uid ): void {
		delete_user_meta( $uid, self::META );
	}
}
