<?php
/**
 * Plugin Name:       HTI Engine
 * Plugin URI:        https://howtoinvest.pro/
 * Description:       The HowToInvest product: educational recommendation engine plus the public content types (glossary, news) that power SEO. Decisions are deterministic; the LLM only explains.
 * Version:           0.8.56
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            HowToInvest
 * Author URI:        https://howtoinvest.pro/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hti-engine
 * Domain Path:       /languages
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version, used for cache-busting enqueued assets.
 */
const VERSION = '0.8.56';

define( 'HTI_ENGINE_FILE', __FILE__ );
define( 'HTI_ENGINE_PATH', plugin_dir_path( __FILE__ ) );
define( 'HTI_ENGINE_URL', plugin_dir_url( __FILE__ ) );

// Composer dependencies (e.g. Dompdf for PDF export), installed at deploy.
if ( is_readable( HTI_ENGINE_PATH . 'vendor/autoload.php' ) ) {
	require_once HTI_ENGINE_PATH . 'vendor/autoload.php';
}

require_once HTI_ENGINE_PATH . 'includes/class-cpt.php';
require_once HTI_ENGINE_PATH . 'includes/class-taxonomy.php';
require_once HTI_ENGINE_PATH . 'includes/class-seo.php';
require_once HTI_ENGINE_PATH . 'includes/class-news-sitemap.php';
require_once HTI_ENGINE_PATH . 'includes/class-ads-txt.php';
require_once HTI_ENGINE_PATH . 'includes/class-redirects.php';
require_once HTI_ENGINE_PATH . 'includes/class-seeder.php';
require_once HTI_ENGINE_PATH . 'includes/class-content-import.php';
require_once HTI_ENGINE_PATH . 'includes/class-glossary-import.php';
require_once HTI_ENGINE_PATH . 'includes/class-config.php';
require_once HTI_ENGINE_PATH . 'includes/class-engine.php';
require_once HTI_ENGINE_PATH . 'includes/class-fallback.php';
require_once HTI_ENGINE_PATH . 'includes/class-validator.php';
require_once HTI_ENGINE_PATH . 'includes/class-prompt.php';
require_once HTI_ENGINE_PATH . 'includes/class-gemini.php';
require_once HTI_ENGINE_PATH . 'includes/class-llm.php';
require_once HTI_ENGINE_PATH . 'includes/class-explainer.php';
require_once HTI_ENGINE_PATH . 'includes/class-disclaimer.php';
require_once HTI_ENGINE_PATH . 'includes/class-rate-limit.php';
require_once HTI_ENGINE_PATH . 'includes/class-mailer.php';
require_once HTI_ENGINE_PATH . 'includes/class-brevo.php';
require_once HTI_ENGINE_PATH . 'includes/class-emails.php';
require_once HTI_ENGINE_PATH . 'includes/class-account.php';
require_once HTI_ENGINE_PATH . 'includes/class-verification.php';
require_once HTI_ENGINE_PATH . 'includes/class-google.php';
require_once HTI_ENGINE_PATH . 'includes/class-learn.php';
require_once HTI_ENGINE_PATH . 'includes/class-rest.php';
require_once HTI_ENGINE_PATH . 'includes/class-questions.php';
require_once HTI_ENGINE_PATH . 'includes/class-frontend.php';
require_once HTI_ENGINE_PATH . 'includes/class-contact.php';
require_once HTI_ENGINE_PATH . 'includes/class-subscribe.php';
require_once HTI_ENGINE_PATH . 'includes/class-campaigns.php';
require_once HTI_ENGINE_PATH . 'includes/class-nps.php';
require_once HTI_ENGINE_PATH . 'includes/class-feedback.php';
require_once HTI_ENGINE_PATH . 'includes/class-tools.php';
require_once HTI_ENGINE_PATH . 'includes/class-deposits.php';
require_once HTI_ENGINE_PATH . 'includes/class-settings.php';
require_once HTI_ENGINE_PATH . 'includes/class-consent.php';
require_once HTI_ENGINE_PATH . 'includes/class-analytics.php';
require_once HTI_ENGINE_PATH . 'includes/class-metrics.php';
require_once HTI_ENGINE_PATH . 'includes/class-pdf.php';
require_once HTI_ENGINE_PATH . 'includes/class-cron.php';

/**
 * Load the plugin text domain (EN default + PT translations in languages/).
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'hti-engine', false, dirname( plugin_basename( HTI_ENGINE_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 0 );

/**
 * Register custom taxonomies (before the CPTs) and post types on every request.
 */
add_action( 'init', array( Taxonomy::class, 'register' ), 9 );
add_action( 'init', array( CPT::class, 'register' ) );

/**
 * Make our public content types translatable in Polylang via code, so PT
 * translations link without anyone having to enable each type in Polylang's
 * settings. No-op when Polylang is inactive (the filters never fire).
 */
add_filter(
	'pll_get_post_types',
	function ( $post_types, $is_settings = false ) {
		foreach ( array( 'glossary', 'news', 'learn' ) as $pt ) {
			$post_types[ $pt ] = $pt;
		}
		return $post_types;
	},
	10,
	2
);
add_filter(
	'pll_get_taxonomies',
	function ( $taxonomies, $is_settings = false ) {
		foreach ( array( 'glossary_topic', 'news_category', 'learn_topic' ) as $tax ) {
			$taxonomies[ $tax ] = $tax;
		}
		return $taxonomies;
	},
	10,
	2
);

/**
 * Flush rewrite rules once per plugin version (so new CPT/taxonomy permalinks
 * like /learn/ resolve after an update, without re-saving permalinks).
 */
