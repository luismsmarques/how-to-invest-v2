<?php
/**
 * Survive the Charts: where the daily charts come from when the import bin is
 * empty — and, more importantly, where they are PROVEN to teach what their
 * label says they teach.
 *
 * The generating half is ordinary: a seeded pseudo-random walk in integer tick
 * space, shaped three different ways. The half that matters is the one below
 * it. Every candidate scenario is played out through STC_Engine::resolve() in
 * BOTH directions before it is allowed to exist, and it is kept only if the
 * arithmetic agrees with the label: a `trap` must actually stop out the
 * direction its visible window implies, and quickly; a `reasonable` must
 * actually reach the target in that direction; an `ambiguous` must actually
 * resolve nothing either way. A candidate that fails is thrown away and the
 * walk continues.
 *
 * That step is not a nicety. Without it, sixty "generated" scenarios are just
 * noise with labels attached — a `trap` that happens to run to target, a
 * `reasonable` that stops out on candle two — and the difference between a
 * game and a coin flip is exactly whether the label is a claim the code has
 * checked. The engine is the only thing entitled to say what a chart does, so
 * the generator asks it rather than asserting it.
 *
 * WHY A HAND-ROLLED PRNG. mt_srand()/mt_rand() are not a stable function of
 * the seed: PHP has changed Mt19937's seeding and output before, and nothing
 * promises it will not again. `hti_stc_seed` is only meaningful if it is a
 * permanent address — put the seed back in and get the identical 120 candles
 * back, on any host, in any PHP version, forever — because that is what lets
 * an editor reproduce a chart a player is arguing about, and what makes the
 * regression lock in tests/test-generator.php mean anything. So the PRNG is
 * mulberry32, written out here in exact 32-bit arithmetic. It is also the
 * algorithm a future JavaScript port would use verbatim (the constants and the
 * operation order below match the canonical implementation, Math.imul and
 * all), so a client-side preview could regenerate the same chart.
 *
 * WHY 365 AND NOT 60. A daily game is played by people who come back. A
 * library of sixty wraps inside two months, visibly — the returning player
 * meets a chart they have already been stopped out on, and the day stops being
 * a decision. Generation is deterministic and free, so the reason to build a
 * year rather than two months is that there is no reason not to.
 *
 * Everything above the WP-CLI divider is pure: integers in, integers out, no
 * WordPress, no database, no clock. The WordPress half is the thin part at the
 * bottom, and it only ever creates DRAFTS — publishing a chart stays a human
 * act, exactly as it is for the importer. Nothing here runs on `init` and
 * nothing here runs on activation: a plugin that silently manufactures 365
 * posts because somebody clicked Activate is a plugin nobody trusts.
 *
 * A candle is array{o:int,h:int,l:int,c:int}, in ticks (price × TICK_SCALE).
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic scenario generation, validated by simulation. Pure.
 */
class STC_Generator {

	/**
	 * Generator version, carried into `hti_stc_source`.
	 *
	 * Bump it when the shaping changes, so that a scenario's seed is read
	 * against the code that produced it and not against today's code.
	 */
	public const VERSION = 'v1';

	/**
	 * The three pedagogic classes, and how much of a library each should be,
	 * in basis points.
	 *
	 * The mix is a curriculum, not a taste. Most days a chart should be
	 * legible, or the game teaches that reading a chart is pointless; a
	 * quarter of them should punish the legible reading, or the game teaches
	 * that a clean trend is a promise; and the third of days in between is
	 * where the actual subject lives, because a market that resolves nothing
	 * is the one where only position size changed your balance.
	 */
	public const MIX_BP = array(
		'reasonable' => 4000,
		'ambiguous'  => 3500,
		'trap'       => 2500,
	);

	/**
	 * Candles per scenario: what the player sees plus what plays out.
	 */
	public const LENGTH = Config::STC_VISIBLE + Config::STC_OUTCOME;

	/**
	 * How far back implied_direction() looks, in candles.
	 *
	 * Deliberately Config::STC_ATR_PERIOD and not a number of its own: the
	 * window the eye reads the trend from and the window the stop is sized
	 * from are then the same fourteen candles, so "what is this chart saying"
	 * and "how big is one unit of risk" are answered on the same evidence.
	 */
	public const IMPLIED_LOOKBACK = Config::STC_ATR_PERIOD;

	/**
	 * A trap has to spring while the player is still congratulating
	 * themselves. Past this many outcome candles it is not a trap, it is a
	 * trend that turned — a different and much less interesting lesson.
	 */
	public const TRAP_MAX_CANDLE = 6;

	/**
	 * The most an `ambiguous` day may be worth to either side, in basis
	 * points of R. 0.75R in 40 candles is a market that answered nothing.
	 */
	public const AMBIGUOUS_MAX_R = 7500;

