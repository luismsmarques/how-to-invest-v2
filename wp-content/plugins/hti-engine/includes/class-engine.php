<?php
/**
 * The deterministic recommendation engine — the defensible core.
 *
 * Rules decide; the LLM only explains. This class turns questionnaire answers
 * into a fixed decision: a score (P1–P5), an archetype (1–5), an allocation by
 * asset class (summing to 100, within the curated ranges), and any safety-trap
 * flags. It is pure PHP — no WordPress calls, no LLM — so it is fully
 * deterministic and unit-testable.
 *
 * See docs/PRD §11, docs/Modelo_Dados §2–4, the hti-engine-spec skill.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic scoring, archetype mapping, allocation and safety traps.
 */
class Engine {

	/**
	 * Engine rules version, stored on every result for audit. Bump when the
	 * scoring/allocation logic or curated defaults change materially.
	 */
	public const VERSION = '1.0.0';

	/**
	 * Canonical asset-class output order.
	 */
	public const CLASSES = array( 'global_equity', 'bonds', 'reits_alt', 'cash', 'crypto' );

	/**
	 * Small fixed crypto slice (percent) when crypto is granted — always at
	 * the low end, clamped to the archetype's crypto range.
	 */
	public const CRYPTO_GRANT = 2;

	/**
	 * The five scored questions (P1–P5).
	 */
	private const SCORED = array( 'p1_horizon', 'p2_goal', 'p3_drop_reaction', 'p4_capacity', 'p5_experience' );

	/**
	 * Produce the full deterministic recommendation for a set of answers.
	 *
	 * @param array<string,mixed>      $answers    Questionnaire answers (P1–P8).
	 * @param array<string,mixed>|null $scoring    Scoring config; defaults to Config::scoring().
	 * @param array<int,mixed>|null    $archetypes Archetype config; defaults to Config::archetypes().
	 * @return array{score:int,archetype_id:int,allocation:list<array{class:string,pct:int}>,safety_flags:list<string>,engine_version:string}
	 *
	 * @throws \InvalidArgumentException If answers are missing or invalid.
	 * @throws \RuntimeException         If the curated ranges cannot yield a valid allocation.
	 */
	public static function recommend( array $answers, ?array $scoring = null, ?array $archetypes = null ): array {
		$scoring    = $scoring ?? Config::scoring();
		$archetypes = $archetypes ?? Config::archetypes();

		self::validate( $answers, $scoring );

		$score        = self::score( $answers, $scoring );
		$raw          = self::archetype_for_score( $score, $scoring['thresholds'] );
		$flags        = array();
		$archetype_id = $raw;

		// Trap 1 — no emergency fund (a lens on framing; portfolio is "for later").
		$no_emergency_fund = ( false === $answers['p6_emergency_fund'] );
		if ( $no_emergency_fund ) {
			$flags[] = 'no_emergency_fund';
		}

		// Trap 2 — short horizon overrides a high appetite (cap to archetype 1–2).
		if ( '3y' === $answers['p1_horizon'] && $raw >= 3 ) {
			$archetype_id = 2;
			$flags[]      = 'horizon_override';
		}

		// Crypto eligibility: requested AND archetype >= 3 AND no emergency-fund trap.
		$crypto_requested = ( 'yes' === ( $answers['p8_crypto'] ?? 'unknown' ) );
		$include_crypto   = $crypto_requested && $archetype_id >= 3 && ! $no_emergency_fund;

		// Trap 3 — crypto requested but conditions unmet.
		if ( $crypto_requested && ! $include_crypto ) {
			$flags[] = 'crypto_blocked';
		}

		if ( ! isset( $archetypes[ $archetype_id ]['ranges'] ) ) {
			throw new \RuntimeException( 'Missing allocation ranges for archetype ' . $archetype_id );
		}

		$allocation = self::allocate( $archetypes[ $archetype_id ]['ranges'], $include_crypto );

		return array(
			'score'          => $score,
			'archetype_id'   => $archetype_id,
			'allocation'     => $allocation,
			'safety_flags'   => $flags,
			'engine_version' => self::VERSION,
		);
	}

	/**
	 * Validate answers against the scoring config's allowed values.
	 *
	 * @param array<string,mixed> $answers Answers.
	 * @param array<string,mixed> $scoring Scoring config.
	 *
	 * @throws \InvalidArgumentException On any missing/invalid answer.
	 */
	public static function validate( array $answers, array $scoring ): void {
		foreach ( self::SCORED as $key ) {
			if ( ! isset( $answers[ $key ] ) ) {
				throw new \InvalidArgumentException( "Missing answer: {$key}" );
			}
			$allowed = array_keys( $scoring['weights'][ $key ] );
			if ( ! in_array( $answers[ $key ], $allowed, true ) ) {
				throw new \InvalidArgumentException( "Invalid value for {$key}: " . wp_json_encode( $answers[ $key ] ) );
			}
		}

		if ( ! array_key_exists( 'p6_emergency_fund', $answers ) || ! is_bool( $answers['p6_emergency_fund'] ) ) {
			throw new \InvalidArgumentException( 'p6_emergency_fund must be a boolean.' );
		}

		foreach ( array( 'p7_esg', 'p8_crypto' ) as $lens ) {
			if ( isset( $answers[ $lens ] ) && ! in_array( $answers[ $lens ], array( 'yes', 'no', 'unknown' ), true ) ) {
				throw new \InvalidArgumentException( "Invalid value for {$lens}." );
			}
		}
	}

