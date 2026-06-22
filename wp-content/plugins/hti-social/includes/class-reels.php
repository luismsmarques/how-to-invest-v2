<?php
/**
 * Reels generator: a submenu under "Social" where you upload a video, type a
 * title + caption, pick a branded overlay, and render a vertical 1080×1920
 * clip in the browser (Canvas + MediaRecorder → WebM). No server, no FFmpeg.
 *
 * Educational use: the disclaimer overlay is on by default and the user is
 * responsible for holding rights to the source footage.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Reels admin page + assets.
 */
class Reels {

	/**
	 * Stored hook suffix of the submenu page (for precise asset loading).
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hook the menu + assets.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Register the Reels submenu under the Social menu.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'hti-social',
			__( 'Social Reels', 'hti-social' ),
			__( 'Reels', 'hti-social' ),
			'edit_posts',
			'hti-social-reels',
			array( __CLASS__, 'render' )
		);
		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Enqueue only on the Reels page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue( string $hook ): void {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 'hti-social', HTI_SOCIAL_URL . 'assets/css/social.css', array(), VERSION );
		wp_enqueue_style( 'hti-social-reels', HTI_SOCIAL_URL . 'assets/css/reels.css', array( 'hti-social' ), VERSION );
		wp_add_inline_style( 'hti-social', self::font_face_css() );

		wp_enqueue_script( 'hti-social-reel-templates', HTI_SOCIAL_URL . 'assets/js/reel-templates.js', array(), VERSION, array( 'in_footer' => true ) );
		wp_enqueue_script( 'hti-social-reels', HTI_SOCIAL_URL . 'assets/js/reels.js', array( 'hti-social-reel-templates' ), VERSION, array( 'in_footer' => true ) );

		wp_localize_script( 'hti-social-reels', 'HTI_SOCIAL', Assets::boot_data() );
	}

	/**
	 * @font-face for the on-screen preview (same as the card editor).
	 */
	private static function font_face_css(): string {
		$css = '';
		foreach ( Brand::font_faces() as $f ) {
			$css .= sprintf(
				"@font-face{font-family:'%s';font-style:normal;font-weight:%d;font-display:swap;src:url('%s') format('woff2');}",
				$f['family'],
				$f['weight'],
				esc_url( $f['url'] )
			);
		}
		return $css;
	}

	/**
	 * Render the mount point; reels.js builds the editor.
	 */
	public static function render(): void {
		echo '<div class="wrap hti-social-wrap">';
		echo '<h1>' . esc_html__( 'Social reels', 'hti-social' ) . '</h1>';
		echo '<p class="hti-social-intro">' . esc_html__( 'Upload a video, add a title and caption, pick a branded overlay, and render a vertical 1080×1920 reel — all in your browser. Use only footage you own or are licensed to use; the educational disclaimer stays on by default.', 'hti-social' ) . '</p>';
		echo '<div id="hti-reels-app"></div>';
		echo '</div>';
	}
}
