<?php
/**
 * Glossary content pipeline (mirrors the Learn pipeline).
 *
 * Expands the seeded one-line glossary terms into fuller bilingual articles from
 * Markdown in content/glossary/*.md, for SEO depth and AI-citability. Idempotent
 * by slug: existing terms are updated in place (the EN post is matched by slug,
 * the PT post by its linked Polylang translation), keeping their status. Reuses
 * the Learn converter (Content_Import::to_blocks) and language helpers.
 *
 * Run: `wp hti import-glossary` (CLI) or Tools → Glossary content (admin).
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Imports the bilingual glossary articles in content/glossary/*.md.
 */
class Glossary_Import {

	private const TYPE = 'glossary';

	/**
	 * Directory holding the Markdown terms (bundled with the plugin).
	 */
	public static function dir(): string {
		return HTI_ENGINE_PATH . 'content/glossary/';
	}

	/**
	 * Wire the admin screen + import handler.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_hti_import_glossary', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * All term files on disk.
	 *
	 * @return array<int,string> Absolute paths.
	 */
	public static function files(): array {
		$files = glob( self::dir() . '*.md' );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * Import (upsert) every term. Returns a per-term report.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function import(): array {
		$report = array();
		foreach ( self::files() as $path ) {
			$t = Content_Import::parse_file( $path );
			if ( $t && ! empty( $t['slug'] ) ) {
				$report[] = self::import_term( $t );
			}
		}
		return $report;
	}

	/**
	 * Upsert one term (EN + PT) and link them.
	 *
	 * @param array<string,mixed> $t Parsed term.
	 * @return array<string,mixed>
	 */
	private static function import_term( array $t ): array {
		$slug    = (string) $t['slug'];
		$slug_pt = (string) ( $t['slug_pt'] ?? $slug );
		$topic   = (string) ( $t['topic'] ?? '' );

		$content_en = Content_Import::to_blocks( (string) ( $t['body_en'] ?? '' ), 'In one line', array( 'Key takeaways' ) );
		$content_pt = Content_Import::to_blocks( (string) ( $t['body_pt'] ?? '' ), 'Em uma linha', array( 'Pontos-chave' ) );

		// EN: match the existing seeded term by slug (or create).
		$en_id = self::upsert_post(
			0,
			$slug,
			(string) ( $t['term_en'] ?? $slug ),
			$content_en,
			(string) ( $t['excerpt_en'] ?? '' ),
			(string) ( $t['seo_title_en'] ?? '' ),
			(string) ( $t['seo_desc_en'] ?? '' )
		);

		// PT: prefer the post's linked Polylang translation; else create by slug_pt.
		$pt_id = 0;
		if ( $en_id && function_exists( 'pll_get_post' ) ) {
			$pt_id = (int) pll_get_post( $en_id, Content_Import::lang_slugs()['pt'] );
		}
		if ( ! empty( $t['term_pt'] ) && ! empty( $t['body_pt'] ) ) {
			$pt_id = self::upsert_post(
				$pt_id,
				$slug_pt,
				(string) $t['term_pt'],
				$content_pt,
				(string) ( $t['excerpt_pt'] ?? '' ),
				(string) ( $t['seo_title_pt'] ?? '' ),
				(string) ( $t['seo_desc_pt'] ?? '' )
			);
		}

		self::link_languages( $en_id, $pt_id );
		self::assign_topic( $en_id, $topic, 'en' );
		if ( $pt_id ) {
			self::assign_topic( $pt_id, $topic, 'pt' );
		}

		return array(
			'slug'      => $slug,
			'term'      => (string) ( $t['term_en'] ?? $slug ),
			'en_id'     => $en_id,
			'pt_id'     => $pt_id,
			'en_status' => $en_id ? get_post_status( $en_id ) : 'none',
			'pt_status' => $pt_id ? get_post_status( $pt_id ) : 'none',
		);
	}

	/**
	 * Update an existing glossary post (by id, else by slug) or insert a new one.
	 * Sets the bilingual SEO title/description meta for whichever plugin is active.
	 *
	 * @param int    $id        Known post id (0 to resolve by slug / insert).
	 * @param string $slug      Post slug.
	 * @param string $title     Term title.
	 * @param string $content   Block content.
	 * @param string $excerpt   One-line definition (post excerpt + meta desc base).
	 * @param string $seo_title SEO title (optional).
	 * @param string $seo_desc  SEO description (optional; falls back to excerpt).
	 * @return int Post id (0 on failure).
	 */
	private static function upsert_post( int $id, string $slug, string $title, string $content, string $excerpt, string $seo_title, string $seo_desc ): int {
		$data = array(
			'post_type'    => self::TYPE,
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'post_excerpt' => $excerpt,
		);

		if ( ! $id ) {
			$existing = get_page_by_path( $slug, OBJECT, self::TYPE );
			if ( $existing instanceof \WP_Post ) {
				$id = (int) $existing->ID;
			}
		}

		if ( $id ) {
			$data['ID'] = $id;
			$res        = wp_update_post( $data, true );
		} else {
			$data['post_name']   = $slug;
			$data['post_status'] = 'publish';
			$res                 = wp_insert_post( $data, true );
		}
		if ( is_wp_error( $res ) || ! $res ) {
			return 0;
		}
		$id = (int) $res;

		self::write_seo_meta( $id, $seo_title, '' !== $seo_desc ? $seo_desc : $excerpt );
		return $id;
	}

	/**
	 * Store SEO title/description as meta for both RankMath and Yoast.
	 *
	 * @param int    $id    Post id.
	 * @param string $title SEO title.
	 * @param string $desc  SEO description.
	 */
	private static function write_seo_meta( int $id, string $title, string $desc ): void {
		if ( '' !== $title ) {
			update_post_meta( $id, 'rank_math_title', $title );
			update_post_meta( $id, '_yoast_wpseo_title', $title );
		}
		if ( '' !== $desc ) {
			update_post_meta( $id, 'rank_math_description', $desc );
			update_post_meta( $id, '_yoast_wpseo_metadesc', $desc );
		}
	}

	/**
	 * Set language on EN/PT posts and link them as a translation pair.
	 *
	 * @param int $en_id English post id.
	 * @param int $pt_id Portuguese post id.
	 */
	private static function link_languages( int $en_id, int $pt_id ): void {
		if ( ! $en_id || ! function_exists( 'pll_set_post_language' ) ) {
			return;
		}
		$L = Content_Import::lang_slugs();
		pll_set_post_language( $en_id, $L['default'] );
		if ( $pt_id ) {
			pll_set_post_language( $pt_id, $L['pt'] );
			if ( function_exists( 'pll_save_post_translations' ) ) {
				pll_save_post_translations( array( $L['default'] => $en_id, $L['pt'] => $pt_id ) );
			}
		}
	}

	/**
	 * File a post under its glossary_topic term (best-effort; never invents terms).
	 *
	 * @param int    $post_id Post id.
	 * @param string $topic   Topic slug.
	 * @param string $lang    Language ('en'|'pt').
	 */
	private static function assign_topic( int $post_id, string $topic, string $lang ): void {
		if ( '' === $topic || ! taxonomy_exists( 'glossary_topic' ) ) {
			return;
		}
		$candidates = 'pt' === $lang ? array( $topic . '-pt', $topic ) : array( $topic );
		foreach ( $candidates as $slug ) {
			$term = get_term_by( 'slug', $slug, 'glossary_topic' );
			if ( $term instanceof \WP_Term ) {
				wp_set_object_terms( $post_id, array( (int) $term->term_id ), 'glossary_topic', false );
				return;
			}
		}
	}

	/* ---------- admin ---------- */

	/**
	 * Tools → Glossary content.
	 */
	public static function menu(): void {
		add_management_page(
			__( 'Glossary content', 'hti-engine' ),
			__( 'Glossary content', 'hti-engine' ),
			'manage_options',
			'hti-glossary-content',
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

		$rows = array();
		foreach ( self::files() as $path ) {
			$t = Content_Import::parse_file( $path );
			if ( ! $t || empty( $t['slug'] ) ) {
				continue;
			}
			$slug   = (string) $t['slug'];
			$en     = get_page_by_path( $slug, OBJECT, self::TYPE );
			$rows[] = array(
				'term' => (string) ( $t['term_en'] ?? $slug ),
				'slug' => $slug,
				'en'   => $en instanceof \WP_Post ? get_post_status( $en ) : 'not imported',
			);
		}
		usort( $rows, static fn( $a, $b ) => strcmp( (string) $a['term'], (string) $b['term'] ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Glossary content', 'hti-engine' ); ?></h1>
			<p><?php esc_html_e( 'Expands the seeded glossary terms with the fuller bilingual articles in content/glossary/*.md. Existing terms are updated in place (EN by slug, PT by its linked translation) and keep their status; new terms are published in both languages. Idempotent by slug.', 'hti-engine' ); ?></p>

			<?php if ( $imported >= 0 ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php /* translators: %d: terms processed. */ printf( esc_html__( 'Imported/synced %d glossary terms.', 'hti-engine' ), (int) $imported ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0">
				<?php wp_nonce_field( 'hti_import_glossary' ); ?>
				<input type="hidden" name="action" value="hti_import_glossary">
				<?php submit_button( __( 'Import / sync Glossary content', 'hti-engine' ), 'primary', 'submit', false ); ?>
			</form>

			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Term', 'hti-engine' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'hti-engine' ); ?></th>
					<th><?php esc_html_e( 'EN status', 'hti-engine' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['term'] ); ?></td>
							<td><code><?php echo esc_html( $r['slug'] ); ?></code></td>
							<td><?php echo esc_html( $r['en'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No term files found in content/glossary/.', 'hti-engine' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle the import form submission.
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hti_import_glossary' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-engine' ) );
		}
		$report = self::import();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'hti-glossary-content',
					'imported' => count( $report ),
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}
}
