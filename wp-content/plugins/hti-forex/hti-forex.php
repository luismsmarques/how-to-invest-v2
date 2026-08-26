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
require_once HTI_FOREX_PATH . 'includes/class-rates.php';
require_once HTI_FOREX_PATH . 'includes/class-tools.php';

/**
 * Admin settings (Settings → HTI Forex): affiliate CTA kill-switch, email
 * capture toggle, subid passthrough, manual rate overrides.
 */
Settings::init();

/**
 * USD→INR/JPY reference rates: twice-daily cron fetch + admin panel.
 */
Rates::init();

/**
 * The tools: `[hti_forex_tool name="position_size|pip_value|sessions"]`.
 */
Tools::init();

/**
 * Activation: schedule the rates cron and queue an immediate first fetch so
 * a fresh install has real rates without blocking activation. No CPTs or
 * rewrite rules are registered, so there is nothing to flush.
 */
function activate(): void {
	Rates::schedule();
	wp_schedule_single_event( time() + 30, Rates::HOOK );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation: clear the rates cron.
 */
function deactivate(): void {
	Rates::unschedule();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
