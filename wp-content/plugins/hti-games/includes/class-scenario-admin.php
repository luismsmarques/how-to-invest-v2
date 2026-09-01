<?php
/**
 * The scenario editor: a meta box, five list columns and a bulk publish.
 *
 * Deliberately plain. Scenarios are generated in batches by STC_Generator or
 * cut from real series by Importer, so this screen is a review queue rather
 * than an authoring tool: the columns exist to answer "is this batch fit to
 * publish?" at a glance — is the mix of classes right, is anything still
 * missing its Portuguese lesson, is this batch real data or generated — and
 * the bulk action exists so that answering "yes" is one click for thirty rows
 * instead of thirty.
 *
 * The candles themselves are not editable here. Hand-tuning an OHLC series in
 * a textarea would break the checksum that makes an import idempotent, and a
 * chart nudged by hand is no longer the real data the pool is claiming to be.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Meta box, list columns and bulk publish for `hti_stc_scenario`.
 */
class Scenario_Admin {

	/**
	 * Nonce action/field for the meta box.
	 */
	private const NONCE_ACTION = 'hti_games_scenario_save';
	private const NONCE_FIELD  = 'hti_games_scenario_nonce';

	/**
	 * Wire up the box, the save handler, the columns and the bulk action.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_' . Config::CPT_SCENARIO, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . Config::CPT_SCENARIO, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . Config::CPT_SCENARIO . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Config::CPT_SCENARIO . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . Config::CPT_SCENARIO, array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . Config::CPT_SCENARIO, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Pure.
	 * ------------------------------------------------------------------- */

	/**
	 * Difficulty, derived from the scenario class rather than stored.
	 *
	 * There is no `hti_stc_difficulty` meta key on purpose: difficulty is not
	 * an independent judgement, it is what the class already says. A separate
	 * field would be a second opinion that can disagree with the first.
	 *
	 * @param string $class One of CPT::SCENARIO_CLASSES.
	 */
	public static function difficulty( string $class ): string {
		return match ( $class ) {
			'reasonable' => __( 'Easy — the chart reads the way it behaves', 'hti-games' ),
			'ambiguous'  => __( 'Medium — it could go either way', 'hti-games' ),
			'trap'       => __( 'Hard — the obvious read is the wrong one', 'hti-games' ),
			default      => __( 'Unclassified', 'hti-games' ),
		};
	}

	/* ---------------------------------------------------------------------
	 * Meta box.
	 * ------------------------------------------------------------------- */

	/**
	 * Register the box.
	 */
	public static function add_box(): void {
		add_meta_box(
			'hti-games-scenario',
			__( 'Scenario', 'hti-games' ),
			array( __CLASS__, 'render' ),
			Config::CPT_SCENARIO,
			'normal',
			'high'
		);
	}

