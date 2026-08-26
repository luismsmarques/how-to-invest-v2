<?php
/**
 * Seeds the /forex/ section: the hub page plus the three tool pages, as
 * ordinary WordPress pages with block markup around the [hti_forex_tool]
 * shortcode. Create-only and idempotent (matched by path) — re-running never
 * overwrites an editor's changes. English-only: when Polylang is active each
 * page is assigned the "en" language with no PT counterpart, which the
 * theme's hreflang/language-switcher code tolerates.
 *
 * FAQ sections are rendered from Config::faqs() — the same array the
 * FAQPage JSON-LD uses — so page copy and schema agree at seed time.
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent page seeder (admin button + `wp hti-forex seed`).
 */
class Seeder {

	private const SEED_FLAG = '_hti_forex_seeded';

	/**
	 * Hook the admin surface.
	 */
	public static function init(): void {
		add_action( 'hti_forex_settings_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'admin_post_hti_forex_seed', array( __CLASS__, 'handle_form' ) );
	}

	/**
	 * Create the missing pages. Safe to re-run.
	 *
	 * @return array{created:int,skipped:int}
	 */
	public static function seed(): array {
		$created = 0;
		$skipped = 0;

		// The hub first, so children can hang off it.
		$hub    = self::page_defs()['hub'];
		$hub_id = self::insert( $hub, 0 );
		if ( $hub_id > 0 ) {
			++$created;
		} else {
			++$skipped;
			$existing = get_page_by_path( $hub['path'], OBJECT, 'page' );
			$hub_id   = $existing instanceof \WP_Post ? (int) $existing->ID : 0;
		}

		foreach ( self::page_defs() as $key => $def ) {
			if ( 'hub' === $key ) {
				continue;
			}
			$id = self::insert( $def, $hub_id );
			if ( $id > 0 ) {
				++$created;
			} else {
				++$skipped;
			}
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
		);
	}

	/**
	 * Insert one page unless its path already exists.
	 *
	 * @param array<string,mixed> $def    Page definition.
	 * @param int                 $parent Parent page ID (0 for the hub).
	 * @return int New post ID, or 0 if skipped/failed.
	 */
	private static function insert( array $def, int $parent ): int {
		if ( get_page_by_path( $def['path'], OBJECT, 'page' ) instanceof \WP_Post ) {
			return 0;
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
			return 0;
		}

		update_post_meta( $id, self::SEED_FLAG, VERSION );
		update_post_meta( $id, Schema::PAGE_META, $def['page'] );
		if ( ! empty( $def['seo_title'] ) ) {
			update_post_meta( $id, 'rank_math_title', $def['seo_title'] );
			update_post_meta( $id, '_yoast_wpseo_title', $def['seo_title'] );
		}
		if ( ! empty( $def['seo_desc'] ) ) {
			update_post_meta( $id, 'rank_math_description', $def['seo_desc'] );
			update_post_meta( $id, '_yoast_wpseo_metadesc', $def['seo_desc'] );
		}

		// English-only by design: assign the EN language, link no translation.
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( (int) $id, 'en' );
		}

		return (int) $id;
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
		$hub_url  = home_url( '/forex/' );
		$pos_url  = home_url( '/forex/position-size-calculator/' );
		$pip_url  = home_url( '/forex/pip-value-calculator/' );
		$hours_url = home_url( '/forex/market-hours-ist/' );

		return array(
			'hub'           => array(
				'page'      => 'hub',
				'path'      => 'forex',
				'slug'      => 'forex',
				'title'     => 'Free forex tools for Indian traders (INR)',
				'seo_title' => 'Free Forex Tools for Indian Traders — INR Calculators & IST Market Hours',
				'seo_desc'  => 'Free forex calculators with INR as the account currency: position size, pip value in rupees, and live market hours in IST. Educational, no sign-up.',
				'content'   => self::p( 'Free forex calculators built for traders in India: your account in Indian rupees, market hours in IST, and the contract conventions actually used on global platforms. No sign-up, no fees — every tool is educational and works on your phone.' )
					. self::h2( 'The tools' )
					. self::ul(
						array(
							'<a href="' . esc_url( $pos_url ) . '">Position size calculator in ₹ (INR)</a> — how many lots fit your account, risk and stop-loss.',
							'<a href="' . esc_url( $pip_url ) . '">Pip value calculator in Indian rupees</a> — what one pip is worth in ₹, including gold (XAUUSD).',
							'<a href="' . esc_url( $hours_url ) . '">Forex market hours in IST</a> — live session clock with the London–New York overlap in Indian time.',
						)
					)
					. self::h2( 'About these tools' )
					. self::p( 'Every calculator here is an illustration of the arithmetic — how position sizing, pip values and session times work — not advice about what, when or whether to trade. Forex and CFDs are leveraged, high-risk products; most retail accounts lose money.' )
					. self::faq_section( 'hub' ),
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
	 * @param string $name Tool name.
	 */
	private static function tool( string $name ): string {
		return '<!-- wp:shortcode -->[hti_forex_tool name="' . $name . '"]<!-- /wp:shortcode -->' . "\n\n";
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
		<h2><?php esc_html_e( 'Seed the /forex/ pages', 'hti-forex' ); ?></h2>
		<?php if ( isset( $_GET['hti_forex_seeded'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p>
				<?php
				printf(
					/* translators: 1: pages created, 2: pages skipped. */
					esc_html__( 'Seeder ran: %1$s created, %2$s already existed.', 'hti-forex' ),
					esc_html( sanitize_key( wp_unslash( $_GET['hti_forex_seeded'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					esc_html( sanitize_key( wp_unslash( $_GET['hti_forex_skipped'] ?? '0' ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);
				?>
			</p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Creates the /forex/ hub and the three tool pages (English only). Existing pages (matched by path) are skipped, so your edits are safe.', 'hti-forex' ); ?></p>
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
					'page'              => 'hti-forex',
					'hti_forex_seeded'  => (string) $report['created'],
					'hti_forex_skipped' => (string) $report['skipped'],
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
