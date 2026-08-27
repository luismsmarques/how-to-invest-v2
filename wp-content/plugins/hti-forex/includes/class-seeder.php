<?php
/**
 * Seeds and syncs the /forex/ section: the hub page (around the
 * [hti_forex_hub] shortcode) plus the tool pages, as ordinary WordPress
 * pages with block markup around the [hti_forex_tool] shortcode. Pages are
 * upserted (matched by path) under a per-page content hash, so repo edits
 * converge on the site while slugs, statuses and unrelated editor changes
 * are preserved. A cheap deploy gate (same design as hti-engine's
 * Content_Sync) schedules one background sync when this file changes;
 * auto mode only updates pages that already exist — creating them stays a
 * manual owner action (admin button or `wp hti-forex seed`). English-only:
 * when Polylang is active each page is assigned the "en" language with no
 * PT counterpart, which the theme's hreflang/language-switcher tolerates.
 *
 * FAQ sections are rendered from Config::faqs() — the same array the
 * FAQPage JSON-LD uses — so page copy and schema agree at sync time.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Upserting page seeder (admin button, `wp hti-forex seed`, deploy sync).
 */
class Seeder {

	private const SEED_FLAG = '_hti_forex_seeded';

	/**
	 * Meta key holding the hash of the repo content last written to a page.
	 * When it matches the current build the page is skipped (no revisions,
	 * no post_modified churn).
	 */
	private const HASH_META = '_hti_forex_synced_hash';

	/**
	 * Cron hook fired once, shortly after a deploy changes this file.
	 */
	public const HOOK = 'hti_forex_content_sync';

	/**
	 * Option holding the last observed content signature.
	 */
	private const OPTION_SIG = 'hti_forex_sync_sig';

	/**
	 * Transient throttling the filesystem check to once per interval.
	 */
	private const THROTTLE = 'hti_forex_sync_checked';

	/**
	 * Hook the admin surface and the deploy gate.
	 */
	public static function init(): void {
		add_action( 'hti_forex_settings_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'admin_post_hti_forex_seed', array( __CLASS__, 'handle_form' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
		add_action( self::HOOK, array( __CLASS__, 'run_auto' ) );
	}

	/* ---------------------------------------------------------------------
	 * Deploy detection (mirrors hti-engine's Content_Sync, scoped to forex).
	 * ------------------------------------------------------------------- */

	/**
	 * Cheap gate, run on init at most once per 10 minutes: when the content
	 * signature no longer matches the stored one, record it and schedule one
	 * background sync event.
	 */
	public static function maybe_schedule(): void {
		if ( false !== get_transient( self::THROTTLE ) ) {
			return;
		}
		set_transient( self::THROTTLE, 1, 10 * MINUTE_IN_SECONDS );

		$sig = self::signature();
		if ( (string) get_option( self::OPTION_SIG ) === $sig ) {
			return;
		}
		update_option( self::OPTION_SIG, $sig, false );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}

	/**
	 * Signature of everything the page content is built from: this file and
	 * the FAQ/config source, keyed by mtime|size, plus the plugin version.
	 * (The cPanel deploy rewrites files, so a deploy always changes mtimes.)
	 */
	public static function signature(): string {
		$entries = array();
		foreach ( array( __FILE__, __DIR__ . '/class-config.php' ) as $path ) {
			$entries[] = is_readable( $path )
				? $path . '|' . (int) filemtime( $path ) . '|' . (int) filesize( $path )
				: $path . '|missing';
		}
		sort( $entries );
		return md5( implode( "\n", $entries ) . "\n" . VERSION );
	}

	/**
	 * Background sync: update-only, so a site where the section was never
	 * seeded (or where the owner deleted a page) is left alone.
	 */
	public static function run_auto(): void {
		self::seed( false );
	}

	/* ---------------------------------------------------------------------
	 * Upsert
	 * ------------------------------------------------------------------- */

	/**
	 * Upsert every page. Safe to re-run: unchanged pages (hash match) are
	 * skipped untouched.
	 *
	 * @param bool $create_missing Create pages that don't exist (manual seed).
	 *                             False in auto mode: update-only.
	 * @return array{created:int,updated:int,unchanged:int,missing:int}
	 */
	public static function seed( bool $create_missing = true ): array {
		$report = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'missing'   => 0,
		);

		// The hub first, so children can hang off it.
		$hub = self::page_defs()['hub'];
		++$report[ self::upsert( $hub, 0, $create_missing ) ];
		$existing = get_page_by_path( $hub['path'], OBJECT, 'page' );
		$hub_id   = $existing instanceof \WP_Post ? (int) $existing->ID : 0;

		foreach ( self::page_defs() as $key => $def ) {
			if ( 'hub' === $key ) {
				continue;
			}
			++$report[ self::upsert( $def, $hub_id, $create_missing ) ];
		}

		return $report;
	}

