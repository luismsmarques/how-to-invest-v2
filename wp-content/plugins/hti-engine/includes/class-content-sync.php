<?php
/**
 * Content sync — the central that converges the site to the repo content.
 *
 * The cPanel deploy is a plain file copy, so no activation hook ever fires.
 * This class watches the repo-managed content sources — the broker seeder
 * data file and the Learn/glossary Markdown — through a cheap mtime|size
 * manifest. When the signature changes (i.e. after a deploy), it schedules
 * ONE background wp-cron event that upserts everything in place:
 * Broker_Seeder::seed() + Content_Import::import() + Glossary_Import::import().
 * No delete + re-seed, ever; statuses and slugs are preserved on update; the
 * admin-managed broker deal fields (Broker_Seeder::PROTECTED_META) are never
 * written by a sync.
 *
 * Manual override: Tools → Content sync ("Sync now") or `wp hti sync-content`.
 * Auto mode never seeds the broker section on a site where it was never
 * seeded — launching that section stays an explicit owner decision (run the
 * first sync manually). Learn/glossary are already live and always sync.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Deploy-triggered + manual sync of all repo-managed content.
 */
class Content_Sync {

	/**
	 * Cron hook fired once, ~30 s after a content change is detected.
	 */
	public const HOOK = 'hti_content_sync';

	/**
	 * Option holding the last applied/observed content signature.
	 */
	private const OPTION_SIG = 'hti_content_sync_sig';

	/**
	 * Option holding the last sync report {time, trigger, brokers, learn, glossary}.
	 */
	private const OPTION_LAST = 'hti_content_last_sync';

	/**
	 * Transient throttling the filesystem check to once per interval.
	 */
	private const THROTTLE = 'hti_content_sync_checked';

	/**
	 * Wire the gate, the cron hook and the Tools page.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
		add_action( self::HOOK, array( __CLASS__, 'run_auto' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_hti_content_sync', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/* -------------------------------------------------------------------------
	 * Deploy detection.
	 * ---------------------------------------------------------------------- */

	/**
	 * Cheap gate, run on init: at most once per 10 minutes, stat the content
	 * sources and, when the signature no longer matches the stored one, record
	 * it immediately (so a failed cron run never re-schedules in a loop — the
	 * manual button and the next deploy still cover it) and schedule the single
	 * background sync event.
	 */
	public static function maybe_schedule(): void {
		if ( false !== get_transient( self::THROTTLE ) ) {
			return;
		}
		set_transient( self::THROTTLE, 1, 10 * MINUTE_IN_SECONDS );

		$sig = self::signature();
		if ( (string) get_option( self::OPTION_SIG ) === $sig ) {
			return;
		}
		update_option( self::OPTION_SIG, $sig, false );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}

