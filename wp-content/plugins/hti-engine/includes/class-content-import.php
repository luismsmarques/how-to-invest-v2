<?php
/**
 * Learn content importer — the editorial pipeline (NOT the seeder).
 *
 * Source of truth for Learn guides is a set of bilingual Markdown files in
 * `content/learn/*.md` (authored with the learn-guide skill). This importer
 * parses them and upserts `learn` posts as DRAFTS for review — idempotent by
 * slug, language-linked via Polylang, filed under the right `learn_topic`, with
 * a TL;DR callout, key-takeaways box and auto-built glossary + chapter links.
 *
 * The seeder stays install-only; ongoing content lives here. Nothing is ever
 * auto-published — you review and publish in WordPress.
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

	/**
	 * A "label: link · link" related line.
	 *
	 * @param string                              $label Localized lead label.
	 * @param array<int,array{0:string,1:string}> $links [url, text] pairs.
	 */
	private static function block_related( string $label, array $links ): string {
		if ( ! $links ) {
			return '';
		}
		$parts = array();
		foreach ( $links as $link ) {
			$parts[] = '<a href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a>';
		}
		return '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size">'
			. esc_html( $label ) . ': ' . implode( ' · ', $parts )
			. '</p><!-- /wp:paragraph -->' . "\n\n";
	}

	private static function block_cta(): string {
		return '<!-- wp:pattern {"slug":"howtoinvest/cta-questionnaire"} /-->' . "\n\n";
	}

	/* ---------- import ---------- */

	/**
	 * Localized labels for the generated blocks.
	 *
	 * @param string $lang 'en'|'pt'.
	 * @return array<string,mixed>
	 */
	private static function labels( string $lang ): array {
		if ( 'pt' === $lang ) {
			return array(
				'tldr'      => 'Em uma linha',
				'takeaways' => array( 'Key takeaways', 'Pontos-chave' ),
				'learn'     => 'Sabe mais',
				'continue'  => 'Continua o percurso',
				'prev'      => 'Anterior',
				'next'      => 'A seguir',
			);
		}
		return array(
			'tldr'      => 'In one line',
			'takeaways' => array( 'Key takeaways', 'Pontos-chave' ),
			'learn'     => 'Learn more',
			'continue'  => 'Continue the path',
			'prev'      => 'Previous',
			'next'      => 'Next',
		);
	}

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
	 * @param array<string,mixed> $chapters All chapters (for prev/next labels).
	 * @return array<string,mixed>
	 */
	private static function import_chapter( array $c, array $chapters ): array {
		$slug    = (string) $c['slug'];
		$slug_pt = (string) ( $c['slug_pt'] ?? ( $slug . '-pt' ) );
		$topic   = (string) ( $c['topic'] ?? 'concepts' );

		$content_en = self::to_blocks( (string) $c['body_en'], 'In one line', array( 'Key takeaways' ) )
			. self::related_blocks( $c, $chapters, 'en' )
			. self::block_cta();
		$content_pt = self::to_blocks( (string) $c['body_pt'], 'Em uma linha', array( 'Pontos-chave' ) )
			. self::related_blocks( $c, $chapters, 'pt' )
			. self::block_cta();

		$en_id = self::upsert( $slug, (string) ( $c['title_en'] ?? $slug ), $content_en, (string) ( $c['excerpt_en'] ?? '' ) );
		$pt_id = 0;
		if ( ! empty( $c['title_pt'] ) && ! empty( $c['body_pt'] ) ) {
			$pt_id = self::upsert( $slug_pt, (string) $c['title_pt'], $content_pt, (string) ( $c['excerpt_pt'] ?? '' ) );
		}

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
	 * Build the "Learn more" (glossary) and "Continue the path" (prev/next) lines.
	 *
	 * @param array<string,mixed> $c        Chapter.
	 * @param array<string,mixed> $chapters All chapters.
	 * @param string              $lang     Language.
	 */
	private static function related_blocks( array $c, array $chapters, string $lang ): string {
		$l   = self::labels( $lang );
		$out = '';

		// Glossary terms → link to the localized term when it exists.
		$gloss = array();
		foreach ( (array) ( $c['glossary'] ?? array() ) as $g_slug ) {
			$link = self::glossary_link( (string) $g_slug, $lang );
			if ( $link ) {
				$gloss[] = $link;
			}
		}
		$out .= self::block_related( $l['learn'], $gloss );

		// Prev / next chapters.
		$nav = array();
		$prev = (string) ( $c['prev'] ?? '' );
		$next = (string) ( $c['next'] ?? '' );
		if ( '' !== $prev ) {
			$nav[] = array( self::chapter_url( $prev, $chapters, $lang ), $l['prev'] . ': ' . self::chapter_title( $prev, $chapters, $lang ) );
		}
		if ( '' !== $next ) {
			$nav[] = array( self::chapter_url( $next, $chapters, $lang ), $l['next'] . ': ' . self::chapter_title( $next, $chapters, $lang ) );
		}
		$out .= self::block_related( $l['continue'], $nav );

		return $out;
	}

	/**
	 * [url, text] for a glossary term in a language, or null if it does not exist.
	 *
	 * @param string $slug Glossary slug.
	 * @param string $lang Language.
	 * @return array{0:string,1:string}|null
	 */
	private static function glossary_link( string $slug, string $lang ): ?array {
		$en = get_page_by_path( $slug, OBJECT, 'glossary' );
		if ( ! $en instanceof \WP_Post ) {
			return null;
		}
		$id = (int) $en->ID;
		if ( 'pt' === $lang && function_exists( 'pll_get_post' ) ) {
			$pt = (int) pll_get_post( $id, 'pt' );
			if ( $pt ) {
				$id = $pt;
			}
		}
		return array( (string) get_permalink( $id ), wp_strip_all_tags( get_the_title( $id ) ) );
	}

	/**
	 * Title of a referenced chapter (from frontmatter, falling back to slug).
	 */
	private static function chapter_title( string $slug, array $chapters, string $lang ): string {
		$c = $chapters[ $slug ] ?? null;
		if ( $c ) {
			$key = 'pt' === $lang ? 'title_pt' : 'title_en';
			if ( ! empty( $c[ $key ] ) ) {
				return (string) $c[ $key ];
			}
		}
		return ucwords( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * URL of a referenced chapter in a language.
	 */
	private static function chapter_url( string $slug, array $chapters, string $lang ): string {
		$c   = $chapters[ $slug ] ?? null;
		$use = $slug;
		if ( 'pt' === $lang && $c ) {
			$use = (string) ( $c['slug_pt'] ?? ( $slug . '-pt' ) );
		}
		return home_url( '/learn/' . $use . '/' );
	}

	/**
	 * Create (draft) or update a `learn` post by slug. Existing status is kept.
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
			$data['post_status'] = 'draft';
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
		if ( ! pll_get_post_language( $en_id ) ) {
			pll_set_post_language( $en_id, 'en' );
		}
		if ( $pt_id ) {
			if ( ! pll_get_post_language( $pt_id ) ) {
				pll_set_post_language( $pt_id, 'pt' );
			}
			if ( function_exists( 'pll_save_post_translations' ) ) {
				pll_save_post_translations( array( 'en' => $en_id, 'pt' => $pt_id ) );
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
			<p><?php esc_html_e( 'Imports the bilingual guides in content/learn/*.md as drafts for review. Idempotent — re-running updates existing posts and never changes their published status. Nothing is auto-published.', 'hti-engine' ); ?></p>

			<?php if ( $imported >= 0 ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php /* translators: %d: chapters processed. */ printf( esc_html__( 'Imported/updated %d chapters as drafts.', 'hti-engine' ), (int) $imported ); ?>
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
