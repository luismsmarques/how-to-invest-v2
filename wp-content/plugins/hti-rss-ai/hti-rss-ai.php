<?php
/**
 * Plugin Name:       HTI RSS AI Feed
 * Plugin URI:        https://howtoinvest.pro/
 * Description:       Turns RSS feeds and YouTube channels into AI-drafted articles for review (Gemini). Works with any post type, taxonomy and theme — configure the target in Settings.
 * Version:           1.12.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            HowToInvest
 * Author URI:        https://howtoinvest.pro/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hti-rss-ai
 * Domain Path:       /languages
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version (also used to cache-bust admin assets).
 */
const VERSION = '1.12.0';

define( 'RSSAI_FILE', __FILE__ );
define( 'RSSAI_PATH', plugin_dir_path( __FILE__ ) );
define( 'RSSAI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Cron hook for the feed fetcher (scheduled in M2).
 */
const CRON_HOOK = 'rssai_fetch_cron';

/**
 * Cron hook for the daily cleanup of old drafts + logs.
 */
const CLEANUP_HOOK = 'rssai_cleanup_cron';

/**
 * Clustering runs on its own tick rather than tacked onto the end of a fetch.
 * The fetch is network-bound and the grouping is CPU-bound; doing both in one
 * request is how a single scheduled job holds a PHP process long enough for a
 * shared host to start refusing everyone else's.
 */
const GROUP_HOOK = 'rssai_group_cron';

/**
 * A fetch that hit its per-run cap queues one of these to carry on shortly,
 * so capping the work per process costs throughput rather than freshness:
 * fifteen feeds still get visited within the hour, just never all inside one
 * request.
 */
const FETCH_MORE_HOOK = 'rssai_fetch_more';

require_once RSSAI_PATH . 'includes/class-activator.php';
require_once RSSAI_PATH . 'includes/class-logger.php';
require_once RSSAI_PATH . 'includes/class-settings.php';
require_once RSSAI_PATH . 'includes/class-cleanup.php';
require_once RSSAI_PATH . 'includes/class-feeds.php';
require_once RSSAI_PATH . 'includes/class-items.php';
require_once RSSAI_PATH . 'includes/class-groups.php';
require_once RSSAI_PATH . 'includes/class-fetcher.php';
require_once RSSAI_PATH . 'includes/class-grouping.php';
require_once RSSAI_PATH . 'includes/class-gemini-client.php';
require_once RSSAI_PATH . 'includes/class-embeddings.php';
require_once RSSAI_PATH . 'includes/class-youtube.php';
require_once RSSAI_PATH . 'includes/class-supadata.php';
require_once RSSAI_PATH . 'includes/class-image-client.php';
require_once RSSAI_PATH . 'includes/class-prompt.php';
require_once RSSAI_PATH . 'includes/class-validator.php';
require_once RSSAI_PATH . 'includes/class-featured-image.php';
require_once RSSAI_PATH . 'includes/class-generator.php';
require_once RSSAI_PATH . 'includes/class-youtube-generator.php';
require_once RSSAI_PATH . 'includes/class-admin.php';
require_once RSSAI_PATH . 'includes/class-dashboard-page.php';
require_once RSSAI_PATH . 'includes/class-drafts.php';
require_once RSSAI_PATH . 'includes/class-groups-page.php';
require_once RSSAI_PATH . 'includes/class-review.php';
require_once RSSAI_PATH . 'includes/class-logs-page.php';

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Activator::class, 'deactivate' ) );

/**
 * Load the plugin text domain (EN default + PT translations in languages/).
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'hti-rss-ai', false, dirname( plugin_basename( RSSAI_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 0 );

/**
 * Create/upgrade tables if the plugin was updated without re-activation.
 */
add_action( 'plugins_loaded', array( Activator::class, 'maybe_upgrade' ) );

/**
 * Admin settings + feeds + drafts; the WP-Cron feed fetcher.
 */
Settings::init();
Admin::init();
Dashboard_Page::init();
Drafts::init();
Groups_Page::init();
Review::init();
Logs_Page::init();
Fetcher::init();
Featured_Image::init();
Cleanup::init();

/**
 * Warn (without breaking) when hti-engine's "news" type is missing — the
 * generated articles target that content type.
 */
function dependency_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || post_type_exists( Settings::post_type() ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'HTI RSS AI Feed: the configured target post type was not found. Pick an existing post type in RSS AI Feed → Settings.', 'hti-rss-ai' )
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\dependency_notice' );
