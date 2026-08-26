<?php
/**
 * The standalone generator page (admin menu → Social). Mounts the JS editor
 * with no prefill so any template can be picked and customised from scratch.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu + page.
 */
class Admin {

	/**
	 * Hook the menu.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Register the top-level menu page.
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'Social Generator', 'hti-social' ),
			__( 'Social', 'hti-social' ),
			'edit_posts',
			'hti-social',
			array( __CLASS__, 'render' ),
			'dashicons-format-image',
			58
		);
	}

	/**
	 * Render the mount point; social.js builds the editor.
	 */
	public static function render(): void {
		echo '<div class="wrap hti-social-wrap">';
		echo '<h1>' . esc_html__( 'Social content generator', 'hti-social' ) . '</h1>';
		echo '<p class="hti-social-intro">' . esc_html__( 'Pick a template, edit the text, drop in a photo, and export a ready-to-post PNG. Disclaimers and asset-class language are built in.', 'hti-social' ) . '</p>';
		echo '<div id="hti-social-app" data-mode="full"></div>';
		echo '</div>';
	}
}