	/**
	 * Signature of everything the sync reads: the broker seeder data file plus
	 * every Learn/glossary Markdown file, keyed by path|mtime|size (the cPanel
	 * deploy rewrites files, so a deploy always changes the mtimes), and the
	 * plugin version.
	 */
	public static function signature(): string {
		$paths = array_merge(
			array( HTI_ENGINE_PATH . 'includes/class-broker-seeder.php' ),
			Content_Import::files(),
			Glossary_Import::files()
		);

		$entries = array();
		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				$entries[] = $path . '|missing';
				continue;
			}
			$entries[] = $path . '|' . (int) filemtime( $path ) . '|' . (int) filesize( $path );
		}

		return self::signature_from( $entries, VERSION );
	}

	/**
	 * Order-independent signature of a file manifest. Pure (testable without
	 * WordPress).
	 *
	 * @param array<int,string> $entries Manifest lines (path|mtime|size).
	 * @param string            $version Plugin version, part of the signature.
	 */
	public static function signature_from( array $entries, string $version ): string {
		sort( $entries, SORT_STRING );
		return md5( $version . "\n" . implode( "\n", $entries ) );
	}

	/* -------------------------------------------------------------------------
	 * Running a sync.
	 * ---------------------------------------------------------------------- */

	/**
	 * Cron callback (the hook passes no arguments).
	 */
	public static function run_auto(): void {
		self::run( 'auto' );
	}

	/**
	 * Run all three content pipelines and store the report.
	 *
	 * @param string $trigger 'auto', 'manual' or 'cli'.
	 * @return array<string,mixed> The stored report.
	 */
	public static function run( string $trigger ): array {
		$brokers = null;
		if ( 'auto' !== $trigger || self::brokers_seeded() ) {
			$brokers = Broker_Seeder::seed();
		}

		$learn    = self::summarize( Content_Import::import() );
		$glossary = self::summarize( Glossary_Import::import() );

		$report = array(
			'time'     => time(),
			'trigger'  => $trigger,
			'brokers'  => $brokers,
			'learn'    => $learn,
			'glossary' => $glossary,
		);

		update_option( self::OPTION_LAST, $report, false );
		// Mark the current repo state as applied (a manual run may precede the
		// gate noticing the change).
		update_option( self::OPTION_SIG, self::signature(), false );

		return $report;
	}

	/**
	 * Whether the broker section was ever seeded on this site (the dormancy
	 * rule: auto sync updates it but never launches it).
	 */
	private static function brokers_seeded(): bool {
		$existing = get_posts(
			array(
				'post_type'   => 'broker',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		return array() !== $existing;
	}

	/**
	 * Compact summary of a Learn/glossary import report.
	 *
	 * @param array<int,array<string,mixed>> $rows Per-slug import rows.
	 * @return array{total:int,pt_missing:int}
	 */
	private static function summarize( array $rows ): array {
		$pt_missing = 0;
		foreach ( $rows as $row ) {
			if ( 'none' === (string) ( $row['pt_status'] ?? 'none' ) ) {
				++$pt_missing;
			}
		}
		return array(
			'total'      => count( $rows ),
			'pt_missing' => $pt_missing,
		);
	}

	/* -------------------------------------------------------------------------
	 * Admin (Tools → Content sync).
	 * ---------------------------------------------------------------------- */

	/**
	 * Register the Tools page.
	 */
	public static function admin_menu(): void {
		add_management_page(
			__( 'HowToInvest — Content sync', 'hti-engine' ),
			__( 'Content sync', 'hti-engine' ),
			'manage_options',
			'hti-content-sync',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the central: status, last run, broker table, Sync now.
	 */
	public static function render_page(): void {
		$pending   = (string) get_option( self::OPTION_SIG ) !== self::signature();
		$scheduled = (bool) wp_next_scheduled( self::HOOK );
		$last      = get_option( self::OPTION_LAST );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'HowToInvest — Content sync', 'hti-engine' ); ?></h1>
			<p><?php echo esc_html__( 'Converges the site to the content in the repository: broker reviews, section pages and guides (seeder data), Learn chapters and glossary articles (content/*.md). Missing entries are created, changed ones are updated in place — statuses and slugs are preserved, and nothing is ever deleted.', 'hti-engine' ); ?></p>
			<p><strong><?php echo esc_html__( 'Broker deal fields (affiliate URL, deal active, network, CFD loss %) are never touched by a sync', 'hti-engine' ); ?></strong> — <?php echo esc_html__( 'they belong to each broker\'s "Broker data" box in wp-admin.', 'hti-engine' ); ?></p>

			<h2><?php echo esc_html__( 'Status', 'hti-engine' ); ?></h2>
			<?php if ( $scheduled ) : ?>
				<p><span class="dashicons dashicons-clock"></span> <?php echo esc_html__( 'Repo changes detected — an automatic sync is scheduled and will run within minutes.', 'hti-engine' ); ?></p>
			<?php elseif ( $pending ) : ?>
				<p><span class="dashicons dashicons-warning"></span> <?php echo esc_html__( 'Repo changes pending — they will be picked up automatically, or run "Sync now" below.', 'hti-engine' ); ?></p>
			<?php else : ?>
				<p><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html__( 'The site matches the repository content.', 'hti-engine' ); ?></p>
			<?php endif; ?>

			<?php if ( is_array( $last ) && ! empty( $last['time'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: date/time, 2: trigger (auto/manual/cli). */
						esc_html__( 'Last sync: %1$s (%2$s).', 'hti-engine' ),
						esc_html( wp_date( 'Y-m-d H:i', (int) $last['time'] ) ),
						esc_html( (string) $last['trigger'] )
					);
					echo ' ';
					if ( is_array( $last['brokers'] ?? null ) ) {
						$b = $last['brokers'];
						printf(
							/* translators: 1-6: counts. */
							esc_html__( 'Brokers: %1$d created, %2$d updated; pages: %3$d created, %4$d updated; PT: %5$d created, %6$d updated.', 'hti-engine' ),
							(int) ( $b['brokers_created'] ?? 0 ),
							(int) ( $b['brokers_updated'] ?? 0 ),
							(int) ( $b['pages_created'] ?? 0 ),
							(int) ( $b['pages_updated'] ?? 0 ),
							(int) ( $b['translations_created'] ?? 0 ),
							(int) ( $b['translations_updated'] ?? 0 )
						);
					} else {
						echo esc_html__( 'Brokers: skipped (section not seeded on this site — run "Sync now" to launch it).', 'hti-engine' );
					}
					echo ' ';
					printf(
						/* translators: 1: Learn items, 2: glossary items. */
						esc_html__( 'Learn: %1$d items; glossary: %2$d items.', 'hti-engine' ),
						(int) ( $last['learn']['total'] ?? 0 ),
						(int) ( $last['glossary']['total'] ?? 0 )
					);
					?>
				</p>
			<?php else : ?>
				<p><?php echo esc_html__( 'No sync has run yet on this site.', 'hti-engine' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hti_content_sync" />
				<?php wp_nonce_field( 'hti_content_sync' ); ?>
				<?php submit_button( __( 'Sync now', 'hti-engine' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Broker section', 'hti-engine' ); ?></h2>
			<p><?php echo esc_html__( 'Per entry: whether the English post and its linked Portuguese twin exist, and whether each matches the current repo content. "Pending" entries are updated by the next sync run.', 'hti-engine' ); ?></p>
			<table class="widefat striped" style="max-width:760px">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Entry', 'hti-engine' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'hti-engine' ); ?></th>
						<th><?php echo esc_html__( 'EN', 'hti-engine' ); ?></th>
						<th><?php echo esc_html__( 'PT', 'hti-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Broker_Seeder::status_rows() as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['title'] ); ?></td>
							<td><?php echo esc_html( $row['type'] ); ?></td>
							<td><?php echo esc_html( self::state_label( $row['en'] ) ); ?></td>
							<td><?php echo esc_html( self::state_label( $row['pt'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:1em">
				<?php echo esc_html__( 'Learn and glossary have their own detail pages:', 'hti-engine' ); ?>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=hti-learn-content' ) ); ?>"><?php echo esc_html__( 'Learn content', 'hti-engine' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=hti-glossary-content' ) ); ?>"><?php echo esc_html__( 'Glossary content', 'hti-engine' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Human label for a status_rows() state.
	 *
	 * @param string $state 'missing', 'pending' or 'ok'.
	 */
	private static function state_label( string $state ): string {
		if ( 'ok' === $state ) {
			return __( 'Up to date', 'hti-engine' );
		}
		if ( 'pending' === $state ) {
			return __( 'Pending sync', 'hti-engine' );
		}
		return __( 'Missing', 'hti-engine' );
	}

	/**
	 * Handle the "Sync now" submission.
	 */
	public static function handle_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hti-engine' ) );
		}
		check_admin_referer( 'hti_content_sync' );

		self::run( 'manual' );
		set_transient( 'hti_content_sync_notice', 1, 60 );

		wp_safe_redirect( add_query_arg( 'page', 'hti-content-sync', admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Success notice after a manual run (the page itself shows the details).
	 */
	public static function admin_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_hti-content-sync' !== $screen->id ) {
			return;
		}
		if ( ! get_transient( 'hti_content_sync_notice' ) ) {
			return;
		}
		delete_transient( 'hti_content_sync_notice' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Content sync complete — details below.', 'hti-engine' )
		);
	}
}
