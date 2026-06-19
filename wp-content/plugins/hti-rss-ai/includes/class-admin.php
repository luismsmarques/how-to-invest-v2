<?php
/**
 * Feeds admin page: list, add/edit form, delete, and "test feed" preview.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Feeds submenu and its actions.
 */
class Admin {

	public const PAGE = 'rssai-feeds';

	/**
	 * Hook menu + form handlers.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_rssai_save_feed', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_rssai_delete_feed', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Add the Feeds submenu under the RSS AI Feed menu.
	 */
	public static function menu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Feeds', 'hti-rss-ai' ),
			__( 'Feeds', 'hti-rss-ai' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Route the page by ?action.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

		if ( 'add' === $action || 'edit' === $action ) {
			self::render_form();
			return;
		}
		self::render_list();
	}

	/* --------------------------- Views --------------------------- */

	/**
	 * The feeds list (with optional notice and test preview).
	 */
	private static function render_list(): void {
		require_once RSSAI_PATH . 'includes/class-feeds-list-table.php';
		$add = add_query_arg( array( 'action' => 'add' ), admin_url( 'admin.php?page=' . self::PAGE ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Feeds', 'hti-rss-ai' ); ?></h1>
			<a href="<?php echo esc_url( $add ); ?>" class="page-title-action"><?php echo esc_html__( 'Add new', 'hti-rss-ai' ); ?></a>
			<hr class="wp-header-end" />
			<?php
			self::maybe_notice();
			self::maybe_test_preview();

			$table = new Feeds_List_Table();
			$table->prepare_items();
			$table->display();
			?>
		</div>
		<?php
	}

	/**
	 * Add/edit form.
	 */
	private static function render_form(): void {
		$id   = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$feed = $id ? Feeds::get( $id ) : null;

		$name   = $feed->name ?? '';
		$url    = $feed->url ?? '';
		$lang   = $feed->lang ?? Settings::get( 'default_lang', 'en' );
		$cat    = (int) ( $feed->default_category ?? 0 );
		$status = isset( $feed->status ) ? (int) $feed->status : 1;
		?>
		<div class="wrap">
			<h1><?php echo $id ? esc_html__( 'Edit feed', 'hti-rss-ai' ) : esc_html__( 'Add feed', 'hti-rss-ai' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rssai_save_feed" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'rssai_save_feed' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rssai_name"><?php echo esc_html__( 'Name', 'hti-rss-ai' ); ?></label></th>
						<td><input name="name" id="rssai_name" type="text" class="regular-text" required value="<?php echo esc_attr( $name ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_url"><?php echo esc_html__( 'Feed URL', 'hti-rss-ai' ); ?></label></th>
						<td><input name="url" id="rssai_url" type="url" class="large-text code" required placeholder="https://example.com/feed/" value="<?php echo esc_attr( $url ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_lang"><?php echo esc_html__( 'Language', 'hti-rss-ai' ); ?></label></th>
						<td>
							<select name="lang" id="rssai_lang">
								<option value="en"<?php selected( $lang, 'en' ); ?>>English</option>
								<option value="pt"<?php selected( $lang, 'pt' ); ?>>Português</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rssai_cat"><?php echo esc_html__( 'Default category', 'hti-rss-ai' ); ?></label></th>
						<td>
							<?php
							if ( taxonomy_exists( 'news_category' ) ) {
								wp_dropdown_categories(
									array(
										'taxonomy'         => 'news_category',
										'name'             => 'default_category',
										'id'               => 'rssai_cat',
										'selected'         => $cat,
										'show_option_none' => __( '— none —', 'hti-rss-ai' ),
										'option_none_value' => 0,
										'hide_empty'       => false,
										'hierarchical'     => true,
									)
								);
							} else {
								echo '<em>' . esc_html__( 'news_category taxonomy not available (activate HTI Engine).', 'hti-rss-ai' ) . '</em>';
							}
							?>
							<p class="description"><?php echo esc_html__( 'Suggested category for items from this feed.', 'hti-rss-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Active', 'hti-rss-ai' ); ?></th>
						<td><label><input type="checkbox" name="status" value="1" <?php checked( $status, 1 ); ?> /> <?php echo esc_html__( 'Fetch this feed', 'hti-rss-ai' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( $id ? __( 'Save feed', 'hti-rss-ai' ) : __( 'Add feed', 'hti-rss-ai' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="button-link"><?php echo esc_html__( 'Cancel', 'hti-rss-ai' ); ?></a>
			</form>
		</div>
		<?php
	}

	/* --------------------------- Handlers --------------------------- */

	/**
	 * Insert/update a feed.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		check_admin_referer( 'rssai_save_feed' );

		$id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$data = array(
			'name'             => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'url'              => isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '',
			'lang'             => isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : 'en',
			'default_category' => isset( $_POST['default_category'] ) ? absint( wp_unslash( $_POST['default_category'] ) ) : 0,
			'status'           => isset( $_POST['status'] ) ? 1 : 0,
		);

		if ( $id ) {
			Feeds::update( $id, $data );
			$notice = 'updated';
		} else {
			Feeds::insert( $data );
			$notice = 'created';
		}

		wp_safe_redirect( add_query_arg( array( 'rssai_notice' => $notice ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Delete a feed.
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( 'rssai_delete_feed_' . $id );

		if ( $id ) {
			Feeds::delete( $id );
		}
		wp_safe_redirect( add_query_arg( array( 'rssai_notice' => 'deleted' ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/* --------------------------- Helpers --------------------------- */

	/**
	 * Show a success notice after an action.
	 */
	private static function maybe_notice(): void {
		$notice = isset( $_GET['rssai_notice'] ) ? sanitize_key( wp_unslash( $_GET['rssai_notice'] ) ) : '';
		$map    = array(
			'created' => __( 'Feed added.', 'hti-rss-ai' ),
			'updated' => __( 'Feed saved.', 'hti-rss-ai' ),
			'deleted' => __( 'Feed deleted.', 'hti-rss-ai' ),
		);
		if ( isset( $map[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $notice ] ) );
		}
	}

	/**
	 * When "Test" was clicked, fetch the feed and preview a few items.
	 */
	private static function maybe_test_preview(): void {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		if ( 'test' !== $action || ! $id ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rssai_test_feed_' . $id ) ) {
			return;
		}
		$feed = Feeds::get( $id );
		if ( ! $feed ) {
			return;
		}

		include_once ABSPATH . WPINC . '/feed.php';
		$rss = fetch_feed( $feed->url );

		if ( is_wp_error( $rss ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sprintf( /* translators: %s: error message. */ __( 'Could not read the feed: %s', 'hti-rss-ai' ), $rss->get_error_message() ) )
			);
			return;
		}

		$max   = $rss->get_item_quantity( 8 );
		$items = $rss->get_items( 0, $max );
		echo '<div class="notice notice-info"><p><strong>' . esc_html( sprintf( /* translators: 1: feed name, 2: item count. */ __( 'Preview of “%1$s” — %2$d items:', 'hti-rss-ai' ), $feed->name, (int) $max ) ) . '</strong></p><ol style="margin:0 0 12px 24px">';
		foreach ( $items as $item ) {
			echo '<li>' . esc_html( (string) $item->get_title() );
			$date = $item->get_date( 'Y-m-d H:i' );
			if ( $date ) {
				echo ' <span style="color:#646970">(' . esc_html( $date ) . ')</span>';
			}
			echo '</li>';
		}
		echo '</ol></div>';
	}
}
