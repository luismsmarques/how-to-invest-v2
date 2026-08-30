<?php
/**
 * The Reveal's editor, and the publish gate that makes the exemption legal.
 *
 * CLAUDE.md invariant 2 forbids naming instruments, funds, brokers or
 * companies anywhere in the engine's or the LLM's output. The Reveal has a
 * narrow written exemption — it names one real company, at a real year, after
 * the player has already decided — and that exemption is conditional. It holds
 * only while every case is (a) about a period at least
 * Config::REVEAL_MIN_AGE_YEARS in the past, so it is history rather than a
 * view on a listed company today, and (b) sourced and verified, so that every
 * number shown next to that name can be traced to a document.
 *
 * A condition that is only written down is not a condition. So it is enforced
 * three times, in three places that fail independently:
 *
 *  1. publishable() — pure, unit-tested, and the only definition of "may be
 *     published" in the plugin.
 *  2. A `wp_insert_post_data` filter that forces post_status back to `draft`
 *     when publish is requested and publishable() says no, naming the exact
 *     field that is missing. Not a warning an editor can click past.
 *  3. Library::published_ids(), which will not serve an unverified case even
 *     if one somehow reaches `publish`.
 *
 * And verification decays on purpose: ticking "verified" is a statement about
 * a specific set of numbers, so changing any of those numbers clears it. A
 * verification that survives an edit to the thing it verified is theatre.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

/**
 * Meta box, publish gate and verification decay for `hti_reveal_case`.
 */
class Case_Admin {

	/**
	 * Nonce action/field for the meta box.
	 */
	private const NONCE_ACTION = 'hti_games_case_save';
	private const NONCE_FIELD  = 'hti_games_case_nonce';

	/**
	 * Where a blocked publish leaves its explanation for the next page load.
	 */
	private const NOTICE_PREFIX = 'hti_games_case_gate_';

	/**
	 * The three fields whose value is what "verified" is a statement about.
	 */
	public const VERIFIED_FIELDS = array( 'hti_rev_return_5y_bp', 'hti_rev_index_return_5y_bp', 'hti_rev_year' );

	/**
	 * Wire up the box, the save handler, the gate and the notice.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_' . Config::CPT_CASE, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . Config::CPT_CASE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'gate' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Pure — the gate itself, unit-tested in tests/test-case-gate.php.
	 * ------------------------------------------------------------------- */

	/**
	 * Whether a case may be published.
	 *
	 * @param array<string,mixed> $meta Case meta, key => value.
	 * @param int|null            $now  Unix timestamp; defaults to now.
	 */
	public static function publishable( array $meta, ?int $now = null ): bool {
		return array() === self::missing( $meta, $now );
	}

	/**
	 * Everything that stops a case from being published, as field keys.
	 *
	 * Returns keys rather than sentences so the rule stays testable and the
	 * wording stays in one place (labels()).
	 *
	 * @param array<string,mixed> $meta Case meta, key => value.
	 * @param int|null            $now  Unix timestamp; defaults to now.
	 * @return array<int,string>
	 */
	public static function missing( array $meta, ?int $now = null ): array {
		$out = array();

		if ( ! self::is_url( (string) ( $meta['hti_rev_source_url'] ?? '' ) ) ) {
			$out[] = 'hti_rev_source_url';
		}

		if ( '1' !== (string) ( $meta['hti_rev_verified'] ?? '' ) ) {
			$out[] = 'hti_rev_verified';
		}

		foreach ( array( 'hti_rev_return_5y_bp', 'hti_rev_index_return_5y_bp' ) as $key ) {
			// A return of exactly 0 bp is a real, publishable answer, so this
			// is an "is it set" test and never an empty() one.
			$value = isset( $meta[ $key ] ) ? trim( (string) $meta[ $key ] ) : '';
			if ( '' === $value || 1 !== preg_match( '/^-?\d+$/', $value ) ) {
				$out[] = $key;
			}
		}

		$year    = (int) ( $meta['hti_rev_year'] ?? 0 );
		$current = (int) gmdate( 'Y', $now ?? time() );
		if ( $year <= 0 || $year > $current - Config::REVEAL_MIN_AGE_YEARS ) {
			$out[] = 'hti_rev_year';
		}

		return $out;
	}

