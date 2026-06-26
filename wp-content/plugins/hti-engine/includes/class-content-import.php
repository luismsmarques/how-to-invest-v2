<?php
/**
 * Learn content importer — the editorial pipeline (NOT the seeder).
 *
 * Source of truth for Learn guides is a set of bilingual Markdown files in
 * `content/learn/*.md` (authored with the learn-guide skill). This importer
 * parses them and upserts `learn` posts — idempotent by slug, language-linked
 * via Polylang (default language + translation), filed under the right
 * `learn_topic`, with a TL;DR callout, key-takeaways box and auto-built
 * glossary + chapter links.
 *
 * New posts are published straight away (both languages); an existing post
 * keeps its current status, so a re-sync never reverts an editor's change. The
 * seeder stays install-only; ongoing content lives here.
 *
 * Run: `wp hti import-learn` (CLI) or Tools → Learn content (admin).
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Markdown → `learn` drafts importer with a status view.
 */
class Content_Import {

	private const TYPE = 'learn';

	/**
	 * Post meta holding a chapter's end-of-chapter quiz (array of questions).
	 */
	public const META_QUIZ = 'hti_quiz';

	/**
	 * Directory holding the Markdown chapters (bundled with the plugin).
	 */
	public static function dir(): string {
		return HTI_ENGINE_PATH . 'content/learn/';
	}

	/**
	 * Wire the admin screen + import handler.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_hti_import_learn', array( __CLASS__, 'handle_import' ) );
	}

	/* ---------- editorial plan / curriculum (front-end) ---------- */

	/**
	 * Parse the editorial plan CSV into rows keyed by column.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function plan(): array {
		$path = HTI_ENGINE_PATH . 'content/learn-plan.csv';
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file
		if ( ! $lines ) {
			return array();
		}
		$head = str_getcsv( (string) array_shift( $lines ) );
		$rows = array();
		foreach ( $lines as $line ) {
			$cols = str_getcsv( $line );
			$rows[] = array_combine( $head, array_pad( $cols, count( $head ), '' ) );
		}
		return $rows;
	}

	/**
	 * Bilingual module titles + descriptions for the "From zero to your first
	 * portfolio" path (keyed by module number).
	 *
	 * @return array<string,array{title:array<string,string>,desc:array<string,string>}>
	 */
	private static function module_meta(): array {
		return array(
			'0' => array(
				'title' => array( 'en' => 'Mindset & money', 'pt' => 'Mentalidade & dinheiro' ),
				'desc'  => array( 'en' => 'The right relationship with risk, time and your goals.', 'pt' => 'A relação certa com o risco, o tempo e os teus objetivos.' ),
			),
			'1' => array(
				'title' => array( 'en' => 'Foundations', 'pt' => 'Fundamentos' ),
				'desc'  => array( 'en' => 'The base ideas that everything else rests on.', 'pt' => 'As ideias-base que sustentam tudo o resto.' ),
			),
			'2' => array(
				'title' => array( 'en' => 'Asset classes', 'pt' => 'Classes de ativos' ),
				'desc'  => array( 'en' => 'Global equities, bonds, cash and alternatives.', 'pt' => 'Ações globais, obrigações, liquidez e alternativos.' ),
			),
			'3' => array(
				'title' => array( 'en' => 'Diversification & portfolios', 'pt' => 'Diversificação & carteiras' ),
				'desc'  => array( 'en' => 'How simple pieces form a robust whole.', 'pt' => 'Como peças simples formam um todo robusto.' ),
			),
			'4' => array(
				'title' => array( 'en' => 'In practice', 'pt' => 'Na prática' ),
				'desc'  => array( 'en' => 'The habits that make a plan work over the years.', 'pt' => 'Os hábitos que fazem o plano funcionar ao longo dos anos.' ),
			),
			'5' => array(
				'title' => array( 'en' => 'Behaviour', 'pt' => 'Comportamento' ),
				'desc'  => array( 'en' => 'Keeping a cool head when markets shake.', 'pt' => 'Manter a cabeça fria quando o mercado treme.' ),
			),
			'6' => array(
				'title' => array( 'en' => 'Your plan', 'pt' => 'O teu plano' ),
				'desc'  => array( 'en' => 'Bring it all together into a simple plan of your own.', 'pt' => 'Juntar tudo num plano simples e teu.' ),
			),
		);
	}

