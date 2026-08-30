<?php
/**
 * The single source of structure for both games.
 *
 * Everything here is pure data or a pure function over it: the seeder, the
 * JSON-LD, the REST layer, the admin and the front end all read this one table
 * so that a slug, a risk tier or a page title cannot disagree with itself in
 * two places. The same reasoning as HTI\Forex\Config.
 *
 * Numbers are the contract: risk in basis points, money in whole dollars,
 * prices in integer ticks. There is no float anywhere in the decision path,
 * which is what lets the PHP and the JavaScript agree exactly rather than
 * approximately.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Structural configuration. Pure.
 */
class Config {

	/**
	 * Game identifiers as they appear in URLs, REST paths and metric labels.
	 */
	public const GAME_STC    = 'stc';
	public const GAME_REVEAL = 'reveal';

	/**
	 * The same two games as they are stored — a tinyint, because this column
	 * is in every leaderboard index and a varchar there would be waste.
	 */
	public const GAME_IDS = array(
		self::GAME_STC    => 1,
		self::GAME_REVEAL => 2,
	);

	/**
	 * Post types holding the content of each game.
	 */
	public const CPT_SCENARIO = 'hti_stc_scenario';
	public const CPT_CASE     = 'hti_reveal_case';

	/**
	 * Starting virtual capital, in whole dollars.
	 */
	public const CAPITAL_START = 10000;

	/**
	 * At or below this the account is blown: the run resets and the record is
	 * kept. Comparison is `<=`, so exactly 1000 is death.
	 */
	public const CAPITAL_FLOOR = 1000;

	/**
	 * Price scale: stored ticks are price × this. 1.09120 is stored 109120.
	 */
	public const TICK_SCALE = 100000;

	/**
	 * Survive the Charts: the shape of a scenario.
	 */
	public const STC_VISIBLE     = 80;
	public const STC_OUTCOME     = 40;
	public const STC_ATR_PERIOD  = 14;
	/**
	 * Stop is 1×ATR from entry; target is 1.5×ATR. Expressed as a fraction so
	 * the arithmetic stays in integers: target = entry ± intdiv(atr * 3, 2).
	 */
	public const STC_TARGET_NUM = 3;
	public const STC_TARGET_DEN = 2;

	/**
	 * Risk tiers, in basis points of the current capital.
	 *
	 * The row escalates deliberately: the first two are what the game is
	 * trying to teach, 200 is the classic ceiling, and 2500 exists so that a
	 * player can find out in two minutes what it costs.
	 */
	public const STC_RISK_BP = array( 50, 100, 200, 500, 1000, 2500 );

	/**
	 * The optional multiplier on both sides of the trade.
	 *
	 * Named "double stake" and never "turbo": the mechanic is unchanged, but a
	 * word that reads as an inducement has no place on a page about position
	 * size, on a site that elsewhere carries broker affiliation.
	 */
	public const STC_DOUBLE = 2;

	/**
	 * The Reveal: how much of the account a decision can commit, in percent.
	 */
	public const REVEAL_SIZES = array( 5, 10, 25, 50 );

	/**
	 * The index the player is measured against compounds a tenth of the
	 * case's index return per day played — the "index player" whose whole
	 * strategy is doing nothing.
	 */
	public const REVEAL_INDEX_STEP_BP = 1000;

	/**
	 * A case is only served when its subject is at least this many years past.
	 * Named companies are a delimited exemption to invariant 2, and "history,
	 * not a view on today" is the line that keeps it one.
	 */
	public const REVEAL_MIN_AGE_YEARS = 5;

	/**
	 * The pool must be at least this large, and entirely real data, before the
	 * landing page is allowed to call the charts historical.
	 */
	public const REAL_CLAIM_MIN_POOL = 30;

	/**
	 * The pages the seeder owns: key => [ en slug, pt slug, indexable ].
	 *
	 * `/games/` and not `/educational-games/`: "games" is what people type,
	 * and the qualifier belongs in the H1 and the title tag, not in the path.
	 *
	 * @return array<string,array{en:string,pt:string,parent:?string,index:bool}>
	 */
	public static function pages(): array {
		return array(
			'hub'         => array(
				'en'     => 'games',
				'pt'     => 'jogos',
				'parent' => null,
				'index'  => true,
			),
			'stc'         => array(
				'en'     => 'survive-the-charts',
				'pt'     => 'sobreviver-aos-graficos',
				'parent' => 'hub',
				'index'  => true,
			),
			'reveal'      => array(
				'en'     => 'the-reveal',
				'pt'     => 'a-revelacao',
				'parent' => 'hub',
				'index'  => true,
			),
			'leaderboard' => array(
				'en'     => 'leaderboard',
				'pt'     => 'classificacao',
				'parent' => 'hub',
				'index'  => true,
			),
			'profile'     => array(
				'en'     => 'profile',
				'pt'     => 'perfil',
				'parent' => 'hub',
				// One player's own numbers: nothing to rank, and a thin page
				// per visitor if it were indexed.
				'index'  => false,
			),
		);
	}

	/**
	 * Whether a game id is one we serve.
	 *
	 * @param string $game Candidate id.
	 */
	public static function is_game( string $game ): bool {
		return isset( self::GAME_IDS[ $game ] );
	}

	/**
	 * Numeric id for storage.
	 *
	 * @param string $game Game id.
	 */
	public static function game_id( string $game ): int {
		return self::GAME_IDS[ $game ] ?? 0;
	}

	/**
	 * Whether a risk tier is one the UI actually offers.
	 *
	 * The value arrives from the open web with every decision, so it is
	 * checked against the offered set rather than merely range-checked: a
	 * clamp would silently accept 2499 and quietly change the game.
	 *
	 * @param int $bp Risk in basis points.
	 */
	public static function is_risk_bp( int $bp ): bool {
		return in_array( $bp, self::STC_RISK_BP, true );
	}

	/**
	 * Whether an investment size is one The Reveal actually offers.
	 *
	 * @param int $pct Percent of capital.
	 */
	public static function is_size( int $pct ): bool {
		return in_array( $pct, self::REVEAL_SIZES, true );
	}
}
