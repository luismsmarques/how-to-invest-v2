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
	 * The pattern key when one exists, the sector when it does not, and an
	 * empty string when the case has neither — never a guess.
	 *
	 * @param array<string,mixed>    $meta       Case meta.
	 * @param array<int,string>|null $registered Registered keys, for tests.
	 */
	public static function pattern_of( array $meta, ?array $registered = null ): string {
		$key = self::optional_key( self::PATTERN_KEYS, $registered );
		if ( '' !== $key && '' !== trim( (string) ( $meta[ $key ] ?? '' ) ) ) {
			return trim( (string) $meta[ $key ] );
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
