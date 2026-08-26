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
	 * Current progress for a user: chapters read (`done`) and quizzes passed
	 * (`passed`).
	 *
	 * @param int $uid User id.
	 * @return array{done:array<int,string>,passed:array<int,string>}
	 */
	public static function get( int $uid ): array {
		$p = get_user_meta( $uid, self::META, true );
		$p = is_array( $p ) ? $p : array();
		return array(
			'done'   => self::list_field( $p, 'done' ),
			'passed' => self::list_field( $p, 'passed' ),
		);
	}

	/**
	 * Merge sets of read / passed slugs into the stored progress (union) and save.
	 *
	 * @param int               $uid    User id.
	 * @param array<int,string> $done   Read chapter slugs to add.
	 * @param array<int,string> $passed Passed-quiz chapter slugs to add.
	 * @return array{done:array<int,string>,passed:array<int,string>}
	 */
	public static function merge( int $uid, array $done, array $passed = array() ): array {
		$cur    = self::get( $uid );
		$merged = array(
			'done'    => self::cap( array_values( array_unique( array_merge( $cur['done'], self::clean( $done ) ) ) ) ),
			'passed'  => self::cap( array_values( array_unique( array_merge( $cur['passed'], self::clean( $passed ) ) ) ) ),
			'updated' => time(),
		);
		update_user_meta( $uid, self::META, $merged );
		return array( 'done' => $merged['done'], 'passed' => $merged['passed'] );
	}

	/**
	 * Read a string-list field from a stored record.
	 *
	 * @param array<string,mixed> $p   Record.
	 * @param string              $key Field.
	 * @return array<int,string>
	 */
	private static function list_field( array $p, string $key ): array {
		$v = ( isset( $p[ $key ] ) && is_array( $p[ $key ] ) ) ? $p[ $key ] : array();
		return array_values( array_unique( array_map( 'strval', $v ) ) );
	}

	/**
	 * Cap a list to the stored maximum (keep the most recent).
	 *
	 * @param array<int,string> $list List.
	 * @return array<int,string>
	 */
	private static function cap( array $list ): array {
		return count( $list ) > self::MAX ? array_slice( $list, -self::MAX ) : $list;
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
