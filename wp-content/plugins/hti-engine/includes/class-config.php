<?php
/**
 * Curated, editable configuration for the recommendation engine.
 *
 * Holds the deterministic scoring weights/thresholds and the per-archetype
 * allocation ranges (Modelo de Dados §4). Defaults live here; admins can
 * override them via the `htinvest_scoring` / `htinvest_archetypes` options
 * without a deploy. Each change should bump the engine version for audit.
 *
 * Pure data + thin option wrappers — no side effects, so the engine and its
 * test suite can use the defaults directly.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Provides scoring and archetype configuration.
 */
class Config {

	public const OPTION_SCORING    = 'htinvest_scoring';
	public const OPTION_ARCHETYPES = 'htinvest_archetypes';

	/**
	 * Scoring config (option override, else defaults).
	 *
	 * @return array<string,mixed>
	 */
	public static function scoring(): array {
		if ( function_exists( 'get_option' ) ) {
			$stored = get_option( self::OPTION_SCORING );
			if ( is_array( $stored ) && isset( $stored['weights'], $stored['thresholds'] ) ) {
				return $stored;
			}
		}
		return self::default_scoring();
	}

	/**
	 * Archetype config (option override, else defaults).
	 *
	 * @return array<int,mixed>
	 */
	public static function archetypes(): array {
		if ( function_exists( 'get_option' ) ) {
			$stored = get_option( self::OPTION_ARCHETYPES );
			if ( is_array( $stored ) && ! empty( $stored ) ) {
				return $stored;
			}
		}
		return self::default_archetypes();
	}

	/**
	 * Default scoring weights and thresholds (Modelo de Dados §4).
	 * Max score = 6+6+6+6+3 = 27.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_scoring(): array {
		return array(
			'weights'    => array(
				'p1_horizon'       => array(
					'3y'       => 0,
					'3_7y'     => 2,
					'7_15y'    => 4,
					'over_15y' => 6,
				),
				'p2_goal'          => array(
					'protect'    => 0,
					'grow'       => 2,
					'accumulate' => 4,
					'maximize'   => 6,
				),
				'p3_drop_reaction' => array(
					'sell_all'  => 0,
					'sell_part' => 2,
					'hold'      => 4,
					'buy_more'  => 6,
				),
				'p4_capacity'      => array(
					'almost_none' => 0,
					'small'       => 2,
					'comfortable' => 4,
					'significant' => 6,
				),
				'p5_experience'    => array(
					'never'     => 0,
					'little'    => 1,
					'some'      => 2,
					'confident' => 3,
				),
			),
			'thresholds' => array(
				1 => array( 0, 5 ),
				2 => array( 6, 11 ),
				3 => array( 12, 17 ),
				4 => array( 18, 23 ),
				5 => array( 24, 27 ),
			),
		);
	}

	/**
	 * Default per-archetype labels and allocation ranges (by asset class).
	 *
	 * Each range is [min, max] in percent. Curated so that, for every
	 * archetype, sum(min) <= 100 <= sum(max), keeping a valid allocation
	 * reachable within the ranges. Crypto min is 0 everywhere (opt-in only).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function default_archetypes(): array {
		return array(
			1 => array(
				'label'  => array(
					'en' => 'Preservation',
					'pt' => 'Preservação',
				),
				'ranges' => array(
					'global_equity' => array( 5, 15 ),
					'bonds'         => array( 40, 55 ),
					'reits_alt'     => array( 0, 10 ),
					'cash'          => array( 20, 35 ),
					'crypto'        => array( 0, 0 ),
				),
			),
			2 => array(
				'label'  => array(
					'en' => 'Balanced income',
					'pt' => 'Rendimento equilibrado',
				),
				'ranges' => array(
					'global_equity' => array( 25, 40 ),
					'bonds'         => array( 35, 50 ),
					'reits_alt'     => array( 5, 12 ),
					'cash'          => array( 5, 15 ),
					'crypto'        => array( 0, 0 ),
				),
			),
			3 => array(
				'label'  => array(
					'en' => 'Balanced',
					'pt' => 'Equilibrado',
				),
				'ranges' => array(
					'global_equity' => array( 50, 65 ),
					'bonds'         => array( 25, 35 ),
					'reits_alt'     => array( 5, 10 ),
					'cash'          => array( 0, 5 ),
					'crypto'        => array( 0, 3 ),
				),
			),
			4 => array(
				'label'  => array(
					'en' => 'Growth',
					'pt' => 'Crescimento',
				),
				'ranges' => array(
					'global_equity' => array( 65, 80 ),
					'bonds'         => array( 10, 20 ),
					'reits_alt'     => array( 5, 10 ),
					'cash'          => array( 0, 5 ),
					'crypto'        => array( 0, 5 ),
				),
			),
			5 => array(
				'label'  => array(
					'en' => 'Aggressive growth',
					'pt' => 'Crescimento agressivo',
				),
				'ranges' => array(
					'global_equity' => array( 80, 92 ),
					'bonds'         => array( 0, 10 ),
					'reits_alt'     => array( 3, 8 ),
					'cash'          => array( 0, 5 ),
					'crypto'        => array( 0, 8 ),
				),
			),
		);
	}
}