	/**
	 * Sum the P1–P5 weights for the given answers.
	 *
	 * @param array<string,mixed> $answers Answers.
	 * @param array<string,mixed> $scoring Scoring config.
	 */
	public static function score( array $answers, array $scoring ): int {
		$total = 0;
		foreach ( self::SCORED as $key ) {
			$total += (int) $scoring['weights'][ $key ][ $answers[ $key ] ];
		}
		return $total;
	}

	/**
	 * Map a score to an archetype id via thresholds.
	 *
	 * @param int                          $score      Total score.
	 * @param array<int,array{0:int,1:int}> $thresholds Threshold ranges.
	 *
	 * @throws \RuntimeException If no threshold matches.
	 */
	public static function archetype_for_score( int $score, array $thresholds ): int {
		foreach ( $thresholds as $id => $range ) {
			if ( $score >= $range[0] && $score <= $range[1] ) {
				return (int) $id;
			}
		}
		throw new \RuntimeException( "No archetype threshold matches score {$score}." );
	}

	/**
	 * Build a concrete allocation within the curated ranges, summing to 100.
	 *
	 * Crypto takes a small fixed slice at the low end (when granted), then the
	 * remaining budget starts every other class at its minimum and is filled
	 * toward the maxima in a fixed, growth-leaning order. Deterministic and
	 * guaranteed within range given consistent curated ranges.
	 *
	 * @param array<string,array{0:int,1:int}> $ranges         Per-class [min,max].
	 * @param bool                              $include_crypto Whether to grant crypto.
	 * @return list<array{class:string,pct:int}> Non-zero classes, canonical order.
	 *
	 * @throws \RuntimeException If the ranges cannot produce a valid 100% allocation.
	 */
	public static function allocate( array $ranges, bool $include_crypto ): array {
		$fill_order = array( 'global_equity', 'bonds', 'reits_alt', 'cash' );

		$pct = array();
		foreach ( self::CLASSES as $class ) {
			$pct[ $class ] = 0;
		}

		// Crypto first (small, low end) or zero.
		$crypto_range  = $ranges['crypto'] ?? array( 0, 0 );
		$pct['crypto'] = $include_crypto
			? max( $crypto_range[0], min( $crypto_range[1], self::CRYPTO_GRANT ) )
			: 0;

		// Start the rest at their minimums.
		foreach ( $fill_order as $class ) {
			$pct[ $class ] = $ranges[ $class ][0];
		}

		$remaining = 100 - array_sum( $pct );
		if ( $remaining < 0 ) {
			throw new \RuntimeException( 'Curated minimums exceed 100%.' );
		}

		// Fill toward the maxima in priority order.
		foreach ( $fill_order as $class ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$headroom = $ranges[ $class ][1] - $pct[ $class ];
			$add      = min( $headroom, $remaining );
			$pct[ $class ] += $add;
			$remaining     -= $add;
		}

		if ( 0 !== $remaining ) {
			throw new \RuntimeException( 'Curated maximums cannot reach 100%.' );
		}

		self::assert_within_ranges( $pct, $ranges );

		$out = array();
		foreach ( self::CLASSES as $class ) {
			if ( $pct[ $class ] > 0 ) {
				$out[] = array(
					'class' => $class,
					'pct'   => (int) $pct[ $class ],
				);
			}
		}
		return $out;
	}

	/**
	 * Guard: every class within its curated range, total exactly 100.
	 *
	 * @param array<string,int>                 $pct    Per-class percentages.
	 * @param array<string,array{0:int,1:int}> $ranges Per-class [min,max].
	 *
	 * @throws \RuntimeException On any violation.
	 */
	private static function assert_within_ranges( array $pct, array $ranges ): void {
		if ( 100 !== array_sum( $pct ) ) {
			throw new \RuntimeException( 'Allocation does not sum to 100.' );
		}
		foreach ( $ranges as $class => $range ) {
			$value = $pct[ $class ] ?? 0;
			if ( $value < $range[0] || $value > $range[1] ) {
				throw new \RuntimeException( "Allocation for {$class} ({$value}) is outside its range." );
			}
		}
	}
}
