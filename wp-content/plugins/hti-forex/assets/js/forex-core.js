/**
 * HTI Forex — math core (pure functions, no DOM).
 *
 * Educational, illustrative only: every rate is indicative and nothing here
 * is advice. Pip/contract conventions live in the PAIRS table, which mirrors
 * includes/class-config.php::pairs() — the two are locked together by
 * tests/test-forex-core.mjs, so edit both or the suite fails.
 *
 * Works as a browser global (window.HTIForex) and as a CommonJS module (tests).
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	root.HTIForex = api;
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/**
	 * Pair specs. quote: currency the pip value is born in. pipSize: price
	 * move that counts as one pip. contractSize: units per 1.00 lot.
	 * XAUUSD uses the $0.10-per-pip-on-100oz convention ($10/lot); USDINR
	 * uses the 0.0025 tick familiar from Indian exchange-traded derivatives.
	 */
	var PAIRS = {
		EURUSD: { label: 'EUR/USD', quote: 'USD', pipSize: 0.0001, contractSize: 100000 },
		GBPUSD: { label: 'GBP/USD', quote: 'USD', pipSize: 0.0001, contractSize: 100000 },
		USDJPY: { label: 'USD/JPY', quote: 'JPY', pipSize: 0.01, contractSize: 100000 },
		XAUUSD: { label: 'Gold (XAU/USD)', quote: 'USD', pipSize: 0.10, contractSize: 100 },
		USDINR: { label: 'USD/INR (offshore)', quote: 'INR', pipSize: 0.0025, contractSize: 100000 }
	};

	/**
	 * Market sessions, each in its own IANA timezone with local open/close.
	 * Mirrors Config::sessions(); IST windows are derived, never hardcoded.
	 */
	var SESSIONS = [
		{ id: 'sydney', label: 'Sydney', tz: 'Australia/Sydney', open: [ 7, 0 ], close: [ 16, 0 ] },
		{ id: 'tokyo', label: 'Tokyo', tz: 'Asia/Tokyo', open: [ 9, 0 ], close: [ 18, 0 ] },
		{ id: 'london', label: 'London', tz: 'Europe/London', open: [ 8, 0 ], close: [ 17, 0 ] },
		{ id: 'new_york', label: 'New York', tz: 'America/New_York', open: [ 8, 0 ], close: [ 17, 0 ] }
	];

	var IST_OFFSET_MS = 5.5 * 3600000; // IST is fixed UTC+5:30, no DST.

	/* -------------------------------------------------------------------
	 * Calculators
	 * ----------------------------------------------------------------- */

	/**
	 * Pip value for a position, in the quote currency, USD and INR.
	 *
	 * @param {string} pair  Key in PAIRS.
	 * @param {number} lots  Position size in lots (1.00 = standard lot).
	 * @param {Object} rates { USDINR, USDJPY } reference rates.
	 * @return {{quote:number,quoteCurrency:string,usd:number,inr:number}|null}
	 */
	function pipValue( pair, lots, rates ) {
		var spec = PAIRS[ pair ];
		if ( ! spec || ! ( lots > 0 ) || ! rates || ! ( rates.USDINR > 0 ) ) {
			return null;
		}

		var quote = spec.pipSize * spec.contractSize * lots;
		var usd;

		if ( 'USD' === spec.quote ) {
			usd = quote;
		} else if ( 'JPY' === spec.quote ) {
			if ( ! ( rates.USDJPY > 0 ) ) {
				return null;
			}
			usd = quote / rates.USDJPY;
		} else if ( 'INR' === spec.quote ) {
			usd = quote / rates.USDINR;
		} else {
			return null;
		}

		return {
			quote: quote,
			quoteCurrency: spec.quote,
			usd: usd,
			inr: 'INR' === spec.quote ? quote : usd * rates.USDINR
		};
	}

	/**
	 * Position size from INR account balance, risk % and stop distance.
	 * Rounds DOWN to the nearest micro lot (0.01) so the rupee amount actually
	 * at risk is never higher than the risk the user chose.
	 *
	 * @param {number} balanceINR Account balance in ₹.
	 * @param {number} riskPct    Risk per trade, percent of balance.
	 * @param {number} stopPips   Stop-loss distance in pips.
	 * @param {string} pair       Key in PAIRS.
	 * @param {Object} rates      { USDINR, USDJPY } reference rates.
	 * @return {{lots:number,units:number,riskINR:number,actualRiskINR:number,pipInrPerLot:number,tooSmall:boolean}|null}
	 */
	function positionSize( balanceINR, riskPct, stopPips, pair, rates ) {
		var perLot = pipValue( pair, 1, rates );
		if ( ! perLot || ! ( balanceINR > 0 ) || ! ( riskPct > 0 ) || ! ( stopPips > 0 ) ) {
			return null;
		}

		var riskINR = balanceINR * riskPct / 100;
		var rawLots = riskINR / ( stopPips * perLot.inr );
		// The tiny epsilon compensates float error so e.g. 0.29 never floors
		// to 0.28; it can overstate size by at most 1e-6 lots (0.1 unit).
		var lots = Math.floor( rawLots * 100 + 1e-6 ) / 100;

		return {
			lots: lots,
			units: Math.round( lots * PAIRS[ pair ].contractSize ),
			riskINR: riskINR,
			actualRiskINR: lots * stopPips * perLot.inr,
			pipInrPerLot: perLot.inr,
			tooSmall: lots < 0.01
		};
	}

	/**
	 * Profit/loss for a closed position, in the quote currency, USD and INR.
	 * Gross price P/L only — spreads, swaps and commissions are not modelled
	 * (the page says so).
	 *
	 * @param {string} pair      Key in PAIRS.
	 * @param {string} direction 'buy' | 'sell'.
	 * @param {number} lots      Position size in lots.
	 * @param {number} entry     Entry price.
	 * @param {number} exit      Exit price.
	 * @param {Object} rates     { USDINR, USDJPY } reference rates.
	 * @return {{pips:number,quote:number,quoteCurrency:string,usd:number,inr:number}|null}
	 */
	function profitLoss( pair, direction, lots, entry, exit, rates ) {
		var spec = PAIRS[ pair ];
		if ( ! spec || ! ( lots > 0 ) || ! ( entry > 0 ) || ! ( exit > 0 ) || ! rates || ! ( rates.USDINR > 0 ) ) {
			return null;
		}

		var sign = 'sell' === direction ? -1 : 1;
		var diff = ( exit - entry ) * sign;
		var quote = diff * spec.contractSize * lots;
		var usd;

		if ( 'USD' === spec.quote ) {
			usd = quote;
		} else if ( 'JPY' === spec.quote ) {
			if ( ! ( rates.USDJPY > 0 ) ) {
				return null;
			}
			usd = quote / rates.USDJPY;
		} else if ( 'INR' === spec.quote ) {
			usd = quote / rates.USDINR;
		} else {
			return null;
		}

		return {
			pips: diff / spec.pipSize,
			quote: quote,
			quoteCurrency: spec.quote,
			usd: usd,
			inr: 'INR' === spec.quote ? quote : usd * rates.USDINR
		};
	}

	/**
	 * Notional value and margin required for a position. Leverage changes
	 * margin, not risk — the "with leverage" page exists to make exactly
	 * that distinction. For USD-base pairs (USDJPY, USDINR) the USD notional
	 * is simply the units, independent of price.
	 *
	 * @param {string} pair     Key in PAIRS.
	 * @param {number} lots     Position size in lots.
	 * @param {number} price    Current/entry price (ignored for USD-base pairs).
	 * @param {number} leverage Leverage multiple (e.g. 500 for 1:500).
	 * @param {Object} rates    { USDINR, USDJPY } reference rates.
	 * @return {{notionalUSD:number,notionalINR:number,marginUSD:number,marginINR:number}|null}
	 */
	function marginRequired( pair, lots, price, leverage, rates ) {
		var spec = PAIRS[ pair ];
		if ( ! spec || ! ( lots > 0 ) || ! ( leverage > 0 ) || ! rates || ! ( rates.USDINR > 0 ) ) {
			return null;
		}

		var units = lots * spec.contractSize;
		var baseUSD = 'USD' === pair.slice( 0, 3 );
		var notionalUSD;

		if ( baseUSD ) {
			notionalUSD = units;
		} else {
			if ( ! ( price > 0 ) ) {
				return null;
			}
			// EURUSD/GBPUSD: base units × USD price. XAUUSD: oz × USD price.
			notionalUSD = units * price;
		}

		return {
			notionalUSD: notionalUSD,
			notionalINR: notionalUSD * rates.USDINR,
			marginUSD: notionalUSD / leverage,
			marginINR: ( notionalUSD * rates.USDINR ) / leverage
		};
	}

	/* -------------------------------------------------------------------
	 * Sessions in IST
	 * ----------------------------------------------------------------- */

	/**
	 * A timezone's UTC offset in minutes at a given instant, via
	 * Intl.formatToParts (portable across browsers and Node).
	 *
	 * @param {string} tz   IANA timezone.
	 * @param {Date}   date Instant.
	 * @return {number} Offset in minutes (east positive).
	 */
	function zoneOffsetMinutes( tz, date ) {
		var dtf = new Intl.DateTimeFormat( 'en-US', {
			timeZone: tz,
			hour12: false,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit'
		} );
		var parts = {};
		dtf.formatToParts( date ).forEach( function ( p ) {
			parts[ p.type ] = p.value;
		} );
		var asUTC = Date.UTC(
			parseInt( parts.year, 10 ),
			parseInt( parts.month, 10 ) - 1,
			parseInt( parts.day, 10 ),
			'24' === parts.hour ? 0 : parseInt( parts.hour, 10 ),
			parseInt( parts.minute, 10 ),
			parseInt( parts.second, 10 )
		);
		return Math.round( ( asUTC - date.getTime() ) / 60000 );
	}

	/**
	 * The calendar day (y/m/d + weekday index) at an instant, in a timezone.
	 *
	 * @param {string} tz   IANA timezone.
	 * @param {Date}   date Instant.
	 * @return {{y:number,m:number,d:number,dow:number}} dow: 0=Sun … 6=Sat.
	 */
	function zoneDay( tz, date ) {
		var dtf = new Intl.DateTimeFormat( 'en-US', {
			timeZone: tz,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			weekday: 'short'
		} );
		var parts = {};
		dtf.formatToParts( date ).forEach( function ( p ) {
			parts[ p.type ] = p.value;
		} );
		var dows = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
		return {
			y: parseInt( parts.year, 10 ),
			m: parseInt( parts.month, 10 ),
			d: parseInt( parts.day, 10 ),
			dow: dows[ parts.weekday ]
		};
	}

	/**
	 * UTC instant (ms) of a wall-clock time on the zone's current day.
	 * One refinement pass handles DST-transition days, where the offset at
	 * the target time differs from the offset at the sampling time.
	 *
	 * @param {string} tz  IANA timezone.
	 * @param {Object} day {y,m,d} in that zone.
	 * @param {Array}  hm  [hour, minute] wall-clock.
	 * @param {Date}   ref Reference instant for the first offset guess.
	 * @return {number} UTC ms.
	 */
	function zoneTimeToUTC( tz, day, hm, ref ) {
		var guess = Date.UTC( day.y, day.m - 1, day.d, hm[ 0 ], hm[ 1 ] ) - zoneOffsetMinutes( tz, ref ) * 60000;
		return Date.UTC( day.y, day.m - 1, day.d, hm[ 0 ], hm[ 1 ] ) - zoneOffsetMinutes( tz, new Date( guess ) ) * 60000;
	}

	/**
	 * Format a UTC instant as HH:MM in IST.
	 *
	 * @param {number} ms UTC ms.
	 * @return {string}
	 */
	function formatIST( ms ) {
		var d = new Date( ms + IST_OFFSET_MS );
		var h = d.getUTCHours();
		var m = d.getUTCMinutes();
		return ( h < 10 ? '0' + h : '' + h ) + ':' + ( m < 10 ? '0' + m : '' + m );
	}

	/**
	 * Today's session windows in IST, with open/closed state.
	 *
	 * @param {number} [nowMs] Instant to evaluate (defaults to Date.now()).
	 * @return {Array<{id:string,label:string,openMs:number,closeMs:number,openIST:string,closeIST:string,closesNextDay:boolean,isOpen:boolean,weekend:boolean}>}
	 */
	function sessionWindowsIST( nowMs ) {
		var now = new Date( 'number' === typeof nowMs ? nowMs : Date.now() );

		return SESSIONS.map( function ( s ) {
			var day = zoneDay( s.tz, now );
			var openMs = zoneTimeToUTC( s.tz, day, s.open, now );
			var closeMs = zoneTimeToUTC( s.tz, day, s.close, now );
			var weekend = 0 === day.dow || 6 === day.dow;

			return {
				id: s.id,
				label: s.label,
				openMs: openMs,
				closeMs: closeMs,
				openIST: formatIST( openMs ),
				closeIST: formatIST( closeMs ),
				closesNextDay: new Date( closeMs + IST_OFFSET_MS ).getUTCDate() !== new Date( openMs + IST_OFFSET_MS ).getUTCDate(),
				weekend: weekend,
				isOpen: ! weekend && now.getTime() >= openMs && now.getTime() < closeMs
			};
		} );
	}

	/**
	 * Today's London–New York overlap window in IST.
	 *
	 * @param {number} [nowMs] Instant to evaluate (defaults to Date.now()).
	 * @return {{startMs:number,endMs:number,startIST:string,endIST:string,active:boolean}}
	 */
	function overlapLondonNY( nowMs ) {
		var now = 'number' === typeof nowMs ? nowMs : Date.now();
		var windows = {};
		sessionWindowsIST( now ).forEach( function ( w ) {
			windows[ w.id ] = w;
		} );

		var start = windows.new_york.openMs;
		var end = windows.london.closeMs;

		return {
			startMs: start,
			endMs: end,
			startIST: formatIST( start ),
			endIST: formatIST( end ),
			active: ! windows.london.weekend && now >= start && now < end
		};
	}

	return {
		PAIRS: PAIRS,
		SESSIONS: SESSIONS,
		pipValue: pipValue,
		positionSize: positionSize,
		profitLoss: profitLoss,
		marginRequired: marginRequired,
		zoneOffsetMinutes: zoneOffsetMinutes,
		sessionWindowsIST: sessionWindowsIST,
		overlapLondonNY: overlapLondonNY,
		formatIST: formatIST
	};
} ) );
