<?php
/**
 * Installs the shipped scenario library from the admin screen, in slices small
 * enough that a shared host finishes each one.
 *
 * THE PROBLEM THIS SOLVES. Until this file existed, Survive the Charts had no
 * playable content after a deploy: the pool was empty until somebody with SSH
 * ran `wp hti-games generate`. A deploy here is a file copy onto cPanel, so
 * "then open a shell" is not a step the launch checklist can contain, and a
 * game that needs one is a game that ships broken.
 *
 * WHAT IS SHIPPED IS AN ADDRESS, NOT A DATA FILE. Config::library() is three
 * integers — seed, count, version — and STC_Generator is deterministic down to
 * the bit, so those three integers reproduce the identical 365 charts on any
 * host forever. The alternative, 365 × 120 candles × 4 integers as JSON in the
 * plugin directory, is over a megabyte re-copied on every deploy to say what
 * `20260830, 365` already says. The address is the pair and not the seed
 * alone, for the reason spelled out in Config: the count decides the class
 * plan, so a 12-scenario library is not the first twelve of a 365 one.
 *
 * WHY THIS PUBLISHES, WHERE THE CLI DRAFTS. Drafting is right for the CLI: a
 * person typing a seed into a shell is a person about to look at what came
 * out. It is wrong here, because the entire purpose of this button is that the
 * game works after a deploy, and "365 drafts nobody has published" is the same
 * empty pool with extra steps. What makes publishing safe is that a generated
 * scenario asserts nothing about the world: it is not a claim about a company,
 * a price or a date, its label has already been PROVEN by replaying it through
 * STC_Engine, and every one of them is stored with `hti_stc_real = 0` — so the
 * landing copy, which derives its claim from that key across the whole pool
 * (Library::is_real), goes on saying these charts are generated. The one thing
 * a human must sign off — "this chart is real market data" — is exactly the
 * thing this path can never assert.
 *
 * WHY IT IS SLICED. 365 posts is ~4,400 inserts of post and meta rows. That is
 * comfortably past a cPanel PHP process budget, and a job that dies at
 * scenario 210 with no memory of having got there is a job that can only ever
 * be started, never finished. So the run is bounded twice — by a count and by
 * a wall clock read off the host's own max_execution_time — and its position
 * is written to an option after every slice, which makes the button
 * "Continue" as naturally as it is "Start". STC_Generator::addresses() is what
 * makes resuming cheap: scenario 210's seed is a few hundred integer
 * operations away, not 209 chart generations away.
 *
 * NO RECURRING SCHEDULE. WP-Cron is disabled here and driven externally, so a
 * recurring schedule would be a job nobody runs (the same reasoning as
 * Privacy's retention and Library's rotation). Each slice queues ONE single
 * event to carry on unattended, exactly as hti-rss-ai's fetcher does, and that
 * is a bonus rather than the mechanism: the button is the path that always
 * works, and the panel says so rather than leaving an owner watching a bar
 * that may not be moving.
 *
 * Idempotent throughout. A scenario's seed is its identity, so re-running
 * skips what is already stored — at any post status, because a chart an editor
 * deliberately unpublished is still a chart we have and must not be
 * resurrected by a second click.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Batched, resumable installation of the shipped scenario library.
 */
class Installer {

	/**
	 * Option holding the run's position and tallies. Named OPTION so
	 * tests/test-security.php finds it and insists uninstall.php deletes it.
	 */
	public const OPTION = 'hti_games_library';

	/**
	 * Single-event hook that carries an unattended run forward. Never given a
	 * schedule — see the file docblock.
	 */
	public const HOOK = 'hti_games_install_more';

	/**
	 * Nonce action, shared by the form and its handler.
	 */
	public const NONCE = 'hti_games_install_library';

	/**
	 * The status installed scenarios are created with. See the file docblock
	 * for why this is not 'draft'.
	 */
	public const STATUS = 'publish';

