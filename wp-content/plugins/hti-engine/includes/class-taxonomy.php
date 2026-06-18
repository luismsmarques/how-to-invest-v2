<?php
/**
 * Taxonomy registration for the public content types.
 *
 * Provides the grouping that powers internal linking (a Phase 1 SEO goal):
 * - `glossary_topic` groups glossary terms (e.g. "Asset classes").
 * - `news_category` categorises news articles.
 *
 * Both are hierarchical, public and REST-enabled so their term archives can
 * be linked from content and surfaced in menus.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's custom taxonomies.
 */
class Taxonomy {

	/**
	 * Register all taxonomies. Hooked on `init` (before the CPTs).
	 */
	public static function register(): void {
		self::register_glossary_topic();
		self::register_news_category();
	}

	/**
	 * Glossary topic — hierarchical grouping for glossary terms.
	 */
	private static function register_glossary_topic(): void {
		$labels = array(
			'name'              => _x( 'Topics', 'taxonomy general name', 'hti-engine' ),
			'singular_name'     => _x( 'Topic', 'taxonomy singular name', 'hti-engine' ),
			'menu_name'         => __( 'Topics', 'hti-engine' ),
			'all_items'         => __( 'All topics', 'hti-engine' ),
			'edit_item'         => __( 'Edit topic', 'hti-engine' ),
			'view_item'         => __( 'View topic', 'hti-engine' ),
			'update_item'       => __( 'Update topic', 'hti-engine' ),
			'add_new_item'      => __( 'Add new topic', 'hti-engine' ),
			'new_item_name'     => __( 'New topic name', 'hti-engine' ),
			'parent_item'       => __( 'Parent topic', 'hti-engine' ),
			'parent_item_colon' => __( 'Parent topic:', 'hti-engine' ),
			'search_items'      => __( 'Search topics', 'hti-engine' ),
			'not_found'         => __( 'No topics found.', 'hti-engine' ),
			'back_to_items'     => __( '← Back to topics', 'hti-engine' ),
		);

		register_taxonomy(
			'glossary_topic',
			array( 'glossary' ),
			array(
				'labels'            => $labels,
				'description'       => __( 'Topics that group glossary terms.', 'hti-engine' ),
				'public'            => true,
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_nav_menus' => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'         => 'glossary-topic',
					'with_front'   => false,
					'hierarchical' => true,
				),
			)
		);
	}

	/**
	 * News category — hierarchical categorisation for news articles.
	 */
	private static function register_news_category(): void {
		$labels = array(
			'name'              => _x( 'News categories', 'taxonomy general name', 'hti-engine' ),
			'singular_name'     => _x( 'News category', 'taxonomy singular name', 'hti-engine' ),
			'menu_name'         => __( 'Categories', 'hti-engine' ),
			'all_items'         => __( 'All categories', 'hti-engine' ),
			'edit_item'         => __( 'Edit category', 'hti-engine' ),
			'view_item'         => __( 'View category', 'hti-engine' ),
			'update_item'       => __( 'Update category', 'hti-engine' ),
			'add_new_item'      => __( 'Add new category', 'hti-engine' ),
			'new_item_name'     => __( 'New category name', 'hti-engine' ),
			'parent_item'       => __( 'Parent category', 'hti-engine' ),
			'parent_item_colon' => __( 'Parent category:', 'hti-engine' ),
			'search_items'      => __( 'Search categories', 'hti-engine' ),
			'not_found'         => __( 'No categories found.', 'hti-engine' ),
			'back_to_items'     => __( '← Back to categories', 'hti-engine' ),
		);

		register_taxonomy(
			'news_category',
			array( 'news' ),
			array(
				'labels'            => $labels,
				'description'       => __( 'Categories for news articles.', 'hti-engine' ),
				'public'            => true,
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_nav_menus' => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'         => 'news-category',
					'with_front'   => false,
					'hierarchical' => true,
				),
			)
		);
	}
}
