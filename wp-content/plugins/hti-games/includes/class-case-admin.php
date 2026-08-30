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
 * ---------------------------------------------------------------------------
 * The door in the wall
 * ---------------------------------------------------------------------------
 *
 * All of the above is a gate, and a gate with no door is a wall. An editor who
 * opens a case sees a long form and no idea what finished looks like, tries to
 * publish, and is told no. So this file also carries the workflow that gets a
 * case through the gate, and every part of it is derived from missing() rather
 * than written a second time:
 *
 *  - checklist() — what is still open on THIS case, split into what blocks a
 *    publish (missing(), verbatim) and what merely makes the dossier readable.
 *    Shown before the editor tries, not after they are refused;
 *  - the research brief, rendered read-only at the top of the box, so the
 *    document to open is on the screen where the figures are typed;
 *  - a verification block that says who ticked it, when, and which three
 *    numbers the tick is a statement about — because an editor who does not
 *    know why the tick vanished concludes the software is broken;
 *  - queue()/sort_queue() — every unfinished case, closest to launchable
 *    first, on the settings screen, which is how an editorial lead sees how
 *    far The Reveal is from being servable at all;
 *  - a preview of one case as a player meets it, from the admin, before it is
 *    published. See render_preview_dossier() for what that reuses and for the
 *    one thing it cannot.
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
	 * The two halves of "the dossier is complete", as meta keys.
	 *
	 * Split in two because the checklist shows them as two rows and the gate
	 * has to agree with the checklist row by row: DOSSIER_FIELDS is the top of
	 * the file the player reads before deciding, AFTERMATH_FIELDS is what they
	 * read once the name is on the screen. Both are required of an
	 * illustrative case and of neither a verified one — see missing().
	 */
	public const DOSSIER_FIELDS   = array( 'hti_rev_sector_en', 'hti_rev_sector_pt', 'hti_rev_revenue_band_en', 'hti_rev_revenue_band_pt' );
	public const AFTERMATH_FIELDS = array( 'hti_rev_context_en', 'hti_rev_context_pt', 'hti_rev_lesson_en', 'hti_rev_lesson_pt' );

	/**
	 * How many fundamentals rows and headlines a finished dossier carries.
	 *
	 * The same ceilings CPT::san_fundamentals() and CPT::san_headlines() store
	 * to, so the checklist counts against what can actually be saved rather
	 * than against a second opinion about the shape of a dossier.
	 */
	public const FUNDAMENTALS = 6;
	public const HEADLINES    = 3;

	/**
	 * Meta keys a per-case research brief may arrive under.
	 *
	 * The brief — which company, which filing, which six figures to look for —
	 * is authored in class-seed-cases.php by a separate workstream, and this
	 * screen must not wait for it. The key is resolved at render time against
	 * CPT::case_meta(), the registry of record: the panel appears by itself the
	 * day the key is registered, and simply does not render before then.
	 * Ordered by preference; the first candidate that exists wins.
	 */
	public const BRIEF_KEYS = array( 'hti_rev_brief', 'hti_rev_research_brief', 'hti_rev_brief_en', 'hti_rev_plan' );

	/**
	 * Meta keys the case's teaching pattern may arrive under.
	 *
	 * Same guard, same reason. A pattern ("the story that ended badly", "the
	 * boring compounder") is what stops a library of twenty-four cases from
	 * being twenty-four of the same case, so the queue shows it — falling back
	 * to the sector, which is the closest thing the dossier already stores.
	 */
	public const PATTERN_KEYS = array( 'hti_rev_pattern', 'hti_rev_pattern_en', 'hti_rev_archetype' );

	/**
	 * The preview screen's page slug, under the Reveal cases menu.
	 */
	public const PREVIEW_PAGE = 'hti-games-case-preview';

	/**
	 * Hook suffix of the preview screen, captured at registration.
	 *
	 * Compared against rather than hard-coded: the suffix WordPress builds for
	 * a submenu of `edit.php?post_type=…` is a detail of core's, and an admin
	 * sheet that loads on the wrong screen because that detail changed is
	 * exactly the kind of bug nobody goes looking for.
	 *
	 * @var string
	 */
	private static string $preview_hook = '';

	/**
	 * Wire up the box, the save handler, the gate and the notice.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_' . Config::CPT_CASE, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . Config::CPT_CASE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'gate' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );

		// The workflow surfaces. The queue registers itself through the
		// settings screen's own extension point rather than by editing it, so
		// this feature owns every screen it puts a pixel on — and so that
		// removing this file removes all of them with it.
		add_action( 'admin_menu', array( __CLASS__, 'add_preview_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'hti_games_settings_panels', array( __CLASS__, 'render_panel' ) );
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
	 * What this case's figures are: 'illustrative' or 'verified'.
	 *
	 * Delegated to CPT::san_provenance so there is exactly one definition of
	 * the default in the plugin, and it is the one on the registry of record.
	 * An unset key, an empty string or anything unrecognised reads as
	 * 'verified' — the strict path, the one that wants a document and a tick.
	 * That direction is load-bearing: a case somebody typed into the admin
	 * before this field existed must fall INTO the source requirement, never
	 * out of it, because a default that fails open is how a gate stops being
	 * a gate.
	 *
	 * @param array<string,mixed> $meta Case meta.
	 */
	public static function provenance( array $meta ): string {
		return CPT::san_provenance( $meta['hti_rev_provenance'] ?? '' );
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
		$out          = array();
		$illustrative = 'illustrative' === self::provenance( $meta );

		if ( ! $illustrative ) {
			// The verified path, unchanged: a document somebody can open, and
			// a tick from the person who read the figures out of it.
			if ( ! self::is_url( (string) ( $meta['hti_rev_source_url'] ?? '' ) ) ) {
				$out[] = 'hti_rev_source_url';
			}

			if ( '1' !== (string) ( $meta['hti_rev_verified'] ?? '' ) ) {
				$out[] = 'hti_rev_verified';
			}
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

		if ( $illustrative ) {
			// An illustrative case has no document to fall back on, so what
			// holds it up is the dossier itself being whole. A hole in a
			// verified case is an editorial untidiness; a hole in this one is
			// the thing the player is looking at while being told the figures
			// were reconstructed to show a pattern — and half a pattern shows
			// nothing. Hence the completeness of the dossier is a publish
			// condition here and only here.
			foreach ( array_merge( self::DOSSIER_FIELDS, self::AFTERMATH_FIELDS ) as $key ) {
				if ( '' === trim( (string) ( $meta[ $key ] ?? '' ) ) ) {
					$out[] = $key;
				}
			}

			if ( self::FUNDAMENTALS > self::fundamentals_complete( (string) ( $meta['hti_rev_fundamentals'] ?? '' ) ) ) {
				$out[] = 'hti_rev_fundamentals';
			}

			if ( self::HEADLINES > self::headlines_complete( (string) ( $meta['hti_rev_headlines'] ?? '' ) ) ) {
				$out[] = 'hti_rev_headlines';
			}
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
	 * Pure — the completion checklist and the queue's ordering.
	 *
	 * The gate answers one question: may this be published? An editor needs
	 * the other one — what is left, and what does finished look like — and
	 * needs it before they press publish, because a form that only answers on
	 * submit teaches people to submit and find out. Everything below is
	 * derived from missing(), so the screen can never promise a case is ready
	 * for something the gate then refuses.
	 * ------------------------------------------------------------------- */

	/**
	 * How many of the six fundamentals rows are finished.
	 *
	 * A row counts only when every field the dossier renders is present in
	 * BOTH languages, and when it carries a key — because REST::fundamentals()
	 * drops a keyless row on the way to the player. A row that looks filled in
	 * the editor and is invisible in the game is exactly the failure this
	 * count exists to surface.
	 *
	 * @param string $json Stored fundamentals JSON.
	 */
	public static function fundamentals_complete( string $json ): int {
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$need = array( 'key', 'label_en', 'label_pt', 'value_en', 'value_pt', 'sector_avg_en', 'sector_avg_pt' );
		$done = 0;

		foreach ( array_slice( $rows, 0, self::FUNDAMENTALS ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$complete = true;
			foreach ( $need as $field ) {
				if ( '' === trim( (string) ( $row[ $field ] ?? '' ) ) ) {
					$complete = false;
					break;
				}
			}
			$done += $complete ? 1 : 0;
		}

		return $done;
	}

	/**
	 * How many of the three headlines exist in both languages.
	 *
	 * Both, never either. The site runs pt_PT_ao90 against pt_PT files with no
	 * fallback, so a headline written only in English is an empty quotation
	 * mark on half the traffic — and the headlines are the part of a dossier a
	 * player is most likely to repeat afterwards.
	 *
	 * @param string $json Stored headlines JSON.
	 */
	public static function headlines_complete( string $json ): int {
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$done = 0;
		foreach ( array_slice( $rows, 0, self::HEADLINES ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( '' !== trim( (string) ( $row['en'] ?? '' ) ) && '' !== trim( (string) ( $row['pt'] ?? '' ) ) ) {
				++$done;
			}
		}

		return $done;
	}

	/**
	 * How many of a set of meta keys carry anything at all.
	 *
	 * @param array<string,mixed> $meta Case meta.
	 * @param array<int,string>   $keys Keys to count.
	 */
	public static function filled( array $meta, array $keys ): int {
		$count = 0;
		foreach ( $keys as $key ) {
			if ( '' !== trim( (string) ( $meta[ $key ] ?? '' ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * What is left to do on one case.
	 *
	 * Two groups, and the difference between them is the point. The `blocking`
	 * rows ARE missing() — read off it, never recomputed — so the checklist
	 * and the gate cannot drift apart. The rest is everything that makes a
	 * dossier readable and that the gate deliberately says nothing about: a
	 * case can be perfectly publishable and still show a player four empty
	 * fundamentals and no Portuguese, and only one of those two problems has
	 * anybody watching it.
	 *
	 * Counts, not sentences — the wording lives in checklist_labels() for the
	 * same reason labels() exists, and the rule stays testable without it.
	 *
	 * @param array<string,mixed> $meta Case meta, key => value.
	 * @param int|null            $now  Unix timestamp; defaults to now.
	 * @return array<int,array{key:string,have:int,need:int,done:bool,blocking:bool}>
	 */
	public static function checklist( array $meta, ?int $now = null ): array {
		$missing = self::missing( $meta, $now );
		$has     = static fn( string $field ): int => in_array( $field, $missing, true ) ? 0 : 1;

		return array(
			self::step( 'year', $has( 'hti_rev_year' ), 1, true ),
			self::step( 'returns', $has( 'hti_rev_return_5y_bp' ) + $has( 'hti_rev_index_return_5y_bp' ), 2, true ),
			self::step( 'source', $has( 'hti_rev_source_url' ), 1, true ),
			self::step( 'verified', $has( 'hti_rev_verified' ), 1, true ),
			self::step( 'company', self::filled( $meta, array( 'hti_rev_company' ) ), 1, false ),
			self::step( 'dossier', self::filled( $meta, array( 'hti_rev_sector_en', 'hti_rev_sector_pt', 'hti_rev_revenue_band_en', 'hti_rev_revenue_band_pt' ) ), 4, false ),
			self::step( 'fundamentals', self::fundamentals_complete( (string) ( $meta['hti_rev_fundamentals'] ?? '' ) ), self::FUNDAMENTALS, false ),
			self::step( 'headlines', self::headlines_complete( (string) ( $meta['hti_rev_headlines'] ?? '' ) ), self::HEADLINES, false ),
			self::step( 'aftermath', self::filled( $meta, array( 'hti_rev_context_en', 'hti_rev_context_pt', 'hti_rev_lesson_en', 'hti_rev_lesson_pt' ) ), 4, false ),
			self::step( 'credit', self::filled( $meta, array( 'hti_rev_source_label', 'hti_rev_source_accessed' ) ), 2, false ),
		);
	}

	/**
	 * One checklist row.
	 *
	 * @param string $key      Row key; wording lives in checklist_labels().
	 * @param int    $have     How many parts are done.
	 * @param int    $need     How many there are.
	 * @param bool   $blocking Whether this row stops a publish.
	 * @return array{key:string,have:int,need:int,done:bool,blocking:bool}
	 */
	private static function step( string $key, int $have, int $need, bool $blocking ): array {
		$have = max( 0, min( $have, $need ) );

		return array(
			'key'      => $key,
			'have'     => $have,
			'need'     => $need,
			'done'     => $have >= $need,
			'blocking' => $blocking,
		);
	}

	/**
	 * The checklist in one line of numbers.
	 *
	 * @param array<int,array<string,mixed>> $checklist Output of checklist().
	 * @return array{total:int,done:int,todo:int,blocking:int}
	 */
	public static function progress( array $checklist ): array {
		$done     = 0;
		$blocking = 0;

		foreach ( $checklist as $row ) {
			if ( ! empty( $row['done'] ) ) {
				++$done;
				continue;
			}
			if ( ! empty( $row['blocking'] ) ) {
				++$blocking;
			}
		}

		return array(
			'total'    => count( $checklist ),
			'done'     => $done,
			'todo'     => count( $checklist ) - $done,
			'blocking' => $blocking,
		);
	}

	/**
	 * The checklist wording: what each row is, and what finishing it means.
	 *
	 * Admin-only copy, so __() with the plugin's text domain is right here —
	 * unlike anything a player reads, which comes from Strings because
	 * WordPress does not fall back from pt_PT_ao90 to pt_PT.
	 *
	 * @return array<string,array{0:string,1:string}> Key => [ what it is, what done means ].
	 */
	public static function checklist_labels(): array {
		return array(
			'year'         => array(
				__( 'The year the dossier describes', 'hti-games' ),
				sprintf(
					/* translators: %d: minimum age of a case, in years. */
					__( 'A four-digit year at least %d years in the past. The age is what keeps naming a real company history rather than a view on a listed business today.', 'hti-games' ),
					Config::REVEAL_MIN_AGE_YEARS
				),
			),
			'returns'      => array(
				__( 'Both five-year returns, in basis points', 'hti-games' ),
				__( 'The company\'s return over the five years after that year, and the broad index\'s over the same five, signed: -8000 is a fall of 80%. Both come off the source document; 0 is a real answer and an empty box is not.', 'hti-games' ),
			),
			'source'       => array(
				__( 'The source URL', 'hti-games' ),
				__( 'A full http(s) address for the document the figures were read out of — an annual report, a regulator filing, an index factsheet. It is shown to the player on the reveal screen, so it has to be a link somebody can follow.', 'hti-games' ),
			),
			'verified'     => array(
				__( 'The verification tick', 'hti-games' ),
				__( 'Ticked only after the year and both returns have been read off that document, by the person who read them. Editing any of those three numbers withdraws it again.', 'hti-games' ),
			),
			'company'      => array(
				__( 'The company', 'hti-games' ),
				__( 'The real name, shown only after the decision. Without it the reveal screen has nothing to reveal.', 'hti-games' ),
			),
			'dossier'      => array(
				__( 'Sector and revenue band, in both languages', 'hti-games' ),
				__( 'The two lines at the top of the file. A band, never an exact figure a search engine can resolve to one company — the dossier is anonymous or it is not a dossier.', 'hti-games' ),
			),
			'fundamentals' => array(
				__( 'Six fundamentals, each against its sector average', 'hti-games' ),
				__( 'Every row needs a key, a label, a value and a sector average in both languages. A row missing any of those is dropped on the way to the player, so five filled rows show as five.', 'hti-games' ),
			),
			'headlines'    => array(
				__( 'Three headlines from the period', 'hti-games' ),
				__( 'Real headlines from the year, in both languages, and never one that names the company. They are the mood of the time, which is the half of the dossier the figures cannot carry.', 'hti-games' ),
			),
			'aftermath'    => array(
				__( 'What happened next, and the lesson', 'hti-games' ),
				__( 'Both in both languages. They are what the player reads after the number lands, and they are the difference between a score and a lesson.', 'hti-games' ),
			),
			'credit'       => array(
				__( 'Source label and the date it was accessed', 'hti-games' ),
				__( 'How the document is credited on the reveal screen, and when it was read. Not required to publish, but a citation nobody can date is a citation nobody can re-check.', 'hti-games' ),
			),
		);
	}

	/**
	 * The first of a set of candidate meta keys that is actually registered.
	 *
	 * How this file tolerates a field another workstream has not landed yet
	 * without either guessing at its name or depending on its timing.
	 *
	 * @param array<int,string>      $candidates Keys to look for, best first.
	 * @param array<int,string>|null $registered Registered keys; defaults to CPT::case_meta().
	 */
	public static function optional_key( array $candidates, ?array $registered = null ): string {
		$registered = $registered ?? array_keys( CPT::case_meta() );

		foreach ( $candidates as $key ) {
			if ( in_array( $key, $registered, true ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * What this case teaches, for the queue's second column.
	 *
	 * The pattern's name when the case names a pattern, the sector when it does
	 * not, and an empty string when it has neither — never a guess. The name
	 * rather than the id, because `great_company_bad_price` in a table column
	 * is a screen an editorial lead stops reading; Reveal_Lessons is the
	 * library that already words every pattern, so it is asked rather than
	 * second-guessed, and an id it does not know falls back to itself instead
	 * of to a blank.
	 *
	 * @param array<string,mixed>    $meta       Case meta.
	 * @param array<int,string>|null $registered Registered keys, for tests.
	 */
	public static function pattern_of( array $meta, ?array $registered = null ): string {
		$key = self::optional_key( self::PATTERN_KEYS, $registered );
		$id  = '' !== $key ? trim( (string) ( $meta[ $key ] ?? '' ) ) : '';

		if ( '' !== $id ) {
			$patterns = class_exists( __NAMESPACE__ . '\\Reveal_Lessons' ) ? Reveal_Lessons::patterns() : array();
			$name     = (string) ( $patterns[ $id ]['en'] ?? '' );

			return '' !== $name ? $name : $id;
		}

		return trim( (string) ( $meta['hti_rev_sector_en'] ?? '' ) );
	}

	/**
	 * One queue row. Pure.
	 *
	 * @param int                 $id      Post id.
	 * @param string              $title   Post title.
	 * @param string              $status  Post status.
	 * @param string              $pattern What the case teaches.
	 * @param array<string,mixed> $meta    Case meta.
	 * @param int|null            $now     Unix timestamp; defaults to now.
	 * @return array{id:int,title:string,status:string,pattern:string,missing:array<int,string>,open:array<int,string>,open_blocking:array<int,string>,blocking:int,todo:int,publishable:bool,live:bool}
	 */
	public static function queue_row( int $id, string $title, string $status, string $pattern, array $meta, ?int $now = null ): array {
		$missing   = self::missing( $meta, $now );
		$checklist = self::checklist( $meta, $now );
		$progress  = self::progress( $checklist );

		// The open rows travel with the row so the queue can word its "what is
		// missing" column out of checklist_labels() — one table of wording for
		// the editor's screen and the lead's, which is one fewer place for the
		// two to describe the same gap differently.
		$open     = array();
		$blocking = array();
		foreach ( $checklist as $step ) {
			if ( ! empty( $step['done'] ) ) {
				continue;
			}
			$open[] = (string) $step['key'];
			if ( ! empty( $step['blocking'] ) ) {
				$blocking[] = (string) $step['key'];
			}
		}

		return array(
			'id'            => $id,
			'title'         => $title,
			'status'        => $status,
			'pattern'       => $pattern,
			'missing'       => $missing,
			'open'          => $open,
			'open_blocking' => $blocking,
			'blocking'      => count( $missing ),
			'todo'          => $progress['todo'],
			'publishable'   => array() === $missing,
			// The one state nobody has to act on: the gate would pass it AND
			// it is published, which is the second thing Library's pool query
			// insists on before a case is ever served.
			'live'          => array() === $missing && 'publish' === $status,
		);
	}

	/**
	 * The queue, closest to launchable first. Pure.
	 *
	 * The ordering is a judgement about what an editorial lead needs: the case
	 * that is one field away from being served is worth more attention than
	 * the case that is nine, because it is the one that moves the pool today.
	 * Ties break on the whole checklist, then the title, then the id — so the
	 * list is stable between page loads, and a queue that reshuffles itself
	 * under you is a queue nobody can work through.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows from queue_row().
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_queue( array $rows ): array {
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( (int) $a['blocking'], (int) $a['todo'], strtolower( (string) $a['title'] ), (int) $a['id'] )
					<=> array( (int) $b['blocking'], (int) $b['todo'], strtolower( (string) $b['title'] ), (int) $b['id'] );
			}
		);

		return $rows;
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

		// Before the fields, in this order: the document to open, and what is
		// still missing. Both above the form because both are answers to
		// questions an editor has before they start typing, not after.
		self::render_brief( $meta );
		self::render_checklist( $meta, (int) $post->ID );

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

		self::render_verification( $meta );

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

		for ( $i = 0; $i < self::FUNDAMENTALS; $i++ ) {
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
		for ( $i = 0; $i < self::HEADLINES; $i++ ) {
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
	 * The workflow panels on the case editor.
	 * ------------------------------------------------------------------- */

	/**
	 * The research brief for this case, read-only, above everything else.
	 *
	 * Read-only on purpose. The brief is the instruction — which company, which
	 * filing, which six figures — and an instruction that can be edited from
	 * inside the task it describes stops being one. It is shown here so that
	 * the document to open is on the same screen as the boxes the numbers go
	 * into, which is the whole reason an editor would otherwise have three tabs
	 * and a lost place.
	 *
	 * @param array<string,mixed> $meta Case meta.
	 */
	private static function render_brief( array $meta ): void {
		$key = self::optional_key( self::BRIEF_KEYS );
		if ( '' === $key ) {
			// The field has not landed yet. A panel about a field that does
			// not exist is worse than no panel.
			return;
		}

		$brief = trim( (string) ( $meta[ $key ] ?? '' ) );

		echo '<div class="hti-cw hti-cw--brief">';
		echo '<h4 class="hti-cw__h">' . esc_html__( 'Research brief', 'hti-games' ) . '</h4>';

		if ( '' === $brief ) {
			echo '<p class="description">' . esc_html__( 'No brief recorded for this case. Whoever plans it should say which document the figures come out of before anybody starts typing numbers into the boxes below.', 'hti-games' ) . '</p>';
		} else {
			// A scrollable region so a long brief does not push the fields off
			// the screen, and focusable because a region that scrolls and
			// cannot be reached by keyboard is a region half the people who
			// need it cannot read (WCAG 2.1.1).
			printf(
				'<div class="hti-cw__brief" role="region" tabindex="0" aria-label="%s">',
				esc_attr__( 'Research brief', 'hti-games' )
			);
			echo wp_kses_post( wpautop( esc_html( $brief ) ) );
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * What is still open on this case, and what finishing each thing means.
	 *
	 * Shown before an editor tries to publish, rather than as the refusal
	 * afterwards: notice() explains a blocked publish, and by then the person
	 * has already been told no by a form that never told them what it wanted.
	 *
	 * @param array<string,mixed> $meta    Case meta.
	 * @param int                 $post_id Post id, for the preview links.
	 */
	private static function render_checklist( array $meta, int $post_id ): void {
		$list     = self::checklist( $meta );
		$progress = self::progress( $list );

		echo '<div class="hti-cw hti-cw--check">';
		echo '<h4 class="hti-cw__h">' . esc_html__( 'What is left on this case', 'hti-games' ) . '</h4>';

		if ( 0 === $progress['blocking'] ) {
			printf(
				'<p class="hti-cw__state is-ready">%s</p>',
				esc_html__( 'Nothing blocks publication. Publishing puts this case into the daily rotation.', 'hti-games' )
			);
		} else {
			printf(
				'<p class="hti-cw__state is-blocked">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of unmet requirements. */
						_n(
							'Not publishable yet — %d requirement is unmet. Pressing Publish saves this as a draft and names it.',
							'Not publishable yet — %d requirements are unmet. Pressing Publish saves this as a draft and names them.',
							$progress['blocking'],
							'hti-games'
						),
						$progress['blocking']
					)
				)
			);
		}

		$blocking = array();
		$rest     = array();
		foreach ( $list as $row ) {
			if ( ! empty( $row['blocking'] ) ) {
				$blocking[] = $row;
			} else {
				$rest[] = $row;
			}
		}

		self::render_checklist_group( __( 'Required before this case can be published', 'hti-games' ), $blocking );
		self::render_checklist_group( __( 'Required before the dossier reads properly', 'hti-games' ), $rest );

		// The count is derived rather than typed: a fifth blocking requirement
		// would otherwise leave a sentence on the screen saying there are four.
		printf(
			'<p class="hti-cw__foot">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of requirements the publish gate enforces. */
					__( 'Those first %d requirements are what the publish gate checks, and the same ones are checked again by the query that picks the day — so a case that reaches Publish by any other route is still not served.', 'hti-games' ),
					count( $blocking )
				)
			)
		);

		if ( $post_id > 0 ) {
			printf(
				'<p class="hti-cw__foot"><a href="%1$s">%2$s</a> · <a href="%3$s">%4$s</a></p>',
				esc_url( self::preview_url( $post_id, 'en' ) ),
				esc_html__( 'Preview it as an English player sees it', 'hti-games' ),
				esc_url( self::preview_url( $post_id, 'pt' ) ),
				esc_html__( 'and as a Portuguese one does', 'hti-games' )
			);
		}

		echo '</div>';
	}

	/**
	 * One group of checklist rows.
	 *
	 * @param string                         $heading Group heading.
	 * @param array<int,array<string,mixed>> $rows    Checklist rows.
	 */
	private static function render_checklist_group( string $heading, array $rows ): void {
		if ( array() === $rows ) {
			return;
		}

		$labels = self::checklist_labels();

		echo '<p class="hti-cw__group">' . esc_html( $heading ) . '</p>';
		echo '<ul class="hti-cw__list">';

		foreach ( $rows as $row ) {
			$key   = (string) $row['key'];
			$done  = ! empty( $row['done'] );
			$label = $labels[ $key ] ?? array( $key, '' );

			// "3 of 6" only where the row has parts. On a one-part row the
			// count is noise, and the tick already says everything.
			$count = (int) $row['need'] > 1
				? sprintf(
					/* translators: 1: parts done, 2: parts needed. */
					__( '%1$d of %2$d', 'hti-games' ),
					(int) $row['have'],
					(int) $row['need']
				)
				: '';

			printf(
				'<li class="hti-cw__item %1$s"><span class="hti-cw__mark" aria-hidden="true">%2$s</span><span class="hti-cw__body"><strong>%3$s</strong> <span class="hti-cw__count">%4$s</span><span class="screen-reader-text">%5$s</span><span class="hti-cw__done">%6$s</span></span></li>',
				esc_attr( ( $done ? 'is-done' : 'is-todo' ) . ( empty( $row['blocking'] ) ? ' is-optional' : ' is-blocking' ) ),
				$done ? '&#10003;' : '&#8226;',
				esc_html( (string) $label[0] ),
				esc_html( $count ),
				esc_html( $done ? __( '— done', 'hti-games' ) : __( '— still to do', 'hti-games' ) ),
				esc_html( (string) ( $label[1] ?? '' ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * The verification block: who said so, when, and about which numbers.
	 *
	 * The decay rule is invisible until it fires, and an editor who watches a
	 * tick disappear without being told why concludes the software is broken
	 * and re-ticks it without re-checking anything — which is worse than no
	 * decay at all. So the block names the three numbers the tick is a
	 * statement about, shows their current values, and says out loud that
	 * editing one withdraws it.
	 *
	 * @param array<string,mixed> $meta Case meta.
	 */
	private static function render_verification( array $meta ): void {
		$verified = '1' === (string) ( $meta['hti_rev_verified'] ?? '' );
		$by       = trim( (string) ( $meta['hti_rev_verified_by'] ?? '' ) );
		$at       = trim( (string) ( $meta['hti_rev_verified_at'] ?? '' ) );

		printf( '<div class="%s">', esc_attr( $verified ? 'hti-cw hti-cw--verify is-verified' : 'hti-cw hti-cw--verify' ) );
		echo '<h4 class="hti-cw__h">' . esc_html__( 'Verification', 'hti-games' ) . '</h4>';

		printf(
			'<p class="hti-cw__tick"><label><input type="checkbox" name="hti_rev_verified" value="1" %1$s /> <strong>%2$s</strong></label></p>',
			checked( $verified, true, false ),
			esc_html__( 'Verified against the source', 'hti-games' )
		);

		if ( $verified && '' !== $at ) {
			printf(
				'<p class="hti-cw__who">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: user login, 2: UTC timestamp. */
						__( 'Verified by %1$s on %2$s UTC.', 'hti-games' ),
						'' !== $by ? $by : __( 'an unrecorded user', 'hti-games' ),
						$at
					)
				)
			);
		} elseif ( $verified ) {
			printf(
				'<p class="hti-cw__who">%s</p>',
				esc_html__( 'Ticked but not yet saved — the name and the time are recorded when this case is saved.', 'hti-games' )
			);
		} else {
			printf(
				'<p class="hti-cw__who">%s</p>',
				esc_html__( 'Not verified. Nothing is served without this: the publish gate refuses it, and so does the query that picks the day.', 'hti-games' )
			);
		}

		echo '<p class="hti-cw__means">' . esc_html__( 'The tick is a statement about these three numbers and about nothing else. Change any one of them — anybody, including whoever ticked it — and it is withdrawn when the case is saved:', 'hti-games' ) . '</p>';

		$names = array(
			'hti_rev_year'               => __( 'Year', 'hti-games' ),
			'hti_rev_return_5y_bp'       => __( 'Five-year return (bp)', 'hti-games' ),
			'hti_rev_index_return_5y_bp' => __( 'Index five-year return (bp)', 'hti-games' ),
		);

		echo '<ul class="hti-cw__three">';
		foreach ( self::VERIFIED_FIELDS as $field ) {
			$value = trim( (string) ( $meta[ $field ] ?? '' ) );
			printf(
				'<li><strong>%1$s</strong> <span class="hti-cw__count">%2$s</span></li>',
				esc_html( $names[ $field ] ?? $field ),
				esc_html( '' !== $value ? $value : __( 'empty', 'hti-games' ) )
			);
		}
		echo '</ul>';

		echo '<p class="description">' . esc_html__( 'Renaming the company, rewriting the lesson or fixing a headline does not withdraw it — none of those is what was checked.', 'hti-games' ) . '</p>';
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * The queue: every unfinished case, on the settings screen.
	 * ------------------------------------------------------------------- */

	/**
	 * Every case that exists, as queue rows, closest to launchable first.
	 *
	 * One meta read per case on an admin screen. The alternative is a
	 * meta_query that would have to be cached and invalidated to answer a
	 * question nobody asks twice a minute, and a stale queue is a queue that
	 * sends somebody to re-check a case that was finished an hour ago.
	 *
	 * @param int $limit Most cases to inspect.
	 * @return array<int,array<string,mixed>>
	 */
	public static function queue( int $limit = 200 ): array {
		$ids = get_posts(
			array(
				'post_type'              => Config::CPT_CASE,
				'post_status'            => array( 'draft', 'pending', 'future', 'publish' ),
				'numberposts'            => $limit,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// One case carries both languages in its meta, so Polylang
				// must not filter the queue down to half of it.
				'suppress_filters'       => true,
			)
		);

		$rows = array();
		foreach ( (array) $ids as $id ) {
			$id   = (int) $id;
			$meta = self::stored_meta( $id );
			$rows[] = self::queue_row(
				$id,
				(string) get_the_title( $id ),
				(string) get_post_status( $id ),
				self::pattern_of( $meta ),
				$meta
			);
		}

		return self::sort_queue( $rows );
	}

	/**
	 * The verification queue, on Settings → HTI Games.
	 *
	 * The readiness panel above it reports a count. A count tells an editorial
	 * lead that there is work; it does not tell them what the work is, which
	 * case is one field from being served, or where to click. This does.
	 */
	public static function render_panel(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$rows    = self::queue();
		$labels  = self::checklist_labels();
		$live    = 0;
		$waiting = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['live'] ) ) {
				++$live;
				continue;
			}
			$waiting[] = $row;
		}

		echo '<h2>' . esc_html__( 'The Reveal — case queue', 'hti-games' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: cases being served, 2: cases in total. */
					__( '%1$d of %2$d cases are published, verified and in the rotation. The rest are below, closest to launchable first.', 'hti-games' ),
					$live,
					count( $rows )
				)
			)
		);

		if ( array() === $waiting ) {
			echo '<p>' . esc_html__( 'Nothing is waiting on anybody.', 'hti-games' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped hti-cq"><thead><tr>';
		foreach ( array(
			__( 'Case', 'hti-games' ),
			__( 'Pattern', 'hti-games' ),
			__( 'Status', 'hti-games' ),
			__( 'What is missing', 'hti-games' ),
		) as $head ) {
			printf( '<th scope="col">%s</th>', esc_html( $head ) );
		}
		echo '</tr></thead><tbody>';

		foreach ( $waiting as $row ) {
			self::render_queue_row( $row, $labels );
		}

		echo '</tbody></table>';
	}

	/**
	 * One row of the queue table.
	 *
	 * @param array<string,mixed>                    $row    A queue_row().
	 * @param array<string,array{0:string,1:string}> $labels checklist_labels().
	 */
	private static function render_queue_row( array $row, array $labels ): void {
		$id    = (int) $row['id'];
		$title = trim( (string) $row['title'] );
		$edit  = (string) get_edit_post_link( $id );

		echo '<tr>';

		printf(
			'<td><strong><a href="%1$s">%2$s</a></strong><div class="row-actions"><span><a href="%3$s">%4$s</a></span> | <span><a href="%5$s">%6$s</a></span></div></td>',
			esc_url( $edit ),
			esc_html( '' !== $title ? $title : sprintf( '#%d', $id ) ),
			esc_url( $edit ),
			esc_html__( 'Edit', 'hti-games' ),
			esc_url( self::preview_url( $id, 'en' ) ),
			esc_html__( 'Preview', 'hti-games' )
		);

		printf( '<td>%s</td>', esc_html( '' !== trim( (string) $row['pattern'] ) ? (string) $row['pattern'] : '—' ) );

		printf( '<td>%s</td>', esc_html( self::queue_state( $row ) ) );

		$open = array();
		foreach ( (array) $row['open_blocking'] as $key ) {
			$open[] = (string) ( $labels[ (string) $key ][0] ?? $key );
		}
		$soft = count( (array) $row['open'] ) - count( (array) $row['open_blocking'] );
		if ( $soft > 0 ) {
			$open[] = sprintf(
				/* translators: %d: number of non-blocking gaps. */
				_n( '%d more thing to write before it reads properly', '%d more things to write before it reads properly', $soft, 'hti-games' ),
				$soft
			);
		}

		printf( '<td>%s</td>', esc_html( array() === $open ? __( 'Nothing — publish it', 'hti-games' ) : implode( ' · ', $open ) ) );

		echo '</tr>';
	}

	/**
	 * What state one queued case is in, in words.
	 *
	 * "Ready to publish" is its own state and the most actionable one on the
	 * screen: the work is finished and a click is left. "Published but not
	 * served" is the state that should be impossible — the gate forces such a
	 * post back to draft — so if it ever appears it is named loudly rather
	 * than shown as an ordinary published post, because the pool query is
	 * silently refusing to serve it.
	 *
	 * @param array<string,mixed> $row A queue_row().
	 */
	private static function queue_state( array $row ): string {
		$status = (string) $row['status'];

		if ( 'publish' === $status ) {
			return __( 'Published but not served', 'hti-games' );
		}
		if ( ! empty( $row['publishable'] ) ) {
			return __( 'Ready to publish', 'hti-games' );
		}

		return match ( $status ) {
			'pending' => __( 'Pending review', 'hti-games' ),
			'future'  => __( 'Scheduled', 'hti-games' ),
			default   => __( 'Draft', 'hti-games' ),
		};
	}

	/* ---------------------------------------------------------------------
	 * The one-case preview.
	 * ------------------------------------------------------------------- */

	/**
	 * Register the preview screen under the Reveal cases menu.
	 */
	public static function add_preview_page(): void {
		self::$preview_hook = (string) add_submenu_page(
			'edit.php?post_type=' . Config::CPT_CASE,
			__( 'Preview a case', 'hti-games' ),
			__( 'Preview', 'hti-games' ),
			'edit_posts',
			self::PREVIEW_PAGE,
			array( __CLASS__, 'render_preview' )
		);
	}

	/**
	 * The preview screen's URL for one case in one language.
	 *
	 * @param int    $id   Case post id.
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function preview_url( int $id, string $lang = 'en' ): string {
		return add_query_arg(
			array(
				'post_type' => Config::CPT_CASE,
				'page'      => self::PREVIEW_PAGE,
				'case'      => $id,
				'lang'      => 'pt' === $lang ? 'pt' : 'en',
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * One case, as a player meets it.
	 *
	 * Read-only, and a GET with no side effect, so the capability is the whole
	 * control: `edit_posts` for the screen and `edit_post` for the case, which
	 * is the same pair that guards the editor this previews.
	 */
	public static function render_preview(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only screen that changes nothing; the capability checks below are the control.
		$id = isset( $_GET['case'] ) ? absint( wp_unslash( $_GET['case'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$lang = isset( $_GET['lang'] ) && 'pt' === sanitize_key( wp_unslash( $_GET['lang'] ) ) ? 'pt' : 'en';

		echo '<div class="wrap hti-cp">';
		echo '<h1>' . esc_html__( 'Preview a case', 'hti-games' ) . '</h1>';

		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof \WP_Post || Config::CPT_CASE !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			if ( $id > 0 ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'That case does not exist, or it is not yours to edit.', 'hti-games' ) . '</p></div>';
			}
			self::render_preview_picker();
			echo '</div>';
			return;
		}

		$meta = self::stored_meta( $id );

		printf(
			'<p><strong>%1$s</strong> — <a href="%2$s">%3$s</a> · <a href="%4$s">%5$s</a> · <a href="%6$s">%7$s</a></p>',
			esc_html( (string) get_the_title( $post ) ),
			esc_url( (string) get_edit_post_link( $id ) ),
			esc_html__( 'Edit this case', 'hti-games' ),
			esc_url( self::preview_url( $id, 'en' ) ),
			esc_html__( 'English', 'hti-games' ),
			esc_url( self::preview_url( $id, 'pt' ) ),
			esc_html__( 'Portuguese', 'hti-games' )
		);

		$missing = self::missing( $meta );
		printf(
			'<div class="notice %1$s inline"><p>%2$s</p></div>',
			esc_attr( array() === $missing ? 'notice-success' : 'notice-warning' ),
			esc_html(
				array() === $missing
					? __( 'This case is publishable. What follows is what a player would see.', 'hti-games' )
					: __( 'This case cannot be published yet, so a player would never reach this screen. It is drawn from what is stored right now — empty boxes appear as they would render.', 'hti-games' )
			)
		);

		echo '<div class="hti-cp__stage">';
		self::render_preview_dossier( $meta, $lang );
		echo '</div>';

		self::render_preview_answer( $meta, $lang );
		echo '</div>';
	}

	/**
	 * The picker shown when the screen is opened without a case.
	 */
	private static function render_preview_picker(): void {
		$rows = self::queue( 40 );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'There are no cases to preview yet.', 'hti-games' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Pick a case to see it as a player would:', 'hti-games' ) . '</p><ul>';
		foreach ( $rows as $row ) {
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( self::preview_url( (int) $row['id'], 'en' ) ),
				esc_html( '' !== trim( (string) $row['title'] ) ? (string) $row['title'] : sprintf( '#%d', (int) $row['id'] ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * The dossier, as the player is served it.
	 *
	 * WHAT THIS REUSES, AND THE ONE THING IT CANNOT.
	 *
	 * The values are REST::public_challenge_reveal() — the same whitelist, in
	 * the same language, that the game's own /today response is built from. So
	 * the preview shows what SURVIVES the boundary rather than what is stored:
	 * a fundamentals row whose key is empty is dropped here exactly as it is
	 * dropped on the way to a browser, and an editor finds that out on this
	 * screen instead of on the day the case is served. The wording is
	 * Strings::get(), the same table the shell reads.
	 *
	 * The MARKUP is the part that could not be reused, and it is worth saying
	 * why rather than pretending otherwise: on the front end the dossier is
	 * painted by assets/js/reveal.js into the empty shell
	 * Frontend::shell_reveal() prints, from a payload fetched over REST. There
	 * is no server-side renderer of a filled dossier to call — building one for
	 * the front end would mean serving the answer's neighbours in the HTML of a
	 * cacheable page, which is the thing the whole anti-cheat design exists to
	 * prevent. So this method mirrors paintDossier(), and
	 * tests/test-case-workflow.php pins every class name and every tint mark it
	 * emits to the ones reveal.js and reveal.css actually use, so the two
	 * cannot drift apart quietly.
	 *
	 * @param array<string,mixed> $meta Case meta.
	 * @param string              $lang 'en' or 'pt'.
	 */
	private static function render_preview_dossier( array $meta, string $lang ): void {
		$data  = REST::public_challenge_reveal( $meta, array( 'lang' => $lang ) );
		$dash  = '—';
		$marks = array(
			'good' => '&#10003;',
			'warn' => '~',
			'bad'  => '!',
		);

		echo '<section class="hti-g hti-rv"><div class="hti-g__phases"><div class="hti-g__phase">';
		echo '<article class="hti-rv__file">';
		echo '<div class="hti-rv__tape" aria-hidden="true"></div>';
		echo '<div class="hti-rv__filehead">';

		printf(
			'<p class="hti-g__kicker hti-num">%s</p>',
			esc_html( sprintf( Strings::get( 'rev_dossier', $lang ), strtoupper( substr( (string) ( $data['ref'] ?? '' ), 0, 6 ) ) ) )
		);
		printf( '<h2 class="hti-rv__unnamed">%s</h2>', esc_html( Strings::get( 'rev_unnamed', $lang ) ) );
		printf( '<p class="hti-rv__stamp" aria-hidden="true">%s</p>', esc_html( Strings::get( 'rev_confidential', $lang ) ) );
		echo '</div>';

		echo '<dl class="hti-rv__meta">';
		printf(
			'<div class="hti-rv__metacell"><dt>%1$s</dt><dd>%2$s</dd></div>',
			esc_html( Strings::get( 'rev_sector', $lang ) ),
			esc_html( '' !== (string) $data['sector'] ? (string) $data['sector'] : $dash )
		);
		printf(
			'<div class="hti-rv__metacell"><dt>%1$s</dt><dd>%2$s</dd></div>',
			esc_html( Strings::get( 'rev_revenue', $lang ) ),
			esc_html( '' !== (string) $data['revenue_band'] ? (string) $data['revenue_band'] : $dash )
		);
		echo '</dl>';

		printf( '<h3 class="hti-rv__sub">%s</h3>', esc_html( Strings::get( 'rev_fundamentals', $lang ) ) );
		echo '<table class="hti-rv__fund"><tbody>';
		foreach ( (array) $data['fundamentals'] as $row ) {
			$tint = (string) ( $row['tint'] ?? 'warn' );
			printf(
				'<tr class="%1$s"><th scope="row">%2$s</th><td class="hti-num hti-rv__value"><span class="hti-rv__mark" aria-hidden="true">%3$s</span><span class="hti-g__sr">%4$s</span>%5$s</td><td class="hti-num hti-rv__avg"><span class="hti-g__sr">%6$s</span>%7$s</td></tr>',
				esc_attr( 'is-' . $tint ),
				esc_html( (string) ( $row['label'] ?? '' ) ),
				esc_html( $marks[ $tint ] ?? '' ),
				esc_html( Strings::get( 'rev_tint_' . $tint, $lang ) . ' — ' ),
				esc_html( (string) ( $row['value'] ?? '' ) ),
				esc_html( Strings::get( 'rev_sector_avg', $lang ) . ' ' ),
				esc_html( (string) ( $row['sector_avg'] ?? '' ) )
			);
		}
		echo '</tbody></table>';

		printf( '<h3 class="hti-rv__sub">%s</h3>', esc_html( Strings::get( 'rev_headlines', $lang ) ) );
		echo '<ul class="hti-rv__heads">';
		foreach ( (array) $data['headlines'] as $line ) {
			printf( '<li class="hti-rv__head"><blockquote>%s</blockquote></li>', esc_html( (string) $line ) );
		}
		echo '</ul>';

		echo '</article>';
		echo '<div class="hti-g__sides">';
		printf( '<button type="button" class="hti-g__choice hti-g__choice--pass" disabled>%s</button>', esc_html( Strings::get( 'rev_pass', $lang ) ) );
		printf( '<button type="button" class="hti-g__choice hti-g__choice--invest" disabled>%s</button>', esc_html( Strings::get( 'rev_invest', $lang ) ) );
		echo '</div>';
		echo '</div></div></section>';
	}

	/**
	 * Everything the player only sees after deciding.
	 *
	 * Plainly laid out rather than staged: the reveal screen's theatre is
	 * animation over values, and what an editor needs to check is the values —
	 * that the name and year are right, that both returns carry the sign they
	 * should, that the Portuguese half exists, and that the source is a link
	 * somebody could actually follow.
	 *
	 * @param array<string,mixed> $meta Case meta.
	 * @param string              $lang 'en' or 'pt'.
	 */
	private static function render_preview_answer( array $meta, string $lang ): void {
		$company = trim( (string) ( $meta['hti_rev_company'] ?? '' ) );
		$year    = (int) ( $meta['hti_rev_year'] ?? 0 );

		echo '<h2>' . esc_html__( 'After the decision', 'hti-games' ) . '</h2>';

		printf(
			'<p class="hti-cp__answer">%s</p>',
			esc_html( '' !== $company && $year > 0 ? $company . ' · ' . $year : __( 'No company or year recorded — the reveal screen would have nothing to reveal.', 'hti-games' ) )
		);

		echo '<table class="widefat striped hti-cp__rows"><tbody>';

		// Admin wording for the two returns, not the player's. On the result
		// screen `rev_line_you` labels what the PLAYER made, which is this
		// figure multiplied by what they committed; borrowing that label here
		// would put the right number under the wrong sentence.
		self::preview_row(
			__( 'What the company returned over the next five years', 'hti-games' ),
			self::bp_label( (string) ( $meta['hti_rev_return_5y_bp'] ?? '' ) )
		);
		self::preview_row(
			__( 'What the index did over the same five years', 'hti-games' ),
			self::bp_label( (string) ( $meta['hti_rev_index_return_5y_bp'] ?? '' ) )
		);
		self::preview_row( __( 'What happened next', 'hti-games' ), self::preview_block( $meta, 'hti_rev_context_', $lang ) );
		self::preview_row( __( 'Lesson', 'hti-games' ), self::preview_block( $meta, 'hti_rev_lesson_', $lang ) );

		$url   = trim( (string) ( $meta['hti_rev_source_url'] ?? '' ) );
		$label = trim( (string) ( $meta['hti_rev_source_label'] ?? '' ) );
		$when  = trim( (string) ( $meta['hti_rev_source_accessed'] ?? '' ) );

		$source = '' === $url
			? __( 'No source — the case cannot be published, and the reveal screen would credit nothing.', 'hti-games' )
			: ( '' !== $label ? $label : $url ) . ( '' !== $when ? ' · ' . sprintf( Strings::get( 'rev_source_note', $lang ), $when ) : '' );

		self::preview_row( Strings::get( 'rev_source', $lang ), $source );

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( Strings::get( 'rev_historical', $lang ) ) . '</p>';
	}

	/**
	 * One label/value row of the answer table.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 */
	private static function preview_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( '' !== trim( $value ) ? $value : '—' )
		);
	}

	/**
	 * A bilingual block as the player would get it, saying so when the
	 * Portuguese half is missing.
	 *
	 * REST::block() falls back to English rather than serving a blank, which is
	 * the right call at runtime and a trap at review time: the Portuguese page
	 * looks finished because it is quietly showing English. Here it is named.
	 *
	 * @param array<string,mixed> $meta   Case meta.
	 * @param string              $prefix Meta key prefix, with its trailing underscore.
	 * @param string              $lang   'en' or 'pt'.
	 */
	private static function preview_block( array $meta, string $prefix, string $lang ): string {
		$text = trim( (string) ( $meta[ $prefix . $lang ] ?? '' ) );
		if ( '' !== $text ) {
			return $text;
		}

		$english = trim( (string) ( $meta[ $prefix . 'en' ] ?? '' ) );
		if ( 'pt' === $lang && '' !== $english ) {
			return sprintf(
				/* translators: %s: the English text a Portuguese player would be served instead. */
				__( 'Portuguese missing — a Portuguese player is served the English: %s', 'hti-games' ),
				$english
			);
		}

		return '';
	}

	/**
	 * A basis-point figure as both the stored integer and the percentage it
	 * means, because one of the two is what an editor read in the filing and
	 * the other is what they typed.
	 *
	 * @param string $bp Stored value.
	 */
	private static function bp_label( string $bp ): string {
		$bp = trim( $bp );
		if ( '' === $bp || 1 !== preg_match( '/^-?\d+$/', $bp ) ) {
			return '';
		}

		return sprintf( '%s bp (%+.2f%%)', $bp, (int) $bp / 100 );
	}

	/* ---------------------------------------------------------------------
	 * Assets. Admin only, and only on the three screens that use them.
	 * ------------------------------------------------------------------- */

	/**
	 * The admin sheet, and — on the preview only — the player's own two.
	 *
	 * Nothing here is ever enqueued on the front end: the front-end budget is
	 * measured in tests/test-asset-budget.php and this file adds nothing to it.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue( $hook ): void {
		$hook   = (string) $hook;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$editor  = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& $screen instanceof \WP_Screen
			&& Config::CPT_CASE === $screen->post_type;
		$preview = '' !== self::$preview_hook && $hook === self::$preview_hook;
		$panel   = 'settings_page_' . Settings::PAGE === $hook;

		if ( ! $editor && ! $preview && ! $panel ) {
			return;
		}

		wp_enqueue_style( 'hti-games-admin-case', HTI_GAMES_URL . 'assets/css/admin-case.css', array(), VERSION );

		if ( $preview ) {
			// The player's own sheets, on the one admin screen that renders the
			// player's own markup. Both are scoped to .hti-g / .hti-rv, and the
			// two page-level rules games.css carries are keyed to a body class
			// no admin screen has — so neither can reach wp-admin's layout.
			wp_enqueue_style( 'hti-games', HTI_GAMES_URL . 'assets/css/games.css', array(), VERSION );
			wp_enqueue_style( 'hti-games-reveal', HTI_GAMES_URL . 'assets/css/reveal.css', array( 'hti-games' ), VERSION );
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
