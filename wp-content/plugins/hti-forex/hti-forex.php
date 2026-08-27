<?php
/**
 * Plugin Name:       HTI Forex
 * Plugin URI:        https://howtoinvest.pro/
 * Description:       Free forex calculators for Indian traders (INR-native position size, pip value and an IST session clock). English-only landing section under /forex/, isolated from the main educational product.
 * Version:           0.5.0
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
const VERSION = '0.5.0';

define( 'HTI_FOREX_FILE', __FILE__ );
define( 'HTI_FOREX_PATH', plugin_dir_path( __FILE__ ) );
define( 'HTI_FOREX_URL', plugin_dir_url( __FILE__ ) );

require_once HTI_FOREX_PATH . 'includes/class-config.php';
require_once HTI_FOREX_PATH . 'includes/class-settings.php';
require_once HTI_FOREX_PATH . 'includes/class-rates.php';
require_once HTI_FOREX_PATH . 'includes/class-tools.php';
require_once HTI_FOREX_PATH . 'includes/class-schema.php';
require_once HTI_FOREX_PATH . 'includes/class-seeder.php';

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
 * JSON-LD (WebApplication INR + FAQPage + breadcrumbs) on the forex pages.
 */
Schema::init();

/**
 * Page seeder (Settings → HTI Forex button, and `wp hti-forex seed`).
 */
Seeder::init();

/**
 * Lead magnet: subscribers who opt in from a forex tool page (source
 * "forex-*") receive the INR lot-size cheat sheet after confirming the
 * double opt-in — delivered by hti-engine via the `hti_lead_magnet` filter.
 * Like the ebook, the gate is "you only learn the URL by confirming".
 */
add_filter(
	'hti_lead_magnet',
	function ( $magnet, $source, $locale ) {
		unset( $locale );
		if ( null === $magnet && str_starts_with( (string) $source, 'forex' ) ) {
			return array(
				'url'  => HTI_FOREX_URL . 'assets/pdf/hti-forex-lot-size-cheat-sheet.pdf',
				'name' => 'INR lot size cheat sheet',
			);
		}
		return $magnet;
	},
	10,
	3
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'hti-forex seed',
		function () {
			$report = Seeder::seed();
			\WP_CLI::success(
				sprintf(
					'%d forex pages created, %d updated, %d unchanged.',
					$report['created'],
					$report['updated'],
					$report['unchanged']
				)
			);
		}
	);
}

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
