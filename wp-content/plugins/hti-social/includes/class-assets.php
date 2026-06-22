<?php
/**
 * Enqueues the editor assets (CSS + templates + engine) and the shared config,
 * only on the generator page and on the News/Glossary post editors.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Asset wiring + the localized boot config.
 */
class Assets {

	const HANDLE = 'hti-social';

	/**
	 * Hook admin asset loading.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue on the generator page or a relevant post editor.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function maybe_enqueue( string $hook ): void {
		$is_generator = ( 'toplevel_page_hti-social' === $hook || str_ends_with( $hook, '_page_hti-social' ) );
		$is_editor    = false;

		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && in_array( $screen->post_type, Templates::metabox_post_types(), true ) ) {
				$is_editor = true;
			}
		}

		if ( ! $is_generator && ! $is_editor ) {
			return;
		}

		self::enqueue();
	}

	/**
	 * Register + enqueue the CSS/JS and localize the config.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE, HTI_SOCIAL_URL . 'assets/css/social.css', array(), VERSION );

		// Preview fonts (real woff2) so the on-screen card matches the export.
		wp_add_inline_style( self::HANDLE, self::font_face_css() );

		wp_enqueue_script( self::HANDLE . '-templates', HTI_SOCIAL_URL . 'assets/js/templates.js', array(), VERSION, array( 'in_footer' => true ) );
		wp_enqueue_script( self::HANDLE, HTI_SOCIAL_URL . 'assets/js/social.js', array( self::HANDLE . '-templates' ), VERSION, array( 'in_footer' => true ) );

		wp_localize_script( self::HANDLE, 'HTI_SOCIAL', self::boot_data() );
	}

	/**
	 * @font-face rules for the on-screen preview (export embeds base64 itself).
	 */
	private static function font_face_css(): string {
		$css = '';
		foreach ( Brand::font_faces() as $f ) {
			$css .= sprintf(
				"@font-face{font-family:'%s';font-style:normal;font-weight:%d;font-display:swap;src:url('%s') format('woff2');}",
				$f['family'],
				$f['weight'],
				esc_url( $f['url'] )
			);
		}
		return $css;
	}

	/**
	 * CDN URLs for the optional ffmpeg.wasm MP4 converter (single-thread core,
	 * so no cross-origin-isolation headers are required). Filterable so the site
	 * can self-host instead of using unpkg.
	 *
	 * @return array<string,string>
	 */
	public static function ffmpeg_urls(): array {
		return (array) apply_filters(
			'hti_social_ffmpeg_urls',
			array(
				'ffmpeg' => 'https://unpkg.com/@ffmpeg/ffmpeg@0.12.10/dist/umd/ffmpeg.js',
				'util'   => 'https://unpkg.com/@ffmpeg/util@0.12.1/dist/umd/index.js',
				'core'   => 'https://unpkg.com/@ffmpeg/core@0.12.6/dist/umd/ffmpeg-core.js',
				'wasm'   => 'https://unpkg.com/@ffmpeg/core@0.12.6/dist/umd/ffmpeg-core.wasm',
				// The FFmpeg class spawns this worker; cross-origin Worker
				// construction is blocked, so we load it as a same-origin blob.
				'worker' => 'https://unpkg.com/@ffmpeg/ffmpeg@0.12.10/dist/umd/814.ffmpeg.js',
			)
		);
	}