	/**
	 * Attempts before a class is declared ungeneratable.
	 *
	 * Bounded on purpose. A generator that loops until it succeeds turns a
	 * shaping bug into a hung CLI process on a live host, and a generator that
	 * quietly returns fewer scenarios than asked ships a library that wraps
	 * early. Both failures are silent; an exception is not.
	 */
	public const MAX_ATTEMPTS = 400;

	/**
	 * Price band a scenario's series starts in, in ticks.
	 *
	 * Varied so that the whole library does not sit on one price level — the
	 * instrument is never named, but a y-axis that reads the same every single
	 * day is its own kind of tell.
	 */
	private const BASE_MIN = 80000;
	private const BASE_MAX = 160000;

	/**
	 * Per-candle volatility, in hundredths of a percent of the base price.
	 * 8..18 is 0.08%..0.18% a candle, which is an ordinary daily range.
	 */
	private const VOL_MIN_BP = 8;
	private const VOL_MAX_BP = 18;

	/* ---------------------------------------------------------------------
	 * The PRNG. mulberry32, in exact 32-bit integer arithmetic.
	 * ------------------------------------------------------------------- */

	/**
	 * A fresh PRNG state from a seed.
	 *
	 * @param int $seed Any integer; only the low 32 bits are used.
	 * @return int Opaque state, to be passed by reference to rng_next().
	 */
	public static function rng_state( int $seed ): int {
		return $seed & 0xFFFFFFFF;
	}

	/**
	 * The next 32-bit value, advancing the state.
	 *
	 * Returns the raw uint32 rather than a 0..1 float, because a float would
	 * be the one place a language difference could creep back in and because
	 * every consumer here wants an integer anyway.
	 *
	 * @param int $state PRNG state, advanced in place.
	 * @return int 0 .. 4294967295.
	 */
	public static function rng_next( int &$state ): int {
		$state = ( $state + 0x6D2B79F5 ) & 0xFFFFFFFF;
		$t     = $state;
		$t     = self::imul( $t ^ ( $t >> 15 ), 1 | $t );
		$t     = ( ( $t + self::imul( $t ^ ( $t >> 7 ), 61 | $t ) ) ^ $t ) & 0xFFFFFFFF;

		return ( $t ^ ( $t >> 14 ) ) & 0xFFFFFFFF;
	}

	/**
	 * A uniform integer in [$min, $max], inclusive.
	 *
	 * @param int $state PRNG state, advanced in place.
	 * @param int $min   Lower bound.
	 * @param int $max   Upper bound; returns $min when it is below it.
	 * @return int
	 */
	public static function rng_int( int &$state, int $min, int $max ): int {
		if ( $max <= $min ) {
			return $min;
		}

		// Modulo would bias the low end of the range; multiplying the draw by
		// the span and taking the high bits does not, and stays in integers.
		return $min + intdiv( self::rng_next( $state ) * ( $max - $min + 1 ), 4294967296 );
	}

	/**
	 * 32-bit multiply, keeping the low 32 bits — JavaScript's Math.imul.
	 *
	 * Split into 16-bit halves rather than multiplied outright: two 32-bit
	 * operands multiply to 64 bits, which overflows a PHP int into a float and
	 * silently loses the low bits — precisely the ones this needs.
	 *
	 * @param int $a Left operand.
	 * @param int $b Right operand.
	 * @return int
	 */
	private static function imul( int $a, int $b ): int {
		$a &= 0xFFFFFFFF;
		$b &= 0xFFFFFFFF;

		$high = ( $a >> 16 ) & 0xFFFF;
		$low  = $a & 0xFFFF;

		// The high half is masked BEFORE the shift: only its low 16 bits can
		// reach the 32-bit result, and shifting first would overflow.
		return ( ( ( ( $high * $b ) & 0xFFFF ) << 16 ) + ( $low * $b ) ) & 0xFFFFFFFF;
	}

	/* ---------------------------------------------------------------------
	 * What the visible window says.
	 * ------------------------------------------------------------------- */

