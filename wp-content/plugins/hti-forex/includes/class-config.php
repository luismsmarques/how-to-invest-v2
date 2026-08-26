<?php
/**
 * Pure configuration for the forex tools: currency pairs, market sessions and
 * the per-page FAQ copy. No WordPress dependencies beyond the ABSPATH guard,
 * so everything here is unit-testable and serves as the single source of
 * truth for the calculators (mirrored into forex-core.js, locked by tests),
 * the seeded page content and the FAQPage JSON-LD.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Static config tables.
 */
class Config {

	/**
	 * Currency pairs offered by the calculators.
	 *
	 * pip_size / contract_size follow the dominant retail CFD conventions:
	 * XAUUSD counts a pip as a $0.10 move on a 100 oz contract ($10/lot) — the
	 * page FAQ explains the competing $0.01-tick convention. USDINR uses the
	 * 0.0025 tick familiar from Indian exchange-traded currency derivatives.
	 * These are content decisions, editable here in one place; the JS table in
	 * forex-core.js must match (asserted by tests/test-forex-core.mjs).
	 *
	 * @return array<string,array{label:string,quote:string,pip_size:float,contract_size:int}>
	 */
	public static function pairs(): array {
		return array(
			'EURUSD' => array(
				'label'         => 'EUR/USD',
				'quote'         => 'USD',
				'pip_size'      => 0.0001,
				'contract_size' => 100000,
			),
			'GBPUSD' => array(
				'label'         => 'GBP/USD',
				'quote'         => 'USD',
				'pip_size'      => 0.0001,
				'contract_size' => 100000,
			),
			'USDJPY' => array(
				'label'         => 'USD/JPY',
				'quote'         => 'JPY',
				'pip_size'      => 0.01,
				'contract_size' => 100000,
			),
			'XAUUSD' => array(
				'label'         => 'Gold (XAU/USD)',
				'quote'         => 'USD',
				'pip_size'      => 0.10,
				'contract_size' => 100,
			),
			'USDINR' => array(
				'label'         => 'USD/INR (offshore)',
				'quote'         => 'INR',
				'pip_size'      => 0.0025,
				'contract_size' => 100000,
			),
		);
	}

	/**
	 * The four market sessions, each in its own IANA timezone with local
	 * open/close hours. IST conversions are derived (never hardcoded) so DST
	 * comes from the tz database.
	 *
	 * @return array<string,array{label:string,tz:string,open:string,close:string}>
	 */
	public static function sessions(): array {
		return array(
			'sydney'   => array(
				'label' => 'Sydney',
				'tz'    => 'Australia/Sydney',
				'open'  => '07:00',
				'close' => '16:00',
			),
			'tokyo'    => array(
				'label' => 'Tokyo',
				'tz'    => 'Asia/Tokyo',
				'open'  => '09:00',
				'close' => '18:00',
			),
			'london'   => array(
				'label' => 'London',
				'tz'    => 'Europe/London',
				'open'  => '08:00',
				'close' => '17:00',
			),
			'new_york' => array(
				'label' => 'New York',
				'tz'    => 'America/New_York',
				'open'  => '08:00',
				'close' => '17:00',
			),
		);
	}

	/**
	 * Session windows for one calendar day, expressed in IST.
	 *
	 * Pure and deterministic for a given date: session open/close are built in
	 * the session's own timezone (so the tz database supplies DST) and then
	 * converted to Asia/Kolkata. Used for the server-rendered baseline table —
	 * the page works with JavaScript disabled.
	 *
	 * @param \DateTimeImmutable $day Any instant on the calendar day to render.
	 * @return array<int,array{id:string,label:string,open_ist:string,close_ist:string,closes_next_day:bool}>
	 */
	public static function session_windows_ist( \DateTimeImmutable $day ): array {
		$ist  = new \DateTimeZone( 'Asia/Kolkata' );
		$date = $day->setTimezone( $ist )->format( 'Y-m-d' );
		$out  = array();

		foreach ( self::sessions() as $id => $s ) {
			$tz    = new \DateTimeZone( $s['tz'] );
			$open  = ( new \DateTimeImmutable( $date . ' ' . $s['open'], $tz ) )->setTimezone( $ist );
			$close = ( new \DateTimeImmutable( $date . ' ' . $s['close'], $tz ) )->setTimezone( $ist );

			$out[] = array(
				'id'              => $id,
				'label'           => $s['label'],
				'open_ist'        => $open->format( 'H:i' ),
				'close_ist'       => $close->format( 'H:i' ),
				'closes_next_day' => $close->format( 'Y-m-d' ) !== $open->format( 'Y-m-d' ),
			);
		}

		return $out;
	}

