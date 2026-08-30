<?php
/**
 * The Reveal: the arithmetic that scores a dossier.
 *
 * Pure, and integer, for the same reason as STC_Engine — this engine exists
 * twice, here and in assets/js/reveal-core.js, and the two have to agree
 * exactly rather than closely. Returns are basis points (+182% is 18200, a
 * total wipeout is -10000), sizes are whole percents, money is whole dollars,
 * and the one division that leaves the integers is rounded by the helper both
 * ports ship identically.
 *
 * The numbers are real: a case carries a company's actual five-year forward
 * return from a year at least Config::REVEAL_MIN_AGE_YEARS in the past. The
 * engine never opines on any of it — it multiplies what happened by what the
 * player committed, and puts the result next to what doing nothing would have
 * paid.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * The dossier game's decision arithmetic. Pure.
 */
class Reveal_Engine {

	/**
	 * One hundred percent, in basis points.
	 */
	public const BP = 10000;

	/**
	 * One hundred percent, in percent.
	 */
	public const PCT = 100;

	/**
	 * The dollars a decision actually puts on the table.
	 *
	 * Truncating, so a commitment is never a dollar more than the share the
	 * player chose, and exact in both languages.
	 *
	 * @param int $capital  Capital before the decision, in dollars.
	 * @param int $size_pct Share of the account committed, in percent.
	 * @return int Whole dollars.
	 */
	public static function committed( int $capital, int $size_pct ): int {
		return intdiv( $capital * max( 0, $size_pct ), self::PCT );
	}

	/**
	 * What a decision made or lost, in whole dollars.
	 *
	 * Computed from committed() rather than from capital × size × return in
	 * one product, for both of the reasons STC_Engine::cash() splits: the
	 * single product needs an intermediate a compounding account eventually
	 * pushes past Number.MAX_SAFE_INTEGER, where PHP stays exact and
	 * JavaScript does not; and the return is applied to exactly the figure the
	 * screen told the player they were committing, so "you put in $2,500" and
	 * the P&L are the same $2,500.
	 *
	 * @param int $capital  Capital before the decision, in dollars.
	 * @param int $size_pct Share of the account committed, in percent.
	 * @param int $r_bp     The case's real five-year return, in basis points.
	 * @return int Whole dollars, signed.
	 */
	public static function pnl( int $capital, int $size_pct, int $r_bp ): int {
		return STC_Engine::round_half_away_from_zero( ( self::committed( $capital, $size_pct ) * $r_bp ) / self::BP );
	}

	/**
	 * Compound the index player's capital by one case.
	 *
	 * The index player's entire strategy is doing nothing, and the point of
	 * carrying their balance alongside is that it is usually winning. Each
	 * case advances them by Config::REVEAL_INDEX_STEP_BP of the index's return
	 * over the same period — a tenth — because one dossier is one decision out
	 * of a life of them, not five years of the player's own money.
	 *
	 * Only the step is rounded, never the running balance, so a hundred days
	 * of compounding do not accumulate a hundred half-dollar errors.
	 *
	 * @param int $index_cap Index capital before the step, in dollars.
	 * @param int $r_idx_bp  The index's return over the case's period, in basis points.
	 * @return int Index capital after the step, in dollars.
	 */
	public static function index_step( int $index_cap, int $r_idx_bp ): int {
		return $index_cap + self::index_pnl( $index_cap, $r_idx_bp );
	}

	/**
	 * The dollars one index step moved, signed.
	 *
	 * @param int $index_cap Index capital before the step, in dollars.
	 * @param int $r_idx_bp  The index's return over the case's period, in basis points.
	 * @return int Whole dollars, signed.
	 */
	public static function index_pnl( int $index_cap, int $r_idx_bp ): int {
		// Split for the same reason as the player's P&L: one product of four
		// integers overflows a JavaScript double long before it overflows a
		// PHP int, and a silent disagreement between the two is the one bug
		// this whole harness exists to prevent.
		$exposure = intdiv( $index_cap * Config::REVEAL_INDEX_STEP_BP, self::BP );

		return STC_Engine::round_half_away_from_zero( ( $exposure * $r_idx_bp ) / self::BP );
	}

	/**
	 * Score one dossier.
	 *
	 * A pass costs nothing and is a real answer: "I could not tell from this"
	 * is the correct response to most dossiers, and the game says so by
	 * putting the pass line next to the other two rather than hiding it.
	 *
	 * The index advances whatever the player did. That is the honest part —
	 * the money that stayed out of the market still had a year, and the player
	 * who passes is not comparing against zero, they are comparing against the
	 * index they did not buy.
	 *
	 * Death is deliberately NOT decided here. The floor is one rule for both
	 * games and it lives in STC_Engine::apply(), which the caller runs on the
	 * capital this returns; two copies of a rule that ends a run is exactly
	 * the kind of duplication that eventually disagrees with itself.
	 *
	 * @param int    $r_bp      The case's real five-year return, in basis points.
	 * @param int    $r_idx_bp  The index's return over the same period, in basis points.
	 * @param string $decision  'invest' or 'pass'; anything else is read as a pass.
	 * @param int    $size_pct  Share of the account committed, in percent.
	 * @param int    $capital   Capital before the decision, in dollars.
	 * @param int    $index_cap Index capital before the decision, in dollars.
	 * @return array{decision:string,size_pct:int,committed:int,r_bp:int,r_idx_bp:int,pnl:int,capital:int,index_pnl:int,index_cap:int,lines:array}
	 */
	public static function resolve( int $r_bp, int $r_idx_bp, string $decision, int $size_pct, int $capital, int $index_cap ): array {
		// A decision arriving from the open web with no size behind it is a
		// pass whatever it calls itself; membership of the offered sizes is
		// still checked separately at the REST layer.
		$invest   = 'pass' !== $decision && $size_pct > 0;
		$size_pct = $invest ? $size_pct : 0;

		$pnl       = $invest ? self::pnl( $capital, $size_pct, $r_bp ) : 0;
		$index_pnl = self::index_pnl( $index_cap, $r_idx_bp );

		return array(
			'decision'  => $invest ? 'invest' : 'pass',
			'size_pct'  => $size_pct,
			'committed' => $invest ? self::committed( $capital, $size_pct ) : 0,
			'r_bp'      => $r_bp,
			'r_idx_bp'  => $r_idx_bp,
			'pnl'       => $pnl,
			'capital'   => $capital + $pnl,
			'index_pnl' => $index_pnl,
			'index_cap' => $index_cap + $index_pnl,
			'lines'     => self::three_lines( $pnl, $index_pnl ),
		);
	}

	/**
	 * The three lines the result screen is built around.
	 *
	 * What you did, what doing nothing would have done, and what the index
	 * did. The middle line is always zero and is shown anyway: without it the
	 * player reads a loss as bad luck instead of as a decision, and a gain as
	 * skill instead of as a market that went up for everybody.
	 *
	 * Keys, not sentences — the wording is bilingual and lives in Strings.
	 *
	 * @param int $pnl       What the player's decision made or lost.
	 * @param int $index_pnl What the index did over the same period.
	 * @return array<int,array{key:string,pnl:int}>
	 */
	public static function three_lines( int $pnl, int $index_pnl ): array {
		return array(
			array(
				'key' => 'you',
				'pnl' => $pnl,
			),
			array(
				'key' => 'pass',
				'pnl' => 0,
			),
			array(
				'key' => 'index',
				'pnl' => $index_pnl,
			),
		);
	}
}