	/**
	 * Hard ceiling on one slice, whatever the clock says.
	 *
	 * A count as well as a deadline because the two bound different failures:
	 * the clock catches a slow host, and this catches a fast one that would
	 * otherwise hold several thousand generated candles in memory at once.
	 */
	public const BATCH_MAX = 100;

	/**
	 * The longest a slice will run for, in seconds, on a host that declares
	 * no execution limit at all.
	 */
	public const BUDGET_MAX = 20;

	/**
	 * Seconds between a slice ending short and the single event that may
	 * carry it on. Long enough that the two never overlap in one process.
	 */
	private const RESUME_DELAY = 60;

	/**
	 * Hook the panel, the button and the resume event.
	 */
	public static function init(): void {
		add_action( 'hti_games_settings_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'admin_post_hti_games_install_library', array( __CLASS__, 'handle_form' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_background' ) );
	}

	/* ---------------------------------------------------------------------
	 * Pure — no WordPress, no clock, unit-tested.
	 * ------------------------------------------------------------------- */

	/**
	 * A run that has not started, at the shipped address.
	 *
	 * @return array<string,mixed>
	 */
	public static function blank(): array {
		return array(
			'seed'    => Config::LIBRARY_SEED,
			'count'   => Config::LIBRARY_COUNT,
			'version' => Config::LIBRARY_VERSION,
			'done'    => 0,
			'created' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'started' => '',
			'updated' => '',
			'error'   => '',
		);
	}

	/**
	 * Normalize whatever is in the option into a usable run.
	 *
	 * Defensive in the same way Settings::normalize() is: a stored row is
	 * merely what was written last time, possibly by an older version of this
	 * file, and every number it carries is used to index an array of charts.
	 * `done` is clamped to the count so a stale row can never make the panel
	 * report 400 of 365, and never walks past the end of the address list.
	 *
	 * @param mixed $stored Raw option value.
	 * @return array<string,mixed>
	 */
	public static function state( $stored ): array {
		$blank = self::blank();
		$state = is_array( $stored ) ? array_merge( $blank, $stored ) : $blank;

		foreach ( array( 'seed', 'count', 'version', 'done', 'created', 'skipped', 'failed' ) as $key ) {
			$state[ $key ] = max( 0, (int) $state[ $key ] );
		}
		foreach ( array( 'started', 'updated', 'error' ) as $key ) {
			$state[ $key ] = (string) $state[ $key ];
		}

		// A stored count of zero is a row written before the address was, and
		// dividing a progress bar by it is how an install screen ends in a
		// warning nobody can act on.
		if ( 0 === $state['count'] ) {
			$state['count'] = $blank['count'];
			$state['done']  = 0;
		}
		$state['done'] = min( $state['done'], $state['count'] );

		return $state;
	}

	/**
	 * Whether a run is the library this build of the plugin ships.
	 *
	 * Compared on the address and not the version: two runs at the same seed
	 * and count ARE the same 365 charts, whatever integer the version
	 * constant was carrying when each was written.
	 *
	 * @param array<string,mixed> $state Normalized run.
	 */
	public static function is_shipped( array $state ): bool {
		return (int) $state['seed'] === Config::LIBRARY_SEED
			&& (int) $state['count'] === Config::LIBRARY_COUNT;
	}

	/**
	 * Whether a run has walked its whole address list.
	 *
	 * "Complete" means every address was visited, not that every visit
	 * created a post: one already present, and therefore skipped, is as done
	 * as one inserted.
	 *
	 * @param array<string,mixed> $state Normalized run.
	 */
	public static function is_complete( array $state ): bool {
		return $state['done'] >= $state['count'] && $state['count'] > 0;
	}

	/**
	 * Progress as a whole percentage, for a label a person can read.
	 *
	 * @param array<string,mixed> $state Normalized run.
	 */
	public static function percent( array $state ): int {
		if ( $state['count'] <= 0 ) {
			return 0;
		}
		return (int) min( 100, intdiv( $state['done'] * 100, $state['count'] ) );
	}

	/**
	 * How many seconds one slice may spend, given the host's own limit.
	 *
	 * Half of what the host allows, capped, because the slice is not the only
	 * thing in the request: WordPress has already booted, the redirect still
	 * has to happen, and a job that uses its entire budget is a job that dies
	 * on the request that was one insert slower than average.
	 *
	 * @param int $max_execution ini_get('max_execution_time'), 0 for none.
	 */
	public static function budget( int $max_execution ): int {
		if ( $max_execution <= 0 ) {
			return self::BUDGET_MAX;
		}
		$half = intdiv( $max_execution, 2 );

		// Never zero: a budget of nothing would stop the loop before its
		// first item and turn "install" into a button that does nothing.
		return max( 1, min( self::BUDGET_MAX, $half ) );
	}

	/**
	 * The lesson rotation index of every address in a library.
	 *
	 * Lessons::for_class() rotates through eight sentences per class, and the
	 * index is which of them a scenario gets. Derived from POSITION in the
	 * library rather than from a counter incremented as posts are created, so
	 * that a given chart carries the same lesson whether it was installed
	 * first, resumed into on the third click, or skipped on one run and
	 * created on the next. A counter would make the copy depend on install
	 * history, which is precisely the kind of thing that is impossible to
	 * reproduce when somebody asks why two sites differ.
	 *
	 * @param array<int,array{class:string,seed:int}> $addresses From STC_Generator::addresses().
	 * @return array<int,int> Same keys, lesson index each.
	 */
	public static function lesson_indexes( array $addresses ): array {
		$seen = array();
		$out  = array();

		foreach ( $addresses as $i => $address ) {
			$class          = (string) ( $address['class'] ?? '' );
			$seen[ $class ] = ( $seen[ $class ] ?? -1 ) + 1;
			$out[ $i ]      = $seen[ $class ];
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * The work.
	 * ------------------------------------------------------------------- */

	/**
	 * The run as stored, normalized.
	 *
	 * @return array<string,mixed>
	 */
	public static function stored(): array {
		return self::state( get_option( self::OPTION, array() ) );
	}

	/**
	 * Install one bounded slice of the shipped library, resuming where the
	 * last one stopped.
	 *
	 * @param bool $restart Walk the address list again from the top. Only
	 *                      meaningful on a finished run that left failures
	 *                      behind: the addresses that succeeded all skip, so
	 *                      a restart costs a dedupe query each and fills the
	 *                      holes. A run at another address restarts anyway.
	 * @return array<string,mixed> The run after this slice.
	 */
	public static function run_slice( bool $restart = false ): array {
		$state = self::stored();

		if ( $restart || ! self::is_shipped( $state ) ) {
			$state = self::blank();
		}
		if ( self::is_complete( $state ) ) {
			return $state;
		}
		if ( '' === $state['started'] ) {
			$state['started'] = gmdate( 'Y-m-d H:i' );
		}

		// The same seed and count are the same 365 charts, so a build that
		// merely renumbered the version constant should not leave the panel
		// reporting the integer that happened to be in the file the day the
		// run started.
		$state['version'] = Config::LIBRARY_VERSION;

		$addresses = STC_Generator::addresses( (int) $state['count'], (int) $state['seed'] );
		$lessons   = self::lesson_indexes( $addresses );
		$deadline  = microtime( true ) + (float) self::budget( (int) ini_get( 'max_execution_time' ) );
		$created   = 0;

		for ( $n = 0; $n < self::BATCH_MAX; $n++ ) {
			$at = (int) $state['done'];
			if ( ! isset( $addresses[ $at ] ) ) {
				break;
			}

			$address = $addresses[ $at ];
			$seed    = (int) $address['seed'];

			if ( STC_Generator::seed_exists( $seed ) ) {
				++$state['skipped'];
			} else {
				try {
					// One chart, built here and not held: the slice's memory
					// ceiling is one scenario, not a hundred.
					$scenario = STC_Generator::scenario( (string) $address['class'], $seed );

					if ( STC_Generator::create( $scenario, (int) $state['seed'], (int) $lessons[ $at ], self::STATUS ) ) {
						++$state['created'];
						++$created;
					} else {
						++$state['failed'];
					}
				} catch ( \RuntimeException $e ) {
					// One address that will not generate must not wedge the
					// other 364. Counted, reported on the panel, and stepped
					// over — but the message is kept, because a library that
					// cannot build itself is a code bug and not an install
					// problem, and silence would hide which.
					++$state['failed'];
					$state['error'] = $e->getMessage();
				}
			}

			++$state['done'];

			// Checked after the item rather than before it, so a hostile
			// clock still costs one scenario of progress instead of none.
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		$state['updated'] = gmdate( 'Y-m-d H:i' );
		update_option( self::OPTION, $state, false );

		if ( $created > 0 && class_exists( __NAMESPACE__ . '\\Library' ) ) {
			// The pool is what the rotation reads, and it is cached for
			// twelve hours; without this the game stays empty until the
			// transient happens to expire.
			Library::flush( Config::GAME_STC );
		}

		if ( self::is_complete( $state ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		} else {
			self::queue_resume();
		}

		return $state;
	}

	/**
	 * Queue the one single event that may carry an unattended run forward.
	 *
	 * Single, never recurring: WP-Cron here is disabled and driven from
	 * outside, so this fires whenever the external caller next runs and
	 * possibly not at all. That is why it is a helper and not the mechanism —
	 * the button finishes the job on its own.
	 */
	private static function queue_resume(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + self::RESUME_DELAY, self::HOOK );
		}
	}

	/**
	 * The scheduled continuation: resume only, never start.
	 *
	 * A run nobody has begun is left alone, for the same reason the page
	 * seeder's background sync only ever updates: a plugin that manufactures
	 * 365 posts on a site because a cron tick happened is a plugin nobody
	 * trusts.
	 */
	public static function run_background(): void {
		$state = self::stored();

		if ( '' === $state['started'] || self::is_complete( $state ) || ! self::is_shipped( $state ) ) {
			return;
		}

		self::run_slice();
	}

	/* ---------------------------------------------------------------------
	 * Readiness
	 * ------------------------------------------------------------------- */

	/**
	 * The readiness row for the shipped library.
	 *
	 * Loud on purpose when nothing is installed: this is the single thing
	 * standing between a deploy and a working game, and it is invisible from
	 * every other screen — the pool row above merely says zero, which reads
	 * like a content backlog rather than a button nobody pressed.
	 *
	 * @param int $pool Published STC pool size, so the row can tell an empty
	 *                  site from one whose charts came from the importer.
	 * @return array{0:string,1:string,2:string}
	 */
	public static function readiness_row( int $pool ): array {
		$state = self::stored();
		$label = __( 'Shipped scenario library', 'hti-games' );

		if ( self::is_shipped( $state ) && self::is_complete( $state ) && $state['failed'] > 0 ) {
			// Finished walking, but with holes in it. Not 'ok': a library
			// short of the charts it says it has wraps sooner than the day
			// count on the page claims, and nobody would ever look again.
			return array(
				'warn',
				$label,
				sprintf(
					/* translators: 1: scenarios that failed, 2: library size. */
					__( 'Installed with %1$d of %2$d missing — they could not be built and were stepped over. "Retry the scenarios that failed" in the panel below walks the library again and fills only the holes.', 'hti-games' ),
					(int) $state['failed'],
					(int) $state['count']
				),
			);
		}

		if ( self::is_shipped( $state ) && self::is_complete( $state ) ) {
			return array(
				'ok',
				$label,
				sprintf(
					/* translators: 1: scenarios created, 2: scenarios skipped, 3: run seed, 4: library version. */
					__( 'Installed: %1$d scenarios created and %2$d already present (seed %3$d, version %4$d). Nothing to run on a deploy — the library is reproduced from its seed, not copied.', 'hti-games' ),
					(int) $state['created'],
					(int) $state['skipped'],
					(int) $state['seed'],
					(int) $state['version']
				),
			);
		}

		if ( self::is_shipped( $state ) && $state['done'] > 0 ) {
			return array(
				'warn',
				$label,
				sprintf(
					/* translators: 1: scenarios done, 2: scenarios in the library, 3: percentage. */
					__( 'Half-installed: %1$d of %2$d (%3$d%%). It resumes where it stopped — press "Continue installing" in the panel below until it reports done.', 'hti-games' ),
					(int) $state['done'],
					(int) $state['count'],
					self::percent( $state )
				),
			);
		}

		if ( $pool > 0 ) {
			return array(
				'warn',
				$label,
				sprintf(
					/* translators: 1: published scenarios, 2: scenarios in the shipped library. */
					__( 'Not installed. The game has %1$d published scenarios from elsewhere, so it works — installing the shipped library would add up to %2$d generated ones on top.', 'hti-games' ),
					$pool,
					(int) Config::LIBRARY_COUNT
				),
			);
		}

		return array(
			'fail',
			$label,
			sprintf(
				/* translators: %d: scenarios in the shipped library. */
				__( 'NOT INSTALLED, and no scenario is published from anywhere else — Survive the Charts has nothing to serve and every visitor is told so. One press of "Install the scenario library" in the panel below publishes %d charts and the game works. No shell, no CLI.', 'hti-games' ),
				(int) Config::LIBRARY_COUNT
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin surface
	 * ------------------------------------------------------------------- */

	/**
	 * The install panel on Settings → HTI Games.
	 */
	public static function render_panel(): void {
		$state    = self::stored();
		$shipped  = self::is_shipped( $state );
		$complete = $shipped && self::is_complete( $state );
		$started  = $shipped && $state['done'] > 0;
		$retry    = $complete && $state['failed'] > 0;
		?>
		<h2><?php esc_html_e( 'Install the scenario library', 'hti-games' ); ?></h2>

		<?php if ( isset( $_GET['hti_games_installed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p>
				<?php
				printf(
					/* translators: 1: scenarios created by this slice, 2: skipped, 3: done so far, 4: library size. */
					esc_html__( 'Installed %1$s new scenarios (%2$s already present). %3$s of %4$s done.', 'hti-games' ),
					esc_html( sanitize_key( wp_unslash( $_GET['hti_games_installed'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					esc_html( sanitize_key( wp_unslash( $_GET['hti_games_install_skipped'] ?? '0' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					esc_html( (string) (int) $state['done'] ),
					esc_html( (string) (int) $state['count'] )
				);
				?>
			</p></div>
		<?php endif; ?>

		<p>
			<?php
			printf(
				/* translators: 1: number of scenarios, 2: run seed. */
				esc_html__( 'The plugin ships %1$s charts for Survive the Charts as a seed rather than as a data file: seed %2$s reproduces exactly the same library on every install, forever, in a few bytes instead of a megabyte copied on every deploy. Every chart is generated, never real market data, and is stored saying so — the landing page keeps its "generated" wording.', 'hti-games' ),
				esc_html( (string) (int) Config::LIBRARY_COUNT ),
				esc_html( (string) (int) Config::LIBRARY_SEED )
			);
			?>
		</p>
		<p class="description"><?php esc_html_e( 'They are published as they install, so the game works the moment the run finishes. Re-running never duplicates anything: a chart is identified by its seed, and one already here is left exactly as you left it — including one you unpublished on purpose.', 'hti-games' ); ?></p>

		<?php if ( $started && ! $complete ) : ?>
			<p>
				<strong>
					<?php
					printf(
						/* translators: 1: scenarios done, 2: library size, 3: percentage. */
						esc_html__( 'In progress: %1$s of %2$s (%3$s%%).', 'hti-games' ),
						esc_html( (string) (int) $state['done'] ),
						esc_html( (string) (int) $state['count'] ),
						esc_html( (string) self::percent( $state ) )
					);
					?>
				</strong>
			</p>
			<p class="description"><?php esc_html_e( 'Each press installs as much as this host allows in one request and remembers where it stopped, so pressing Continue until it says done is all there is to it. A background continuation is queued too, but WP-Cron on this site is driven from outside and may be slow or off — the button is the part that always works.', 'hti-games' ); ?></p>
		<?php elseif ( $complete ) : ?>
			<p>
				<strong>
					<?php
					printf(
						/* translators: 1: scenarios created, 2: scenarios already present. */
						esc_html__( 'Done: %1$s created, %2$s already present.', 'hti-games' ),
						esc_html( (string) (int) $state['created'] ),
						esc_html( (string) (int) $state['skipped'] )
					);
					?>
				</strong>
			</p>
		<?php elseif ( $shipped ) : ?>
			<p class="description"><?php esc_html_e( 'Not installed on this site yet.', 'hti-games' ); ?></p>
		<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: the previously installed seed, 2: its size, 3: the shipped seed, 4: the shipped size. */
					esc_html__( 'A different library was installed here (seed %1$s, %2$s scenarios). This build ships seed %3$s with %4$s. Installing adds the new one alongside the old charts; nothing already stored is touched or rewritten.', 'hti-games' ),
					esc_html( (string) (int) $state['seed'] ),
					esc_html( (string) (int) $state['count'] ),
					esc_html( (string) (int) Config::LIBRARY_SEED ),
					esc_html( (string) (int) Config::LIBRARY_COUNT )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $state['error'] ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				printf(
					/* translators: 1: number of failures, 2: the last error message. */
					esc_html__( '%1$s scenario(s) could not be built and were stepped over. Last reason: %2$s', 'hti-games' ),
					esc_html( (string) (int) $state['failed'] ),
					esc_html( $state['error'] )
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php
		if ( ! $complete || $retry ) :
			if ( $retry ) {
				$label = __( 'Retry the scenarios that failed', 'hti-games' );
			} elseif ( $started ) {
				$label = __( 'Continue installing', 'hti-games' );
			} else {
				$label = __( 'Install the scenario library', 'hti-games' );
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hti_games_install_library" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<?php submit_button( $label, 'primary', 'submit', false ); ?>
			</form>
			<?php
		endif;
		?>
		<?php
	}

	/**
	 * Handle the install button.
	 */
	public static function handle_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hti-games' ) );
		}
		check_admin_referer( self::NONCE );

		$before = self::stored();

		// Two presses mean "walk the list again": one on a run belonging to
		// some other library, and one on a finished run that left holes —
		// there everything that succeeded is skipped on a dedupe query and
		// only the failures are rebuilt. Every other press resumes, because
		// re-walking a healthy library is 365 queries to discover that
		// nothing is missing.
		$restart = ! self::is_shipped( $before ) || ( self::is_complete( $before ) && $before['failed'] > 0 );
		$after   = self::run_slice( $restart );

		// A restart begins the tallies again, so the difference between them
		// would be negative; what this press did IS what the new run has done.
		$created = $restart ? (int) $after['created'] : (int) $after['created'] - (int) $before['created'];
		$skipped = $restart ? (int) $after['skipped'] : (int) $after['skipped'] - (int) $before['skipped'];

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                      => Settings::PAGE,
					'hti_games_installed'       => (string) max( 0, $created ),
					'hti_games_install_skipped' => (string) max( 0, $skipped ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
