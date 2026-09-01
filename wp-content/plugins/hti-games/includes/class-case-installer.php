<?php
/**
 * Installs the shipped Reveal case library from the admin screen.
 *
 * THE PROBLEM THIS SOLVES. The thirty-four dossiers were reachable only by
 * `wp hti-games seed-cases` over SSH — and worse, class-seed-cases.php was
 * required only from inside the WP-CLI branch, so on an ordinary cPanel site
 * the library was not merely uninstalled, it was not even loaded. The Reveal
 * therefore shipped to production with an empty pool and no way, from the
 * admin, to fill it. Survive the Charts already had its button (Installer);
 * this is the other half of the same argument, and it was missing.
 *
 * The symptom was worse than an empty game. The case-queue panel reported
 * "0 of 0 cases are published" and then "Nothing is waiting on anybody" —
 * which is true of an empty table and reads exactly like everything is fine.
 * A panel that says nothing is wrong when there is no content at all is a
 * worse failure than the missing button, and Case_Admin::render_panel() now
 * distinguishes the two.
 *
 * WHY IT IS NOT SLICED, WHEN Installer IS. Thirty-four cases at twenty-four
 * meta rows each is around two thousand queries; one slice of the scenario
 * library is about three thousand, and that class's own reasoning calls that a
 * fair ask of one request. Adding a resume state machine for a job two thirds
 * the size of one slice would be machinery earning nothing. What makes that
 * safe is not optimism: Seed_Cases::install() is idempotent by company and
 * year, so a run cut short on a slow host is finished by pressing the button
 * again — it skips whatever the first attempt stored.
 *
 * THE LIBRARY LOADS LAZILY. class-seed-cases.php is two thousand lines of
 * dossier data and has no business being parsed on every admin request to
 * support one button. The panel reads its size from Config::case_library(),
 * and the file is required only when the button is actually pressed.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * The "install the case library" button and its handler.
 */
class Case_Installer {

	/**
	 * Option holding the last run's tally. Named OPTION so
	 * tests/test-security.php finds it and insists uninstall.php deletes it.
	 */
	public const OPTION_LAST = 'hti_games_last_cases';

	/**
	 * Nonce action, shared by the form and its handler.
	 */
	public const NONCE = 'hti_games_install_cases';

	/**
	 * Hook the handler. The form itself is rendered inside the case queue
	 * panel — the screen that reports the library is empty is the screen that
	 * should carry the cure.
	 */
	public static function init(): void {
		add_action( 'admin_post_hti_games_install_cases', array( __CLASS__, 'handle_form' ) );
	}

	/* ---------------------------------------------------------------------
	 * Pure — no WordPress, unit-tested.
	 * ------------------------------------------------------------------- */

	/**
	 * How the panel should describe the library, given what is shipped and
	 * what is already stored.
	 *
	 * Three states, and they are genuinely different things to say: nothing
	 * installed (the game cannot run), some installed (a partial or
	 * interrupted run), all installed (the button is a no-op an owner may
	 * still press safely).
	 *
	 * @param int $shipped   Cases in the shipped library.
	 * @param int $installed Cases already in the database, any status.
	 * @return array{state:string,missing:int}
	 */
	public static function state( int $shipped, int $installed ): array {
		$shipped   = max( 0, $shipped );
		$installed = max( 0, $installed );
		$missing   = max( 0, $shipped - $installed );

		if ( 0 === $installed ) {
			$state = 'empty';
		} elseif ( $missing > 0 ) {
			$state = 'partial';
		} else {
			$state = 'complete';
		}

		return array(
			'state'   => $state,
			'missing' => $missing,
		);
	}

	/* ---------------------------------------------------------------------
	 * WordPress-bound.
	 * ------------------------------------------------------------------- */

	/**
	 * Cases the shipped library holds, without loading the library.
	 */
	public static function shipped(): int {
		return (int) Config::case_library()['count'];
	}

	/**
	 * Cases already stored, at any post status — because a case an editor
	 * deliberately unpublished is still one we have, and re-installing must
	 * not resurrect it.
	 */
	public static function installed(): int {
		$counts = wp_count_posts( Config::CPT_CASE );
		if ( ! is_object( $counts ) ) {
			return 0;
		}
		$total = 0;
		foreach ( get_object_vars( $counts ) as $count ) {
			$total += (int) $count;
		}
		return $total;
	}

