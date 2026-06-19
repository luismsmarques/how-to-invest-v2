<?php
/**
 * HowToInvest child theme functions.
 *
 * Keeps the theme thin: design tokens live in theme.json, the interactive
 * questionnaire/result are provided by the hti-engine plugin. This file only
 * wires up enqueuing, i18n, theme supports and our block-pattern category.
 *
 * @package HowToInvest
 */

namespace HowToInvest\Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Theme version, used for cache-busting enqueued assets.
 */
const VERSION = '0.3.0';

/**
 * Load the theme text domain (EN default + PT translations in languages/).
 *
 * Translations ship as a performant PHP file (howtoinvest-pt_PT.l10n.php),
 * loaded automatically by WordPress 6.5+.
 */
function load_textdomain(): void {
	load_theme_textdomain( 'howtoinvest', get_stylesheet_directory() . '/languages' );
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\load_textdomain' );

/**
 * Declare theme supports not already inherited from the parent block theme.
 */
function theme_supports(): void {
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\theme_supports' );

/**
 * Enqueue the child theme stylesheet after the parent's styles.
 */
function enqueue_styles(): void {
	wp_enqueue_style(
		'howtoinvest-style',
		get_stylesheet_uri(),
		array(),
		VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_styles' );

/**
 * Enqueue the small mobile-header menu toggle (deferred, footer).
 */
function enqueue_scripts(): void {
	wp_enqueue_script(
		'howtoinvest-header',
		get_stylesheet_directory_uri() . '/assets/js/header.js',
		array(),
		VERSION,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_scripts' );

/**
 * Register a dedicated block-pattern category for our reusable patterns.
 */
function register_pattern_category(): void {
	register_block_pattern_category(
		'howtoinvest',
		array( 'label' => __( 'HowToInvest', 'howtoinvest' ) )
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_pattern_category' );

/**
 * Register classic nav-menu locations.
 *
 * Registering locations also restores the Appearance → Menus screen under a
 * block theme, so the header/footer menus can be edited there (and assigned
 * per language by Polylang). The header and footer template parts render these
 * via the howtoinvest/menu block below.
 */
function register_menus(): void {
	register_nav_menus(
		array(
			'primary' => __( 'Header menu', 'howtoinvest' ),
			'footer'  => __( 'Footer menu', 'howtoinvest' ),
		)
	);
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\register_menus' );

/**
 * Ensure Appearance → Menus is reachable under a block theme.
 *
 * Block themes hide the classic Menus screen by default; registering menu
 * locations normally restores it, but we add the submenu link defensively
 * (without duplicating it if core already provides it).
 */
function ensure_menus_admin_page(): void {
	if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
		return;
	}

	global $submenu;
	if ( isset( $submenu['themes.php'] ) ) {
		foreach ( $submenu['themes.php'] as $item ) {
			if ( isset( $item[2] ) && 'nav-menus.php' === $item[2] ) {
				return;
			}
		}
	}

	add_submenu_page(
		'themes.php',
		__( 'Menus', 'howtoinvest' ),
		__( 'Menus', 'howtoinvest' ),
		'edit_theme_options',
		'nav-menus.php'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\ensure_menus_admin_page', 100 );

/**
 * A small server-rendered block that prints a classic nav menu by location,
 * so the FSE header/footer can show menus managed in Appearance → Menus.
 * Falls back to sensible default links until a menu is assigned to the location.
 */
function register_menu_block(): void {
	register_block_type(
		'howtoinvest/menu',
		array(
			'api_version'     => 3,
			'title'           => __( 'HowToInvest menu', 'howtoinvest' ),
			'category'        => 'theme',
			'attributes'      => array(
				'location'  => array(
					'type'    => 'string',
					'default' => 'primary',
				),
				'className' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'render_callback' => __NAMESPACE__ . '\\render_menu_block',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_menu_block' );

/**
 * Render the howtoinvest/menu block: a classic menu for the given location,
 * or default links when none is assigned yet.
 *
 * @param array<string,mixed> $attributes Block attributes (location, className).
 * @return string Safe HTML.
 */
function render_menu_block( array $attributes ): string {
	$location = isset( $attributes['location'] ) ? sanitize_key( (string) $attributes['location'] ) : 'primary';
	$extra    = isset( $attributes['className'] ) ? sanitize_html_class( (string) $attributes['className'] ) : '';

	if ( has_nav_menu( $location ) ) {
		$menu = wp_nav_menu(
			array(
				'theme_location'  => $location,
				'container'       => 'nav',
				'container_class' => trim( 'hti-menu-nav ' . $extra ),
				'menu_class'      => 'hti-menu',
				'depth'           => 1,
				'fallback_cb'     => false,
				'echo'            => false,
			)
		);
		if ( is_string( $menu ) && '' !== $menu ) {
			return $menu;
		}
	}

	return default_menu( $location, $extra );
}

/**
 * Default header/footer links, shown until a menu is assigned to the location.
 *
 * @param string $location Menu location slug.
 * @param string $extra    Extra container class.
 * @return string Safe HTML.
 */
function default_menu( string $location, string $extra ): string {
	if ( 'footer' === $location ) {
		$items = array(
			'/privacy-policy/'       => __( 'Privacy', 'howtoinvest' ),
			'/terms-and-conditions/' => __( 'Terms', 'howtoinvest' ),
			'/contact/'              => __( 'Contact', 'howtoinvest' ),
		);
	} else {
		$items = array(
			'/how-to-start-investing/' => __( 'Learn', 'howtoinvest' ),
			'/investing-glossary/'     => __( 'Glossary', 'howtoinvest' ),
			'/financial-news/'         => __( 'News', 'howtoinvest' ),
		);
	}

	$list = '';
	foreach ( $items as $path => $label ) {
		$list .= '<li class="menu-item"><a href="' . esc_url( home_url( $path ) ) . '">' . esc_html( $label ) . '</a></li>';
	}

	return '<nav class="' . esc_attr( trim( 'hti-menu-nav ' . $extra ) ) . '"><ul class="hti-menu">' . $list . '</ul></nav>';
}