	/**
	 * The London–New York overlap for one calendar day, in IST.
	 *
	 * @param \DateTimeImmutable $day Any instant on the calendar day.
	 * @return array{start_ist:string,end_ist:string}
	 */
	public static function overlap_london_ny_ist( \DateTimeImmutable $day ): array {
		$ist  = new \DateTimeZone( 'Asia/Kolkata' );
		$date = $day->setTimezone( $ist )->format( 'Y-m-d' );

		$ny_open      = ( new \DateTimeImmutable( $date . ' 08:00', new \DateTimeZone( 'America/New_York' ) ) )->setTimezone( $ist );
		$london_close = ( new \DateTimeImmutable( $date . ' 17:00', new \DateTimeZone( 'Europe/London' ) ) )->setTimezone( $ist );

		return array(
			'start_ist' => $ny_open->format( 'H:i' ),
			'end_ist'   => $london_close->format( 'H:i' ),
		);
	}

	/**
	 * FAQ copy per page. Single source for the seeded page sections AND the
	 * FAQPage JSON-LD, so visible content and schema agree at seed time.
	 * Plain text only (no HTML); conditional, educational voice throughout.
	 *
	 * @param string $page hub|position_size|pip_value|sessions.
	 * @return array<int,array{q:string,a:string}>
	 */
	public static function faqs( string $page ): array {
		$faqs = array(
			'hub'           => array(
				array(
					'q' => 'Is forex trading legal in India?',
					'a' => 'Forex trading in India is regulated under the Foreign Exchange Management Act (FEMA). Indian residents can trade currency derivatives — INR pairs and certain cross-currency pairs — on recognised Indian exchanges through SEBI-registered brokers. The Reserve Bank of India also publishes an Alert List of platforms that are not authorised to deal in forex in India, and trading through unauthorised offshore platforms can violate FEMA. Rules change, so verify the current position with SEBI, the RBI or a qualified professional. Everything on this site is education, not legal or investment advice.',
				),
				array(
					'q' => 'Are these forex calculators free?',
					'a' => 'Yes. All the tools in this section are free to use, with no account or sign-up required. They are educational calculators: the numbers they produce are illustrations based on the inputs you enter, not trading advice.',
				),
				array(
					'q' => 'Do the calculators work with INR as the account currency?',
					'a' => 'Yes — that is the point of this section. Position size and pip value are calculated natively in Indian rupees using a published USD/INR reference rate, which you can also edit by hand. Most global calculators only support USD, EUR or GBP account currencies.',
				),
			),
			'position_size' => array(
				array(
					'q' => 'What lot size should I use for a $100 (about ₹8,500) account?',
					'a' => 'As an illustration: with a $100 account, risking 1% per trade (about ₹85) with a 20-pip stop-loss on EUR/USD works out to roughly 0.005 lots — below one micro lot (0.01), which is the smallest size most brokers allow. An account that small usually cannot hold a position at that risk level, which is why many traders first grow the account until at least one micro lot fits inside their chosen risk. This is an example of how the arithmetic works, not a recommendation.',
				),
				array(
					'q' => 'How much do traders typically risk per trade?',
					'a' => 'A common convention in trading literature is risking around 1–2% of the account on any single trade, so that a run of losses does not do lasting damage. That is a description of a widely used rule of thumb, not a prescription — the right number, if any, depends on circumstances this calculator cannot know.',
				),
				array(
					'q' => 'What is the difference between standard, mini and micro lots?',
					'a' => 'A standard lot is 100,000 units of the base currency, a mini lot is 10,000 units and a micro lot is 1,000 units. On EUR/USD a one-pip move is worth about $10 per standard lot, $1 per mini lot and $0.10 per micro lot. This calculator returns the position in lots and in units.',
				),
				array(
					'q' => 'How is position size calculated from the stop-loss?',
					'a' => 'The calculator takes the amount at risk (account balance times risk percentage), divides it by the stop-loss distance in pips times the pip value in rupees per lot, and rounds down to the nearest micro lot. Rounding down means the actual rupee risk shown is never higher than the risk you chose.',
				),
			),
			'pip_value'     => array(
				array(
					'q' => 'How much is 1 pip in Indian rupees?',
					'a' => 'It depends on the pair and the lot size. On EUR/USD, one pip on a standard lot (100,000 units) is worth $10 — about ₹830 at a rate of ₹83 per US dollar. On a mini lot it is about ₹83, and on a micro lot about ₹8.30. The calculator above converts pip value to rupees using a published USD/INR reference rate that you can edit.',
				),
				array(
					'q' => 'How much is 1 pip on XAUUSD (gold)?',
					'a' => 'Using the most common retail convention — one pip equals a $0.10 move on a 100 oz contract — one pip on a standard gold lot is worth $10, or roughly ₹830 at ₹83 per dollar. Some brokers instead count each $0.01 tick as a "pip" worth $1, so it is worth checking the contract specifications of the platform you use. This calculator uses the $0.10 convention.',
				),
				array(
					'q' => 'Why is pip value different on USD/JPY?',
					'a' => 'Because yen pairs are quoted to two decimal places, one pip on USD/JPY is a 0.01 move, worth 1,000 yen per standard lot. The calculator converts that yen amount to US dollars using the USD/JPY rate, and then to rupees using USD/INR — so the rupee pip value on USD/JPY changes as both rates move.',
				),
				array(
					'q' => 'How is pip value converted to INR?',
					'a' => 'The pip value is first expressed in the pair\'s quote currency, converted to US dollars where needed, and then multiplied by the USD/INR reference rate. The rate used and its date are shown next to the result, and you can overwrite the rate by hand — useful when your broker\'s conversion rate differs from the published reference.',
				),
			),
			'sessions'      => array(
				array(
					'q' => 'What time does the forex market open in India (IST)?',
					'a' => 'The global forex market runs 24 hours a day, five days a week — there is no single opening bell. In Indian time the trading week starts early on Monday morning when the Sydney session opens (roughly 1:30–2:30 AM IST, depending on Australian daylight saving) and winds down on Saturday around 2:30–3:30 AM IST when New York closes.',
				),
				array(
					'q' => 'When do the London and New York sessions overlap in IST?',
					'a' => 'Roughly 18:30 to 22:30 IST while the northern hemisphere is on winter time, and 17:30 to 21:30 IST during summer time. For about three weeks each March and November the United States and the United Kingdom switch their clocks on different dates, so the overlap temporarily runs about 17:30 to 22:30 IST. The clock above computes the exact window for today.',
				),
				array(
					'q' => 'Why do forex session times in IST shift in March and November?',
					'a' => 'India does not observe daylight saving time — IST is fixed at UTC+5:30 all year. London, New York and Sydney do shift their clocks, so from an Indian point of view it is the foreign sessions that move by an hour twice a year, in late March and late October/early November.',
				),
				array(
					'q' => 'Which forex session has the most activity?',
					'a' => 'Measured by traded volume, the London session and the London–New York overlap have historically been the most active hours, with the largest share of global FX turnover. Activity is a description of when markets have tended to be busiest, not a statement about when anyone should trade.',
				),
			),
		);

		return $faqs[ $page ] ?? array();
	}
}
