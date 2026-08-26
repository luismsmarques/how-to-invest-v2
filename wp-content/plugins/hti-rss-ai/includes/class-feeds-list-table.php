<?php
/**
 * Feeds list table (WP_List_Table) for the Feeds admin page.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the feeds as an admin list table.
 */
class Feeds_List_Table extends \WP_List_Table {

	/**
	 * Set up labels.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'feed',
				'plural'   => 'feeds',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'name'         => __( 'Name', 'hti-rss-ai' ),
			'kind'         => __( 'Type', 'hti-rss-ai' ),
			'url'          => __( 'URL', 'hti-rss-ai' ),
			'lang'         => __( 'Lang', 'hti-rss-ai' ),
			'category'     => __( 'Category', 'hti-rss-ai' ),
			'status'       => __( 'Status', 'hti-rss-ai' ),
			'last_fetched' => __( 'Last fetched', 'hti-rss-ai' ),
		);
	}

	/**
	 * Load rows (optionally filtered by language).
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$feeds                 = Feeds::all();
		$lang                  = self::current_lang();
		if ( '' !== $lang ) {
			$feeds = array_values( array_filter( $feeds, static fn( $feed ) => (string) $feed->lang === $lang ) );
		}
		$this->items = $feeds;
	}

	/**
	 * The language filter currently applied ('' = all).
	 */
	private static function current_lang(): string {
		$lang = isset( $_GET['flang'] ) ? preg_replace( '/[^a-z]/', '', strtolower( (string) wp_unslash( $_GET['flang'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $lang, Settings::languages(), true ) ? $lang : '';
	}

	/**
	 * Language tabs above the table (only when more than one language).
	 *
	 * @return array<string,string>
	 */
	protected function get_views(): array {
		$langs = Settings::languages();
		if ( count( $langs ) < 2 ) {
			return array();
		}
		$feeds   = Feeds::all();
		$current = self::current_lang();
		$base    = admin_url( 'admin.php?page=' . Admin::PAGE );

		$views = array();
		$all   = '' === $current ? 'current' : '';
		$views['all'] = sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', esc_url( $base ), esc_attr( $all ), esc_html__( 'All', 'hti-rss-ai' ), count( $feeds ) );
		foreach ( $langs as $code ) {
			$count   = count( array_filter( $feeds, static fn( $feed ) => (string) $feed->lang === $code ) );
			$class   = $current === $code ? 'current' : '';
			$url     = add_query_arg( 'flang', $code, $base );
			$views[ $code ] = sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', esc_url( $url ), esc_attr( $class ), esc_html( strtoupper( $code ) ), $count );
		}
		return $views;
	}

	/**
	 * Default column rendering.
	 *
	 * @param object $item        Feed row.
	 * @param string $column_name Column key.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'kind':
				return 'youtube' === ( $item->kind ?? 'rss' )
					? '<span style="color:#b32d2e">▶ ' . esc_html__( 'YouTube', 'hti-rss-ai' ) . '</span>'
					: '<span style="color:#646970">' . esc_html__( 'RSS', 'hti-rss-ai' ) . '</span>';
			case 'url':
				return '<span style="color:#646970">' . esc_html( $item->url ) . '</span>';
			case 'lang':
				return esc_html( strtoupper( (string) $item->lang ) );
			case 'category':
				$term = (int) $item->default_category ? get_term( (int) $item->default_category, Settings::taxonomy() ) : null;
				return $term && ! is_wp_error( $term ) ? esc_html( $term->name ) : '—';
			case 'status':
				$errors = (int) ( $item->error_count ?? 0 );
				$max    = max( 1, (int) Settings::get( 'feed_max_errors', 5 ) );
				if ( (int) $item->status ) {
					if ( $errors > 0 ) {
						return '<span style="color:#bd8600" title="' . esc_attr__( 'Failing — retried with back-off before auto-pause.', 'hti-rss-ai' ) . '">⚠ ' . esc_html( sprintf( /* translators: 1: errors, 2: max. */ __( 'Retrying (%1$d/%2$d)', 'hti-rss-ai' ), $errors, $max ) ) . '</span>';
					}
					return '<span style="color:#1a7f37">● ' . esc_html__( 'Active', 'hti-rss-ai' ) . '</span>';
				}
				if ( $errors >= $max ) {
					return '<span style="color:#b32d2e" title="' . esc_attr__( 'Auto-paused after repeated errors. Edit the feed to re-enable.', 'hti-rss-ai' ) . '">⏸ ' . esc_html__( 'Paused (errors)', 'hti-rss-ai' ) . '</span>';
				}
				return '<span style="color:#b32d2e">○ ' . esc_html__( 'Inactive', 'hti-rss-ai' ) . '</span>';
			case 'last_fetched':
				$fetched = $item->last_fetched ? esc_html( $item->last_fetched ) : '—';
				if ( ! empty( $item->last_error ) ) {
					$fetched .= '<br /><span style="color:#b32d2e;font-size:11px">' . esc_html( sprintf( /* translators: %s: datetime. */ __( 'last error: %s', 'hti-rss-ai' ), (string) $item->last_error ) ) . '</span>';
				}
				return $fetched;
			default:
				return '';
		}
	}

	/**
	 * Name column with row actions.
	 *
	 * @param object $item Feed row.
	 */
	public function column_name( $item ): string {
		$base = admin_url( 'admin.php?page=' . Admin::PAGE );
		$edit = add_query_arg(
			array(
				'action' => 'edit',
				'id'     => (int) $item->id,
			),
			$base
		);
		$test = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'test',
					'id'     => (int) $item->id,
				),
				$base
			),
			'rssai_test_feed_' . (int) $item->id
		);
		$delete = wp_nonce_url(
			admin_url( 'admin-post.php?action=rssai_delete_feed&id=' . (int) $item->id ),
			'rssai_delete_feed_' . (int) $item->id
		);

		$actions = array(
			'edit'   => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'hti-rss-ai' ) . '</a>',
			'test'   => '<a href="' . esc_url( $test ) . '">' . esc_html__( 'Test', 'hti-rss-ai' ) . '</a>',
			'delete' => '<a href="' . esc_url( $delete ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this feed?', 'hti-rss-ai' ) ) . '\')" style="color:#b32d2e">' . esc_html__( 'Delete', 'hti-rss-ai' ) . '</a>',
		);

		return '<strong><a href="' . esc_url( $edit ) . '">' . esc_html( $item->name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'No feeds yet. Add your first RSS feed.', 'hti-rss-ai' );
	}
}
