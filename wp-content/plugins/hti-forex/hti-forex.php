<?php
/**
 * Plugin Name:       HTI Forex
 * Plugin URI:        https://howtoinvest.pro/
 * Description:       Free forex calculators for Indian traders (INR-native position size, pip value and an IST session clock). English-only landing section under /forex/, isolated from the main educational product.
 * Version:           0.14.2
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
const VERSION = '0.14.2';

define( 'HTI_FOREX_FILE', __FILE__ );
define( 'HTI_FOREX_PATH', plugin_dir_path( __FILE__ ) );
define( 'HTI_FOREX_URL', plugin_dir_url( __FILE__ ) );

require_once HTI_FOREX_PATH . 'includes/class-config.php';
require_once HTI_FOREX_PATH . 'includes/class-settings.php';
require_once HTI_FOREX_PATH . 'includes/class-rates.php';
require_once HTI_FOREX_PATH . 'includes/class-tools.php';
require_once HTI_FOREX_PATH . 'includes/class-schema.php';
require_once HTI_FOREX_PATH . 'includes/class-seeder.php';
require_once HTI_FOREX_PATH . 'includes/class-go.php';
require_once HTI_FOREX_PATH . 'includes/class-bot-math.php';
require_once HTI_FOREX_PATH . 'includes/class-bot-images.php';
require_once HTI_FOREX_PATH . 'includes/class-telegram.php';
require_once HTI_FOREX_PATH . 'includes/class-bot-store.php';
require_once HTI_FOREX_PATH . 'includes/class-bot.php';
require_once HTI_FOREX_PATH . 'includes/class-bot-nudge.php';
require_once HTI_FOREX_PATH . 'includes/class-bot-broadcast.php';
require_once HTI_FOREX_PATH . 'includes/class-bot-admin.php';

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
 * The tools: `[hti_forex_tool name="position_size|pip_value|profit_loss|sessions"]`.
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
 * /forex/go/{slot} — outbound partner redirector for offline material (the
 * cheat sheet PDF), so no affiliate URL is ever printed into a file.
 */
Go::init();

/**
 * The Telegram bot: send it an account balance, get back what the smallest
 * position available costs to hold and costs when it moves — in rupees. The
 * webhook is a REST route (no rewrite flush), the subscriber table is created
 * from init (the deploy runs no activation hook), and the only unprompted
 * message anyone ever gets is one an admin writes and confirms.
 */
Bot_Store::init();
Bot::init();
Bot_Nudge::init();
Bot_Broadcast::init();
Bot_Admin::init();

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

/**
 * Surface the cheat-sheet PDF in hti-engine's "Lead magnet & email readiness"
 * panel (Settings → HowToInvest): the /forex/ email offer promises this file,
 * and without the check a missing deploy would 404 the download link silently.
 */
add_filter(
	'hti_readiness_rows',
	function ( $rows ) {
		$exists = file_exists( HTI_FOREX_PATH . 'assets/pdf/hti-forex-lot-size-cheat-sheet.pdf' );
		$rows[] = array(
			$exists ? 'ok' : 'fail',
			__( 'Forex cheat-sheet PDF', 'hti-forex' ),
			$exists
				? __( 'File found in hti-forex/assets/pdf/.', 'hti-forex' )
				: __( 'Missing — the /forex/ email offer would deliver a dead download link.', 'hti-forex' ),
		);
		return $rows;
	}
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
 * a fresh install has real rates without blocking activation. The /forex/go/
 * rewrite is not flushed here: Go::add_rewrite() flushes once per plugin
 * VERSION on init, which also covers the deploy path (cPanel never reactivates
 * plugins).
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
