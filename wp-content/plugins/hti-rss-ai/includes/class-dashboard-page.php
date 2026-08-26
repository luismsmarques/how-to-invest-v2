<?php
/**
 * News dashboard: a per-language overview of the whole pipeline.
 *
 * EN and PT are intentionally separate audiences (US-market feeds vs Portuguese
 * feeds), so the dashboard shows one card per language side by side — feeds
 * health, ungrouped items, open groups, drafts awaiting review and published
 * articles — plus the manual Fetch / Group / Cleanup actions.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Dashboard submenu.
 */
class Dashboard_Page {

	public const PAGE = 'rssai-dashboard';

	/**
	 * Hook the menu.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
	}

	/**
	 * Add the Dashboard submenu (first item under the RSS AI Feed menu).
	 */
	public static function menu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Dashboard', 'hti-rss-ai' ),
			__( 'Dashboard', 'hti-rss-ai' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render one card per configured language.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$langs      = Settings::languages();
		$feeds      = Feeds::all();
		$open       = Groups::all( 'open' );
		$fetch_url  = wp_nonce_url( admin_url( 'admin-post.php?action=rssai_fetch_now' ), 'rssai_fetch_now' );
		$group_url  = wp_nonce_url( admin_url( 'admin-post.php?action=rssai_group_now' ), 'rssai_group_now' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'News dashboard', 'hti-rss-ai' ); ?></h1>
			<a href="<?php echo esc_url( $fetch_url ); ?>" class="page-title-action"><?php echo esc_html__( 'Fetch now', 'hti-rss-ai' ); ?></a>
			<a href="<?php echo esc_url( $group_url ); ?>" class="page-title-action"><?php echo esc_html__( 'Group now', 'hti-rss-ai' ); ?></a>
			<hr class="wp-header-end" />
			<p class="description"><?php echo esc_html__( 'Each language is a separate audience with its own feeds. Fetch and Group run across all languages; the cards below show where each stands.', 'hti-rss-ai' ); ?></p>

			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:16px">
				<?php foreach ( $langs as $lang ) : ?>
					<?php self::render_card( $lang, $feeds, $open ); ?>
				<?php endforeach; ?>
			</div>

			<p style="margin-top:18px">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Drafts::PAGE ) ); ?>"><?php echo esc_html__( 'Open Drafts', 'hti-rss-ai' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Groups_Page::PAGE ) ); ?>"><?php echo esc_html__( 'Open Groups', 'hti-rss-ai' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE ) ); ?>"><?php echo esc_html__( 'Manage Feeds', 'hti-rss-ai' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'Settings & cleanup', 'hti-rss-ai' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * One language card.
	 *
	 * @param string            $lang  Language code.
	 * @param array<int,object> $feeds All feeds.
	 * @param array<int,object> $open  All open groups.
	 */
	private static function render_card( string $lang, array $feeds, array $open ): void {
		$lang_feeds  = array_filter( $feeds, static fn( $feed ) => (string) $feed->lang === $lang );
		$active      = count( array_filter( $lang_feeds, static fn( $feed ) => ! empty( $feed->status ) ) );
		$paused      = count( $lang_feeds ) - $active;
		$new_items   = Items::count( array( 'status' => 'new', 'lang' => $lang ) );
		$grouped     = Items::count( array( 'status' => 'grouped', 'lang' => $lang ) );
		$open_groups = count( array_filter( $open, static fn( $group ) => (string) $group->lang === $lang ) );
		$pending     = self::count_posts( $lang, array( 'pending', 'draft' ) );
		$published   = self::count_posts( $lang, array( 'publish' ) );
		$last_fetch  = self::last_fetch( $lang_feeds );

		$drafts_link = add_query_arg(
			array( 'page' => Drafts::PAGE, 'flang' => $lang, 'fstatus' => 'new' ),
			admin_url( 'admin.php' )
		);
		$groups_link = add_query_arg(
			array( 'page' => Groups_Page::PAGE, 'flang' => $lang ),
			admin_url( 'admin.php' )
		);
		?>
		<div style="flex:1 1 260px;min-width:260px;max-width:420px;border:1px solid #dcdcde;border-radius:8px;background:#fff;padding:16px">
			<h2 style="margin:0 0 4px;font-size:18px"><?php echo esc_html( strtoupper( $lang ) ); ?></h2>
			<p style="color:#646970;margin:0 0 12px;font-size:12px">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: active feeds, 2: paused feeds. */
						__( '%1$d feeds active · %2$d paused', 'hti-rss-ai' ),
						$active,
						$paused
					)
				);
				if ( $last_fetch ) {
					echo ' · ' . esc_html( sprintf( /* translators: %s: datetime. */ __( 'last fetch %s', 'hti-rss-ai' ), $last_fetch ) );
				}
				?>
			</p>
			<table class="widefat striped" style="border:none">
				<tbody>
					<?php
					self::stat_row( __( 'New items (ungrouped)', 'hti-rss-ai' ), $new_items, $drafts_link );
					self::stat_row( __( 'Items in groups', 'hti-rss-ai' ), $grouped, '' );
					self::stat_row( __( 'Open groups', 'hti-rss-ai' ), $open_groups, $groups_link );
					self::stat_row( __( 'Awaiting review', 'hti-rss-ai' ), $pending, '' );
					self::stat_row( __( 'Published', 'hti-rss-ai' ), $published, '' );
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * A metric row, optionally linked.
	 *
	 * @param string $label Label.
	 * @param int    $value Count.
	 * @param string $link  Optional URL.
	 */
	private static function stat_row( string $label, int $value, string $link ): void {
		$shown = $link
			? '<a href="' . esc_url( $link ) . '">' . (int) $value . '</a>'
			: (string) (int) $value;
		printf(
			'<tr><td>%1$s</td><td style="text-align:right;font-weight:600;font-size:16px">%2$s</td></tr>',
			esc_html( $label ),
			wp_kses( $shown, array( 'a' => array( 'href' => array() ) ) )
		);
	}

	/**
	 * Count pipeline posts in a language and set of statuses.
	 *
	 * @param string            $lang     Language code.
	 * @param array<int,string> $statuses Post statuses.
	 */
	private static function count_posts( string $lang, array $statuses ): int {
		$query = new \WP_Query(
			array(
				'post_type'        => Settings::post_type(),
				'post_status'      => $statuses,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => false,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'rssai_lang',
						'value' => $lang,
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Most recent successful fetch time across a language's feeds ('' if none).
	 *
	 * @param array<int,object> $feeds Feeds of one language.
	 */
	private static function last_fetch( array $feeds ): string {
		$latest = '';
		foreach ( $feeds as $feed ) {
			$when = (string) ( $feed->last_fetched ?? '' );
			if ( '' !== $when && $when > $latest ) {
				$latest = $when;
			}
		}
		return $latest;
	}
}
