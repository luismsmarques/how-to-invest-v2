<?php
/**
 * Drafts (items) list table.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists ingested draft items with filters + a bulk "ignore" action.
 */
class Items_List_Table extends \WP_List_Table {

	/**
	 * Cached feed id => name map.
	 *
	 * @var array<int,string>
	 */
	private array $feed_names = array();

	/**
	 * Labels.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'item',
				'plural'   => 'items',
				'ajax'     => false,
			)
		);
		foreach ( Feeds::all() as $feed ) {
			$this->feed_names[ (int) $feed->id ] = $feed->name;
		}
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'image'        => __( 'Image', 'hti-rss-ai' ),
			'title'        => __( 'Title', 'hti-rss-ai' ),
			'feed'         => __( 'Feed', 'hti-rss-ai' ),
			'lang'         => __( 'Lang', 'hti-rss-ai' ),
			'status'       => __( 'Status', 'hti-rss-ai' ),
			'published_at' => __( 'Published', 'hti-rss-ai' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	public function get_bulk_actions(): array {
		return array( 'ignore' => __( 'Ignore', 'hti-rss-ai' ) );
	}

	/**
	 * Current filters from the request.
	 *
	 * @return array<string,mixed>
	 */
	private function filters(): array {
		return array(
			'feed_id' => isset( $_GET['feed_id'] ) ? absint( wp_unslash( $_GET['feed_id'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'  => isset( $_GET['fstatus'] ) ? sanitize_key( wp_unslash( $_GET['fstatus'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Load rows + pagination.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$per_page = 20;
		$paged    = $this->get_pagenum();
		$filters  = $this->filters();

		$total       = Items::count( $filters );
		$this->items = Items::query(
			array_merge(
				$filters,
				array(
					'per_page' => $per_page,
					'offset'   => ( $paged - 1 ) * $per_page,
				)
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Item row.
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="item[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Default columns.
	 *
	 * @param object $item        Item row.
	 * @param string $column_name Column key.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'image':
				return $item->image_url
					? '<img src="' . esc_url( $item->image_url ) . '" alt="" style="width:64px;height:42px;object-fit:cover;border-radius:4px" loading="lazy" />'
					: '<span style="color:#a7aaad">—</span>';
			case 'title':
				$title = '<strong>' . esc_html( $item->title ) . '</strong>';
				if ( $item->link ) {
					$title .= ' <a href="' . esc_url( $item->link ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__( 'Open source', 'hti-rss-ai' ) . '">↗</a>';
				}
				if ( $item->source ) {
					$title .= '<div style="color:#646970;font-size:12px">' . esc_html( $item->source ) . '</div>';
				}
				return $title;
			case 'feed':
				return esc_html( $this->feed_names[ (int) $item->feed_id ] ?? ( '#' . (int) $item->feed_id ) );
			case 'lang':
				return esc_html( strtoupper( (string) $item->lang ) );
			case 'status':
				return esc_html( (string) $item->status );
			case 'published_at':
				return esc_html( (string) $item->published_at );
			default:
				return '';
		}
	}

	/**
	 * Feed + status filters above the table.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = $this->filters();
		echo '<div class="alignleft actions">';

		echo '<select name="feed_id"><option value="0">' . esc_html__( 'All feeds', 'hti-rss-ai' ) . '</option>';
		foreach ( $this->feed_names as $id => $name ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $id, selected( $filters['feed_id'], $id, false ), esc_html( $name ) );
		}
		echo '</select> ';

		echo '<select name="fstatus"><option value="">' . esc_html__( 'Any status', 'hti-rss-ai' ) . '</option>';
		foreach ( array( 'new', 'grouped', 'used', 'ignored' ) as $status ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $status ), selected( $filters['status'], $status, false ) );
		}
		echo '</select> ';

		submit_button( __( 'Filter', 'hti-rss-ai' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'No drafts yet. Add feeds and run a fetch.', 'hti-rss-ai' );
	}
}
