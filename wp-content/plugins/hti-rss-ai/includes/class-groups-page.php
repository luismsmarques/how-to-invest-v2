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
		add_action( 'admin_post_rssai_remove_item', array( __CLASS__, 'handle_remove_item' ) );
		add_action( 'admin_post_rssai_merge_group', array( __CLASS__, 'handle_merge_group' ) );
		add_action( 'admin_post_rssai_move_item', array( __CLASS__, 'handle_move_item' ) );
		add_action( 'admin_post_rssai_promote_item', array( __CLASS__, 'handle_promote_item' ) );
		add_action( 'admin_post_rssai_edit_label', array( __CLASS__, 'handle_edit_label' ) );
	}

	/**
	 * Remove one item from a group (it returns to the Drafts pool). If the group
	 * is left empty it is dismissed.
	 */
	public static function handle_remove_item(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$gid  = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$item = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		check_admin_referer( 'rssai_remove_item_' . $item );

		if ( $item ) {
			Items::update( $item, array( 'status' => 'new', 'group_id' => 0 ) );
		}

		$remaining = $gid ? Groups::items( $gid ) : array();
		$args      = array( 'page' => self::PAGE );
		if ( $gid && ! $remaining ) {
			Groups::dismiss( $gid );
		} else {
			if ( $gid ) {
				// Keep the stored size honest after a manual removal.
				Groups::recount( $gid, true );
			}
			$args['action'] = 'view';
			$args['id']     = $gid;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Merge one open group into another (same language). The source group's
	 * items move to the destination; the emptied source is deleted.
	 */
	public static function handle_merge_group(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$src = isset( $_POST['src'] ) ? absint( wp_unslash( $_POST['src'] ) ) : 0;
		$dst = isset( $_POST['dst'] ) ? absint( wp_unslash( $_POST['dst'] ) ) : 0;
		check_admin_referer( 'rssai_merge_group_' . $src );

		$source = $src ? Groups::get( $src ) : null;
		$target = $dst ? Groups::get( $dst ) : null;
		if ( $source && $target && $src !== $dst && (string) $source->lang === (string) $target->lang ) {
			$ids = array_map( static fn( $item ) => (int) $item->id, Groups::items( $src ) );
			if ( $ids ) {
				Items::set_group( $ids, $dst );
			}
			Groups::recount( $dst, true );
			Groups::delete( $src );
			Logger::log( 'group', sprintf( 'Merged group %d into %d (%d items)', $src, $dst, count( $ids ) ) );
			self::redirect_to_group( $dst );
		}
		self::redirect_to_group( $dst ? $dst : $src );
	}

	/**
	 * Move a single item to another existing group, or split it into a brand
	 * new group. The source group is recounted and dismissed if left empty.
	 */
	public static function handle_move_item(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$item_id = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		$target  = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '';
		check_admin_referer( 'rssai_move_item_' . $item_id );

		$item = $item_id ? Items::get( $item_id ) : null;
		if ( ! $item ) {
			self::redirect_to_group( 0 );
		}
		$from = (int) $item->group_id;

		if ( 'new' === $target ) {
			$dest = self::promote_to_new_group( $item_id );
		} else {
			$dest   = absint( $target );
			$group  = $dest ? Groups::get( $dest ) : null;
			// Only allow moving within the same language.
			if ( ! $group || (string) $group->lang !== (string) $item->lang ) {
				self::redirect_to_group( $from );
			}
			Items::set_group( array( $item_id ), $dest );
			Groups::recount( $dest, true );
		}

		self::reconcile_source( $from );
		self::redirect_to_group( $dest ? $dest : $from );
	}

	/**
	 * Promote a single item into its own new group (useful for an important
	 * story that never reached the 2-item clustering threshold).
	 */
	public static function handle_promote_item(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$item_id = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		check_admin_referer( 'rssai_promote_item_' . $item_id );

		$item = $item_id ? Items::get( $item_id ) : null;
		if ( ! $item ) {
			self::redirect_to_group( 0 );
		}
		$from = (int) $item->group_id;
		$dest = self::promote_to_new_group( $item_id );
		self::reconcile_source( $from );
		self::redirect_to_group( $dest );
	}

	/**
	 * Rename a group.
	 */
	public static function handle_edit_label(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		check_admin_referer( 'rssai_edit_label_' . $id );
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		if ( $id && '' !== $label ) {
			Groups::set_label( $id, $label );
		}
		self::redirect_to_group( $id );
	}

	/**
	 * Create a new open group holding just this item. Returns the new group id.
	 *
	 * @param int $item_id Item id.
	 */
	private static function promote_to_new_group( int $item_id ): int {
		$item = Items::get( $item_id );
		if ( ! $item ) {
			return 0;
		}
		$gid = Groups::insert(
			array(
				'label'  => (string) $item->title,
				'lang'   => Settings::valid_lang( (string) $item->lang ),
				'status' => 'open',
				'score'  => 1.0,
				'size'   => 1,
			)
		);
		if ( $gid ) {
			Items::set_group( array( $item_id ), $gid );
			Logger::log( 'group', sprintf( 'Promoted item %d to new group %d', $item_id, $gid ) );
		}
		return (int) $gid;
	}

	/**
	 * Recount a source group after an item left it; dismiss it if now empty.
	 *
	 * @param int $group_id Source group id (0 = none).
	 */
	private static function reconcile_source( int $group_id ): void {
		if ( ! $group_id ) {
			return;
		}
		if ( 0 === Groups::recount( $group_id, true ) ) {
			Groups::dismiss( $group_id );
		}
	}

	/**
	 * Redirect to a group's detail view (or the list when id is 0).
	 *
	 * @param int $group_id Group id.
	 */
	private static function redirect_to_group( int $group_id ): void {
		$args = array( 'page' => self::PAGE );
		if ( $group_id ) {
			$args['action'] = 'view';
			$args['id']     = $group_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
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
	 * The language filter currently applied ('' = all).
	 */
	private static function current_lang(): string {
		$lang = isset( $_GET['flang'] ) ? preg_replace( '/[^a-z]/', '', strtolower( (string) wp_unslash( $_GET['flang'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $lang, Settings::languages(), true ) ? (string) $lang : '';
	}

	/**
	 * Render language filter tabs (only when more than one language).
	 *
	 * @param array<int,object> $groups  All open groups.
	 * @param string            $current Active language filter.
	 */
	private static function render_lang_tabs( array $groups, string $current ): void {
		$langs = Settings::languages();
		if ( count( $langs ) < 2 ) {
			return;
		}
		$base  = admin_url( 'admin.php?page=' . self::PAGE );
		$links = array();
		$cls   = '' === $current ? 'current' : '';
		$links[] = sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', esc_url( $base ), esc_attr( $cls ), esc_html__( 'All', 'hti-rss-ai' ), count( $groups ) );
		foreach ( $langs as $code ) {
			$count   = count( array_filter( $groups, static fn( $group ) => (string) $group->lang === $code ) );
			$cls     = $current === $code ? 'current' : '';
			$url     = add_query_arg( 'flang', $code, $base );
			$links[] = sprintf( '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>', esc_url( $url ), esc_attr( $cls ), esc_html( strtoupper( $code ) ), $count );
		}
		echo '<ul class="subsubsub">';
		$last = count( $links ) - 1;
		foreach ( $links as $i => $link ) {
			echo '<li>' . wp_kses_post( $link ) . ( $i < $last ? ' | ' : '' ) . '</li>';
		}
		echo '</ul><div class="clear"></div>';
	}

	/**
	 * List open groups.
	 */
	private static function render_list(): void {
		self::maybe_notice();
		$all_open  = Groups::all( 'open' );
		$lang      = self::current_lang();
		$groups    = '' === $lang ? $all_open : array_values( array_filter( $all_open, static fn( $group ) => (string) $group->lang === $lang ) );
		$group_now = wp_nonce_url( admin_url( 'admin-post.php?action=rssai_group_now' ), 'rssai_group_now' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Groups', 'hti-rss-ai' ); ?></h1>
			<a href="<?php echo esc_url( $group_now ); ?>" class="page-title-action"><?php echo esc_html__( 'Group now', 'hti-rss-ai' ); ?></a>
			<hr class="wp-header-end" />
			<p class="description"><?php echo esc_html__( 'Clusters of related drafts. Open one to generate an article, edit its items, or merge it with another.', 'hti-rss-ai' ); ?></p>
			<?php self::render_lang_tabs( $all_open, $lang ); ?>
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
		$items   = Groups::items( $id );
		$targets = self::sibling_groups( $group );
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
			<?php if ( 'open' === $group->status ) : ?>
				<?php self::render_manage_box( $group, $targets ); ?>
			<?php endif; ?>
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
								<?php if ( 'generated' !== $group->status ) : ?>
									<div style="margin-top:6px;font-size:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
										<a style="text-decoration:none" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rssai_promote_item&item=' . (int) $item->id ), 'rssai_promote_item_' . (int) $item->id ) ); ?>"><?php echo esc_html__( 'Split to new group', 'hti-rss-ai' ); ?></a>
										<?php self::render_move_control( (int) $item->id, $targets ); ?>
										<a class="submitdelete" style="color:#b32d2e;text-decoration:none" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rssai_remove_item&id=' . (int) $group->id . '&item=' . (int) $item->id ), 'rssai_remove_item_' . (int) $item->id ) ); ?>"><?php echo esc_html__( 'Remove from group', 'hti-rss-ai' ); ?></a>
									</div>
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
	 * Other open groups in the same language (valid merge/move targets).
	 *
	 * @param object $group Current group.
	 * @return array<int,object>
	 */
	private static function sibling_groups( object $group ): array {
		$out = array();
		foreach ( Groups::all( 'open' ) as $candidate ) {
			if ( (int) $candidate->id !== (int) $group->id && (string) $candidate->lang === (string) $group->lang ) {
				$out[] = $candidate;
			}
		}
		return $out;
	}

	/**
	 * Rename + merge controls for an open group.
	 *
	 * @param object            $group   Group row.
	 * @param array<int,object> $targets Sibling groups (same language).
	 */
	private static function render_manage_box( object $group, array $targets ): void {
		$post = esc_url( admin_url( 'admin-post.php' ) );
		echo '<div class="rssai-manage" style="display:flex;gap:24px;flex-wrap:wrap;margin:10px 0 4px;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px">';

		// Rename.
		echo '<form method="post" action="' . $post . '" style="display:flex;gap:6px;align-items:center">';
		echo '<input type="hidden" name="action" value="rssai_edit_label" />';
		echo '<input type="hidden" name="id" value="' . (int) $group->id . '" />';
		wp_nonce_field( 'rssai_edit_label_' . (int) $group->id );
		echo '<label style="font-weight:600">' . esc_html__( 'Title', 'hti-rss-ai' ) . '</label> ';
		echo '<input type="text" name="label" class="regular-text" value="' . esc_attr( (string) $group->label ) . '" />';
		submit_button( __( 'Rename', 'hti-rss-ai' ), 'secondary small', 'submit', false );
		echo '</form>';

		// Merge into another same-language group.
		if ( $targets ) {
			echo '<form method="post" action="' . $post . '" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm(\'' . esc_js( __( 'Merge this group into the selected one?', 'hti-rss-ai' ) ) . '\')">';
			echo '<input type="hidden" name="action" value="rssai_merge_group" />';
			echo '<input type="hidden" name="src" value="' . (int) $group->id . '" />';
			wp_nonce_field( 'rssai_merge_group_' . (int) $group->id );
			echo '<label style="font-weight:600">' . esc_html__( 'Merge into', 'hti-rss-ai' ) . '</label> ';
			echo '<select name="dst">';
			foreach ( $targets as $target ) {
				printf( '<option value="%1$d">%2$s</option>', (int) $target->id, esc_html( wp_trim_words( (string) $target->label, 10 ) ) );
			}
			echo '</select>';
			submit_button( __( 'Merge', 'hti-rss-ai' ), 'secondary small', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Per-item "move to another group" dropdown (same language only).
	 *
	 * @param int               $item_id Item id.
	 * @param array<int,object> $targets Sibling groups (same language).
	 */
	private static function render_move_control( int $item_id, array $targets ): void {
		if ( ! $targets ) {
			return;
		}
		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="rssai_move_item" />';
		echo '<input type="hidden" name="item" value="' . (int) $item_id . '" />';
		wp_nonce_field( 'rssai_move_item_' . (int) $item_id, '_wpnonce', false );
		echo '<select name="target" onchange="this.form.submit()">';
		echo '<option value="">' . esc_html__( 'Move to…', 'hti-rss-ai' ) . '</option>';
		foreach ( $targets as $target ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $target->id, esc_html( wp_trim_words( (string) $target->label, 8 ) ) );
		}
		echo '</select>';
		echo '</form>';
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
					'post_type'        => Settings::post_type(),
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
			Logger::log( 'generate-error', sprintf( 'group=%d: %s', $id, $result->get_error_message() ) );
		} else {
			Logger::log( 'generate', sprintf( 'group=%d → news post #%d (pending)', $id, (int) $result ) );
		}
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
