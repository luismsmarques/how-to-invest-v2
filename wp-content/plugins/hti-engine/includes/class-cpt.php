<?php
/**
 * Custom post type registration.
 *
 * Phase 1 (SEO foundation) registers the public, indexable content types
 * `glossary` and `news`. The private engine CPT `htinvest_profile` is added
 * here in Phase 2 (see docs/Modelo_Dados_API §2).
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's custom post types.
 */
class CPT {

	/**
	 * Register all custom post types. Hooked on `init`.
	 */
	public static function register(): void {
		self::register_glossary();
		self::register_news();
		self::register_learn();
		self::register_profile();
	}

	/**
	 * Learn — public, indexable educational articles (the SEO pillar/cluster
	 * content). A dedicated type with its own /learn/ base and topic taxonomy,
	 * kept separate from the WordPress blog and from time-sensitive `news`.
	 */
	private static function register_learn(): void {
		$labels = array(
			'name'               => _x( 'Learn', 'post type general name', 'hti-engine' ),
			'singular_name'      => _x( 'Article', 'post type singular name', 'hti-engine' ),
			'menu_name'          => _x( 'Learn', 'admin menu', 'hti-engine' ),
			'name_admin_bar'     => _x( 'Article', 'add new on admin bar', 'hti-engine' ),
			'add_new'            => __( 'Add new', 'hti-engine' ),
			'add_new_item'       => __( 'Add new article', 'hti-engine' ),
			'new_item'           => __( 'New article', 'hti-engine' ),
			'edit_item'          => __( 'Edit article', 'hti-engine' ),
			'view_item'          => __( 'View article', 'hti-engine' ),
			'view_items'         => __( 'View Learn', 'hti-engine' ),
			'all_items'          => __( 'All articles', 'hti-engine' ),
			'search_items'       => __( 'Search Learn', 'hti-engine' ),
			'not_found'          => __( 'No articles found.', 'hti-engine' ),
			'not_found_in_trash' => __( 'No articles found in Trash.', 'hti-engine' ),
			'archives'           => __( 'Learn', 'hti-engine' ),
			'item_published'     => __( 'Article published.', 'hti-engine' ),
			'item_updated'       => __( 'Article updated.', 'hti-engine' ),
		);

		register_post_type(
			'learn',
			array(
				'labels'              => $labels,
				'description'         => __( 'Educational articles (the Learn hub).', 'hti-engine' ),
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => true,
				'show_in_rest'        => true,
				'exclude_from_search' => false,
				'has_archive'         => 'learn',
				'hierarchical'        => false,
				'menu_position'       => 20,
				'menu_icon'           => 'dashicons-welcome-learn-more',
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
				'rewrite'             => array(
					'slug'       => 'learn',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Investor profile — PRIVATE. Stores anonymous/account questionnaire
	 * submissions and their saved result. Never public, never indexable, never
	 * exposed via the default REST API (we use our own controlled endpoints).
	 * See docs/Modelo_Dados §2.
	 */
	private static function register_profile(): void {
		register_post_type(
			'htinvest_profile',
			array(
				'label'               => __( 'Investor profiles', 'hti-engine' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'supports'            => array( 'title', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Glossary term — public, indexable. Seeds SEO/internal linking
	 * (e.g. the per-asset-class notes become "What is a bond?" entries).
	 */
	private static function register_glossary(): void {
		$labels = array(
			'name'                  => _x( 'Glossary', 'post type general name', 'hti-engine' ),
			'singular_name'         => _x( 'Glossary term', 'post type singular name', 'hti-engine' ),
			'menu_name'             => _x( 'Glossary', 'admin menu', 'hti-engine' ),
			'name_admin_bar'        => _x( 'Glossary term', 'add new on admin bar', 'hti-engine' ),
			'add_new'               => __( 'Add new', 'hti-engine' ),
			'add_new_item'          => __( 'Add new term', 'hti-engine' ),
			'new_item'              => __( 'New term', 'hti-engine' ),
			'edit_item'             => __( 'Edit term', 'hti-engine' ),
			'view_item'             => __( 'View term', 'hti-engine' ),
			'view_items'            => __( 'View glossary', 'hti-engine' ),
			'all_items'             => __( 'All terms', 'hti-engine' ),
			'search_items'          => __( 'Search glossary', 'hti-engine' ),
			'not_found'             => __( 'No terms found.', 'hti-engine' ),
			'not_found_in_trash'    => __( 'No terms found in Trash.', 'hti-engine' ),
			'archives'              => __( 'Glossary', 'hti-engine' ),
			'item_published'        => __( 'Term published.', 'hti-engine' ),
			'item_updated'          => __( 'Term updated.', 'hti-engine' ),
		);

		register_post_type(
			'glossary',
			array(
				'labels'             => $labels,
				'description'        => __( 'Educational glossary of investing terms.', 'hti-engine' ),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => true,
				'show_in_rest'       => true,
				'exclude_from_search' => false,
				'has_archive'        => true,
				'hierarchical'       => false,
				'menu_position'      => 21,
				'menu_icon'          => 'dashicons-book-alt',
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
				'rewrite'            => array(
					'slug'       => 'investing-glossary',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * News article — public, indexable. Editorial content for organic growth.
	 */
	private static function register_news(): void {
		$labels = array(
			'name'                  => _x( 'News', 'post type general name', 'hti-engine' ),
			'singular_name'         => _x( 'News article', 'post type singular name', 'hti-engine' ),
			'menu_name'             => _x( 'News', 'admin menu', 'hti-engine' ),
			'name_admin_bar'        => _x( 'News article', 'add new on admin bar', 'hti-engine' ),
			'add_new'               => __( 'Add new', 'hti-engine' ),
			'add_new_item'          => __( 'Add new article', 'hti-engine' ),
			'new_item'              => __( 'New article', 'hti-engine' ),
			'edit_item'             => __( 'Edit article', 'hti-engine' ),
			'view_item'             => __( 'View article', 'hti-engine' ),
			'view_items'            => __( 'View news', 'hti-engine' ),
			'all_items'             => __( 'All articles', 'hti-engine' ),
			'search_items'          => __( 'Search news', 'hti-engine' ),
			'not_found'             => __( 'No articles found.', 'hti-engine' ),
			'not_found_in_trash'    => __( 'No articles found in Trash.', 'hti-engine' ),
			'archives'              => __( 'News', 'hti-engine' ),
			'item_published'        => __( 'Article published.', 'hti-engine' ),
			'item_updated'          => __( 'Article updated.', 'hti-engine' ),
		);

		register_post_type(
			'news',
			array(
				'labels'             => $labels,
				'description'        => __( 'Educational news and updates.', 'hti-engine' ),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => true,
				'show_in_rest'       => true,
				'exclude_from_search' => false,
				'has_archive'        => true,
				'hierarchical'       => false,
				'menu_position'      => 22,
				'menu_icon'          => 'dashicons-megaphone',
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
				'rewrite'            => array(
					'slug'       => 'financial-news',
					'with_front' => false,
				),
			)
		);
	}
}
