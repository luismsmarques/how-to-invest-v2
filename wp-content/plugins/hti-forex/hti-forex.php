<?php
/**
 * Plugin Name:       HTI Forex
 * Plugin URI:        https://howtoinvest.pro/
 * Description:       Free forex calculators for Indian traders (INR-native position size, pip value and an IST session clock). English-only landing section under /forex/, isolated from the main educational product.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            HowToInvest
 * Author URI:        https://howtoinvest.pro/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hti-forex
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version, used for cache-busting enqueued assets.
 */
const VERSION = '0.1.0';

define( 'HTI_FOREX_FILE', __FILE__ );
define( 'HTI_FOREX_PATH', plugin_dir_path( __FILE__ ) );
define( 'HTI_FOREX_URL', plugin_dir_url( __FILE__ ) );

require_once HTI_FOREX_PATH . 'includes/class-config.php';
require_once HTI_FOREX_PATH . 'includes/class-settings.php';

/**
 * Admin settings (Settings → HTI Forex): affiliate CTA kill-switch, email
 * capture toggle, subid passthrough, manual rate overrides.
 */
Settings::init();

/**
 * Activation / deactivation. The plugin registers no CPTs or rewrite rules,
 * so there is nothing to flush; cron scheduling is added by the Rates layer.
 */
function activate(): void {
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation counterpart.
 */
function deactivate(): void {
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
