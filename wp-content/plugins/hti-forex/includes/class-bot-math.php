<?php
/**
 * The arithmetic behind the Telegram bot, and the parser that gets there.
 *
 * Deliberately pure: no WordPress calls, no I/O, no state. Everything the
 * bot answers is a function of (balance, pair, leverage, rates), which is
 * what lets the whole thing be tested in the pure-PHP harness and what makes
 * the JS↔PHP parity test possible at all.
 *
 * pip_value() and margin_required() are ports of the same functions in
 * assets/js/forex-core.js and MUST agree with them to the rupee. The website
 * and the bot giving different answers for the same inputs would cost us the
 * one thing that distinguishes this project.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Position arithmetic in rupees.
 */
class Bot_Math {

	/**
	 * The smallest position most platforms allow — one micro lot. The whole
	 * reply is built around it because for a small account it is the binding
	 * constraint, not the entry price.
	 */
	public const MIN_LOT = 0.01;

	/**
	 * The stop distances the reply prices up. Two is enough to show the shape
	 * without turning the answer into a table nobody reads.
	 */
	public const STOPS = array( 20, 50 );

	/**
	 * Pairs the bot offers. Gold is deliberately absent: its margin needs a
	 * live metal price and we have no source for one, and a bot that answers
	 * some pairs fully and others partly is worse than one that is clear
	 * about its scope. Gold stays on the website, where the price is typed.
	 */
	public const PAIRS = array( 'EURUSD', 'GBPUSD', 'USDJPY' );

	/**
	 * Leverage steps the buttons offer.
	 */
	public const LEVERAGES = array( 100, 200, 500 );

	/**
	 * Balance buckets for the aggregate audience counter. Stored as counts
	 * only, never against a chat id — see Bot_Store. The boundaries follow
	 * Indian conventions (a lakh is 1,00,000).
	 *
	 * @return array<int,array{key:string,max:float,label:string}>
	 */
	public static function buckets(): array {
		return array(
			array(
				'key'   => 'under_2k',
				'max'   => 2000.0,
				'label' => 'under ₹2,000',
			),
			array(
				'key'   => '2k_5k',
				'max'   => 5000.0,
				'label' => '₹2,000–5,000',
			),
			array(
				'key'   => '5k_10k',
				'max'   => 10000.0,
				'label' => '₹5,000–10,000',
			),
			array(
				'key'   => '10k_25k',
				'max'   => 25000.0,
				'label' => '₹10,000–25,000',
			),
			array(
				'key'   => '25k_50k',
				'max'   => 50000.0,
				'label' => '₹25,000–50,000',
			),
			array(
				'key'   => '50k_1l',
				'max'   => 100000.0,
				'label' => '₹50,000–1 lakh',
			),
			array(
				'key'   => '1l_5l',
				'max'   => 500000.0,
				'label' => '₹1–5 lakh',
			),
			array(
				'key'   => 'over_5l',
				'max'   => INF,
				'label' => 'over ₹5 lakh',
			),
		);
	}

	/**
	 * Which bucket a balance falls into.
	 *
	 * @param float $inr Balance in rupees.
	 * @return string Bucket key.
	 */
	public static function bucket( float $inr ): string {
		foreach ( self::buckets() as $bucket ) {
			if ( $inr < $bucket['max'] ) {
				return $bucket['key'];
			}
		}
		return 'over_5l';
	}