	/**
	 * Pull in the dossier data. Kept out of the plugin's class map on purpose
	 * — see the file docblock.
	 */
	public static function load_library(): void {
		if ( ! class_exists( __NAMESPACE__ . '\\Seed_Cases' ) ) {
			require_once HTI_GAMES_PATH . 'includes/class-seed-cases.php';
		}
	}

	/**
	 * The last run's tally, or an empty array when it has never run.
	 *
	 * @return array<string,mixed>
	 */
	public static function report(): array {
		$last = get_option( self::OPTION_LAST );
		return is_array( $last ) ? $last : array();
	}

	/**
	 * Run the install and record what happened.
	 *
	 * @return array{created:int,skipped:int,failed:int,total:int}
	 */
	public static function install(): array {
		self::load_library();
		$report = Seed_Cases::install();

		update_option(
			self::OPTION_LAST,
			array(
				'time'    => gmdate( 'Y-m-d H:i' ),
				'created' => (int) $report['created'],
				'skipped' => (int) $report['skipped'],
				'failed'  => (int) $report['failed'],
				'total'   => (int) $report['total'],
				'version' => (int) Config::case_library()['version'],
			),
			false
		);

		return $report;
	}

	/**
	 * The button handler.
	 */
	public static function handle_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-games' ) );
		}
		check_admin_referer( self::NONCE );

		$report = self::install();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                    => Settings::PAGE,
					'hti_games_cases'         => (string) $report['created'],
					'hti_games_cases_skipped' => (string) $report['skipped'],
					'hti_games_cases_failed'  => (string) $report['failed'],
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * The install form, rendered from inside the case queue panel.
	 */
	public static function render_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$shipped = self::shipped();
		$state   = self::state( $shipped, self::installed() );
		$last    = self::report();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of the redirect's own tallies.
		if ( isset( $_GET['hti_games_cases'] ) ) {
			$failed = absint( wp_unslash( $_GET['hti_games_cases_failed'] ?? '0' ) );
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$failed > 0 ? 'warning' : 'success',
				esc_html(
					sprintf(
						/* translators: 1: cases created, 2: cases already present, 3: cases that failed. */
						__( 'Case library: %1$d created, %2$d already present, %3$d could not be created.', 'hti-games' ),
						absint( wp_unslash( $_GET['hti_games_cases'] ) ),
						absint( wp_unslash( $_GET['hti_games_cases_skipped'] ?? '0' ) ),
						$failed
					)
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$intro = match ( $state['state'] ) {
			'empty'   => __( 'No cases are installed, so The Reveal has nothing to serve. The plugin ships thirty-four complete dossiers — real companies, real periods, real outcomes, with reconstructed figures that every result screen declares as reconstructions. Installing them makes the game playable now; promoting one to a sourced case is editorial work you can do afterwards, one case at a time.', 'hti-games' ),
			'partial' => __( 'Part of the shipped library is installed. Pressing the button again adds only what is missing — a case already here is left exactly as you left it, including one you unpublished on purpose.', 'hti-games' ),
			default   => __( 'The whole shipped library is installed. The button is safe to press again; it will find nothing to do.', 'hti-games' ),
		};

		printf( '<p>%s</p>', esc_html( $intro ) );

		printf(
			'<p class="description">%s</p>',
			esc_html(
				'empty' === $state['state'] || 'partial' === $state['state']
					? sprintf(
						/* translators: 1: cases missing, 2: cases in the shipped library. */
						__( '%1$d of %2$d shipped cases are not installed yet.', 'hti-games' ),
						$state['missing'],
						$shipped
					)
					: sprintf(
						/* translators: %d: cases in the shipped library. */
						__( 'All %d shipped cases are installed.', 'hti-games' ),
						$shipped
					)
			)
		);

		if ( ! empty( $last['time'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: UTC timestamp, 2: created, 3: already present, 4: failed. */
						__( 'Last install %1$s UTC: %2$s created, %3$s already present, %4$s failed.', 'hti-games' ),
						(string) $last['time'],
						(string) ( $last['created'] ?? 0 ),
						(string) ( $last['skipped'] ?? 0 ),
						(string) ( $last['failed'] ?? 0 )
					)
				)
			);
		}

		printf(
			'<form method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="hti_games_install_cases" />';
		wp_nonce_field( self::NONCE );
		submit_button(
			'complete' === $state['state']
				? __( 'Re-check the case library', 'hti-games' )
				: __( 'Install the case library', 'hti-games' ),
			'empty' === $state['state'] ? 'primary' : 'secondary',
			'submit',
			false
		);
		echo '</form>';
	}
}
