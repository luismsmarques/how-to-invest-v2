<?php
/**
 * Front-end mounts for the interactive app (E5–E7) and the account area.
 *
 * - `[hti_questionnaire]` renders the questionnaire/result (noindex).
 * - `[hti_account]` renders the logged-in dashboard: saved profiles, data
 *   export and account deletion (RGPD), and the sign-in/register forms.
 *
 * Lightweight vanilla JS, enqueued only where used; all scoring and account
 * actions go through `/wp-json/htinvest/v1/*`.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes + asset wiring for the questionnaire/result and account UI.
 */
class Frontend {

	private const SHORTCODE_APP     = 'hti_questionnaire';
	private const SHORTCODE_ACCOUNT = 'hti_account';

	/**
	 * Hook shortcodes, assets and robots.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE_APP, array( __CLASS__, 'render_app' ) );
		add_shortcode( self::SHORTCODE_ACCOUNT, array( __CLASS__, 'render_account' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * Whether the current singular view contains a given shortcode.
	 *
	 * @param string $shortcode Shortcode tag.
	 */
	private static function has( string $shortcode ): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( $post->post_content, $shortcode );
	}

	private static function is_app_page(): bool {
		return self::has( self::SHORTCODE_APP );
	}

	private static function is_account_page(): bool {
		return self::has( self::SHORTCODE_ACCOUNT );
	}

	/**
	 * Site locale reduced to a supported key.
	 */
	private static function locale(): string {
		return str_starts_with( strtolower( (string) get_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Enqueue assets on the questionnaire and/or account page.
	 */
	public static function enqueue(): void {
		$app     = self::is_app_page();
		$account = self::is_account_page();
		if ( ! $app && ! $account ) {
			return;
		}

		$locale = self::locale();

		wp_enqueue_style( 'hti-app', HTI_ENGINE_URL . 'assets/css/app.css', array(), VERSION );

		// Account script + context: needed by the result save-flow and the dashboard.
		wp_register_script(
			'hti-account',
			HTI_ENGINE_URL . 'assets/js/account.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script( 'hti-account', 'HTI_ACCT', self::account_context( $locale ) );
		wp_enqueue_script( 'hti-account' );

		if ( $app ) {
			wp_register_script( 'hti-result', HTI_ENGINE_URL . 'assets/js/result.js', array( 'hti-account' ), VERSION, array( 'in_footer' => true ) );
			wp_register_script(
				'hti-questionnaire',
				HTI_ENGINE_URL . 'assets/js/questionnaire.js',
				array( 'hti-result' ),
				VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_localize_script(
				'hti-questionnaire',
				'HTI_DATA',
				array(
					'restUrl' => esc_url_raw( rest_url( 'htinvest/v1/recommend' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'locale'  => $locale,
					'data'    => Questions::payload( $locale ),
					'pdf'     => array(
						'url'   => esc_url_raw( admin_url( 'admin-post.php' ) ),
						'nonce' => wp_create_nonce( 'hti_pdf' ),
					),
				)
			);
			wp_enqueue_script( 'hti-result' );
			wp_enqueue_script( 'hti-questionnaire' );
		}
	}

	/**
	 * Context for account.js (REST base, nonce, auth state, strings).
	 *
	 * @param string $locale Locale key.
	 * @return array<string,mixed>
	 */
	private static function account_context( string $locale ): array {
		return array(
			'restBase'   => esc_url_raw( rest_url( 'htinvest/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'isLoggedIn' => is_user_logged_in(),
			'locale'     => $locale,
			'accountUrl' => esc_url( home_url( '/my-account/' ) ),
			'homeUrl'    => esc_url( home_url( '/' ) ),
			'google'     => array(
				'enabled' => Google::is_configured(),
				'start'   => esc_url_raw( Google::start_url() ),
			),
			'strings'    => self::account_strings( 'pt' === $locale ),
		);
	}

	/**
	 * Account UI strings.
	 *
	 * @param bool $pt Whether Portuguese.
	 * @return array<string,string>
	 */
	private static function account_strings( bool $pt ): array {
		if ( $pt ) {
			return array(
				'save_profile'   => 'Guardar o meu perfil',
				'save_intro'     => 'Cria uma conta (ou entra) para guardar este perfil e voltar a ele mais tarde.',
				'create_account' => 'Criar conta',
				'sign_in'        => 'Entrar',
				'email'          => 'Email',
				'password'       => 'Palavra-passe',
				'saved'          => 'Perfil guardado ✓',
				'view_profiles'  => 'Ver os meus perfis',
				'my_profiles'    => 'Os meus perfis',
				'no_profiles'    => 'Ainda não tens perfis guardados.',
				'export_data'    => 'Exportar os meus dados',
				'delete_account' => 'Apagar a minha conta',
				'delete_confirm' => 'Apagar a conta remove definitivamente todos os teus perfis e dados. Esta ação é irreversível. Continuar?',
				'deleted'        => 'A conta foi apagada.',
				'signin_to_view' => 'Entra para ver os teus perfis guardados.',
				'archetype'      => 'Arquétipo',
				'error'          => 'Algo correu mal. Tenta novamente.',
				'working'        => 'A processar…',
				'check_email'    => 'Quase lá — confirma o teu email para guardar o perfil.',
				'verified'       => 'Email confirmado — o teu perfil foi guardado.',
				'verify_error'   => 'Esse link de confirmação é inválido ou expirou.',
				'google'         => 'Continuar com o Google',
				'or'             => 'ou',
			);
		}
		return array(
			'save_profile'   => 'Save my profile',
			'save_intro'     => 'Create an account (or sign in) to save this profile and come back to it later.',
			'create_account' => 'Create account',
			'sign_in'        => 'Sign in',
			'email'          => 'Email',
			'password'       => 'Password',
			'saved'          => 'Profile saved ✓',
			'view_profiles'  => 'View my profiles',
			'my_profiles'    => 'My profiles',
			'no_profiles'    => "You don't have any saved profiles yet.",
			'export_data'    => 'Export my data',
			'delete_account' => 'Delete my account',
			'delete_confirm' => 'Deleting your account permanently removes all your profiles and data. This cannot be undone. Continue?',
			'deleted'        => 'Your account has been deleted.',
			'signin_to_view' => 'Sign in to view your saved profiles.',
			'archetype'      => 'Archetype',
			'error'          => 'Something went wrong. Please try again.',
			'working'        => 'Working…',
			'check_email'    => 'Almost there — check your email to confirm and save your profile.',
			'verified'       => 'Email confirmed — your profile is saved.',
			'verify_error'   => 'That confirmation link is invalid or has expired.',
			'google'         => 'Continue with Google',
			'or'             => 'or',
		);
	}

	/**
	 * `[hti_questionnaire]` output.
	 */
	public static function render_app(): string {
		$noscript = esc_html__( 'This questionnaire needs JavaScript enabled in your browser.', 'hti-engine' );
		return '<div id="hti-app" class="hti-app" aria-live="polite"></div>'
			. '<noscript><p class="hti-noscript">' . $noscript . '</p></noscript>';
	}

	/**
	 * `[hti_account]` output (dashboard mount).
	 */
	public static function render_account(): string {
		$noscript = esc_html__( 'Your account area needs JavaScript enabled in your browser.', 'hti-engine' );
		return '<div id="hti-account" class="hti-app"></div>'
			. '<noscript><p class="hti-noscript">' . $noscript . '</p></noscript>';
	}

	/**
	 * Mark questionnaire/result and account pages noindex (not SEO content).
	 *
	 * @param array<string,bool> $robots Robots directives.
	 * @return array<string,bool>
	 */
	public static function robots( array $robots ): array {
		if ( self::is_app_page() || self::is_account_page() ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}
}