	/**
	 * The learning path for the hub: modules (with bilingual meta) and their
	 * chapters resolved against the published `learn` posts.
	 *
	 * @param string $lang 'en'|'pt'.
	 * @return array<int,array<string,mixed>>
	 */
	public static function curriculum( string $lang ): array {
		$meta  = self::module_meta();
		$slugs = self::lang_slugs();
		$mods  = array();

		foreach ( self::plan() as $r ) {
			$mn = (string) ( $r['module'] ?? '' );
			if ( '' === $mn ) {
				continue;
			}
			if ( ! isset( $mods[ $mn ] ) ) {
				$mods[ $mn ] = array(
					'num'      => $mn,
					'title'    => $meta[ $mn ]['title'][ $lang ] ?? ( $meta[ $mn ]['title']['en'] ?? ( 'Module ' . $mn ) ),
					'desc'     => $meta[ $mn ]['desc'][ $lang ] ?? ( $meta[ $mn ]['desc']['en'] ?? '' ),
					'chapters' => array(),
				);
			}

			$slug = (string) ( $r['slug'] ?? '' );
			$post = $slug ? get_page_by_path( $slug, OBJECT, self::TYPE ) : null;
			$url  = '';
			$mins = 0;
			$published   = false;
			$post_title  = '';
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				$published = true;
				$id        = (int) $post->ID;
				$resolved  = true;
				if ( 'pt' === $lang && function_exists( 'pll_get_post' ) ) {
					$tr = (int) pll_get_post( $id, $slugs['pt'] );
					if ( $tr ) {
						$id = $tr;
					} else {
						$resolved = false; // PT translation not linked yet.
					}
				}
				$url  = (string) get_permalink( $id );
				$mins = self::reading_time( (string) get_post_field( 'post_content', $id ) );
				// Use the real post title only when it is actually in the
				// requested language; otherwise fall back to the editorial plan.
				if ( $resolved ) {
					$post_title = wp_strip_all_tags( get_the_title( $id ) );
				}
			}

			// Prefer the real published title (in sync with WordPress); else the
			// editorial plan in the requested language; else a humanized slug.
			$title = '' !== $post_title
				? $post_title
				: ( 'pt' === $lang ? (string) ( $r['title_pt'] ?? '' ) : (string) ( $r['title_en'] ?? '' ) );
			if ( '' === $title ) {
				$title = ucwords( str_replace( '-', ' ', $slug ) );
			}

			$mods[ $mn ]['chapters'][] = array(
				'slug'      => $slug,
				'title'     => $title,
				'url'       => $url,
				'published' => $published,
				// Whether the chapter ships an end-of-chapter quiz, so the badge
				// engine can require a pass (quizzed) vs a visit (un-quizzed).
				'has_quiz'  => $published && $post instanceof \WP_Post && ! empty( self::get_quiz( (int) $post->ID ) ),
				'mins'      => $mins > 0 ? $mins : 5,
			);
		}