	/**
	 * Read an account balance out of whatever someone typed.
	 *
	 * Accepts the shapes this audience actually sends: "5000", "₹5,000",
	 * "Rs 5000", "1,00,000" (Indian grouping), "50k", "2 lakh", "$100". The
	 * dollar forms matter — the page that converts best on the site is
	 * literally "lot size for $100 account", so a good share of the people
	 * arriving here think in dollars.
	 *
	 * @param string $raw       What the user sent.
	 * @param float  $usd_inr   USD/INR rate, for converting dollar amounts.
	 * @return array{inr:float,typed:string}|null Null when it isn't a usable amount.
	 */
	public static function parse_amount( string $raw, float $usd_inr ): ?array {
		if ( $usd_inr <= 0 ) {
			return null;
		}

		$text = strtolower( trim( $raw ) );
		if ( '' === $text || strlen( $text ) > 40 ) {
			return null;
		}

		// Dollars are whatever carries a $ or says usd/dollar; everything else
		// is rupees, including the explicit ₹/rs/inr forms.
		$is_usd = (bool) preg_match( '/(\$|\busd\b|\bdollars?\b)/', $text );

		// Multipliers, longest first so "crore" is not eaten by "cr".
		$multiplier = 1.0;
		foreach ( array(
			'crore' => 10000000.0,
			'lakhs' => 100000.0,
			'lakh'  => 100000.0,
			'lac'   => 100000.0,
			'cr'    => 10000000.0,
			'l'     => 100000.0,
			'k'     => 1000.0,
		) as $suffix => $factor ) {
			if ( preg_match( '/[\d\s]' . preg_quote( $suffix, '/' ) . '\b/', $text . ' ' ) ) {
				$multiplier = $factor;
				$text       = preg_replace( '/' . preg_quote( $suffix, '/' ) . '\b/', ' ', $text, 1 );
				break;
			}
		}

		// Pull the number out rather than deleting everything around it. The
		// deleting approach looks simpler and is wrong: "rs.5000" loses the
		// letters but keeps the dot, and ₹5,000 silently becomes 50 paise.
		// Requiring a leading digit anchors the match past any currency
		// prefix, and the comma class carries Indian grouping (1,00,000).
		if ( ! preg_match( '/\d[\d,]*(?:\.\d+)?/', (string) $text, $found ) ) {
			return null;
		}

		$digits = str_replace( ',', '', $found[0] );
		if ( ! is_numeric( $digits ) ) {
			return null;
		}

		$amount = (float) $digits * $multiplier;
		if ( $amount <= 0 || ! is_finite( $amount ) ) {
			return null;
		}

		$inr = $is_usd ? $amount * $usd_inr : $amount;

		// A sanity ceiling: past this the input is a typo or a joke, and the
		// reply would be meaningless either way.
		if ( $inr > 1000000000.0 ) {
			return null;
		}

		return array(
			'inr'   => $inr,
			'typed' => $is_usd ? '$' . self::plain( $amount ) : '₹' . self::inr( $amount ),
		);
	}

	/**
	 * Pip value for a position, in the quote currency, USD and INR.
	 *
	 * Port of pipValue() in assets/js/forex-core.js — keep them in step.
	 *
	 * @param string                   $pair  Pair key.
	 * @param float                    $lots  Position size in lots.
	 * @param array<string,float>      $rates Reference rates.
	 * @return array{quote:float,quote_currency:string,usd:float,inr:float}|null
	 */
	public static function pip_value( string $pair, float $lots, array $rates ): ?array {
		$pairs = Config::pairs();
		$spec  = $pairs[ $pair ] ?? null;
		$inr   = (float) ( $rates['USDINR'] ?? 0 );

		if ( null === $spec || $lots <= 0 || $inr <= 0 ) {
			return null;
		}

		$quote = $spec['pip_size'] * $spec['contract_size'] * $lots;

		if ( 'USD' === $spec['quote'] ) {
			$usd = $quote;
		} elseif ( 'JPY' === $spec['quote'] ) {
			$jpy = (float) ( $rates['USDJPY'] ?? 0 );
			if ( $jpy <= 0 ) {
				return null;
			}
			$usd = $quote / $jpy;
		} elseif ( 'INR' === $spec['quote'] ) {
			$usd = $quote / $inr;
		} else {
			return null;
		}

		return array(
			'quote'          => $quote,
			'quote_currency' => $spec['quote'],
			'usd'            => $usd,
			'inr'            => 'INR' === $spec['quote'] ? $quote : $usd * $inr,
		);
	}

	/**
	 * Margin locked by a position, in USD and INR.
	 *
	 * Port of marginRequired() in assets/js/forex-core.js — keep them in step.
	 *
	 * @param string              $pair     Pair key.
	 * @param float               $lots     Position size in lots.
	 * @param float               $price    Current price (ignored for USD-base pairs).
	 * @param float               $leverage Leverage denominator, e.g. 500.
	 * @param array<string,float> $rates    Reference rates.
	 * @return array{notional_usd:float,notional_inr:float,margin_usd:float,margin_inr:float}|null
	 */
	public static function margin_required( string $pair, float $lots, float $price, float $leverage, array $rates ): ?array {
		$pairs = Config::pairs();
		$spec  = $pairs[ $pair ] ?? null;
		$inr   = (float) ( $rates['USDINR'] ?? 0 );

		if ( null === $spec || $lots <= 0 || $leverage <= 0 || $inr <= 0 ) {
			return null;
		}

		$units    = $lots * $spec['contract_size'];
		$base_usd = 'USD' === substr( $pair, 0, 3 );

		if ( $base_usd ) {
			$notional_usd = $units;
		} else {
			if ( $price <= 0 ) {
				return null;
			}
			$notional_usd = $units * $price;
		}

		return array(
			'notional_usd' => $notional_usd,
			'notional_inr' => $notional_usd * $inr,
			'margin_usd'   => $notional_usd / $leverage,
			'margin_inr'   => ( $notional_usd * $inr ) / $leverage,
		);
	}

