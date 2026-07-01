<?php
/**
 * Script → Reel: a submenu under "Social" where you paste a timed script
 * (hook + timestamped lines + caption) and the browser renders a vertical
 * 1080×1920 reel of branded scenes with an AI voice-over.
 *
 * The script is narrated segment by segment with Gemini TTS (server-side; the
 * key never reaches the browser). Each segment becomes a solid branded scene;
 * scenes are drawn to a canvas and captured with the narration audio via
 * MediaRecorder → WebM (optional MP4 via ffmpeg.wasm), exactly like the Reels
 * engine. No uploaded footage required.
 *
 * @package HTI_Social
 */

namespace HTI\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Script → Reel admin page + assets.
 */
class Script_Reel {

	/**
	 * Stored hook suffix of the submenu page (for precise asset loading).
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hook the menu + assets.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Register the submenu under the Social menu.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'hti-social',
			__( 'Script → Reel', 'hti-social' ),
			__( 'Script → Reel', 'hti-social' ),
			'edit_posts',
			'hti-social-script-reel',
			array( __CLASS__, 'render' )
		);
		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Available AI voices (Gemini prebuilt voices), filterable.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	public static function voices(): array {
		return (array) apply_filters(
			'hti_social_tts_voices',
			array(
				array( 'id' => 'Kore', 'label' => 'Kore — firme (F)' ),
				array( 'id' => 'Puck', 'label' => 'Puck — animada (M)' ),
				array( 'id' => 'Charon', 'label' => 'Charon — informativa (M)' ),
				array( 'id' => 'Aoede', 'label' => 'Aoede — leve (F)' ),
				array( 'id' => 'Fenrir', 'label' => 'Fenrir — intensa (M)' ),
				array( 'id' => 'Leda', 'label' => 'Leda — jovem (F)' ),
			)
		);
	}

	/**
	 * Enqueue only on the Script → Reel page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue( string $hook ): void {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 'hti-social', HTI_SOCIAL_URL . 'assets/css/social.css', array(), VERSION );
		wp_enqueue_style( 'hti-social-reels', HTI_SOCIAL_URL . 'assets/css/reels.css', array( 'hti-social' ), VERSION );
		wp_enqueue_style( 'hti-social-script-reel', HTI_SOCIAL_URL . 'assets/css/script-reel.css', array( 'hti-social-reels' ), VERSION );
		wp_add_inline_style( 'hti-social', self::font_face_css() );

		wp_enqueue_script( 'hti-social-script-reel', HTI_SOCIAL_URL . 'assets/js/script-reel.js', array(), VERSION, array( 'in_footer' => true ) );

		wp_localize_script( 'hti-social-script-reel', 'HTI_SOCIAL', Assets::boot_data() );
		wp_localize_script( 'hti-social-script-reel', 'HTI_SREEL', self::boot_data() );
	}

	/**
	 * Script → Reel specific config (voices + i18n).
	 *
	 * @return array<string,mixed>
	 */
	public static function boot_data(): array {
		$pt = 'pt' === Plugin::locale();
		return array(
			'voices'  => self::voices(),
			'aiVoice' => Gemini::is_configured(),
			'sample'  => self::sample_script( $pt ),
			'i18n'    => array(
				'script'      => $pt ? 'Script' : 'Script',
				'script_hint' => $pt ? 'Cola o teu guião: um "Hook", linhas com tempos (0-1s, 2-5s…) e uma "Caption". A narração é gerada por segmento.' : 'Paste your script: a "Hook", timestamped lines (0-1s, 2-5s…) and a "Caption". Narration is generated per segment.',
				'voice'       => $pt ? 'Voz (IA)' : 'Voice (AI)',
				'no_voice'    => $pt ? 'Chave Gemini não configurada — o reel sai sem voz (só texto + música).' : 'Gemini key not configured — the reel renders without voice (text + music only).',
				'music'       => $pt ? 'Música de fundo (opcional)' : 'Background music (optional)',
				'choose_music' => $pt ? 'Escolher áudio…' : 'Choose audio…',
				'parse'       => $pt ? 'Analisar script' : 'Parse script',
				'segments'    => $pt ? 'Cenas' : 'Scenes',
				'voice_gen'   => $pt ? 'Gerar narração' : 'Generate narration',
				'voice_wait'  => $pt ? 'A gerar voz…' : 'Generating voice…',
				'voice_ready' => $pt ? 'Narração pronta ✓' : 'Narration ready ✓',
				'render'      => $pt ? 'Gerar reel' : 'Render reel',
				'rendering'   => $pt ? 'A gravar em tempo real…' : 'Recording in real time…',
				'render_hint' => $pt ? 'A gravação corre em tempo real (a duração do reel) — mantém este separador à frente.' : 'Recording runs in real time (the reel length) — keep this tab focused.',
				'mp4'         => $pt ? 'Exportar em MP4' : 'Export as MP4',
				'endcard'     => $pt ? 'Cartão final (CTA)' : 'End card (CTA)',
				'end_title'   => $pt ? 'Título do cartão final' : 'End-card title',
				'end_cta'     => $pt ? 'Botão do cartão final' : 'End-card button',
				'caption'     => $pt ? 'Legenda da publicação' : 'Post caption',
				'copy'        => $pt ? 'Copiar' : 'Copy',
				'copied'      => $pt ? 'Copiado ✓' : 'Copied ✓',
				'need_script' => $pt ? 'Cola e analisa um script primeiro.' : 'Paste and parse a script first.',
				'need_voice_first' => $pt ? 'Gera a narração antes de gravar.' : 'Generate the narration before recording.',
				'err_voice'   => $pt ? 'Não foi possível gerar a voz. Vê os Logs.' : 'Could not generate the voice. Check the Logs.',
				'no_support'  => $pt ? 'O teu browser não suporta a gravação. Usa o Chrome ou o Firefox.' : 'Your browser cannot record. Use Chrome or Firefox.',
				'disclaimer_note' => $pt ? 'O disclaimer educativo é adicionado ao cartão final.' : 'The educational disclaimer is added to the end card.',
			),
		);
	}