		return array_values( $mods );
	}

	/**
	 * Resolve the site's real Polylang language slugs (default + Portuguese).
	 * Polylang slugs are not guaranteed to be 'en'/'pt', so we read them rather
	 * than hard-coding — otherwise translation links silently fail to form.
	 *
	 * @return array{default:string,pt:string}
	 */
	public static function lang_slugs(): array {
		$def = 'en';
		$pt  = 'pt';
		if ( function_exists( 'pll_default_language' ) ) {
			$d = (string) pll_default_language( 'slug' );
			if ( '' !== $d ) {
				$def = $d;
			}
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			$list    = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
			foreach ( $list as $i => $slug ) {
				if ( $slug === $def ) {
					continue;
				}
				if ( 0 === stripos( (string) ( $locales[ $i ] ?? '' ), 'pt' ) ) {
					$pt = (string) $slug;
					break;
				}
			}
		}
		return array( 'default' => $def, 'pt' => $pt );
	}

	/**
	 * Rough reading time in minutes from post content (≈200 wpm, min 2).
	 *
	 * @param string $content Post content.
	 */
	private static function reading_time( string $content ): int {
		$words = str_word_count( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		return max( 2, (int) ceil( $words / 200 ) );
	}

	/* ---------- parsing ---------- */

	/**
	 * All chapter files on disk.
	 *
	 * @return array<int,string> Absolute paths.
	 */
	public static function files(): array {
		$files = glob( self::dir() . '*.md' );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * Parse one chapter file into frontmatter + per-language raw bodies.
	 *
	 * @param string $path File path.
	 * @return array<string,mixed>|null
	 */
	public static function parse_file( string $path ): ?array {
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === $raw ) {
			return null;
		}
		$raw = str_replace( "\r\n", "\n", $raw );

		if ( ! preg_match( '/^---\n(.*?)\n---\n(.*)$/s', $raw, $m ) ) {
			return null;
		}
		$front = self::parse_front( $m[1] );
		$body  = $m[2];

		// Split the body into EN / PT halves.
		$en = $body;
		$pt = '';
		if ( false !== strpos( $body, '<!-- PT -->' ) ) {
			$parts = explode( '<!-- PT -->', $body, 2 );
			$en    = $parts[0];
			$pt    = $parts[1] ?? '';
		}
		$en = trim( str_replace( '<!-- EN -->', '', $en ) );
		$pt = trim( $pt );

		$front['body_en'] = $en;
		$front['body_pt'] = $pt;
		return $front;
	}

	/**
	 * Minimal YAML-ish frontmatter parser (key: value, lists as a,b,c).
	 *
	 * @param string $text Frontmatter block.
	 * @return array<string,mixed>
	 */
	private static function parse_front( string $text ): array {
		$out = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( '' === trim( $line ) || ! preg_match( '/^([a-z_]+):\s*(.*)$/', trim( $line ), $m ) ) {
				continue;
			}
			$key = $m[1];
			$val = trim( $m[2] );
			$val = trim( $val, '"\'' );
			if ( 'glossary' === $key ) {
				$val = array_values( array_filter( array_map( 'trim', explode( ',', $val ) ) ) );
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	/* ---------- markdown → blocks ---------- */

	/**
	 * Convert a chapter body (our small Markdown dialect) to Gutenberg blocks.
	 *
	 * Supports: a leading `> ` TL;DR callout, `## `/`### ` headings, `- ` lists,
	 * a "Key takeaways"/"Pontos-chave" heading whose list becomes the takeaways
	 * box, `**bold**`, and `[glossary:slug|Text]` / `[learn:slug|Text]` links.
	 *
	 * @param string $body        Raw body half.
	 * @param string $tldr_label  Localized "In one line" label.
	 * @param array  $takeaway_h  Heading texts that mark the takeaways list.
	 * @return string Block markup.
	 */
	private static function to_blocks( string $body, string $tldr_label, array $takeaway_h ): string {
		$lines = explode( "\n", $body );
		$out   = '';
		$list  = array();
		$in_takeaways = false;

		$flush_list = static function () use ( &$list, &$out, &$in_takeaways, $takeaway_h ) {
			if ( ! $list ) {
				return;
			}
			if ( $in_takeaways ) {
				$out .= self::block_takeaways( $list, $in_takeaways );
			} else {
				$out .= self::block_list( $list );
			}
			$list         = array();
			$in_takeaways = false;
		};

		foreach ( $lines as $line ) {
			$t = trim( $line );

			if ( '' === $t ) {
				$flush_list();
				continue;
			}
			// List item.
			if ( preg_match( '/^[-*]\s+(.*)$/', $t, $m ) ) {
				$list[] = $m[1];
				continue;
			}
			$flush_list();

			// TL;DR callout.
			if ( preg_match( '/^>\s+(.*)$/', $t, $m ) ) {
				$out .= self::block_tldr( $m[1], $tldr_label );
				continue;
			}
			// Headings.
			if ( preg_match( '/^###\s+(.*)$/', $t, $m ) ) {
				$out .= self::block_heading( $m[1], 3 );
				continue;
			}
			if ( preg_match( '/^##\s+(.*)$/', $t, $m ) ) {
				$heading = $m[1];
				if ( in_array( mb_strtolower( trim( $heading ) ), array_map( 'mb_strtolower', $takeaway_h ), true ) ) {
					// The next list becomes the takeaways box (remember its label).
					$in_takeaways = $heading;
					continue;
				}
				$out .= self::block_heading( $heading, 2 );
				continue;
			}
			if ( preg_match( '/^#\s+/', $t ) ) {
				continue; // H1 lives in the post title, skip in body.
			}

			$out .= self::block_paragraph( $t );
		}
		$flush_list();

		return $out;
	}

	/**
	 * Inline formatting: escape, then apply **bold** and link tokens.
	 *
	 * @param string $text Raw inline text.
	 */
	private static function inline( string $text ): string {
		$html = esc_html( $text );
		$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = preg_replace_callback(
			'/\[(glossary|learn):([a-z0-9-]+)\|(.+?)\]/',
			static function ( array $m ): string {
				$base = 'glossary' === $m[1] ? '/investing-glossary/' : '/learn/';
				return '<a href="' . esc_url( home_url( $base . $m[2] . '/' ) ) . '">' . $m[3] . '</a>';
			},
			(string) $html
		);
		return (string) $html;
	}

	/* ---------- quiz ---------- */

	/**
	 * Split a body into its prose and an optional `## Quiz` / `## Questionário`
	 * section (parsed into questions). The quiz is removed from the prose.
	 *
	 * @param string $body Raw language body.
	 * @return array{0:string,1:array<int,array<string,mixed>>}
	 */
	private static function split_quiz( string $body ): array {
		if ( ! preg_match( '/^##\s+(quiz|question[aá]rio)\s*$/imu', $body, $m, PREG_OFFSET_CAPTURE ) ) {
			return array( $body, array() );
		}
		$at      = (int) $m[0][1];
		$prose   = rtrim( substr( $body, 0, $at ) );
		$section = substr( $body, $at );
		// Drop the heading line itself.
		$section = (string) preg_replace( '/^##\s+.*$/m', '', $section, 1 );
		return array( $prose, self::parse_quiz( $section ) );
	}

	/**
	 * Parse a quiz section: numbered questions with `- [ ]` / `- [x]` options.
	 *
	 * @param string $text Quiz section text.
	 * @return array<int,array{q:string,options:array<int,array{t:string,c:bool}>}>
	 */
	private static function parse_quiz( string $text ): array {
		$quiz = array();
		$cur  = null;
		foreach ( explode( "\n", $text ) as $line ) {
			$t = trim( $line );
			if ( '' === $t ) {
				continue;
			}
			if ( preg_match( '/^\d+[.)]\s+(.*)$/', $t, $m ) ) {
				if ( $cur && ! empty( $cur['options'] ) ) {
					$quiz[] = $cur;
				}
				$cur = array( 'q' => trim( $m[1] ), 'options' => array() );
				continue;
			}
			if ( $cur && preg_match( '/^[-*]\s*\[([ xX])\]\s*(.*)$/', $t, $m ) ) {
				$cur['options'][] = array( 't' => trim( $m[2] ), 'c' => 'x' === strtolower( $m[1] ) );
			}
		}
		if ( $cur && ! empty( $cur['options'] ) ) {
			$quiz[] = $cur;
		}
		// Keep only well-formed questions (≥2 options, exactly one correct).
		return array_values(
			array_filter(
				$quiz,
				static function ( $q ) {
					$correct = array_filter( $q['options'], static fn( $o ) => $o['c'] );
					return count( $q['options'] ) >= 2 && 1 === count( $correct );
				}
			)
		);
	}

	/**
	 * Store (or clear) a chapter's quiz on its post.
	 *
	 * @param int                          $post_id Post id (0 = skip).
	 * @param array<int,array<string,mixed>> $quiz    Parsed quiz.
	 */
	private static function save_quiz( int $post_id, array $quiz ): void {
		if ( ! $post_id ) {
			return;
		}
		if ( empty( $quiz ) ) {
			delete_post_meta( $post_id, self::META_QUIZ );
			return;
		}
		update_post_meta( $post_id, self::META_QUIZ, wp_slash( wp_json_encode( $quiz ) ) );
	}

	/**
	 * Read a chapter's quiz (decoded), or an empty array.
	 *
	 * @param int $post_id Post id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_quiz( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_QUIZ, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	private static function block_paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . self::inline( $text ) . '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	private static function block_heading( string $text, int $level ): string {
		$tag = 3 === $level ? 'h3' : 'h2';
		$lvl = 3 === $level ? ' {"level":3}' : '';
		return '<!-- wp:heading' . $lvl . ' --><' . $tag . ' class="wp-block-heading">' . self::inline( $text ) . '</' . $tag . '><!-- /wp:heading -->' . "\n\n";
	}

	private static function block_list( array $items ): string {
		$lis = '';
		foreach ( $items as $item ) {
			$lis .= '<!-- wp:list-item --><li>' . self::inline( $item ) . '</li><!-- /wp:list-item -->';
		}
		return '<!-- wp:list --><ul class="wp-block-list">' . $lis . '</ul><!-- /wp:list -->' . "\n\n";
	}

	private static function block_takeaways( array $items, string $label ): string {
		return self::block_heading( $label, 2 ) . self::block_list( $items );
	}

	private static function block_tldr( string $text, string $label ): string {
		return '<!-- wp:paragraph {"backgroundColor":"primary-soft","className":"hti-tldr"} -->'
			. '<p class="hti-tldr has-primary-soft-background-color has-background">'
			. '<strong>' . esc_html( $label ) . ':</strong> ' . self::inline( $text )
			. '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	/* ---------- import ---------- */

	/**
	 * Import (upsert) every chapter. Returns a per-chapter report.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function import(): array {
		// Load all chapters first, keyed by slug, for cross-references.
		$chapters = array();
		foreach ( self::files() as $path ) {
			$c = self::parse_file( $path );
			if ( $c && ! empty( $c['slug'] ) ) {
				$chapters[ (string) $c['slug'] ] = $c;
			}
		}

		$report = array();
		foreach ( $chapters as $slug => $c ) {
			$report[] = self::import_chapter( $c, $chapters );
		}
		return $report;
	}

	/**
	 * Upsert one chapter (EN + PT) and link them.
	 *
	 * @param array<string,mixed> $c        Parsed chapter.
	 * @param array<string,mixed> $chapters All chapters (reserved for cross-references).
	 * @return array<string,mixed>
	 */
	private static function import_chapter( array $c, array $chapters ): array {
		$slug    = (string) $c['slug'];
		$slug_pt = (string) ( $c['slug_pt'] ?? ( $slug . '-pt' ) );
		$topic   = (string) ( $c['topic'] ?? 'concepts' );

		// Split the optional end-of-chapter quiz out of each body.
		list( $body_en, $quiz_en ) = self::split_quiz( (string) $c['body_en'] );
		list( $body_pt, $quiz_pt ) = self::split_quiz( (string) $c['body_pt'] );

		// The chapter prose is the whole content. The end-of-chapter glossary
		// "Learn more" line and the questionnaire CTA were removed: they broke
		// the reading flow before the quiz / course nav. Prev/next is rendered
		// by the howtoinvest/learn-nav block, the quiz by howtoinvest/learn-quiz.
		$content_en = self::to_blocks( $body_en, 'In one line', array( 'Key takeaways' ) );
		$content_pt = self::to_blocks( $body_pt, 'Em uma linha', array( 'Pontos-chave' ) );

		$en_id = self::upsert( $slug, (string) ( $c['title_en'] ?? $slug ), $content_en, (string) ( $c['excerpt_en'] ?? '' ) );
		$pt_id = 0;
		if ( ! empty( $c['title_pt'] ) && ! empty( $c['body_pt'] ) ) {
			$pt_id = self::upsert( $slug_pt, (string) $c['title_pt'], $content_pt, (string) ( $c['excerpt_pt'] ?? '' ) );
		}

		// Store / clear the quiz on each language's post.
		self::save_quiz( $en_id, $quiz_en );
		self::save_quiz( $pt_id, $quiz_pt );

		// Language + translation linking (no-op without Polylang).
		self::link_languages( $en_id, $pt_id );

		// Topic terms (reuse the seeder's term convention: EN slug, PT slug+'-pt').
		self::assign_topic( $en_id, $topic, 'en' );
		if ( $pt_id ) {
			self::assign_topic( $pt_id, $topic, 'pt' );
		}

		return array(
			'slug'      => $slug,
			'module'    => $c['module'] ?? '',
			'order'     => $c['order'] ?? '',
			'title'     => $c['title_en'] ?? $slug,
			'en_id'     => $en_id,
			'pt_id'     => $pt_id,
			'en_status' => $en_id ? get_post_status( $en_id ) : 'none',
			'pt_status' => $pt_id ? get_post_status( $pt_id ) : 'none',
		);
	}

	/**
	 * Create (published) or update a `learn` post by slug. New posts are
	 * published straight away; an existing post keeps its current status, so a
	 * re-sync never reverts something an editor unpublished.
	 *
	 * @return int Post id (0 on failure).
	 */
	private static function upsert( string $slug, string $title, string $content, string $excerpt ): int {
		$existing = get_page_by_path( $slug, OBJECT, self::TYPE );

		$data = array(
			'post_type'    => self::TYPE,
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => wp_slash( $content ),
			'post_excerpt' => $excerpt,
		);

		if ( $existing instanceof \WP_Post ) {
			$data['ID'] = (int) $existing->ID;
			$id         = wp_update_post( $data, true );
		} else {
			$data['post_status'] = 'publish';
			$id                  = wp_insert_post( $data, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		$id = (int) $id;

		// SEO meta (description) for whichever plugin is active.
		if ( '' !== $excerpt ) {
			update_post_meta( $id, '_rank_math_description', $excerpt );
			update_post_meta( $id, '_yoast_wpseo_metadesc', $excerpt );
		}
		return $id;
	}

	/**
	 * Set language on EN/PT posts and link them as a translation pair.
	 */
	private static function link_languages( int $en_id, int $pt_id ): void {
		if ( ! $en_id || ! function_exists( 'pll_set_post_language' ) ) {
			return;
		}
		$L = self::lang_slugs();
		// Always (re)assert the default language so the pair links reliably even
		// if a prior import left it unset or wrong.
		pll_set_post_language( $en_id, $L['default'] );
		if ( $pt_id ) {
			pll_set_post_language( $pt_id, $L['pt'] );
			if ( function_exists( 'pll_save_post_translations' ) ) {
				pll_save_post_translations( array( $L['default'] => $en_id, $L['pt'] => $pt_id ) );
			}
		}
	}

	/**
	 * File a post under its learn_topic term (creating the term if needed).
	 *
	 * @param int    $post_id Post id.
	 * @param string $topic   Topic slug (EN).
	 * @param string $lang    Language ('en' uses slug; 'pt' uses slug-pt).
	 */
	private static function assign_topic( int $post_id, string $topic, string $lang ): void {
		if ( ! taxonomy_exists( 'learn_topic' ) ) {
			return;
		}
		$slug = 'pt' === $lang ? $topic . '-pt' : $topic;
		$term = get_term_by( 'slug', $slug, 'learn_topic' );
		if ( ! $term instanceof \WP_Term ) {
			return; // Terms are created by the seeder; don't invent taxonomy here.
		}
		wp_set_object_terms( $post_id, array( (int) $term->term_id ), 'learn_topic', false );
	}

	/* ---------- admin ---------- */

	/**
	 * Tools → Learn content.
	 */
	public static function menu(): void {
		add_management_page(
			__( 'Learn content', 'hti-engine' ),
			__( 'Learn content', 'hti-engine' ),
			'manage_options',
			'hti-learn-content',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Status table + import button.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$imported = isset( $_GET['imported'] ) ? absint( wp_unslash( $_GET['imported'] ) ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Build the status view from the files reconciled with WordPress.
		$rows = array();
		foreach ( self::files() as $path ) {
			$c = self::parse_file( $path );
			if ( ! $c || empty( $c['slug'] ) ) {
				continue;
			}
			$slug    = (string) $c['slug'];
			$slug_pt = (string) ( $c['slug_pt'] ?? ( $slug . '-pt' ) );
			$en      = get_page_by_path( $slug, OBJECT, self::TYPE );
			$pt      = get_page_by_path( $slug_pt, OBJECT, self::TYPE );
			$rows[]  = array(
				'module' => (string) ( $c['module'] ?? '' ),
				'order'  => (string) ( $c['order'] ?? '' ),
				'title'  => (string) ( $c['title_en'] ?? $slug ),
				'slug'   => $slug,
				'en'     => $en instanceof \WP_Post ? get_post_status( $en ) : 'not imported',
				'pt'     => $pt instanceof \WP_Post ? get_post_status( $pt ) : 'not imported',
				'plan'   => (string) ( $c['status'] ?? '' ),
			);
		}
		usort(
			$rows,
			static fn( $a, $b ) => array( $a['module'], $a['order'] ) <=> array( $b['module'], $b['order'] )
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Learn content', 'hti-engine' ); ?></h1>
			<p><?php esc_html_e( 'Imports the bilingual guides in content/learn/*.md. New chapters are published straight away in both languages (linked as translations); existing posts are updated in place and keep their current status, so a re-sync never reverts an editor change. Idempotent by slug.', 'hti-engine' ); ?></p>

			<?php if ( $imported >= 0 ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php /* translators: %d: chapters processed. */ printf( esc_html__( 'Imported/synced %d chapters (new ones published in both languages).', 'hti-engine' ), (int) $imported ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0">
				<?php wp_nonce_field( 'hti_import_learn' ); ?>
				<input type="hidden" name="action" value="hti_import_learn">
				<?php submit_button( __( 'Import / sync Learn content', 'hti-engine' ), 'primary', 'submit', false ); ?>
			</form>

			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Module', 'hti-engine' ); ?></th>
					<th><?php esc_html_e( 'Title', 'hti-engine' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'hti-engine' ); ?></th>
					<th><?php esc_html_e( 'Plan', 'hti-engine' ); ?></th>
					<th><?php esc_html_e( 'EN', 'hti-engine' ); ?></th>
					<th><?php esc_html_e( 'PT', 'hti-engine' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No chapter files found in content/learn/.', 'hti-engine' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><?php echo esc_html( $r['module'] . ( '' !== $r['order'] ? '.' . $r['order'] : '' ) ); ?></td>
								<td><?php echo esc_html( $r['title'] ); ?></td>
								<td><code><?php echo esc_html( $r['slug'] ); ?></code></td>
								<td><?php echo esc_html( $r['plan'] ); ?></td>
								<td><?php echo esc_html( $r['en'] ); ?></td>
								<td><?php echo esc_html( $r['pt'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle the import button.
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hti_import_learn' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-engine' ) );
		}
		$report = self::import();
		wp_safe_redirect( add_query_arg( 'imported', count( $report ), admin_url( 'tools.php?page=hti-learn-content' ) ) );
		exit;
	}
}