	/**
	 * The price to use for a pair's notional, taken from the rates layer.
	 *
	 * USD-base pairs need no price at all (the notional is the unit count),
	 * so they return 0 and margin_required() ignores it.
	 *
	 * @param string              $pair  Pair key.
	 * @param array<string,float> $rates Reference rates.
	 * @return float Price, or 0 when the pair needs none, or -1 when unknown.
	 */
	public static function price_for( string $pair, array $rates ): float {
		if ( 'USD' === substr( $pair, 0, 3 ) ) {
			return 0.0;
		}
		$price = (float) ( $rates[ $pair ] ?? 0 );
		return $price > 0 ? $price : -1.0;
	}

	/**
	 * Everything the bot answers, from one balance.
	 *
	 * The shape of this is the product: one number in, the whole risk picture
	 * out, in rupees. What a micro lot costs to hold, what it costs when it
	 * moves, and what fraction of the account that is.
	 *
	 * @param float               $balance_inr Account balance in rupees.
	 * @param string              $pair        Pair key.
	 * @param float               $leverage    Leverage denominator.
	 * @param array<string,float> $rates       Reference rates.
	 * @return array<string,mixed>|null
	 */
	public static function picture( float $balance_inr, string $pair, float $leverage, array $rates ): ?array {
		if ( ! in_array( $pair, self::PAIRS, true ) || $balance_inr <= 0 ) {
			return null;
		}

		$pip = self::pip_value( $pair, self::MIN_LOT, $rates );
		if ( null === $pip ) {
			return null;
		}

		$price  = self::price_for( $pair, $rates );
		$margin = $price < 0 ? null : self::margin_required( $pair, self::MIN_LOT, $price, $leverage, $rates );

		$stops = array();
		foreach ( self::STOPS as $pips ) {
			$cost    = $pips * $pip['inr'];
			$stops[] = array(
				'pips'    => $pips,
				'cost'    => $cost,
				'percent' => $cost / $balance_inr * 100,
			);
		}

		// Inverted position sizing: at the smallest lot available, how far can
		// the stop go before the loss exceeds the risk chosen? This is the
		// same arithmetic as positionSize(), read from the other end.
		$room = array();
		foreach ( array( 1.0, 2.0 ) as $risk_pct ) {
			$risk_inr = $balance_inr * $risk_pct / 100;
			$room[]   = array(
				'risk_pct' => $risk_pct,
				'risk_inr' => $risk_inr,
				'pips'     => $risk_inr / $pip['inr'],
			);
		}

		$pairs = Config::pairs();

		return array(
			'balance'    => $balance_inr,
			'pair'       => $pair,
			'pair_label' => $pairs[ $pair ]['label'],
			'leverage'   => $leverage,
			'lots'       => self::MIN_LOT,
			'units'      => (int) round( self::MIN_LOT * $pairs[ $pair ]['contract_size'] ),
			'pip_inr'    => $pip['inr'],
			'margin_inr' => null === $margin ? null : $margin['margin_inr'],
			'stops'      => $stops,
			'room'       => $room,
			// The honest headline: at the smallest position available, is one
			// ordinary 20-pip stop already more than 2% of this account?
			'tight'      => $stops[0]['percent'] > 2.0,
		);
	}

	/**
	 * Format a number with Indian digit grouping — ₹1,00,000, not ₹100,000.
	 * The last three digits group normally, everything above them in twos.
	 *
	 * @param float $value  Amount.
	 * @param int   $places Decimal places.
	 * @return string
	 */
	public static function inr( float $value, int $places = 0 ): string {
		$negative = $value < 0;
		$value    = abs( $value );
		$fixed    = number_format( $value, $places, '.', '' );

		$parts   = explode( '.', $fixed );
		$integer = $parts[0];
		$decimal = isset( $parts[1] ) ? '.' . $parts[1] : '';

		if ( strlen( $integer ) > 3 ) {
			$last  = substr( $integer, -3 );
			$rest  = substr( $integer, 0, -3 );
			$rest  = preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', $rest );
			$integer = $rest . ',' . $last;
		}

		return ( $negative ? '-' : '' ) . $integer . $decimal;
	}

	/**
	 * Plain thousands grouping, for dollar amounts echoed back to the user.
	 *
	 * @param float $value  Amount.
	 * @param int   $places Decimal places.
	 * @return string
	 */
	public static function plain( float $value, int $places = 0 ): string {
		return number_format( $value, $places, '.', ',' );
	}
}
