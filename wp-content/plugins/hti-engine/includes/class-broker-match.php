<?php
/**
 * Deterministic archetype → brokers matching for the post-result partner
 * module ("Passar à prática").
 *
 * Pure PHP (no WordPress, no LLM) so the matrix is unit-testable — the same
 * discipline as the Engine. The LLM never selects brokers (invariant 1 +
 * broker-affiliate skill), and nothing here is persisted into the profile:
 * the module is recomputed per request so it always reflects current deals.
 *
 * Selection rules, in order:
 * 1. keep brokers whose curated `profile_fit` includes the archetype;
 * 2. keep brokers whose `asset_classes` cover every allocation class weighted
 *    ≥ MIN_PCT (cash is always considered covered — any platform holds cash);
 * 3. order by `menu_order` (editorial), then slug (stable);
 * 4. cap at MAX_ITEMS;
 * 5. if fewer than MIN_ITEMS remain, top up with 'beginners'-tagged brokers
 *    (editorial order) so the module never renders half-empty.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Pure broker-matching rules.
 */
class Broker_Match {

	/**
	 * Allocation classes at or above this weight must be holdable.
	 */
	public const MIN_PCT = 10;

	/**
	 * At most this many suggestions.
	 */
	public const MAX_ITEMS = 3;

	/**
	 * Below this count, top up from the beginners-tagged pool.
	 */
	public const MIN_ITEMS = 2;

	/**
	 * Pick the brokers to suggest for an archetype + allocation.
	 *
	 * @param list<array<string,mixed>>              $brokers    Normalized records
	 *        (need: slug, menu_order, profile_fit (list<int>),
	 *        asset_classes (list<string>), use_cases (list<string>)).
	 * @param int                                    $archetype_id Archetype 1–5.
	 * @param list<array{class:string,pct:int|float}> $allocation  Fixed allocation.
	 * @return list<array<string,mixed>> At most MAX_ITEMS records.
	 */
	public static function pick( array $brokers, int $archetype_id, array $allocation ): array {
		$needed = self::needed_classes( $allocation );

		$eligible = array();
		foreach ( $brokers as $broker ) {
			$fit = array_map( 'intval', (array) ( $broker['profile_fit'] ?? array() ) );
			if ( ! in_array( $archetype_id, $fit, true ) ) {
				continue;
			}
			if ( ! self::covers( (array) ( $broker['asset_classes'] ?? array() ), $needed ) ) {
				continue;
			}
			$eligible[] = $broker;
		}

		$eligible = self::sort( $eligible );
		$picked   = array_slice( $eligible, 0, self::MAX_ITEMS );

		if ( count( $picked ) < self::MIN_ITEMS ) {
			$have = array_column( $picked, 'slug' );
			foreach ( self::sort( $brokers ) as $broker ) {
				if ( count( $picked ) >= self::MIN_ITEMS ) {
					break;
				}
				if ( in_array( (string) ( $broker['slug'] ?? '' ), $have, true ) ) {
					continue;
				}
				if ( ! in_array( 'beginners', (array) ( $broker['use_cases'] ?? array() ), true ) ) {
					continue;
				}
				$picked[] = $broker;
				$have[]   = (string) ( $broker['slug'] ?? '' );
			}
		}

		return $picked;
	}

	/**
	 * Allocation classes weighted at or above MIN_PCT (cash excluded — any
	 * platform can hold cash).
	 *
	 * @param list<array{class:string,pct:int|float}> $allocation Allocation.
	 * @return list<string>
	 */
	public static function needed_classes( array $allocation ): array {
		$needed = array();
		foreach ( $allocation as $slice ) {
			$class = (string) ( $slice['class'] ?? '' );
			$pct   = (float) ( $slice['pct'] ?? 0 );
			if ( '' === $class || 'cash' === $class || $pct < self::MIN_PCT ) {
				continue;
			}
			$needed[] = $class;
		}
		return $needed;
	}

	/**
	 * Whether a broker's holdable classes cover every needed class.
	 *
	 * @param list<string> $holdable Broker asset classes.
	 * @param list<string> $needed   Needed classes.
	 */
	private static function covers( array $holdable, array $needed ): bool {
		foreach ( $needed as $class ) {
			if ( ! in_array( $class, $holdable, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Editorial order: menu_order asc, then slug asc (stable, deterministic).
	 *
	 * @param list<array<string,mixed>> $brokers Records.
	 * @return list<array<string,mixed>>
	 */
	private static function sort( array $brokers ): array {
		usort(
			$brokers,
			static function ( array $a, array $b ): int {
				return ( (int) ( $a['menu_order'] ?? 0 ) <=> (int) ( $b['menu_order'] ?? 0 ) )
					?: strcmp( (string) ( $a['slug'] ?? '' ), (string) ( $b['slug'] ?? '' ) );
			}
		);
		return array_values( $brokers );
	}
}