	/**
	 * The direction the visible window implies.
	 *
	 * The sign of the close-to-close move over the last IMPLIED_LOOKBACK
	 * candles. Chosen because it is the crudest reading that a player can
	 * verify by eye — "is the right-hand end of this chart above or below
	 * where it was two weeks ago" — and because a generator whose notion of
	 * "what the chart implies" needed a paragraph to explain would be
	 * validating its scenarios against an opinion nobody shares. It is not a
	 * trading signal and is never shown to anybody; it exists so that "the
	 * direction the visible window implies" is one specific thing when the
	 * validator asks.
	 *
	 * @param array<int,array{o:int,h:int,l:int,c:int}> $visible The candles the player would see.
	 * @return string 'buy', 'sell', or '' when the window implies nothing at all.
	 */
	public static function implied_direction( array $visible ): string {
		$visible = array_values( $visible );
		$count   = count( $visible );
		$look    = min( self::IMPLIED_LOOKBACK, $count - 1 );

		if ( $look < 1 ) {
			return '';
		}

		$move = (int) $visible[ $count - 1 ]['c'] - (int) $visible[ $count - 1 - $look ]['c'];

		if ( 0 === $move ) {
			// Exactly flat over a fortnight. Rare, and there is no honest
			// answer, so the generator throws the candidate away rather than
			// picking a side for it.
			return '';
		}

		return $move > 0 ? 'buy' : 'sell';
	}

	/**
	 * The other side of a position.
	 *
	 * @param string $direction 'buy' or 'sell'.
	 * @return string
	 */
	public static function opposite( string $direction ): string {
		return 'sell' === $direction ? 'buy' : 'sell';
	}

	/* ---------------------------------------------------------------------
	 * Generation.
	 * ------------------------------------------------------------------- */

	/**
	 * One scenario of a given class, from a given seed.
	 *
	 * The seed is the scenario's permanent address: the same class and seed
	 * give back the identical 120 candles on any host, forever.
	 *
	 * Rejection sampling, bounded: a candidate is shaped, played out by the
	 * engine, and kept only if it behaves like its class. Failures advance the
	 * PRNG, so attempt two is a different chart rather than the same one
	 * again, and the whole run stays a function of the seed alone.
	 *
	 * @param string $class 'reasonable', 'ambiguous' or 'trap'.
	 * @param int    $seed  Scenario seed.
	 * @throws \RuntimeException When no candidate passes within MAX_ATTEMPTS — loudly, because a short library and an unverified label are both silent failures.
	 * @return array{class:string,seed:int,attempts:int,scale:int,visible:int,outcome:int,candles:array<int,array{o:int,h:int,l:int,c:int}>,entry:int,atr:int,implied:string,pass_right:bool,checksum:string}
	 */
	public static function scenario( string $class, int $seed ): array {
		if ( ! in_array( $class, CPT::SCENARIO_CLASSES, true ) ) {
			throw new \RuntimeException( 'unknown scenario class: ' . $class );
		}

		$state = self::rng_state( $seed );

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$base  = self::rng_int( $state, self::BASE_MIN, self::BASE_MAX );
			$sigma = max( 4, intdiv( $base * self::rng_int( $state, self::VOL_MIN_BP, self::VOL_MAX_BP ), 10000 ) );
			$dir   = 0 === self::rng_int( $state, 0, 1 ) ? 1 : -1;

			$visible = 'ambiguous' === $class
				? self::visible_range( $state, $base, $sigma )
				: self::visible_trend( $state, $base, $sigma, $dir, 'trap' === $class );

			$entry = (int) $visible[ count( $visible ) - 1 ]['c'];
			$atr   = STC_Engine::atr( $visible, Config::STC_ATR_PERIOD );

			// A flat window has no risk unit, so nothing can be sized off it
			// and the engine would resolve the day as free and flat.
			if ( $atr <= 0 ) {
				continue;
			}

			$implied = self::implied_direction( $visible );
			if ( '' === $implied ) {
				continue;
			}

			// For the two directional classes the label is a statement about
			// the implied direction, so a window that ended up implying the
			// other way is not a candidate at all — it is a different chart.
			if ( 'ambiguous' !== $class && $implied !== ( $dir > 0 ? 'buy' : 'sell' ) ) {
				continue;
			}

			switch ( $class ) {
				case 'reasonable':
					$after = self::outcome_continuation( $state, $entry, $atr, $dir );
					break;
				case 'trap':
					$after = self::outcome_whipsaw( $state, $entry, $atr, $dir );
					break;
				default:
					$after = self::outcome_chop( $state, $entry, $atr );
					break;
			}

			$verdict = self::behaviour( $visible, $after, $implied );

			if ( ! self::behaves_like( $class, $verdict ) ) {
				continue;
			}

			$candles = array_merge( $visible, $after );

			return array(
				'class'      => $class,
				'seed'       => $seed,
				'attempts'   => $attempt,
				'scale'      => Config::TICK_SCALE,
				'visible'    => Config::STC_VISIBLE,
				'outcome'    => Config::STC_OUTCOME,
				'candles'    => $candles,
				'entry'      => $entry,
				'atr'        => $atr,
				'implied'    => $implied,
				// Not asserted from the label: passing is right exactly when
				// neither side of the trade was worth taking, and the engine
				// is what says so.
				'pass_right' => $verdict['with']['r_bp'] <= 0 && $verdict['against']['r_bp'] <= 0,
				'checksum'   => self::checksum( $candles ),
			);
		}