	/**
	 * Config consumed by social.js.
	 *
	 * @return array<string,mixed>
	 */
	public static function boot_data(): array {
		$pt = 'pt' === Plugin::locale();
		return array(
			'locale'      => Plugin::locale(),
			'restCaption' => esc_url_raw( rest_url( 'hti-social/v1/caption' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'aiEnabled'   => Gemini::is_configured(),
			'ffmpeg'      => self::ffmpeg_urls(),
			'restFfmpeg'  => esc_url_raw( rest_url( 'hti-social/v1/ffmpeg-assets' ) ),
			'logoSvg'     => Brand::logo_svg(),
			'illoShip'    => Brand::ship_svg(),
			'illoGold'    => Brand::gold_svg(),
			'brand'       => Brand::defaults(),
			'disclaimers' => Brand::disclaimers(),
			'fontFaces'   => Brand::font_faces(),
			'categories'  => Templates::categories(),
			'i18n'        => array(
				'title'        => $pt ? 'Gerador de conteúdo social' : 'Social content generator',
				'pick'         => $pt ? 'Escolhe um modelo' : 'Pick a template',
				'fields'       => $pt ? 'Conteúdo' : 'Content',
				'image'        => $pt ? 'Imagem' : 'Image',
				'choose_image' => $pt ? 'Escolher imagem…' : 'Choose image…',
				'remove_image' => $pt ? 'Remover' : 'Remove',
				'handle'       => $pt ? 'Handle / domínio' : 'Handle / domain',
				'legal'        => $pt ? 'Mostrar disclaimer' : 'Show disclaimer',
				'lang'         => $pt ? 'Idioma do disclaimer' : 'Disclaimer language',
				'export'       => $pt ? 'Exportar PNG' : 'Export PNG',
				'exporting'    => $pt ? 'A exportar…' : 'Exporting…',
				'drop'         => $pt ? 'Arrasta uma imagem para aqui' : 'Drag an image here',
				'video'        => $pt ? 'Vídeo' : 'Video',
				'choose_video' => $pt ? 'Escolher vídeo…' : 'Choose video…',
				'title'        => $pt ? 'Título' : 'Title',
				'caption'      => $pt ? 'Legenda' : 'Caption',
				'render'       => $pt ? 'Gerar reel (WebM)' : 'Render reel (WebM)',
				'rendering'    => $pt ? 'A gravar…' : 'Recording…',
				'render_hint'  => $pt ? 'A gravação corre em tempo real (a duração do vídeo) — mantém este separador à frente.' : 'Recording runs in real time (the video length) — keep this tab focused.',
				'need_video'   => $pt ? 'Primeiro escolhe um vídeo.' : 'Choose a video first.',
				'no_support'   => $pt ? 'O teu browser não suporta a gravação. Usa o Chrome ou o Firefox.' : 'Your browser cannot record. Use Chrome or Firefox.',
				'webm_note'    => $pt ? 'O ficheiro sai em WebM. O Instagram pede MP4 — converte rapidamente num conversor WebM→MP4.' : 'Output is WebM. Instagram wants MP4 — convert quickly with a WebM→MP4 converter.',
				'ai'           => $pt ? 'Assistente IA' : 'AI assistant',
				'ai_on'        => $pt ? 'Gerar texto com IA' : 'Generate text with AI',
				'ai_brief'     => $pt ? 'Tema do vídeo (ex.: Warren Buffett sobre juro composto)' : 'Video topic (e.g. Warren Buffett on compound interest)',
				'ai_go'        => $pt ? 'Gerar com IA' : 'Generate with AI',
				'ai_working'   => $pt ? 'A gerar…' : 'Generating…',
				'ai_desc'      => $pt ? 'Descrição para a publicação' : 'Post description',
				'ai_copy'      => $pt ? 'Copiar' : 'Copy',
				'ai_copied'    => $pt ? 'Copiado ✓' : 'Copied ✓',
				'ai_off_note'  => $pt ? 'Chave Gemini não configurada no servidor — escreve o texto manualmente.' : 'Gemini key not configured on the server — write the text manually.',
				'ai_error'     => $pt ? 'Não foi possível gerar. Tenta de novo.' : 'Could not generate. Try again.',
				'show_caption' => $pt ? 'Mostrar legenda no overlay' : 'Show caption on the overlay',
				'anim_caps'    => $pt ? 'Legendas animadas (palavra a palavra)' : 'Animated captions (word by word)',
				'anim_hint'    => $pt ? 'Distribui as palavras da legenda ao longo do vídeo. Substitui a legenda fixa.' : 'Spreads the caption words across the video. Replaces the fixed caption.',
				'end_card'     => $pt ? 'Cartão final (CTA)' : 'End card (CTA)',
				'end_title'    => $pt ? 'Título do cartão final' : 'End-card title',
				'end_cta'      => $pt ? 'Botão do cartão final' : 'End-card button',
				'mp4'          => $pt ? 'Exportar em MP4 (experimental)' : 'Export as MP4 (experimental)',
				'mp4_note'     => $pt ? 'Converte para MP4 no browser (carrega ~30 MB na 1.ª vez, mais lento). Pronto para o Instagram.' : 'Converts to MP4 in the browser (loads ~30 MB the first time, slower). Instagram-ready.',
				'mp4_loading'  => $pt ? 'A carregar o conversor…' : 'Loading the converter…',
				'mp4_doing'    => $pt ? 'A converter para MP4…' : 'Converting to MP4…',
				'mp4_fail'     => $pt ? 'A conversão para MP4 falhou — guardei o WebM.' : 'MP4 conversion failed — saved the WebM instead.',
			),
		);
	}
}