add_action(
	'init',
	function () {
		if ( get_option( 'hti_rewrite_version' ) !== VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'hti_rewrite_version', VERSION );
		}
	},
	11
);

/**
 * Wire up SEO structured data (JSON-LD) for the public content types.
 */
SEO::init();

/**
 * Google News XML sitemap at /news-sitemap.xml (last 48h, per-post language).
 */
News_Sitemap::init();

/**
 * ads.txt (AdSense authorized sellers) served virtually at /ads.txt.
 */
Ads_Txt::init();

/**
 * 301 redirects from the legacy Base44 URLs.
 */
Redirects::init();

/**
 * REST API (htinvest/v1): /recommend and the account/RGPD routes.
 */
REST::init();

/**
 * Email verification (double opt-in): verify link + unverified-login gate.
 */
Verification::init();

/**
 * "Sign in with Google" OAuth flow.
 */
Google::init();

/**
 * Front-end app: the [hti_questionnaire] shortcode (questionnaire + result).
 */
Frontend::init();
Contact::init();
Subscribe::init();
Campaigns::init();
Nps::init();
Feedback::init();
Emails::init();
Account::init();
Tools::init();
Deposits::init();

/**
 * Admin settings (Settings → HowToInvest): Gemini key/model + scoring/archetypes.
 */
Settings::init();

/**
 * Cookie consent banner (E8, RGPD): privacy-first, analytics opt-in.
 */
Consent::init();

/**
 * Google Analytics — loaded only after analytics consent is granted.
 */
Analytics::init();

/**
 * First-party anonymous funnel counters (HTI Funnel admin screen).
 */
Metrics::init();

/**
 * PDF export of a saved result (admin-post handler).
 */
PdfExport::init();

/**
 * Baseline security headers on front-end responses. A full CSP is intentionally
 * omitted — the site relies on inline scripts (the GA loader, consent, the
 * ebook gate) that a strict CSP would break without per-request nonces. HSTS is
 * opt-in via the `hti_enable_hsts` filter so it is only enabled once HTTPS is
 * confirmed forced (enabling it prematurely can lock users out).
 */
function send_security_headers(): void {
	if ( is_admin() || headers_sent() ) {
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
	if ( is_ssl() && (bool) apply_filters( 'hti_enable_hsts', false ) ) {
		$max_age = max( 0, (int) apply_filters( 'hti_hsts_max_age', 15552000 ) );
		$hsts    = 'max-age=' . $max_age;
		if ( (bool) apply_filters( 'hti_hsts_include_subdomains', true ) ) {
			$hsts .= '; includeSubDomains';
		}
		header( 'Strict-Transport-Security: ' . $hsts );
	}
}
add_action( 'send_headers', __NAMESPACE__ . '\\send_security_headers' );

/**
 * HSTS rollout — staged and reversible.
 *
 * Phase A (current): enabled with a short 5-minute max-age and apex only (no
 * includeSubDomains), so any HTTPS problem self-heals within minutes instead of
 * locking browsers for months.
 *
 * Phase B (later, once the header is confirmed live and EVERY subdomain —
 * including staging — serves valid HTTPS): raise `hti_hsts_max_age` to
 * 15552000 (180 days) and set `hti_hsts_include_subdomains` to true.
 *
 * To roll back before Phase B, remove the three filters below.
 */
add_filter( 'hti_enable_hsts', '__return_true' );
add_filter( 'hti_hsts_max_age', static function (): int { return 300; } );
add_filter( 'hti_hsts_include_subdomains', '__return_false' );

/**
 * Daily pruning of stale anonymous profiles (RGPD minimization).
 */
Cron::init();

/**
 * Content seeder (Tools → Seed content, and the `wp hti seed` WP-CLI command).
 */
Seeder::register();

/**
 * Learn content pipeline (Tools → Learn content, and `wp hti import-learn`).
 * Separate from the seeder: imports content/learn/*.md as reviewable drafts.
 */
Content_Import::init();
Glossary_Import::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'hti seed',
		function () {
			$report = Seeder::seed();
			\WP_CLI::success(
				sprintf(
					'%d glossary terms, %d pages and %d articles created, %d PT translations linked, %d skipped.',
					$report['glossary_created'],
					$report['pages_created'],
					$report['articles_created'],
					$report['translations_created'],
					$report['skipped']
				)
			);
		}
	);

	\WP_CLI::add_command(
		'hti import-learn',
		function () {
			$report = Content_Import::import();
			foreach ( $report as $r ) {
				\WP_CLI::line( sprintf( '- %-34s EN:%-8s PT:%-8s', $r['slug'], $r['en_status'], $r['pt_status'] ) );
			}
			\WP_CLI::success( sprintf( '%d Learn chapters imported/synced (new ones published in both languages).', count( $report ) ) );
		}
	);

	\WP_CLI::add_command(
		'hti import-glossary',
		function () {
			$report = Glossary_Import::import();
			foreach ( $report as $r ) {
				\WP_CLI::line( sprintf( '- %-26s EN:%-8s PT:%-8s', $r['slug'], $r['en_status'], $r['pt_status'] ) );
			}
			\WP_CLI::success( sprintf( '%d glossary terms imported/synced (existing updated in place, new published in both languages).', count( $report ) ) );
		}
	);
}

/**
 * Activation: register CPTs once, then flush rewrite rules so their
 * archive/single permalinks (/investing-glossary/, /financial-news/) resolve immediately.
 */
function activate(): void {
	Taxonomy::register();
	CPT::register();
	Cron::schedule();
	Feedback::install();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation: drop the CPT rewrite rules we added.
 */
function deactivate(): void {
	Cron::unschedule();
	Campaigns::unschedule();
	Account::unschedule();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
