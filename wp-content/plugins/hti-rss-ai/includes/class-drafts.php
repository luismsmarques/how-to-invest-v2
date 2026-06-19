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
		if ( 'ignore' !== $action || empty( $_REQUEST['item'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-items' );

		$ids     = array_map( 'absint', (array) wp_unslash( $_REQUEST['item'] ) );
		$updated = Items::update_status( $ids, 'ignored' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( /* translators: %d: number of items. */ _n( '%d item ignored.', '%d items ignored.', $updated, 'hti-rss-ai' ), $updated ) )
		);
	}
}