	/**
	 * A ready-to-edit example script (matches the format the parser expects).
	 *
	 * @param bool $pt Portuguese sample.
	 */
	private static function sample_script( bool $pt ): string {
		if ( $pt ) {
			return "Hook 0-1s: [Texto no ecrã] \"PÁRA de acreditar nesta mentira sobre investir\"\n"
				. "Script:\n"
				. "0-1s: NÃO precisas de 10 mil para começar.\n"
				. "2-5s: Começa com o preço de um café.\n"
				. "6-10s: As apps de hoje deixam-te comprar frações. 5€ já te põem no jogo.\n"
				. "11-15s: O tempo conta mais do que o valor. Começar cedo é o que importa.\n\n"
				. "Caption: Não és pobre demais para investir. Só chegas tarde se esperares.\n"
				. "Qual foi o teu primeiro valor? 👇 #HowToInvest #InvestirParaIniciantes";
		}
		return "Hook 0-1s: [Text on screen] \"STOP believing this lie about investing\"\n"
			. "Script:\n"
			. "0-1s: You do NOT need 10k to start.\n"
			. "2-5s: You can start with the price of a coffee.\n"
			. "6-10s: Apps today let you buy fractions. A few dollars gets you in the game.\n"
			. "11-15s: Time matters more than the amount. Starting early is what counts.\n\n"
			. "Caption: You're not too poor to invest. You're just too late if you wait.\n"
			. "What was your first amount? 👇 #HowToInvest #InvestingForBeginners";
	}

	/**
	 * @font-face for the on-screen preview (same as the reels editor).
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
	 * Render the mount point; script-reel.js builds the editor.
	 */
	public static function render(): void {
		echo '<div class="wrap hti-social-wrap">';
		echo '<h1>' . esc_html__( 'Script → Reel', 'hti-social' ) . '</h1>';
		echo '<p class="hti-social-intro">' . esc_html__( 'Paste a timed script and generate a vertical 1080×1920 reel with branded scenes and an AI voice-over — all in your browser. Educational use only; the disclaimer rides on the end card.', 'hti-social' ) . '</p>';
		echo '<div id="hti-sreel-app"></div>';
		echo '</div>';
	}
}
