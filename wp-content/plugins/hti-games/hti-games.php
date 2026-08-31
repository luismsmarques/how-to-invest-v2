<?php
/**
 * Plugin Name:       HTI Games
 * Plugin URI:        https://howtoinvest.pro/games/
 * Description:       Two educational games under /games/ — "Survive the Charts" (a daily chart, buy/sell/pass, and what position size does to an account) and "The Reveal" (an anonymised dossier of a real company at a real year). Virtual money only. No brokers, no affiliate links, no prizes: the section is sealed from the monetised part of the site by design.
 * Version:           0.3.0
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            HowToInvest
 * Author URI:        https://howtoinvest.pro/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hti-games
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version, used for cache-busting enqueued assets.
 */
const VERSION = '0.3.0';

define( 'HTI_GAMES_FILE', __FILE__ );
define( 'HTI_GAMES_PATH', plugin_dir_path( __FILE__ ) );
define( 'HTI_GAMES_URL', plugin_dir_url( __FILE__ ) );

/**
 * The complete class map, in dependency order: file slug => class name.
 *
 * Written out in full from the first commit and guarded by is_readable() so
 * that the workstreams building the rest of the plugin never have to come back
 * and edit this file. A class whose file does not exist yet is simply skipped;
 * the day it lands it starts loading and booting with no change here.
 *
 * The map is explicit rather than derived from the filename because the
 * casing we want (STC_Engine, CPT, REST) is not what a mechanical
 * ucfirst-per-part would produce.
 *
 * @var array<string,string>
 */
const CLASSES = array(
	// Pure — no WordPress, unit-testable on their own, no init().
	'class-config'         => 'Config',
	'class-strings'        => 'Strings',
	'class-day'            => 'Day',
	'class-stc-engine'     => 'STC_Engine',
	'class-stc-generator'  => 'STC_Generator',
	'class-reveal-engine'  => 'Reveal_Engine',
	'class-scoring'        => 'Scoring',
	'class-importer'       => 'Importer',
	// WordPress-bound.
	'class-cpt'            => 'CPT',
	'class-store'          => 'Store',
	'class-library'        => 'Library',
	'class-scenario-admin' => 'Scenario_Admin',
	'class-case-admin'     => 'Case_Admin',
	'class-case-installer' => 'Case_Installer',
	'class-player'         => 'Player',
	'class-auth'           => 'Auth',
	'class-leaderboard'    => 'Leaderboard',
	'class-rest'           => 'REST',
	'class-privacy'        => 'Privacy',
	'class-frontend'       => 'Frontend',
	'class-installer'      => 'Installer',
	'class-seeder'         => 'Seeder',
	'class-schema'         => 'Schema',
	'class-settings'       => 'Settings',
);

foreach ( array_keys( CLASSES ) as $hti_games_slug ) {
	$hti_games_file = HTI_GAMES_PATH . 'includes/' . $hti_games_slug . '.php';
	if ( is_readable( $hti_games_file ) ) {
		require_once $hti_games_file;
	}
}
unset( $hti_games_slug, $hti_games_file );

/**
 * Boot every class that has landed and wants booting.
 *
 * A pure class has no init() and is skipped; so is one not written yet.
 */
function boot(): void {
	foreach ( CLASSES as $name ) {
		$fqcn = __NAMESPACE__ . '\\' . $name;
		if ( class_exists( $fqcn ) && method_exists( $fqcn, 'init' ) ) {
			$fqcn::init();
		}
	}
}
boot();

/**
 * Rate-limit keys for the game endpoints.
 *
 * Registered through hti-engine's filter rather than by editing its table, so
 * the section stays removable. Note that RateLimit::exceeded() returns false
 * for an unknown action — it fails OPEN — so a typo here silently disables a
 * limit. tests/test-rest-contract.php asserts every key a route uses exists.
 */
add_filter(
	'hti_rate_limits',
	function ( $limits ) {
		return array_merge(
			(array) $limits,
			array(
				'game_session'  => array( 20, 600 ),
				'game_today'    => array( 60, 600 ),
				'game_decision' => array( 10, 600 ),
				'game_board'    => array( 60, 600 ),
				'game_profile'  => array( 30, 600 ),
				'game_nick'     => array( 5, 3600 ),
				'game_link'     => array( 5, 3600 ),
				'game_forget'   => array( 5, 3600 ),
			)
		);
	}
);

/**
 * Countable events for the games.
 *
 * hti-engine drops any event name not on its allowlist, silently — so this
 * filter is what makes the funnel screen able to count a single game action.
 * The vocabulary is fixed in code and never derived from anything a visitor
 * types; the per-event detail rides in `location` (e.g. "stc_risk_200").
 */
add_filter(
	'hti_metrics_events',
	function ( $events ) {
		return array_merge(
			(array) $events,
			array(
				'game_view',
				'game_start',
				'game_decision',
				'game_result',
				'game_death',
				'game_share',
				'game_board_view',
				'game_nickname_set',
				'game_link_request',
				'game_link_confirmed',
			)
		);
	}
);

/**
 * hti-engine is a hard dependency: the games reuse its REST permission
 * callbacks, rate limiter, mailer and metrics. Say so in the admin rather than
 * fataling on a half-deployed site.
 */
add_action(
	'admin_notices',
	function (): void {
		if ( class_exists( '\\HTI\\Engine\\REST' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'HTI Games needs the HTI Engine plugin to be active — it reuses its REST permission callbacks, rate limiter and mailer.', 'hti-games' )
		);
	}
);