	/**
	 * Render the fields.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$get = static fn( string $key ): string => (string) get_post_meta( $post->ID, $key, true );

		$ticks = json_decode( $get( 'hti_stc_ticks' ), true );
		$count = is_array( $ticks ) ? count( $ticks ) : 0;

		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html__( 'Series:', 'hti-games' ),
			esc_html(
				sprintf(
					/* translators: 1: candle count, 2: visible candles, 3: outcome candles, 4: tick scale. */
					__( '%1$d candles (%2$d visible, %3$d outcome), scale %4$d.', 'hti-games' ),
					$count,
					(int) $get( 'hti_stc_visible' ),
					(int) $get( 'hti_stc_outcome' ),
					(int) $get( 'hti_stc_scale' )
				)
			)
		);

		echo '<p><label for="hti_stc_class"><strong>' . esc_html__( 'Class', 'hti-games' ) . '</strong></label><br /><select id="hti_stc_class" name="hti_stc_class">';
		printf( '<option value="">%s</option>', esc_html__( '— unclassified —', 'hti-games' ) );
		foreach ( CPT::SCENARIO_CLASSES as $class ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $class ),
				selected( $get( 'hti_stc_class' ), $class, false )
			);
		}
		echo '</select><br /><span class="description">' . esc_html__( 'Reasonable, ambiguous or trap. This is also what the list screen calls difficulty.', 'hti-games' ) . '</span></p>';

		printf(
			'<p><label><input type="checkbox" name="hti_stc_real" value="1" %1$s /> <strong>%2$s</strong></label><br /><span class="description">%3$s</span></p>',
			checked( '1' === $get( 'hti_stc_real' ), true, false ),
			esc_html__( 'Real market data', 'hti-games' ),
			esc_html__( 'The landing page may only call the charts real while every published scenario has this ticked. Nothing else grants that claim.', 'hti-games' )
		);

		printf(
			'<p><label><input type="checkbox" name="hti_stc_pass_right" value="1" %1$s /> <strong>%2$s</strong></label><br /><span class="description">%3$s</span></p>',
			checked( '1' === $get( 'hti_stc_pass_right' ), true, false ),
			esc_html__( 'Passing was the right call', 'hti-games' ),
			esc_html__( 'Some days there is no trade. A pool where this is never true teaches the opposite of the lesson.', 'hti-games' )
		);

		$text = static function ( string $key, string $label, string $help = '' ) use ( $get ): void {
			printf(
				'<p><label for="%1$s"><strong>%2$s</strong></label><br /><input type="text" class="widefat" id="%1$s" name="%1$s" value="%3$s" />%4$s</p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $get( $key ) ),
				'' !== $help ? '<span class="description">' . esc_html( $help ) . '</span>' : ''
			);
		};

		$text( 'hti_stc_symbol', __( 'Symbol (admin only)', 'hti-games' ), __( 'Provenance for us. Never emitted anywhere on the front end: naming the instrument would break invariant 2 and hand the player the answer.', 'hti-games' ) );
		$text( 'hti_stc_source', __( 'Source', 'hti-games' ), __( 'Where the series came from, e.g. "import:eurusd-2019.csv@2026-08-30".', 'hti-games' ) );
		$text( 'hti_stc_seed', __( 'Seed', 'hti-games' ), __( 'The generator seed, so a scenario can be reproduced exactly.', 'hti-games' ) );
		$text( 'hti_stc_slot', __( 'Pinned slot (optional)', 'hti-games' ), __( 'A rotation position, or an absolute day index. 0 = unpinned.', 'hti-games' ) );

		foreach ( array( 'hti_stc_lesson_en' => __( 'Lesson (EN)', 'hti-games' ), 'hti_stc_lesson_pt' => __( 'Lesson (PT)', 'hti-games' ) ) as $key => $label ) {
			printf(
				'<p><label for="%1$s"><strong>%2$s</strong></label><br /><textarea class="widefat" rows="3" id="%1$s" name="%1$s">%3$s</textarea></p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_textarea( $get( $key ) )
			);
		}
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Both languages are required before publishing: the site runs Portuguese with no fallback, so a missing PT lesson renders silently in English.', 'hti-games' )
		);
	}

	/**
	 * Persist the editable fields.
	 *
	 * The candle series, its scale, the checksum and the visible/outcome
	 * counts are absent by design — they are written once by the importer or
	 * the generator and are not a thing a form may change.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || Config::CPT_SCENARIO !== $post->post_type ) {
			return;
		}

		// Every value below is re-sanitized by the registered meta callback in
		// CPT; this pass is about shape, not about trust.
		foreach ( array( 'hti_stc_class', 'hti_stc_symbol', 'hti_stc_source', 'hti_stc_seed', 'hti_stc_slot', 'hti_stc_lesson_en', 'hti_stc_lesson_pt' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) ) );
			}
		}

		update_post_meta( $post_id, 'hti_stc_real', isset( $_POST['hti_stc_real'] ) ? '1' : '0' );
		update_post_meta( $post_id, 'hti_stc_pass_right', isset( $_POST['hti_stc_pass_right'] ) ? '1' : '0' );
	}

	/* ---------------------------------------------------------------------
	 * List table.
	 * ------------------------------------------------------------------- */

	/**
	 * Add the review columns, keeping title first and date last.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public static function columns( $columns ) {
		$columns = is_array( $columns ) ? $columns : array();
		$date    = $columns['date'] ?? null;
		unset( $columns['date'] );

		$columns['hti_class']      = __( 'Class', 'hti-games' );
		$columns['hti_difficulty'] = __( 'Difficulty', 'hti-games' );
		$columns['hti_real']       = __( 'Data', 'hti-games' );
		$columns['hti_seed']       = __( 'Seed', 'hti-games' );
		$columns['hti_lesson']     = __( 'Lesson EN/PT', 'hti-games' );

		if ( null !== $date ) {
			$columns['date'] = $date;
		}

		return $columns;
	}

	/**
	 * Render one review column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 */
	public static function column( $column, $post_id ): void {
		$post_id = (int) $post_id;
		$get     = static fn( string $key ): string => (string) get_post_meta( $post_id, $key, true );

		switch ( $column ) {
			case 'hti_class':
				echo esc_html( '' !== $get( 'hti_stc_class' ) ? $get( 'hti_stc_class' ) : '—' );
				break;

			case 'hti_difficulty':
				echo esc_html( self::difficulty( $get( 'hti_stc_class' ) ) );
				break;

			case 'hti_real':
				echo esc_html( '1' === $get( 'hti_stc_real' ) ? __( 'Real', 'hti-games' ) : __( 'Generated', 'hti-games' ) );
				break;

			case 'hti_seed':
				echo '<code>' . esc_html( '' !== $get( 'hti_stc_seed' ) ? $get( 'hti_stc_seed' ) : '—' ) . '</code>';
				break;

			case 'hti_lesson':
				$en = '' !== trim( $get( 'hti_stc_lesson_en' ) );
				$pt = '' !== trim( $get( 'hti_stc_lesson_pt' ) );
				// The missing half is what this column is for, so it is named
				// rather than shown as a tick nobody reads.
				if ( $en && $pt ) {
					echo esc_html__( 'EN + PT', 'hti-games' );
				} elseif ( $en ) {
					echo '<strong>' . esc_html__( 'PT missing', 'hti-games' ) . '</strong>';
				} elseif ( $pt ) {
					echo '<strong>' . esc_html__( 'EN missing', 'hti-games' ) . '</strong>';
				} else {
					echo '<strong>' . esc_html__( 'both missing', 'hti-games' ) . '</strong>';
				}
				break;
		}
	}

	/* ---------------------------------------------------------------------
	 * Bulk publish.
	 * ------------------------------------------------------------------- */

	/**
	 * Offer "Publish" in the bulk menu.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @return array<string,string>
	 */
	public static function bulk_actions( $actions ) {
		$actions                      = is_array( $actions ) ? $actions : array();
		$actions['hti_games_publish'] = __( 'Publish', 'hti-games' );

		return $actions;
	}

	/**
	 * Publish the selected scenarios.
	 *
	 * WordPress verifies the bulk-action nonce before this filter runs; the
	 * per-post capability check is ours, because a bulk action is still a
	 * request a contributor can craft.
	 *
	 * @param string         $redirect Redirect URL.
	 * @param string         $action   Action key.
	 * @param array<int,int> $ids      Selected post ids.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'hti_games_publish' !== $action ) {
			return $redirect;
		}

		$done = 0;
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( ! current_user_can( 'publish_post', $id ) ) {
				continue;
			}
			$result = wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				),
				true
			);
			if ( ! is_wp_error( $result ) ) {
				++$done;
			}
		}

		// The pool cache is keyed to the post type, and a bulk publish is
		// exactly the moment it must not be stale.
		Library::flush( Config::GAME_STC );

		return add_query_arg( 'hti_games_published', $done, $redirect );
	}

	/**
	 * Report what the bulk action did.
	 */
	public static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a count back off our own redirect; nothing is changed here.
		if ( ! isset( $_GET['hti_games_published'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$done = absint( wp_unslash( $_GET['hti_games_published'] ) );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of scenarios published. */
					_n( '%d scenario published.', '%d scenarios published.', $done, 'hti-games' ),
					$done
				)
			)
		);
	}
}