		throw new \RuntimeException(
			sprintf( 'no %s scenario survived validation in %d attempts (seed %d)', $class, self::MAX_ATTEMPTS, $seed )
		);
	}

	/**
	 * A library of scenarios honouring MIX_BP, from one run seed.
	 *
	 * A library's address is the seed AND the count, not the seed alone: the
	 * class counts come from the mix, so asking for 400 reshuffles the plan and
	 * every draw after the first differs. Re-running with a different --count
	 * therefore builds an entirely new library rather than extending the old
	 * one, which is why the CLI dedupes on the scenario seed and reports how
	 * many it skipped.
	 *
	 * @param int $count How many scenarios.
	 * @param int $seed  Run seed; every scenario's own seed is derived from it.
	 * @throws \RuntimeException Propagated from scenario().
	 * @return array<int,array<string,mixed>>
	 */
	public static function batch( int $count, int $seed ): array {
		$count = max( 0, $count );
		$state = self::rng_state( $seed );
		$plan  = self::plan( $count, $state );
		$out   = array();

		foreach ( $plan as $class ) {
			// Drawn from the run PRNG rather than being the index, so that
			// two libraries generated from different run seeds share no
			// scenarios, and a scenario's seed says nothing about its date.
			$out[] = self::scenario( $class, self::rng_next( $state ) );
		}

		return $out;
	}

	/**
	 * The observed class distribution of a set of scenarios.
	 *
	 * All three classes are always present, zero-filled, so a caller can
	 * compare two mixes without checking for missing keys first.
	 *
	 * @param array<int,array<string,mixed>> $scenarios From batch().
	 * @return array<string,int> class => count.
	 */
	public static function mix( array $scenarios ): array {
		$out = array_fill_keys( CPT::SCENARIO_CLASSES, 0 );

		foreach ( $scenarios as $scenario ) {
			$class = (string) ( $scenario['class'] ?? '' );
			if ( isset( $out[ $class ] ) ) {
				++$out[ $class ];
			}
		}

		return $out;
	}

	/**
	 * The class of each scenario in a library, in the order it will be built.
	 *
	 * Counts come from MIX_BP; the remainder goes to the class the library is
	 * shortest of, which for any realistic count is the trap. The order is
	 * then shuffled, because a library that runs 146 legible days and only
	 * then starts trapping teaches its own rhythm rather than the lesson.
	 *
	 * @param int $count Library size.
	 * @param int $state PRNG state, advanced in place.
	 * @return array<int,string>
	 */
	public static function plan( int $count, int &$state ): array {
		$plan  = array();
		$taken = 0;

		$classes = array_keys( self::MIX_BP );
		$last    = (string) end( $classes );

		foreach ( self::MIX_BP as $class => $bp ) {
			$n = $class === $last ? $count - $taken : intdiv( $count * $bp, 10000 );
			$n = max( 0, $n );

			for ( $i = 0; $i < $n; $i++ ) {
				$plan[] = $class;
			}
			$taken += $n;
		}

		// Fisher-Yates, off our own PRNG — shuffle() would reintroduce exactly
		// the "stable across PHP versions?" problem mt_rand was avoided for.
		for ( $i = count( $plan ) - 1; $i > 0; $i-- ) {
			$j           = self::rng_int( $state, 0, $i );
			$swap        = $plan[ $i ];
			$plan[ $i ]  = $plan[ $j ];
			$plan[ $j ]  = $swap;
		}

		return $plan;
	}

	/* ---------------------------------------------------------------------
	 * Validation by simulation — the part that makes a label a claim.
	 * ------------------------------------------------------------------- */

	/**
	 * Play a candidate out in both directions.
	 *
	 * Uses the engine's own pass path, which reports both legs, so the
	 * validator sees precisely what a player who passed would be shown — and
	 * cannot drift from it by resolving the two sides some other way.
	 *
	 * The risk tier and capital are the smallest offered tier and the
	 * starting capital: nothing here reads the money, only `outcome`,
	 * `candle` and `r_bp`, which are the same at any tier.
	 *
	 * @param array<int,array{o:int,h:int,l:int,c:int}> $visible Visible candles.
	 * @param array<int,array{o:int,h:int,l:int,c:int}> $after   Outcome candles.
	 * @param string                                    $implied Direction the window implies.
	 * @return array{with:array<string,mixed>,against:array<string,mixed>}
	 */
	public static function behaviour( array $visible, array $after, string $implied ): array {
		$both = STC_Engine::resolve( $visible, $after, 'pass', Config::STC_RISK_BP[0], false, Config::CAPITAL_START );
		$legs = is_array( $both['would'] ?? null ) ? $both['would'] : array();

		return array(
			'with'    => (array) ( $legs[ $implied ] ?? array() ),
			'against' => (array) ( $legs[ self::opposite( $implied ) ] ?? array() ),
		);
	}

	/**
	 * Whether a played-out candidate deserves its label.
	 *
	 * Each rule is the class's promise to the player, written as arithmetic:
	 *
	 *  - `reasonable`: the direction the window implied reached the target.
	 *    The chart was legible and the legible reading was paid.
	 *  - `trap`: the implied direction was stopped out, within TRAP_MAX_CANDLE
	 *    candles, AND the other side was not paid either. The second half is
	 *    what makes passing the right answer rather than merely a safe one —
	 *    a chart that punishes the obvious trade but rewards its opposite is
	 *    not a trap, it is a reasonable scenario the generator mislabelled.
	 *  - `ambiguous`: neither side resolved at all inside the window, and
	 *    neither ended more than AMBIGUOUS_MAX_R from flat. Nobody was right,
	 *    nobody was punished, and the only thing that moved the account was
	 *    how much was put behind the guess. That is the lesson.
	 *
	 * @param string                                                     $class   Claimed class.
	 * @param array{with:array<string,mixed>,against:array<string,mixed>} $verdict From behaviour().
	 * @return bool
	 */
	public static function behaves_like( string $class, array $verdict ): bool {
		$with    = $verdict['with'];
		$against = $verdict['against'];

		if ( ! isset( $with['outcome'], $against['outcome'] ) ) {
			return false;
		}

		switch ( $class ) {
			case 'reasonable':
				return 'target' === $with['outcome'];

			case 'trap':
				return 'stop' === $with['outcome']
					&& (int) $with['candle'] <= self::TRAP_MAX_CANDLE
					&& (int) $against['r_bp'] <= 0;

			case 'ambiguous':
				return 'open' === $with['outcome']
					&& 'open' === $against['outcome']
					&& abs( (int) $with['r_bp'] ) <= self::AMBIGUOUS_MAX_R
					&& abs( (int) $against['r_bp'] ) <= self::AMBIGUOUS_MAX_R;
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Shaping. Each of these produces a candidate; none of them is trusted.
	 * ------------------------------------------------------------------- */

	/**
	 * A visible window with a direction in it.
	 *
	 * A drifted random walk. The `clean` variant — used for traps — carries
	 * more drift and less noise, so the chart reads as an orderly continuation
	 * rather than a scrappy one: the trap only works on a player who was given
	 * something worth believing.
	 *
	 * @param int  $state PRNG state, advanced in place.
	 * @param int  $base  Starting price in ticks.
	 * @param int  $sigma Per-candle noise scale in ticks.
	 * @param int  $dir   +1 up, -1 down.
	 * @param bool $clean Whether to draw the tidier trend.
	 * @return array<int,array{o:int,h:int,l:int,c:int}>
	 */
	private static function visible_trend( int &$state, int $base, int $sigma, int $dir, bool $clean ): array {
		$drift = intdiv( $sigma * ( $clean ? 45 : 30 ), 100 ) * $dir;
		$noise = intdiv( $sigma * ( $clean ? 75 : 100 ), 100 );

		$closes = array();
		$price  = $base;

		for ( $i = 0; $i < Config::STC_VISIBLE; $i++ ) {
			$price   += $drift + self::rng_int( $state, -$noise, $noise );
			$closes[] = $price;
		}

		return self::candles( $state, $closes, $base, max( 1, intdiv( $sigma, 2 ) ) );
	}

	/**
	 * A visible window that goes nowhere.
	 *
	 * Mean reversion around the base price rather than a walk with zero drift:
	 * a driftless walk still wanders, and half of them would end up looking
	 * like a trend to the player and to implied_direction(). A range has to be
	 * built as a range.
	 *
	 * @param int $state PRNG state, advanced in place.
	 * @param int $base  Centre of the range, in ticks.
	 * @param int $sigma Per-candle noise scale in ticks.
	 * @return array<int,array{o:int,h:int,l:int,c:int}>
	 */
	private static function visible_range( int &$state, int $base, int $sigma ): array {
		$closes = array();
		$price  = $base;

		for ( $i = 0; $i < Config::STC_VISIBLE; $i++ ) {
			// Pull 18% of the way home each candle, then step.
			$price    = $base + intdiv( ( $price - $base ) * 82, 100 ) + self::rng_int( $state, -$sigma, $sigma );
			$closes[] = $price;
		}

		return self::candles( $state, $closes, $base, max( 1, intdiv( $sigma, 2 ) ) );
	}

	/**
	 * Outcome candles that keep going the way the window pointed.
	 *
	 * A walk with real pullbacks rather than a ramp to the target: a ramp
	 * would pass validation every single time, which would make the validator
	 * decorative. About one candidate in twenty is still stopped out on the way
	 * and thrown away, which is the point.
	 *
	 * @param int $state PRNG state, advanced in place.
	 * @param int $entry Entry price in ticks.
	 * @param int $atr   Risk unit in ticks.
	 * @param int $dir   +1 up, -1 down.
	 * @return array<int,array{o:int,h:int,l:int,c:int}>
	 */
	private static function outcome_continuation( int &$state, int $entry, int $atr, int $dir ): array {
		$drift = intdiv( $atr * 30, 100 ) * $dir;
		$noise = intdiv( $atr * 65, 100 );

		$closes = array();
		$price  = $entry;

		for ( $i = 0; $i < Config::STC_OUTCOME; $i++ ) {
			$price   += $drift + self::rng_int( $state, -$noise, $noise );
			$closes[] = $price;
		}

		return self::candles( $state, $closes, $entry, max( 1, intdiv( $atr, 4 ) ) );
	}

	/**
	 * Outcome candles that take out both stops.
	 *
	 * Three moves: a gap away from the implied direction, a thrust that
	 * breaches the implied side's stop within a handful of candles, and a
	 * reversal that runs back through the other side's stop. The thrust is
	 * aimed at 1.05–1.35 ATR and never at 1.5: past 1.5 ATR the opposite
	 * position would have hit its TARGET, and a day the contrarian gets paid
	 * for is not a day where passing was right.
	 *
	 * @param int $state PRNG state, advanced in place.
	 * @param int $entry Entry price in ticks.
	 * @param int $atr   Risk unit in ticks.
	 * @param int $dir   +1 when the window implied a buy, -1 a sell.
	 * @return array<int,array{o:int,h:int,l:int,c:int}>
	 */
	private static function outcome_whipsaw( int &$state, int $entry, int $atr, int $dir ): array {
		$thrust  = $entry - $dir * intdiv( $atr * self::rng_int( $state, 105, 132 ), 100 );
		$recover = $entry + $dir * intdiv( $atr * self::rng_int( $state, 108, 150 ), 100 );

		$down = self::rng_int( $state, 2, self::TRAP_MAX_CANDLE - 1 );
		$up   = self::rng_int( $state, 5, 14 );
		$wick = max( 1, intdiv( $atr, 8 ) );
		$jit  = max( 1, intdiv( $atr, 4 ) );

		$closes = array();
		$from   = $entry;

		// Leg one: through the implied side's stop, fast.
		for ( $i = 1; $i <= $down; $i++ ) {
			$closes[] = $from + intdiv( ( $thrust - $from ) * $i, $down ) + self::rng_int( $state, -$jit, $jit );
		}

		// Leg two: back through the other side's stop.
		$from = $closes[ count( $closes ) - 1 ];
		for ( $i = 1; $i <= $up; $i++ ) {
			$closes[] = $from + intdiv( ( $recover - $from ) * $i, $up ) + self::rng_int( $state, -$jit, $jit );
		}

		// Whatever is left drifts on, so the chart does not simply stop.
		$from = $closes[ count( $closes ) - 1 ];
		while ( count( $closes ) < Config::STC_OUTCOME ) {
			$from    += self::rng_int( $state, -$wick * 3, $wick * 3 );
			$closes[] = $from;
		}

		$closes = array_slice( $closes, 0, Config::STC_OUTCOME );

		// The gap is what makes it read as an event rather than a wobble, and
		// it is the one place a scenario opens away from the previous close.
		$gap = -$dir * intdiv( $atr * self::rng_int( $state, 20, 60 ), 100 );

		return self::candles( $state, $closes, $entry, $wick, $gap );
	}

	/**
	 * Outcome candles that resolve nothing.
	 *
	 * Mean reversion around the entry, in a band tight enough that neither
	 * stop — a full ATR either way — is reached in forty candles. The bars
	 * keep their wicks, so the chart reads as a market that went quiet rather
	 * than as a flat line, which would have no risk unit at all.
	 *
	 * @param int $state PRNG state, advanced in place.
	 * @param int $entry Entry price in ticks.
	 * @param int $atr   Risk unit in ticks.
	 * @return array<int,array{o:int,h:int,l:int,c:int}>
	 */
	private static function outcome_chop( int &$state, int $entry, int $atr ): array {
		$noise = max( 1, intdiv( $atr * 32, 100 ) );

		$closes = array();
		$price  = $entry;

		for ( $i = 0; $i < Config::STC_OUTCOME; $i++ ) {
			$price    = $entry + intdiv( ( $price - $entry ) * 74, 100 ) + self::rng_int( $state, -$noise, $noise );
			$closes[] = $price;
		}

		return self::candles( $state, $closes, $entry, max( 1, intdiv( $atr, 4 ) ) );
	}

	/**
	 * Turn a path of closes into candles.
	 *
	 * Each candle opens at the previous close (plus an optional gap on the
	 * first), and its wicks are drawn outside the body — which is what makes
	 * `h >= max(o,c)` and `l <= min(o,c)` true by construction rather than by
	 * a repair pass afterwards. Both wicks are at least one tick, so no candle
	 * has zero range and no window can hand the engine an ATR of zero.
	 *
	 * @param int             $state      PRNG state, advanced in place.
	 * @param array<int,int>  $closes     The path.
	 * @param int             $prev_close Where the series is coming from.
	 * @param int             $wick       Longest wick, in ticks.
	 * @param int             $first_gap  Offset of the first open from $prev_close.
	 * @return array<int,array{o:int,h:int,l:int,c:int}>
	 */
	private static function candles( int &$state, array $closes, int $prev_close, int $wick, int $first_gap = 0 ): array {
		$wick = max( 1, $wick );
		$open = $prev_close + $first_gap;
		$out  = array();

		foreach ( $closes as $close ) {
			$close = (int) $close;
			$out[] = array(
				'o' => $open,
				'h' => max( $open, $close ) + self::rng_int( $state, 1, $wick ),
				'l' => min( $open, $close ) - self::rng_int( $state, 1, $wick ),
				'c' => $close,
			);
			$open  = $close;
		}

		return $out;
	}

	/**
	 * The candles as the integer OHLC quads `hti_stc_ticks` stores.
	 *
	 * @param array<int,array{o:int,h:int,l:int,c:int}> $candles Candles.
	 * @return array<int,array<int,int>>
	 */
	public static function quads( array $candles ): array {
		$out = array();

		foreach ( $candles as $candle ) {
			$out[] = array( (int) $candle['o'], (int) $candle['h'], (int) $candle['l'], (int) $candle['c'] );
		}

		return $out;
	}

	/**
	 * A fingerprint of a series, so the same chart is never stored twice.
	 *
	 * The same shape as Importer::checksum() minus the timestamps, which a
	 * generated series does not have.
	 *
	 * @param array<int,array{o:int,h:int,l:int,c:int}> $candles Candles.
	 * @return string
	 */
	public static function checksum( array $candles ): string {
		$parts = array();

		foreach ( $candles as $candle ) {
			$parts[] = $candle['o'] . ',' . $candle['h'] . ',' . $candle['l'] . ',' . $candle['c'];
		}

		return md5( implode( '|', $parts ) );
	}

	/* =====================================================================
	 * The WordPress half. Everything above this line is pure.
	 * ================================================================== */

	/**
	 * Register the CLI commands this workstream owns.
	 *
	 * Called only from the WP_CLI guard at the bottom of this file, so nothing
	 * here loads on a web request. Registration is not generation: `wp` has to
	 * be typed by a person before a single post exists.
	 */
	public static function register_cli(): void {
		\WP_CLI::add_command( 'hti-games generate', array( __CLASS__, 'cli_generate' ) );
		\WP_CLI::add_command( 'hti-games seed-cases', array( Seed_Cases::class, 'cli_seed' ) );
	}

	/**
	 * `wp hti-games generate --count=365 --seed=… [--dry-run]`
	 *
	 * Creates draft scenarios. Drafts, always: the pool the game serves is
	 * published posts, so nothing this command does can put a chart in front
	 * of a player without somebody having looked at it first.
	 *
	 * Idempotent by seed. Re-running the same command does not manufacture a
	 * second copy of the library — the seed IS the chart's identity, and a
	 * scenario already carrying it is left exactly as the editor left it.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<n>]
	 * : How many scenarios to build. Default 365 — a daily game with a
	 * two-month library visibly repeats itself.
	 *
	 * [--seed=<n>]
	 * : Run seed. The same seed rebuilds the same library, forever.
	 *
	 * [--dry-run]
	 * : Generate and report the class distribution, write nothing.
	 *
	 * @param array<int,string>    $args  Positional arguments (unused).
	 * @param array<string,string> $assoc Associative arguments.
	 */
	public static function cli_generate( array $args, array $assoc ): void {
		unset( $args );

		$count = max( 1, (int) ( $assoc['count'] ?? 365 ) );
		$seed  = isset( $assoc['seed'] ) ? (int) $assoc['seed'] : (int) gmdate( 'Ymd' );
		$dry   = array_key_exists( 'dry-run', $assoc );

		\WP_CLI::log( sprintf( 'Generating %d scenarios from seed %d…', $count, $seed ) );

		try {
			$scenarios = self::batch( $count, $seed );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'Generation failed: ' . $e->getMessage() );
			return;
		}

		$mix      = self::mix( $scenarios );
		$attempts = array_sum( array_column( $scenarios, 'attempts' ) );

		foreach ( $mix as $class => $n ) {
			\WP_CLI::log(
				sprintf(
					'  %-11s %4d  (%s%%, target %s%%)',
					$class,
					$n,
					number_format( $n * 100 / max( 1, count( $scenarios ) ), 1 ),
					number_format( self::MIX_BP[ $class ] / 100, 1 )
				)
			);
		}
		\WP_CLI::log( sprintf( '  %d candidates simulated for %d kept scenarios.', $attempts, count( $scenarios ) ) );

		if ( $dry ) {
			\WP_CLI::success( 'Dry run — nothing was written.' );
			return;
		}

		$created = 0;
		$skipped = 0;
		$lesson  = array_fill_keys( CPT::SCENARIO_CLASSES, 0 );

		foreach ( $scenarios as $scenario ) {
			$class = (string) $scenario['class'];

			if ( self::seed_exists( (int) $scenario['seed'] ) ) {
				++$skipped;
				continue;
			}

			if ( self::create( $scenario, $seed, $lesson[ $class ] ) ) {
				++$created;
				++$lesson[ $class ];
			}
		}

		if ( $created > 0 && class_exists( __NAMESPACE__ . '\\Library' ) ) {
			Library::flush( Config::GAME_STC );
		}

		\WP_CLI::success(
			sprintf( '%d draft scenarios created, %d already present. Publishing is still a human act.', $created, $skipped )
		);
	}

	/**
	 * Whether a scenario with this seed is already stored.
	 *
	 * Any status, like the importer's own dedupe: a scenario an editor
	 * deliberately left unpublished is still a scenario we have.
	 *
	 * @param int $seed Scenario seed.
	 * @return bool
	 */
	private static function seed_exists( int $seed ): bool {
		$found = get_posts(
			array(
				'post_type'        => Config::CPT_SCENARIO,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => 'hti_stc_seed', // phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaKey -- exact-match lookup on an indexed meta key, once per candidate, in a CLI command.
				'meta_value'       => (string) $seed, // phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaValue -- as above.
			)
		);

		return array() !== $found;
	}

	/**
	 * Store one generated scenario as a draft.
	 *
	 * @param array<string,mixed> $scenario From scenario().
	 * @param int                 $run_seed The seed the whole library came from.
	 * @param int                 $lesson   Rotation index into the class's lessons.
	 * @return bool
	 */
	private static function create( array $scenario, int $run_seed, int $lesson ): bool {
		$class = (string) $scenario['class'];

		$post_id = wp_insert_post(
			array(
				'post_type'   => Config::CPT_SCENARIO,
				'post_status' => 'draft',
				'post_title'  => sprintf( 'Generated · %s · #%s', $class, substr( (string) $scenario['checksum'], 0, 6 ) ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
			return false;
		}

		$text = Lessons::for_class( $class, $lesson );

		$meta = array(
			'hti_stc_ticks'      => (string) wp_json_encode( self::quads( (array) $scenario['candles'] ) ),
			'hti_stc_scale'      => (int) $scenario['scale'],
			'hti_stc_visible'    => (int) $scenario['visible'],
			'hti_stc_outcome'    => (int) $scenario['outcome'],
			'hti_stc_class'      => $class,
			'hti_stc_pass_right' => ! empty( $scenario['pass_right'] ) ? '1' : '0',
			// Never 1. The landing page's "these are real charts" claim is
			// computed from this key across the whole pool, so a generated
			// scenario that lied here would make the page lie.
			'hti_stc_real'       => '0',
			'hti_stc_source'     => 'generated:' . self::VERSION . '#' . $run_seed,
			'hti_stc_seed'       => (string) $scenario['seed'],
			'hti_stc_checksum'   => (string) $scenario['checksum'],
			'hti_stc_lesson_en'  => $text['en'],
			'hti_stc_lesson_pt'  => $text['pt'],
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( (int) $post_id, $key, $value );
		}

		return true;
	}
}

/**
 * CLI registration, exactly as hti-forex.php does it: behind the guard, at
 * file scope, so a web request never sees any of it.
 *
 * Lessons and Seed_Cases are pulled in here rather than added to the plugin's
 * class map, because nothing outside these commands reads either of them and a
 * library of copy has no business being parsed on every page load.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/class-lessons.php';
	require_once __DIR__ . '/class-seed-cases.php';

	STC_Generator::register_cli();
}
