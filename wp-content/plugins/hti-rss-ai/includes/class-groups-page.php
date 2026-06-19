<?php
/**
 * Groups admin page: list clusters, run grouping, view a group, dismiss it.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Groups submenu and its actions.
 */
class Groups_Page {

	public const PAGE = 'rssai-groups';

	/**
	 * Hook menu + action handlers.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 13 );
		add_action( 'admin_post_rssai_group_now', array( __CLASS__, 'handle_group_now' ) );
		add_action( 'admin_post_rssai_dismiss_group', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_post_rssai_generate', array( __CLASS__, 'handle_generate' ) );
	}

	/**
	 * Add the Groups submenu.
	 */
	public static function menu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Groups', 'hti-rss-ai' ),
			__( 'Groups', 'hti-rss-ai' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Route by ?action.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'view' === $action ) {
			self::render_detail();
			return;
		}
		self::render_list();
	}

	/**
	 * List open groups.
	 */
	private static function render_list(): void {
		self::maybe_notice();
		$groups   = Groups::all( 'open' );
		$group_now = wp_nonce_url( admin_url( 'admin-post.php?action=rssai_group_now' ), 'rssai_group_now' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Groups', 'hti-rss-ai' ); ?></h1>
			<a href="<?php echo esc_url( $group_now ); ?>" class="page-title-action"><?php echo esc_html__( 'Group now', 'hti-rss-ai' ); ?></a>
			<hr class="wp-header-end" />
			<p class="description"><?php echo esc_html__( 'Clusters of related drafts. Pick one to generate an article (next milestone).', 'hti-rss-ai' ); ?></p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Topic', 'hti-rss-ai' ); ?></th>
						<th style="width:80px"><?php echo esc_html__( 'Items', 'hti-rss-ai' ); ?></th>
						<th style="width:70px"><?php echo esc_html__( 'Lang', 'hti-rss-ai' ); ?></th>
						<th style="width:90px"><?php echo esc_html__( 'Score', 'hti-rss-ai' ); ?></th>
						<th style="width:160px"><?php echo esc_html__( 'Actions', 'hti-rss-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $groups ) : ?>
						<tr><td colspan="5"><?php echo esc_html__( 'No groups yet. Fetch drafts, then “Group now”.', 'hti-rss-ai' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $groups as $group ) : ?>
						<?php
						$view    = add_query_arg(
							array(
								'page'   => self::PAGE,
								'action' => 'view',
								'id'     => (int) $group->id,
							),
							admin_url( 'admin.php' )
						);
						$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=rssai_dismiss_group&id=' . (int) $group->id ), 'rssai_dismiss_group_' . (int) $group->id );
						?>
						<tr>
							<td><strong><a href="<?php echo esc_url( $view ); ?>"><?php echo esc_html( $group->label ); ?></a></strong></td>
							<td><?php echo (int) $group->size; ?></td>
							<td><?php echo esc_html( strtoupper( (string) $group->lang ) ); ?></td>
							<td><?php echo esc_html( number_format( (float) $group->score, 2 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $view ); ?>"><?php echo esc_html__( 'View', 'hti-rss-ai' ); ?></a> |
								<a href="<?php echo esc_url( $dismiss ); ?>" style="color:#b32d2e" onclick="return confirm('<?php echo esc_js( __( 'Dismiss this group and ignore its items?', 'hti-rss-ai' ) ); ?>')"><?php echo esc_html__( 'Dismiss', 'hti-rss-ai' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Show one group and its items.
	 */
	private static function render_detail(): void {
		$id    = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$group = $id ? Groups::get( $id ) : null;
		if ( ! $group ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Group not found.', 'hti-rss-ai' ) . '</p></div>';
			return;
		}
		$items = Groups::items( $id );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $group->label ); ?></h1>
			<?php self::maybe_gen_notice(); ?>
			<?php self::render_generate_box( $group ); ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">&larr; <?php echo esc_html__( 'Back to groups', 'hti-rss-ai' ); ?></a>
				&nbsp;·&nbsp;
				<?php echo esc_html( sprintf( /* translators: 1: count, 2: language. */ __( '%1$d items · %2$s', 'hti-rss-ai' ), count( $items ), strtoupper( (string) $group->lang ) ) ); ?>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<tr>
							<td style="width:90px">
								<?php if ( $item->image_url ) : ?>
									<img src="<?php echo esc_url( $item->image_url ); ?>" alt="" style="width:72px;height:48px;object-fit:cover;border-radius:4px" loading="lazy" />
								<?php endif; ?>
							</td>
							<td>
								<strong><?php echo esc_html( $item->title ); ?></strong>
								<?php if ( $item->link ) : ?>
									<a href="<?php echo esc_url( $item->link ); ?>" target="_blank" rel="noopener noreferrer">↗</a>
								<?php endif; ?>
								<div style="color:#646970;font-size:12px"><?php echo esc_html( $item->source ); ?> · <?php echo esc_html( (string) $item->published_at ); ?></div>
								<?php if ( $item->description ) : ?>
									<div style="color:#50575e;margin-top:4px"><?php echo esc_html( wp_trim_words( $item->description, 40 ) ); ?></div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Generate button (open group) or a link to the created post.
	 *
	 * @param object $group Group row.
	 */
	private static function render_generate_box( object $group ): void {
		echo '<div class="notice notice-info inline" style="padding:12px 14px;margin:12px 0">';
		if ( 'generated' === $group->status ) {
			$posts = get_posts(
				array(
					'post_type'        => 'news',
					'post_status'      => 'any',
					'meta_key'         => 'rssai_group_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => (int) $group->id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'numberposts'      => 1,
					'suppress_filters' => true,
				)
			);
			if ( $posts ) {
				$edit = get_edit_post_link( $posts[0]->ID, 'raw' );
				printf(
					'%s <a class="button button-primary" href="%s">%s</a>',
					esc_html__( 'An article was generated for this group.', 'hti-rss-ai' ),
					esc_url( (string) $edit ),
					esc_html__( 'Open the pending article', 'hti-rss-ai' )
				);
			} else {
				echo esc_html__( 'This group was marked generated.', 'hti-rss-ai' );
			}
		} elseif ( 'open' === $group->status ) {
			if ( ! Gemini_Client::available() ) {
				echo esc_html__( 'Set the Gemini API key to enable generation.', 'hti-rss-ai' );
			} else {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="rssai_generate" />';
				echo '<input type="hidden" name="id" value="' . (int) $group->id . '" />';
				wp_nonce_field( 'rssai_generate_' . (int) $group->id );
				submit_button( __( 'Generate article', 'hti-rss-ai' ), 'primary', 'submit', false );
				echo ' <span class="description">' . esc_html__( 'Researches the topic with Gemini and creates a pending news article for review.', 'hti-rss-ai' ) . '</span>';
				echo '</form>';
			}
		}
		echo '</div>';
	}

	/**
	 * Run clustering on demand.
	 */
	public static function handle_group_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		check_admin_referer( 'rssai_group_now' );

		$report = Grouping::run();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE,
					'rssai_grouped' => 1,
					'g'           => (int) $report['groups'],
					'i'           => (int) $report['items'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Dismiss a group.
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( 'rssai_dismiss_group_' . $id );

		if ( $id ) {
			Groups::dismiss( $id );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'rssai_dismissed' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Run article generation for a group.
	 */
	public static function handle_generate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		check_admin_referer( 'rssai_generate_' . $id );

		$result = Generator::generate( $id );
		$key    = 'rssai_gen_msg_' . get_current_user_id();
		if ( is_wp_error( $result ) ) {
			set_transient(
				$key,
				array(
					'type' => 'error',
					'msg'  => $result->get_error_message(),
				),
				60
			);
		} else {
			set_transient(
				$key,
				array(
					'type' => 'success',
					'msg'  => __( 'Article generated and saved as pending review.', 'hti-rss-ai' ),
					'edit' => (string) get_edit_post_link( (int) $result, 'raw' ),
				),
				60
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'action' => 'view',
					'id'     => $id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Show the generation result notice (from a transient).
	 */
	private static function maybe_gen_notice(): void {
		$key  = 'rssai_gen_msg_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( $key );
		$class = 'error' === ( $data['type'] ?? '' ) ? 'notice-error' : 'notice-success';
		$extra = '';
		if ( ! empty( $data['edit'] ) ) {
			$extra = ' <a href="' . esc_url( $data['edit'] ) . '">' . esc_html__( 'Edit it →', 'hti-rss-ai' ) . '</a>';
		}
		printf( '<div class="notice %1$s is-dismissible"><p>%2$s%3$s</p></div>', esc_attr( $class ), esc_html( (string) ( $data['msg'] ?? '' ) ), wp_kses_post( $extra ) );
	}

	/**
	 * Notices after grouping/dismiss.
	 */
	private static function maybe_notice(): void {
		if ( ! empty( $_GET['rssai_grouped'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$groups = isset( $_GET['g'] ) ? absint( wp_unslash( $_GET['g'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$items  = isset( $_GET['i'] ) ? absint( wp_unslash( $_GET['i'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( /* translators: 1: groups, 2: items. */ __( 'Grouping complete: %1$d groups from %2$d items.', 'hti-rss-ai' ), $groups, $items ) )
			);
		}
		if ( ! empty( $_GET['rssai_dismissed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Group dismissed.', 'hti-rss-ai' ) );
		}
	}
}