	/**
	 * Deterministic hash of the fields the sync manages. Pure (testable
	 * without WordPress).
	 *
	 * @param array<string,mixed> $def Page definition.
	 */
	public static function sync_hash( array $def ): string {
		return md5(
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure helper, must run without WordPress.
				array(
					'title'     => (string) ( $def['title'] ?? '' ),
					'content'   => (string) ( $def['content'] ?? '' ),
					'seo_title' => (string) ( $def['seo_title'] ?? '' ),
					'seo_desc'  => (string) ( $def['seo_desc'] ?? '' ),
					'page'      => (string) ( $def['page'] ?? '' ),
				)
			)
		);
	}

	/**
	 * Create or update one page, matched by path. Updates preserve the slug,
	 * status and parent; only title, content, schema key and SEO meta are
	 * written, and only when the repo content actually changed.
	 *
	 * @param array<string,mixed> $def            Page definition.
	 * @param int                 $parent         Parent page ID (0 for the hub).
	 * @param bool                $create_missing Create the page if absent.
	 * @return string Report bucket: created|updated|unchanged|missing.
	 */
	private static function upsert( array $def, int $parent, bool $create_missing ): string {
		$hash     = self::sync_hash( $def );
		$existing = get_page_by_path( $def['path'], OBJECT, 'page' );

		if ( $existing instanceof \WP_Post ) {
			if ( get_post_meta( (int) $existing->ID, self::HASH_META, true ) === $hash ) {
				return 'unchanged';
			}
			$res = wp_update_post(
				wp_slash(
					array(
						'ID'           => (int) $existing->ID,
						'post_title'   => $def['title'],
						'post_content' => $def['content'],
					)
				),
				true
			);
			if ( is_wp_error( $res ) || 0 === $res ) {
				return 'unchanged';
			}
			self::write_meta( (int) $existing->ID, $def, $hash );
			return 'updated';
		}

		if ( ! $create_missing ) {
			return 'missing';
		}

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $def['title'],
					'post_name'    => $def['slug'],
					'post_parent'  => $parent,
					'post_content' => $def['content'],
				)
			),
			true
		);
		if ( is_wp_error( $id ) || 0 === $id ) {
			return 'missing';
		}
		self::write_meta( (int) $id, $def, $hash );

		// English-only by design: assign the EN language, link no translation.
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( (int) $id, 'en' );
		}

		return 'created';
	}

	/**
	 * Write the seed flag, schema page key, SEO meta and sync hash.
	 *
	 * @param int                 $id   Page ID.
	 * @param array<string,mixed> $def  Page definition.
	 * @param string              $hash Sync hash for the definition.
	 */
	private static function write_meta( int $id, array $def, string $hash ): void {
		update_post_meta( $id, self::SEED_FLAG, VERSION );
		update_post_meta( $id, self::HASH_META, $hash );
		update_post_meta( $id, Schema::PAGE_META, $def['page'] );
		if ( ! empty( $def['seo_title'] ) ) {
			update_post_meta( $id, 'rank_math_title', $def['seo_title'] );
			update_post_meta( $id, '_yoast_wpseo_title', $def['seo_title'] );
		}
		if ( ! empty( $def['seo_desc'] ) ) {
			update_post_meta( $id, 'rank_math_description', $def['seo_desc'] );
			update_post_meta( $id, '_yoast_wpseo_metadesc', $def['seo_desc'] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Page definitions
	 * ------------------------------------------------------------------- */

	/**
	 * The four pages. Paths are hierarchical (get_page_by_path resolves
	 * them); slugs are the head query, with INR/India carried by titles,
	 * H1s and FAQs so the slug survives audience broadening.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function page_defs(): array {
		$pos_url    = home_url( '/forex/position-size-calculator/' );
		$pip_url    = home_url( '/forex/pip-value-calculator/' );
		$hours_url  = home_url( '/forex/market-hours-ist/' );
		$profit_url = home_url( '/forex/profit-calculator/' );
		$small_url  = home_url( '/forex/lot-size-for-100-dollar-account/' );

		return array(
			'hub'           => array(
				'page'      => 'hub',
				'path'      => 'forex',
				'slug'      => 'forex',
				'title'     => 'Free forex tools for Indian traders (INR)',
				'seo_title' => 'Free Forex Tools for Indian Traders — INR Calculators & IST Market Hours',
				'seo_desc'  => 'Free forex calculators with INR as the account currency: position size, pip value in rupees, and live market hours in IST. Educational, no sign-up.',
				'content'   => '<!-- wp:shortcode -->[hti_forex_hub]<!-- /wp:shortcode -->' . "\n",
			),
			'position_size' => array(
				'page'      => 'position_size',
				'path'      => 'forex/position-size-calculator',
				'slug'      => 'position-size-calculator',
				'title'     => 'Forex position size calculator in ₹ (INR)',
				'seo_title' => 'Forex Position Size Calculator in INR (₹) — Lots from Risk & Stop-Loss',
				'seo_desc'  => 'Position size calculator with INR as the account currency: enter balance in ₹, risk % and stop-loss in pips to get lots, units and the exact rupees at risk.',
				'content'   => self::p( 'Enter your account balance in rupees, the percentage you are prepared to risk and your stop-loss distance — the calculator returns the position in lots and units, and the exact amount in ₹ actually at risk. It recalculates as you type, natively in INR rather than converting at the end.' )
					. self::tool( 'position_size' )
					. self::h2( 'How it works' )
					. self::p( 'The amount at risk is your balance multiplied by the risk percentage. Dividing it by the stop-loss distance in pips times the pip value in rupees per lot gives the raw position, which is then rounded down to the nearest micro lot (0.01) — rounding down means the rupee risk shown is never higher than the risk you chose. As an example, a ₹1,00,000 account risking 1% with a 20-pip stop on EUR/USD at ₹83 per dollar works out to 0.06 lots, with ₹996 actually at risk.' )
					. self::faq_section( 'position_size' )
					. self::p( 'Also see the <a href="' . esc_url( $pip_url ) . '">pip value calculator in Indian rupees</a> and the <a href="' . esc_url( $hours_url ) . '">forex market hours in IST</a>.' ),
			),
			'pip_value'     => array(
				'page'      => 'pip_value',
				'path'      => 'forex/pip-value-calculator',
				'slug'      => 'pip-value-calculator',
				'title'     => 'Pip value calculator in Indian rupees (INR)',
				'seo_title' => 'Pip Value Calculator in Indian Rupees — 1 Pip in INR (EURUSD, Gold, USDJPY)',
				'seo_desc'  => 'How much is 1 pip in Indian rupees? Convert pip value to ₹ for EURUSD, GBPUSD, USDJPY, gold (XAUUSD) and USDINR, per standard, mini and micro lot.',
				'content'   => self::p( 'How much is one pip worth in Indian rupees? Pick a pair and a position size — the calculator shows the pip value in ₹ and US dollars, plus the value per standard, mini and micro lot. The USD/INR reference rate is shown with its date and stays editable.' )
					. self::tool( 'pip_value' )
					. self::h2( 'How it works' )
					. self::p( 'A pip value is born in the pair\'s quote currency: pip size times contract size times lots. It is converted to US dollars where needed (yen pairs via USD/JPY) and then to rupees at the USD/INR rate. On EUR/USD, one pip on a standard lot is $10 — about ₹830 at ₹83 per dollar.' )
					. self::h2( 'Gold (XAUUSD) pip value in rupees', 'xauusd' )
					. self::p( 'Gold is quoted in dollars per troy ounce and a standard lot is 100 oz. Using the most common retail convention — one pip equals a $0.10 move — one pip on a full gold lot is worth $10, roughly ₹830 at ₹83 per dollar. Some platforms count each $0.01 tick as a pip worth $1 instead; the FAQ below covers the difference.' )
					. self::faq_section( 'pip_value' )
					. self::p( 'Also see the <a href="' . esc_url( $pos_url ) . '">position size calculator in ₹</a> and the <a href="' . esc_url( $hours_url ) . '">forex market hours in IST</a>.' ),
			),
			'profit_loss'   => array(
				'page'      => 'profit_loss',
				'path'      => 'forex/profit-calculator',
				'slug'      => 'profit-calculator',
				'title'     => 'Forex profit calculator in ₹ (INR)',
				'seo_title' => 'Forex Profit Calculator in INR (₹) — P/L in Rupees per Trade',
				'seo_desc'  => 'Work out the profit or loss of a forex trade in Indian rupees: pair, buy or sell, lots, entry and exit price — converted to ₹ at the USD/INR rate.',
				'content'   => self::p( 'Pick a pair and direction, enter the position size, entry and exit prices — the calculator shows the gross profit or loss of the trade in rupees and dollars, plus the pips moved. Prices prefill with plausible values when you switch pairs; overwrite them with your own.' )
					. self::tool( 'profit_loss' )
					. self::h2( 'How it works' )
					. self::p( 'The price difference is multiplied by the contract size and the lots, giving the result in the pair\'s quote currency; that amount is converted to US dollars where needed and then to rupees at the USD/INR reference rate. Buying 0.10 lots of EUR/USD at 1.0900 and closing at 1.0920 gains 20 pips — $20, or about ₹1,660 at ₹83 per dollar. The result is gross: spreads, commissions and swaps are not included.' )
					. self::faq_section( 'profit_loss' )
					. self::p( 'Also see the <a href="' . esc_url( $pos_url ) . '">position size calculator in ₹</a> and the <a href="' . esc_url( $pip_url ) . '">pip value calculator in Indian rupees</a>.' ),
			),
			'xauusd'        => array(
				'page'      => 'xauusd',
				'path'      => 'forex/xauusd-lot-size-calculator',
				'slug'      => 'xauusd-lot-size-calculator',
				'title'     => 'XAUUSD (gold) lot size calculator in ₹',
				'seo_title' => 'XAUUSD Lot Size Calculator — Gold Position Size in INR (₹)',
				'seo_desc'  => 'Gold (XAUUSD) position size calculator with INR as the account currency: 100 oz contracts, $0.10 pips, stop-loss in pips and the exact rupees at risk.',
				'content'   => self::p( 'Gold is the most-searched instrument among Indian forex traders, and it is also where position sizing goes wrong most often: a standard XAUUSD lot is 100 troy ounces — around $330,000 of exposure at $3,300 per ounce — and the price moves in bigger dollar steps than any major currency pair. This calculator sizes a gold position from your rupee balance, risk percentage and stop distance, preset to XAUUSD.' )
					. self::tool( 'position_size', 'pair="XAUUSD" stop="50"' )
					. self::h2( 'How gold contracts work' )
					. self::p( 'XAUUSD is quoted in US dollars per troy ounce. On the common retail convention a pip is a $0.10 move, worth $10 per standard lot (100 oz), about ₹830 at ₹83 per dollar — some platforms count each $0.01 tick as a $1 pip instead, so it is worth checking the contract specification. Because gold\'s daily range is wide, stops tend to be set in larger pip counts than on currency pairs, which shrinks the lot size the same rupee risk can support.' )
					. self::faq_section( 'xauusd' )
					. self::p( 'Also see the <a href="' . esc_url( $pip_url ) . '#xauusd">gold section of the pip value calculator</a> and the <a href="' . esc_url( $profit_url ) . '">profit calculator in ₹</a>.' ),
			),
			'small_account' => array(
				'page'      => 'small_account',
				'path'      => 'forex/lot-size-for-100-dollar-account',
				'slug'      => 'lot-size-for-100-dollar-account',
				'title'     => 'Lot size for a $100 forex account',
				'seo_title' => 'What Lot Size for a $100 Forex Account? The Honest Arithmetic (INR)',
				'seo_desc'  => 'What lot size fits a $100 (≈₹8,500) forex account? The honest answer: often below one micro lot. Run the numbers in rupees for $100, $500 and $1,000 accounts.',
				'content'   => self::p( 'One of the most-asked questions among new traders is what lot size fits a $100 account — and most pages dodge the honest answer. This calculator is preset to roughly ₹8,500 (about $100), 1% risk and a 20-pip stop; run it and it will tell you the position is below one micro lot, because it is. Change the inputs to see what balances the arithmetic actually supports.' )
					. self::tool( 'position_size', 'balance="8500" risk="1" stop="20"' )
					. self::h2( 'The honest arithmetic' )
					. self::p( 'Risking 1% of ₹8,500 is about ₹85 per trade. With a 20-pip stop on EUR/USD, where a micro lot\'s pip is worth about ₹8.30 at ₹83 per dollar, the position that matches ₹85 of risk is roughly half a micro lot — below the smallest size most platforms allow. That is not a flaw in the calculator; it is the reason very small accounts so often blow up: every position they can open risks more than the percentage its owner intended.' )
					. self::faq_section( 'small_account' )
					. self::p( 'Also see the <a href="' . esc_url( $pos_url ) . '">full position size calculator in ₹</a> and the <a href="' . esc_url( $pip_url ) . '">pip value calculator in Indian rupees</a>.' ),
			),
			'leverage'      => array(
				'page'      => 'leverage',
				'path'      => 'forex/lot-size-calculator-with-leverage',
				'slug'      => 'lot-size-calculator-with-leverage',
				'title'     => 'Lot size calculator with leverage (margin in ₹)',
				'seo_title' => 'Lot Size Calculator with Leverage — Position Size & Margin in INR (₹)',
				'seo_desc'  => 'Position size calculator with leverage: lots from your balance, risk and stop, plus the notional value and margin required in Indian rupees at any leverage.',
				'content'   => self::p( 'Leverage is the most misunderstood number in forex: it does not decide how large your position should be — your balance, risk percentage and stop-loss do that. What leverage decides is the margin set aside to hold the position. This calculator does both jobs at once: it sizes the position from your risk, then shows the notional value and the margin that size actually requires in rupees at your chosen leverage.' )
					. self::tool( 'position_size', 'leverage="1"' )
					. self::h2( 'Position size vs margin' )
					. self::p( 'The position size comes from the risk arithmetic: rupees at risk divided by stop distance times pip value. The margin comes from the exposure: the position\'s notional value divided by the leverage. At 1:500, a 0.06-lot EUR/USD position (about ₹5.4 lakh of notional at ₹83 per dollar) needs only around ₹1,086 of margin — which is exactly why high leverage makes it dangerously easy to open positions far larger than a risk-based size.' )
					. self::faq_section( 'leverage' )
					. self::p( 'Also see the <a href="' . esc_url( $pos_url ) . '">position size calculator in ₹</a> and the <a href="' . esc_url( $small_url ) . '">lot size for a $100 account</a>.' ),
			),
			'sessions'      => array(
				'page'      => 'sessions',
				'path'      => 'forex/market-hours-ist',
				'slug'      => 'market-hours-ist',
				'title'     => 'Forex market hours in IST (India time)',
				'seo_title' => 'Forex Market Hours in IST — Live Session Clock for India',
				'seo_desc'  => 'Forex market timings in Indian Standard Time: live IST clock, London, New York, Tokyo and Sydney sessions, and the London–NY overlap — DST handled automatically.',
				'content'   => self::p( 'The forex market runs 24 hours a day, five days a week — but not all hours are equal, and every session table you find online is written in someone else\'s timezone. This clock shows today\'s sessions in Indian Standard Time, live, including the daylight-saving shifts abroad that move the times twice a year.' )
					. self::tool( 'sessions' )
					. self::h2( 'Reading the clock' )
					. self::p( 'India does not observe daylight saving, so IST never moves — it is the foreign sessions that shift. The London–New York overlap, historically the busiest stretch of the day, runs roughly 18:30–22:30 IST in winter and 17:30–21:30 IST in summer, with a few transition weeks each March and late October when the US and UK change their clocks on different dates.' )
					. self::faq_section( 'sessions' )
					. self::p( 'Also see the <a href="' . esc_url( $pos_url ) . '">position size calculator in ₹</a> and the <a href="' . esc_url( $pip_url ) . '">pip value calculator in Indian rupees</a>.' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Block-markup helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Paragraph block.
	 *
	 * @param string $html Inner HTML (already escaped where needed).
	 */
	private static function p( string $html ): string {
		return '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	/**
	 * H2 block, optionally with an anchor id.
	 *
	 * @param string $text   Heading text.
	 * @param string $anchor Optional anchor id.
	 */
	private static function h2( string $text, string $anchor = '' ): string {
		$attr = '' !== $anchor ? ' {"anchor":"' . $anchor . '"}' : '';
		$id   = '' !== $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';
		return '<!-- wp:heading' . $attr . ' --><h2 class="wp-block-heading"' . $id . '>' . esc_html( $text ) . '</h2><!-- /wp:heading -->' . "\n\n";
	}

	/**
	 * H3 block.
	 *
	 * @param string $text Heading text.
	 */
	private static function h3( string $text ): string {
		return '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . esc_html( $text ) . '</h3><!-- /wp:heading -->' . "\n\n";
	}

	/**
	 * Unordered-list block.
	 *
	 * @param array<int,string> $items Item HTML.
	 */
	private static function ul( array $items ): string {
		$li = '';
		foreach ( $items as $item ) {
			$li .= '<!-- wp:list-item --><li>' . $item . '</li><!-- /wp:list-item -->';
		}
		return '<!-- wp:list --><ul class="wp-block-list">' . $li . '</ul><!-- /wp:list -->' . "\n\n";
	}

	/**
	 * Tool shortcode block.
	 *
	 * @param string $name  Tool name.
	 * @param string $extra Extra shortcode attributes (already formatted).
	 */
	private static function tool( string $name, string $extra = '' ): string {
		$atts = 'name="' . $name . '"' . ( '' !== $extra ? ' ' . $extra : '' );
		return '<!-- wp:shortcode -->[hti_forex_tool ' . $atts . ']<!-- /wp:shortcode -->' . "\n\n";
	}

	/**
	 * FAQ section rendered from the same config the FAQPage schema uses.
	 *
	 * @param string $page Config::faqs() key.
	 */
	private static function faq_section( string $page ): string {
		$faqs = Config::faqs( $page );
		if ( array() === $faqs ) {
			return '';
		}
		$out = self::h2( 'Frequently asked questions' );
		foreach ( $faqs as $faq ) {
			$out .= self::h3( $faq['q'] ) . self::p( esc_html( $faq['a'] ) );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Admin surface
	 * ------------------------------------------------------------------- */

	/**
	 * Seeder panel on the settings screen.
	 */
	public static function render_panel(): void {
		?>
		<h2><?php esc_html_e( 'Seed / sync the /forex/ pages', 'hti-forex' ); ?></h2>
		<?php if ( isset( $_GET['hti_forex_seeded'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p>
				<?php
				printf(
					/* translators: 1: pages created, 2: pages updated, 3: pages unchanged. */
					esc_html__( 'Seeder ran: %1$s created, %2$s updated, %3$s unchanged.', 'hti-forex' ),
					esc_html( sanitize_key( wp_unslash( $_GET['hti_forex_seeded'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					esc_html( sanitize_key( wp_unslash( $_GET['hti_forex_updated'] ?? '0' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					esc_html( sanitize_key( wp_unslash( $_GET['hti_forex_unchanged'] ?? '0' ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);
				?>
			</p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Creates the /forex/ pages (English only) and updates the ones whose repo content changed. Slugs, statuses and pages you deleted are never touched; after a deploy the update runs automatically in the background.', 'hti-forex' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="hti_forex_seed" />
			<?php wp_nonce_field( 'hti_forex_seed' ); ?>
			<?php submit_button( __( 'Seed forex pages', 'hti-forex' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Handle the seeder form submission.
	 */
	public static function handle_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hti-forex' ) );
		}
		check_admin_referer( 'hti_forex_seed' );

		$report = self::seed();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => 'hti-forex',
					'hti_forex_seeded'    => (string) $report['created'],
					'hti_forex_updated'   => (string) $report['updated'],
					'hti_forex_unchanged' => (string) $report['unchanged'],
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