	/**
	 * Whether an edit invalidates an existing verification.
	 *
	 * True when any of the three verified fields had a value and that value is
	 * being changed. Compared numerically, so "0800" and "800" do not read as
	 * an edit while a genuinely different number always does.
	 *
	 * A field that was empty and is now being filled is deliberately NOT a
	 * change: that is a new case being written, and the tick in the same
	 * submission is verifying the numbers as submitted. There is no earlier
	 * verification to invalidate, and treating it as one would make every
	 * first publish fail with a message about a field the editor just filled.
	 *
	 * @param array<string,mixed> $old Stored meta.
	 * @param array<string,mixed> $new Incoming meta.
	 */
	public static function clears_verification( array $old, array $new ): bool {
		foreach ( self::VERIFIED_FIELDS as $key ) {
			if ( ! array_key_exists( $key, $new ) ) {
				// Not submitted at all (a partial update) is not an edit.
				continue;
			}
			$before = array_key_exists( $key, $old ) ? trim( (string) $old[ $key ] ) : '';
			if ( '' === $before ) {
				continue;
			}
			if ( (int) $before !== (int) $new[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A usable http(s) source URL. Pure — no WordPress.
	 *
	 * @param string $url Candidate.
	 */
	public static function is_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || 1 !== preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		// The scheme is checked by hand rather than left to FILTER_VALIDATE_URL
		// alone, which happily validates javascript: and data: URLs — and this
		// value ends up as an href on the reveal screen.
		return false !== filter_var( $url, FILTER_VALIDATE_URL );
	}

	/**
	 * Human labels for the fields the gate can complain about.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'hti_rev_source_url'         => __( 'Source URL (must be a full http(s) address)', 'hti-games' ),
			'hti_rev_verified'           => __( 'Verified (tick it only after checking the numbers against the source)', 'hti-games' ),
			'hti_rev_return_5y_bp'       => __( 'Five-year return, in basis points', 'hti-games' ),
			'hti_rev_index_return_5y_bp' => __( 'Index five-year return, in basis points', 'hti-games' ),
			'hti_rev_year'               => sprintf(
				/* translators: %d: minimum age of a case, in years. */
				__( 'Year (must be at least %d years in the past)', 'hti-games' ),
				Config::REVEAL_MIN_AGE_YEARS
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * The gate.
	 * ------------------------------------------------------------------- */

	/**
	 * Force an unpublishable case back to draft.
	 *
	 * Runs on `wp_insert_post_data`, i.e. before the post row is written and
	 * before `save_post` stores the meta — so the meta it judges is the meta
	 * that is *about* to be stored (the submitted form), falling back to what
	 * is already stored for anything the form did not send. It also applies
	 * the verification-decay rule itself, because otherwise an editor could
	 * change the return figure and publish in the same request: the gate would
	 * see the still-ticked checkbox and let it through a moment before save()
	 * un-ticked it.
	 *
	 * @param array<string,mixed> $data    Sanitized post data, about to be written.
	 * @param array<string,mixed> $postarr Raw post array.
	 * @return array<string,mixed>
	 */
	public static function gate( $data, $postarr ) {
		if ( ! is_array( $data ) || Config::CPT_CASE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		if ( 'publish' !== ( $data['post_status'] ?? '' ) ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$stored  = self::stored_meta( $post_id );
		$meta    = array_merge( $stored, self::submitted_meta() );

		if ( self::clears_verification( $stored, $meta ) ) {
			$meta['hti_rev_verified'] = '0';
		}

		$missing = self::missing( $meta );
		if ( array() === $missing ) {
			return $data;
		}

		$data['post_status'] = 'draft';
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), $missing, 60 );

		return $data;
	}

	/**
	 * Explain a blocked publish, once.
	 */
	public static function notice(): void {
		$key     = self::NOTICE_PREFIX . get_current_user_id();
		$missing = get_transient( $key );
		if ( ! is_array( $missing ) || array() === $missing ) {
			return;
		}
		delete_transient( $key );

		$labels = self::labels();
		echo '<div class="notice notice-error"><p><strong>';
		esc_html_e( 'This case was kept as a draft: The Reveal never serves an unverified case.', 'hti-games' );
		echo '</strong></p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $missing as $field ) {
			printf( '<li>%s</li>', esc_html( $labels[ $field ] ?? (string) $field ) );
		}
		echo '</ul></div>';
	}

	/* ---------------------------------------------------------------------
	 * Meta box.
	 * ------------------------------------------------------------------- */

	/**
	 * Register the box.
	 */
	public static function add_box(): void {
		add_meta_box(
			'hti-games-case',
			__( 'Case dossier', 'hti-games' ),
			array( __CLASS__, 'render' ),
			Config::CPT_CASE,
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

		$meta = self::stored_meta( $post->ID );
		$get  = static fn( string $key ): string => (string) ( $meta[ $key ] ?? '' );

		$text = static function ( string $key, string $label, string $help = '' ) use ( $get ): void {
			printf(
				'<p><label for="%1$s"><strong>%2$s</strong></label><br /><input type="text" class="widefat" id="%1$s" name="%1$s" value="%3$s" />%4$s</p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $get( $key ) ),
				'' !== $help ? '<span class="description">' . esc_html( $help ) . '</span>' : ''
			);
		};

		$area = static function ( string $key, string $label, string $help = '' ) use ( $get ): void {
			printf(
				'<p><label for="%1$s"><strong>%2$s</strong></label><br /><textarea class="widefat" rows="3" id="%1$s" name="%1$s">%3$s</textarea>%4$s</p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_textarea( $get( $key ) ),
				'' !== $help ? '<span class="description">' . esc_html( $help ) . '</span>' : ''
			);
		};

		echo '<h4>' . esc_html__( 'The answer (never sent to the client before a decision is recorded)', 'hti-games' ) . '</h4>';
		$text( 'hti_rev_company', __( 'Company', 'hti-games' ), __( 'The real name. Shown only on the reveal screen, after the decision.', 'hti-games' ) );
		$text( 'hti_rev_year', __( 'Year', 'hti-games' ), __( 'The year the dossier describes.', 'hti-games' ) );
		$text( 'hti_rev_return_5y_bp', __( 'Five-year return (basis points)', 'hti-games' ), __( 'Signed: -8000 is a 80% fall. 10000 bp = 100%.', 'hti-games' ) );
		$text( 'hti_rev_index_return_5y_bp', __( 'Index five-year return (basis points)', 'hti-games' ), __( 'What the broad index did over the same five years.', 'hti-games' ) );

		echo '<h4>' . esc_html__( 'The dossier the player sees', 'hti-games' ) . '</h4>';
		$text( 'hti_rev_sector_en', __( 'Sector (EN)', 'hti-games' ) );
		$text( 'hti_rev_sector_pt', __( 'Sector (PT)', 'hti-games' ) );
		$text( 'hti_rev_revenue_band_en', __( 'Revenue band (EN)', 'hti-games' ), __( 'A band, never a figure a search engine can resolve to one company.', 'hti-games' ) );
		$text( 'hti_rev_revenue_band_pt', __( 'Revenue band (PT)', 'hti-games' ) );

		self::render_fundamentals( $get( 'hti_rev_fundamentals' ) );
		self::render_headlines( $get( 'hti_rev_headlines' ) );

		echo '<h4>' . esc_html__( 'After the reveal', 'hti-games' ) . '</h4>';
		$area( 'hti_rev_context_en', __( 'What happened next (EN)', 'hti-games' ) );
		$area( 'hti_rev_context_pt', __( 'What happened next (PT)', 'hti-games' ) );
		$area( 'hti_rev_lesson_en', __( 'Lesson (EN)', 'hti-games' ) );
		$area( 'hti_rev_lesson_pt', __( 'Lesson (PT)', 'hti-games' ) );

		echo '<h4>' . esc_html__( 'Sourcing — the case cannot be published without this', 'hti-games' ) . '</h4>';
		$text( 'hti_rev_source_url', __( 'Source URL', 'hti-games' ), __( 'The document the numbers came from: an annual report, a regulator filing, an index factsheet.', 'hti-games' ) );
		$text( 'hti_rev_source_label', __( 'Source label', 'hti-games' ), __( 'How it is credited on the reveal screen.', 'hti-games' ) );
		$text( 'hti_rev_source_accessed', __( 'Accessed (YYYY-MM-DD)', 'hti-games' ) );

		printf(
			'<p><label><input type="checkbox" name="hti_rev_verified" value="1" %1$s /> <strong>%2$s</strong></label><br /><span class="description">%3$s</span></p>',
			checked( '1' === $get( 'hti_rev_verified' ), true, false ),
			esc_html__( 'Verified against the source', 'hti-games' ),
			esc_html__( 'Editing the year or either return figure clears this automatically — the tick is a statement about those numbers, not about the case in general.', 'hti-games' )
		);

		if ( '' !== $get( 'hti_rev_verified_at' ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: user login, 2: UTC timestamp. */
						__( 'Verified by %1$s on %2$s UTC.', 'hti-games' ),
						$get( 'hti_rev_verified_by' ),
						$get( 'hti_rev_verified_at' )
					)
				)
			);
		}

		$text( 'hti_rev_slot', __( 'Pinned slot (optional)', 'hti-games' ), __( 'A rotation position, or an absolute day index to line the case up with a date. 0 = unpinned.', 'hti-games' ) );
	}

	/**
	 * The six fundamentals rows.
	 *
	 * @param string $json Stored JSON.
	 */
	private static function render_fundamentals( string $json ): void {
		$rows = json_decode( $json, true );
		$rows = is_array( $rows ) ? $rows : array();

		echo '<h4>' . esc_html__( 'Fundamentals (six rows, each against its sector average)', 'hti-games' ) . '</h4>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'key', 'label EN', 'label PT', 'value EN', 'value PT', 'sector avg EN', 'sector avg PT', 'tint' ) as $head ) {
			printf( '<th>%s</th>', esc_html( $head ) );
		}
		echo '</tr></thead><tbody>';

		for ( $i = 0; $i < 6; $i++ ) {
			$row = is_array( $rows[ $i ] ?? null ) ? $rows[ $i ] : array();
			echo '<tr>';
			foreach ( array( 'key', 'label_en', 'label_pt', 'value_en', 'value_pt', 'sector_avg_en', 'sector_avg_pt' ) as $field ) {
				printf(
					'<td><input type="text" name="hti_rev_f[%1$d][%2$s]" value="%3$s" style="width:100%%" /></td>',
					(int) $i,
					esc_attr( $field ),
					esc_attr( (string) ( $row[ $field ] ?? '' ) )
				);
			}
			echo '<td><select name="hti_rev_f[' . (int) $i . '][tint]">';
			foreach ( CPT::TINTS as $tint ) {
				printf(
					'<option value="%1$s" %2$s>%1$s</option>',
					esc_attr( $tint ),
					selected( (string) ( $row['tint'] ?? 'warn' ), $tint, false )
				);
			}
			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The three period headlines.
	 *
	 * @param string $json Stored JSON.
	 */
	private static function render_headlines( string $json ): void {
		$rows = json_decode( $json, true );
		$rows = is_array( $rows ) ? $rows : array();

		echo '<h4>' . esc_html__( 'Headlines from the period (three, in both languages)', 'hti-games' ) . '</h4>';
		for ( $i = 0; $i < 3; $i++ ) {
			$row = is_array( $rows[ $i ] ?? null ) ? $rows[ $i ] : array();
			printf(
				'<p><input type="text" class="widefat" name="hti_rev_h[%1$d][en]" value="%2$s" placeholder="EN" /><input type="text" class="widefat" name="hti_rev_h[%1$d][pt]" value="%3$s" placeholder="PT" /></p>',
				(int) $i,
				esc_attr( (string) ( $row['en'] ?? '' ) ),
				esc_attr( (string) ( $row['pt'] ?? '' ) )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Persistence.
	 * ------------------------------------------------------------------- */

	/**
	 * Persist the dossier, applying the verification-decay rule.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! self::nonce_ok() ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || Config::CPT_CASE !== $post->post_type ) {
			return;
		}

		$stored   = self::stored_meta( $post_id );
		$incoming = self::submitted_meta();

		// The decay rule, applied before anything is written: whoever changes
		// one of the three verified numbers un-verifies the case, including
		// the person who verified it in the first place.
		if ( self::clears_verification( $stored, $incoming ) ) {
			$incoming['hti_rev_verified'] = '0';
		}

		$was_verified = '1' === (string) ( $stored['hti_rev_verified'] ?? '' );
		$is_verified  = '1' === (string) ( $incoming['hti_rev_verified'] ?? '' );

		if ( $is_verified && ! $was_verified ) {
			$user                            = wp_get_current_user();
			$incoming['hti_rev_verified_by'] = (string) $user->user_login;
			$incoming['hti_rev_verified_at'] = gmdate( 'Y-m-d H:i:s' );
		} elseif ( ! $is_verified ) {
			$incoming['hti_rev_verified_by'] = '';
			$incoming['hti_rev_verified_at'] = '';
		}

		foreach ( $incoming as $key => $value ) {
			// update_post_meta runs the registered sanitize_callback (CPT),
			// so this is the only place the values need to be assembled.
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Everything currently stored for a case.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,string>
	 */
	public static function stored_meta( int $post_id ): array {
		$out = array();
		if ( $post_id <= 0 ) {
			return $out;
		}
		foreach ( array_keys( CPT::case_meta() ) as $key ) {
			$out[ $key ] = (string) get_post_meta( $post_id, $key, true );
		}

		return $out;
	}

	/**
	 * The submitted dossier, sanitized, or an empty array without a nonce.
	 *
	 * Read by both save() and gate(); the nonce check is inside rather than at
	 * the call sites so there is no path that reads $_POST without it.
	 *
	 * @return array<string,string>
	 */
	private static function submitted_meta(): array {
		if ( ! self::nonce_ok() ) {
			return array();
		}

		$out = array();

		foreach ( array_keys( CPT::case_meta() ) as $key ) {
			if ( in_array( $key, array( 'hti_rev_fundamentals', 'hti_rev_headlines', 'hti_rev_verified', 'hti_rev_verified_by', 'hti_rev_verified_at' ), true ) ) {
				continue;
			}
			if ( isset( $_POST[ $key ] ) ) {
				$out[ $key ] = sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) );
			}
		}

		$out['hti_rev_verified'] = isset( $_POST['hti_rev_verified'] ) ? '1' : '0';

		if ( isset( $_POST['hti_rev_f'] ) && is_array( $_POST['hti_rev_f'] ) ) {
			$rows = array();
			// Sanitized wholesale by CPT::san_fundamentals on write; every
			// value there goes through sanitize_text_field.
			foreach ( wp_unslash( $_POST['hti_rev_f'] ) as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( is_array( $row ) && '' !== trim( (string) ( $row['label_en'] ?? '' ) ) ) {
					$rows[] = array_map( 'strval', $row );
				}
			}
			$out['hti_rev_fundamentals'] = (string) wp_json_encode( $rows );
		}

		if ( isset( $_POST['hti_rev_h'] ) && is_array( $_POST['hti_rev_h'] ) ) {
			$rows = array();
			foreach ( wp_unslash( $_POST['hti_rev_h'] ) as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( is_array( $row ) && '' !== trim( (string) ( $row['en'] ?? '' ) ) ) {
					$rows[] = array(
						'en' => (string) ( $row['en'] ?? '' ),
						'pt' => (string) ( $row['pt'] ?? '' ),
					);
				}
			}
			$out['hti_rev_headlines'] = (string) wp_json_encode( $rows );
		}

		return $out;
	}

	/**
	 * Whether this request carries our meta box nonce.
	 */
	private static function nonce_ok(): bool {
		return isset( $_POST[ self::NONCE_FIELD ] )
			&& (bool) wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION );
	}
}
