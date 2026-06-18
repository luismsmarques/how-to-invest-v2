<?php
/**
 * Plugin Name:       HTI Engine
 * Plugin URI:        https://howtoinvest.pro/
 * Description:       The HowToInvest product: educational recommendation engine plus the public content types (glossary, news) that power SEO. Decisions are deterministic; the LLM only explains.
 * Version:           0.1.0
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
const VERSION = '0.1.0';

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
require_once HTI_ENGINE_PATH . 'includes/class-redirects.php';
require_once HTI_ENGINE_PATH . 'includes/class-seeder.php';
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
require_once HTI_ENGINE_PATH . 'includes/class-verification.php';
require_once HTI_ENGINE_PATH . 'includes/class-google.php';
require_once HTI_ENGINE_PATH . 'includes/class-rest.php';
require_once HTI_ENGINE_PATH . 'includes/class-questions.php';
require_once HTI_ENGINE_PATH . 'includes/class-frontend.php';
require_once HTI_ENGINE_PATH . 'includes/class-settings.php';
require_once HTI_ENGINE_PATH . 'includes/class-consent.php';
require_once HTI_ENGINE_PATH . 'includes/class-analytics.php';
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
 * Wire up SEO structured data (JSON-LD) for the public content types.
 */
SEO::init();

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
 * PDF export of a saved result (admin-post handler).
 */
PdfExport::init();

/**
 * Daily pruning of stale anonymous profiles (RGPD minimization).
 */
Cron::init();

/**
 * Content seeder (Tools → Seed content, and the `wp hti seed` WP-CLI command).
 */
Seeder::register();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'hti seed',
		function () {
			$report = Seeder::seed();
			\WP_CLI::success(
				sprintf(
					'%d glossary terms, %d pages and %d articles created, %d skipped.',
					$report['glossary_created'],
					$report['pages_created'],
					$report['articles_created'],
					$report['skipped']
				)
			);
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
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation: drop the CPT rewrite rules we added.
 */
function deactivate(): void {
	Cron::unschedule();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
