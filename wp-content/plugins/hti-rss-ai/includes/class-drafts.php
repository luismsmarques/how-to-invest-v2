<?php
/**
 * Drafts admin page: lists ingested items, "Fetch now", and bulk "ignore".
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Drafts submenu and its actions.
 */
class Drafts {

	public const PAGE = 'rssai-drafts';

	/**
	 * Hook menu + the manual fetch handler.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		add_action( 'admin_post_rssai_fetch_now', array( __CLASS__, 'handle_fetch_now' ) );
		add_action( 'admin_post_rssai_gen_video', array( __CLASS__, 'handle_gen_video' ) );
		add_action( 'admin_post_rssai_gen_item', array( __CLASS__, 'handle_gen_item' ) );
	}

	/**
	 * Add the Drafts submenu.
	 */
	public static function menu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Drafts', 'hti-rss-ai' ),
			__( 'Drafts', 'hti-rss-ai' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the page (process bulk first, then the list).
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require_once RSSAI_PATH . 'includes/class-items-list-table.php';

		self::maybe_fetch_notice();
		self::maybe_gen_notice();
		self::maybe_bulk();

		$table = new Items_List_Table();
		$table->prepare_items();

		$fetch_url = wp_nonce_url( admin_url( 'admin-post.php?action=rssai_fetch_now' ), 'rssai_fetch_now' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Drafts', 'hti-rss-ai' ); ?></h1>
			<a href="<?php echo esc_url( $fetch_url ); ?>" class="page-title-action"><?php echo esc_html__( 'Fetch now', 'hti-rss-ai' ); ?></a>
			<hr class="wp-header-end" />
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Generate an article from one YouTube video item (a chosen type).
	 */
	public static function handle_gen_video(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$item = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'news';
		check_admin_referer( 'rssai_gen_video_' . $item );

		$result = YouTube_Generator::generate( $item, $type );

		$args = array( 'page' => self::PAGE );
		if ( is_wp_error( $result ) ) {
			$args['rssai_gen'] = 'err';
			$args['rssai_msg'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['rssai_gen']  = 'ok';
			$args['rssai_post'] = (int) $result;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Generate an article of a chosen format from any single draft item.
	 */
	public static function handle_gen_item(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$item = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'news';
		check_admin_referer( 'rssai_gen_item_' . $item );

		$result = Generator::generate_from_item( $item, $type );

		$args = array( 'page' => self::PAGE );
		if ( is_wp_error( $result ) ) {
			$args['rssai_gen'] = 'err';
			$args['rssai_msg'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['rssai_gen']  = 'ok';
			$args['rssai_post'] = (int) $result;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Notice after a per-video generation.
	 */
	private static function maybe_gen_notice(): void {
		$gen = isset( $_GET['rssai_gen'] ) ? sanitize_key( wp_unslash( $_GET['rssai_gen'] ) ) : '';
		if ( 'ok' === $gen ) {
			$post = isset( $_GET['rssai_post'] ) ? absint( wp_unslash( $_GET['rssai_post'] ) ) : 0;
			$edit = $post ? get_edit_post_link( $post, '' ) : '';
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s %s</p></div>',
				esc_html__( 'Article generated and saved as pending review.', 'hti-rss-ai' ),
				$edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit it →', 'hti-rss-ai' ) . '</a>' : ''
			);
		} elseif ( 'err' === $gen ) {
			$msg = isset( $_GET['rssai_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['rssai_msg'] ) ) : '';
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s %s</p></div>',
				esc_html__( 'Could not generate:', 'hti-rss-ai' ),
				esc_html( $msg )
			);
		}
	}

	/**
	 * Run a fetch on demand.
	 */
	public static function handle_fetch_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		check_admin_referer( 'rssai_fetch_now' );

		$report = Fetcher::run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE,
					'rssai_fetched' => 1,
					'f'            => (int) $report['feeds'],
					'i'            => (int) $report['items'],
					'd'            => (int) $report['dupes'],
					'e'            => (int) $report['errors'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Notice after a manual fetch.
	 */
	private static function maybe_fetch_notice(): void {
		if ( empty( $_GET['rssai_fetched'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$feeds = isset( $_GET['f'] ) ? absint( wp_unslash( $_GET['f'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$new   = isset( $_GET['i'] ) ? absint( wp_unslash( $_GET['i'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dupes = isset( $_GET['d'] ) ? absint( wp_unslash( $_GET['d'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err   = isset( $_GET['e'] ) ? absint( wp_unslash( $_GET['e'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: feeds, 2: new items, 3: duplicates skipped, 4: errors. */
					__( 'Fetch complete: %1$d feeds, %2$d new items, %3$d duplicates skipped, %4$d errors.', 'hti-rss-ai' ),
					$feeds,
					$new,
					$dupes,
					$err
				)
			)
		);
	}

	/**
	 * Process the bulk "ignore" action.
	 */
	private static function maybe_bulk(): void {
		$action = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		if ( ! in_array( $action, array( 'ignore', 'group' ), true ) || empty( $_REQUEST['item'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-items' );

		$ids = array_map( 'absint', (array) wp_unslash( $_REQUEST['item'] ) );
		$ids = array_values( array_filter( $ids ) );

		if ( 'group' === $action ) {
			self::create_group_from( $ids );
			return;
		}

		$updated = Items::update_status( $ids, 'ignored' );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( /* translators: %d: number of items. */ _n( '%d item ignored.', '%d items ignored.', $updated, 'hti-rss-ai' ), $updated ) )
		);
	}

	/**
	 * Build a manual group from the selected item ids and send the editor to it.
	 *
	 * @param array<int,int> $ids Item ids.
	 */
	private static function create_group_from( array $ids ): void {
		if ( ! $ids ) {
			return;
		}
		$label = '';
		$langs = array();
		foreach ( $ids as $id ) {
			$item = Items::get( (int) $id );
			if ( ! $item ) {
				continue;
			}
			$langs[] = (string) $item->lang;
			if ( mb_strlen( (string) $item->title ) > mb_strlen( $label ) ) {
				$label = (string) $item->title;
			}
		}
		if ( '' === $label ) {
			return;
		}
		$lang = $langs ? ( array_keys( array_count_values( $langs ) )[0] ) : Settings::valid_lang( '' );

		$gid = Groups::insert(
			array(
				'label'  => $label,
				'lang'   => Settings::valid_lang( $lang ),
				'status' => 'open',
				'score'  => 1.0,
				'size'   => count( $ids ),
			)
		);
		if ( ! $gid ) {
			return;
		}
		Items::set_group( $ids, $gid );
		Logger::log( 'group', sprintf( 'Manual group %d from %d items', $gid, count( $ids ) ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => Groups_Page::PAGE,
					'action' => 'view',
					'id'     => $gid,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
