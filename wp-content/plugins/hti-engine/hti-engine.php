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

require_once HTI_ENGINE_PATH . 'includes/class-cpt.php';
require_once HTI_ENGINE_PATH . 'includes/class-taxonomy.php';
require_once HTI_ENGINE_PATH . 'includes/class-seo.php';
require_once HTI_ENGINE_PATH . 'includes/class-redirects.php';
require_once HTI_ENGINE_PATH . 'includes/class-seeder.php';

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
					'%d glossary terms and %d pages created, %d skipped.',
					$report['glossary_created'],
					$report['pages_created'],
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
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation: drop the CPT rewrite rules we added.
 */
function deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
