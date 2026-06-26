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
		// Depends on hti-track so window.HTITrack exists before any event fires
		// (the whole app chain inherits this: account → result → questionnaire).
		wp_register_script(
			'hti-account',
			HTI_ENGINE_URL . 'assets/js/account.js',
			array( 'hti-track' ),
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
					'restUrl'   => esc_url_raw( rest_url( 'htinvest/v1/recommend' ) ),
					'resultUrl' => esc_url_raw( rest_url( 'htinvest/v1/result' ) ),
					'emailUrl'  => esc_url_raw( rest_url( 'htinvest/v1/email-result' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'locale'    => $locale,
					'homeUrl'   => esc_url( home_url( '/' ) ),
					'data'      => Questions::payload( $locale ),
					'feedbackUrl' => Feedback::page_url(),
					'pdf'       => array(
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
			'email'      => is_user_logged_in() ? wp_get_current_user()->user_email : '',
			'deleteAt'   => self::deletion_date( $locale ),
			'prefs'      => is_user_logged_in() ? Account::get_prefs( get_current_user_id() ) : null,
			'categories' => Account::categories_list( $locale ),
			'onboarded'  => is_user_logged_in() ? Account::is_onboarded( get_current_user_id() ) : true,
			'pageLocale' => $locale,
			'locale'     => $locale,
			'accountUrl' => esc_url( home_url( '/my-account/' ) ),
			'homeUrl'    => esc_url( home_url( '/' ) ),
			'logoutUrl'  => esc_url( wp_logout_url( home_url( '/my-account/' ) ) ),
			'resultBase' => esc_url( home_url( '/investor-profile-quiz/' ) ),
			'policiesUrl' => esc_url( ( $p = get_page_by_path( 'privacy-policy' ) ) ? (string) get_permalink( $p->ID ) : home_url( '/privacy-policy/' ) ),
			'lostUrl'    => esc_url( wp_lostpassword_url() ),
			'google'     => array(
				'enabled' => Google::is_configured(),
				'start'   => esc_url_raw( Google::start_url() ),
			),
			'learn'      => self::learn_dashboard( get_current_user_id(), $locale ),
			'discover'   => self::discover_links(),
			'archDesc'   => self::archetype_descriptions( $locale ),
			'allocColors' => array(
				'global_equity' => '#FF6B5E',
				'bonds'         => '#7C5CFC',
				'reits_alt'     => '#D69A1E',
				'crypto'        => '#22C3A6',
				'cash'          => '#B7AEC4',
			),
			'classLabels' => 'pt' === $locale
				? array( 'global_equity' => 'Ações globais', 'bonds' => 'Obrigações', 'reits_alt' => 'REITs e alternativos', 'cash' => 'Liquidez', 'crypto' => 'Cripto' )
				: array( 'global_equity' => 'Global equities', 'bonds' => 'Bonds', 'reits_alt' => 'REITs & alternatives', 'cash' => 'Cash', 'crypto' => 'Crypto' ),
			'strings'    => self::account_strings( 'pt' === $locale ),
		);
	}

	/**
	 * Per-archetype short descriptions keyed by id (for the dashboard profile card).
	 *
	 * @return array<int,string>
	 */
	private static function archetype_descriptions( string $locale ): array {
		$key = 'pt' === $locale ? 'pt' : 'en';
		$out = array();
		if ( class_exists( '\\HTI\\Engine\\Config' ) ) {
			foreach ( Config::descriptions() as $id => $d ) {
				$out[ (int) $id ] = (string) ( $d[ $key ] ?? ( $d['en'] ?? '' ) );
			}
		}
		return $out;
	}

	/**
	 * Resolve the "Discover" cross-link URLs for the account hub.
	 *
	 * @return array<string,string>
	 */
	private static function discover_links(): array {
		$learn = get_post_type_archive_link( 'learn' );
		return array(
			'comparador' => esc_url( (string) apply_filters( 'hti_deposits_page_url', home_url( '/comparador-de-depositos/' ) ) ),
			'glossary'   => esc_url( (string) ( get_post_type_archive_link( 'glossary' ) ?: home_url( '/investing-glossary/' ) ) ),
			'news'       => esc_url( (string) ( get_post_type_archive_link( 'news' ) ?: home_url( '/financial-news/' ) ) ),
			'ebook'      => esc_url( (string) apply_filters( 'hti_ebook_page_url', home_url( '/ebook/' ) ) ),
			'learn'      => esc_url( (string) ( $learn ?: home_url( '/learn/' ) ) ),
		);
	}

	/**
	 * Compute the signed-in user's learning summary for the account hub:
	 * chapters done/total, percent, per-module badge state, and the next chapter.
	 *
	 * @param int    $uid    User id.
	 * @param string $locale Locale.
	 * @return array<string,mixed>
	 */
	private static function learn_dashboard( int $uid, string $locale ): array {
		if ( ! $uid || ! class_exists( '\\HTI\\Engine\\Content_Import' ) || ! class_exists( '\\HTI\\Engine\\Learn' ) ) {
			return array( 'enabled' => false );
		}
		$lang = 'pt' === $locale ? 'pt' : 'en';
		$cur  = Content_Import::curriculum( $lang );
		if ( empty( $cur ) ) {
			return array( 'enabled' => false );
		}
		$prog   = Learn::get( $uid );
		$done   = array_flip( $prog['done'] );
		$passed = array_flip( $prog['passed'] );

		$total = 0;
		$dn    = 0;
		$next  = null;
		$badges = array();
		foreach ( $cur as $m ) {
			$mtotal = 0;
			$mmast  = 0;
			foreach ( (array) $m['chapters'] as $c ) {
				++$total;
				++$mtotal;
				$slug    = (string) $c['slug'];
				$is_done = isset( $done[ $slug ] );
				if ( $is_done ) {
					++$dn;
				}
				if ( isset( $passed[ $slug ] ) || ( empty( $c['has_quiz'] ) && $is_done ) ) {
					++$mmast;
				}
				if ( null === $next && ! empty( $c['published'] ) && ! $is_done ) {
					$next = array( 'title' => (string) $c['title'], 'url' => (string) $c['url'] );
				}
			}
			$badges[] = array(
				'num'   => (string) $m['num'],
				'title' => (string) $m['title'],
				'state' => ( $mtotal > 0 && $mmast === $mtotal ) ? 'earned' : ( $mmast > 0 ? 'inprog' : 'locked' ),
			);
		}
		$hub = get_post_type_archive_link( 'learn' ) ?: home_url( '/learn/' );
		return array(
			'enabled'   => true,
			'done'      => $dn,
			'total'     => $total,
			'pct'       => $total ? (int) round( $dn / $total * 100 ) : 0,
			'badges'    => $badges,
			'nextTitle' => $next['title'] ?? '',
			'nextUrl'   => esc_url( (string) ( $next['url'] ?? $hub ) ),
			'hubUrl'    => esc_url( (string) $hub ),
		);
	}

	/**
	 * Human deletion date for the current user, or '' if none scheduled.
	 *
	 * @param string $locale Locale.
	 */
	private static function deletion_date( string $locale ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$at = Account::deletion_at( get_current_user_id() );
		if ( $at <= 0 ) {
			return '';
		}
		return (string) wp_date( 'pt' === $locale ? 'j \d\e F \d\e Y' : 'j F Y', $at );
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
				'forgot'         => 'Esqueceste-te da password?',
				'rec_back'       => '← Entrar',
				'rec_title'      => 'Recuperar acesso',
				'rec_body'       => 'Indica o teu email e enviamos-te um link para criares uma nova palavra-passe.',
				'rec_email'      => 'Email',
				'rec_ph'         => 'tu@exemplo.pt',
				'rec_send'       => 'Enviar link de recuperação',
				'rec_invalid'    => 'Introduz um email válido.',
				'rec_sent_title' => 'Verifica o teu email',
				'rec_sent_body'  => 'Se existir uma conta associada a %s, vais receber um link para repor a palavra-passe nos próximos minutos.',
				'rec_back_login' => 'Voltar a entrar',
				'open_profile'   => 'Ver resultado',
				'account_email'  => 'Email da conta',
				'change_email'   => 'Alterar email',
				'new_email'      => 'Novo email',
				'email_pending'  => 'Confirma a alteração no email que enviámos para o novo endereço.',
				'email_changed'  => 'Email alterado com sucesso.',
				'email_error'    => 'Esse link de alteração é inválido ou expirou.',
				'save'           => 'Guardar',
				'cancel'         => 'Cancelar',
				'delete_scheduled' => 'A tua conta está agendada para eliminação a %s.',
				'cancel_deletion'  => 'Cancelar eliminação',
				'deletion_set'     => 'Conta agendada para eliminação. Enviámos os detalhes por email.',
				'deletion_off'     => 'Eliminação cancelada. A tua conta continua ativa.',
				'preferences'      => 'Preferências de email',
				'pref_newsletter'  => 'Receber a newsletter',
				'pref_frequency'   => 'Frequência',
				'pref_weekly'      => 'Semanal',
				'pref_daily'       => 'Diária',
				'pref_categories'  => 'Categorias de interesse',
				'prefs_saved'      => 'Preferências guardadas. Enviámos-te a confirmação por email.',
				'onb_title'        => 'Bem-vindo! Vamos personalizar a tua experiência',
				'onb_lang'         => 'Em que língua preferes o conteúdo e os emails?',
				'onb_en'           => 'Inglês',
				'onb_pt'           => 'Português',
				'onb_nl'           => 'Quero receber o resumo de notícias',
				'onb_q_label'      => 'Qual é a tua maior dúvida ou dificuldade nos investimentos?',
				'onb_q_ph'         => 'Escreve à vontade — ajuda-nos a criar conteúdo útil para ti.',
				'onb_finish'       => 'Concluir',
				'sign_out'         => 'Terminar sessão',
				'acc_profile_eyebrow' => 'O meu perfil de investidor',
				'acc_data_eyebrow' => 'Os meus dados (RGPD)',
				'acc_export_sub'   => 'Portabilidade — descarrega tudo',
				'acc_delete_sub'   => 'Irreversível',
				'acc_privacy'      => 'Privacidade e termos',
				'acc_privacy_sub'  => 'Como tratamos os teus dados',
				'acc_view_result'  => 'Ver resultado completo',
				'acc_redo'         => '↻ Refazer questionário',
				'acc_no_profile'   => 'Ainda não fizeste o questionário. Descobre o teu perfil em poucos minutos.',
				'acc_discover'     => 'Descobrir o meu perfil →',
				'acc_learn_eyebrow' => 'A tua aprendizagem',
				'acc_discover_eyebrow' => 'Descobrir',
				'acc_data_settings' => 'Os teus dados e definições',
				'acc_learn_path'   => 'Do zero à tua primeira carteira',
				'acc_chapters_done' => '%1$s de %2$s capítulos concluídos',
				'acc_continue_learning' => 'Continuar a aprender →',
				'acc_course'       => 'Curso',
				'acc_by_class'     => 'por classe',
				'acc_illustrative' => 'Exemplo ilustrativo por classes de ativos — educativo, não é aconselhamento.',
				'acc_saved'        => 'Guardado',
				'acc_noprofile_t'  => 'Ainda não fizeste o questionário',
				'acc_noprofile_b'  => 'Em ~2 minutos descobres o teu perfil e um exemplo de carteira por classes de ativos.',
				'acc_dc_comp_t'    => 'Comparador de Depósitos',
				'acc_dc_comp_d'    => 'Compara taxas a prazo, sem letras pequenas.',
				'acc_dc_gloss_t'   => 'Glossário',
				'acc_dc_gloss_d'   => '~54 termos, em português simples.',
				'acc_dc_news_t'    => 'Notícias',
				'acc_dc_news_d'    => 'As finanças explicadas com calma.',
				'acc_dc_ebook_t'   => 'Ebook grátis',
				'acc_dc_ebook_d'   => 'As bases, num PDF para guardares.',
				'acc_dc_open'      => 'Abrir →',
				'acc_guest_eyebrow' => 'Conta gratuita',
				'acc_guest_title'  => 'Cria uma conta gratuita para:',
				'acc_guest_intro'  => 'O questionário e as leituras funcionam sem conta. A conta só guarda o que é teu — e podes apagá-la quando quiseres.',
				'acc_guest_b1_t'   => 'Guardar o teu perfil de investidor',
				'acc_guest_b1_d'   => 'O teu arquétipo e a carteira ilustrativa por classes, sempre à mão.',
				'acc_guest_b2_t'   => 'Ganhar badges ao aprender',
				'acc_guest_b2_d'   => 'Acompanha o progresso do curso, módulo a módulo.',
				'acc_guest_b3_t'   => 'Sincronizar entre dispositivos',
				'acc_guest_b3_d'   => 'Continua de onde ficaste, no telemóvel ou no portátil.',
				'acc_guest_b4_t'   => 'Gerir os teus dados (RGPD)',
				'acc_guest_b4_d'   => 'Exporta ou apaga tudo a qualquer momento, sem perguntas.',
				'acct_back'        => '← A minha conta',
				'exp_eyebrow'      => 'Portabilidade · RGPD Art. 20.º',
				'exp_intro'        => 'Descarrega uma cópia de tudo o que guardamos sobre ti, num ficheiro legível por máquina (JSON). É teu, e podes levá-lo para onde quiseres.',
				'exp_included'     => 'Incluído no ficheiro',
				'exp_item1'        => 'Dados de conta (nome, email, data de registo)',
				'exp_item2'        => 'Respostas ao questionário e perfil atribuído',
				'exp_item3'        => 'Preferências de consentimento e comunicações',
				'exp_btn'          => 'Preparar e descarregar (JSON)',
				'exp_done_t'       => 'Download iniciado',
				'exp_done_b'       => 'Guardámos howtoinvest-os-meus-dados.json nas tuas transferências.',
				'del_title'        => 'Apagar a tua conta',
				'del_perm'         => 'Esta ação é permanente e irreversível. Ao apagar a conta, removemos em cascata:',
				'del_c1'           => 'O teu perfil guardado e respostas ao questionário',
				'del_c2'           => 'Os teus dados de conta e preferências',
				'del_c3'           => 'As subscrições de email associadas',
				'del_confirm_lbl'  => 'Para confirmar, escreve %s abaixo.',
				'del_word'         => 'APAGAR',
				'del_btn'          => 'Apagar conta definitivamente',
				'del_sched_t'      => 'Eliminação agendada',
				'del_sched_b'      => 'A tua conta e todos os dados serão apagados definitivamente daqui a 30 dias. Até lá, podes cancelar a qualquer momento e fica tudo como estava.',
				'nl_eyebrow'       => 'Comunicações',
				'nl_title'         => 'Gerir a newsletter',
				'nl_intro'         => 'Escolhe com que frequência queres receber o resumo e os temas que mais te interessam. Mudas isto quando quiseres.',
				'nl_daily_desc'    => 'Todas as manhãs, às 7h',
				'nl_weekly_desc'   => 'Um resumo, ao domingo',
				'nl_topics_lbl'    => 'Temas que queres receber',
				'nl_save'          => 'Guardar preferências',
				'nl_saved_t'       => 'Preferências guardadas',
				'nl_unsub'         => 'Cancelar subscrição',
				'onb_eyebrow'      => 'Bem-vindo(a) à HowToInvest',
				'onb_lang_label'   => 'Idioma preferido',
				'onb_digest_t'     => 'Resumo de notícias por email',
				'onb_digest_d'     => 'Um email semanal, calmo e sem jargão. Sem spam — cancelas quando quiseres.',
				'onb_finish_long'  => 'Concluir e ir para a minha conta →',
				'onb_skip'         => 'Agora não',
				'onb_disclaimer'   => 'Conteúdo educativo, não constitui aconselhamento financeiro.',
				'onb_q_optional'   => 'Opcional — ajuda-nos a perceber por onde começar contigo.',
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
			'forgot'         => 'Forgot your password?',
			'rec_back'       => '← Sign in',
			'rec_title'      => 'Recover access',
			'rec_body'       => 'Enter your email and we’ll send you a link to set a new password.',
			'rec_email'      => 'Email',
			'rec_ph'         => 'you@example.com',
			'rec_send'       => 'Send recovery link',
			'rec_invalid'    => 'Please enter a valid email.',
			'rec_sent_title' => 'Check your email',
			'rec_sent_body'  => 'If an account exists for %s, you’ll get a password-reset link in the next few minutes.',
			'rec_back_login' => 'Back to sign in',
			'open_profile'   => 'View result',
			'account_email'  => 'Account email',
			'change_email'   => 'Change email',
			'new_email'      => 'New email',
			'email_pending'  => 'Confirm the change via the email we sent to the new address.',
			'email_changed'  => 'Your email was changed.',
			'email_error'    => 'That change link is invalid or has expired.',
			'save'           => 'Save',
			'cancel'         => 'Cancel',
			'delete_scheduled' => 'Your account is scheduled for deletion on %s.',
			'cancel_deletion'  => 'Cancel deletion',
			'deletion_set'     => 'Account scheduled for deletion. We’ve emailed you the details.',
			'deletion_off'     => 'Deletion cancelled. Your account stays active.',
			'preferences'      => 'Email preferences',
			'pref_newsletter'  => 'Receive the newsletter',
			'pref_frequency'   => 'Frequency',
			'pref_weekly'      => 'Weekly',
			'pref_daily'       => 'Daily',
			'pref_categories'  => 'Topics you care about',
			'prefs_saved'      => 'Preferences saved. We’ve emailed you the confirmation.',
			'onb_title'        => 'Welcome! Let’s personalise your experience',
			'onb_lang'         => 'Which language do you prefer for content and emails?',
			'onb_en'           => 'English',
			'onb_pt'           => 'Portuguese',
			'onb_nl'           => 'Send me the news roundup',
			'onb_q_label'      => 'What’s your biggest doubt or difficulty with investing?',
			'onb_q_ph'         => 'Write freely — it helps us create content that’s useful to you.',
			'onb_finish'       => 'Finish',
			'sign_out'         => 'Sign out',
			'acc_profile_eyebrow' => 'My investor profile',
			'acc_data_eyebrow' => 'My data (GDPR)',
			'acc_export_sub'   => 'Portability — download everything',
			'acc_delete_sub'   => 'Irreversible',
			'acc_privacy'      => 'Privacy & terms',
			'acc_privacy_sub'  => 'How we handle your data',
			'acc_view_result'  => 'View full result',
			'acc_redo'         => '↻ Retake quiz',
			'acc_no_profile'   => 'You haven\'t taken the quiz yet. Discover your profile in minutes.',
			'acc_discover'     => 'Discover my profile →',
			'acc_learn_eyebrow' => 'Your learning',
			'acc_discover_eyebrow' => 'Discover',
			'acc_data_settings' => 'Your data & settings',
			'acc_learn_path'   => 'From zero to your first portfolio',
			'acc_chapters_done' => '%1$s of %2$s chapters completed',
			'acc_continue_learning' => 'Continue learning →',
			'acc_course'       => 'Course',
			'acc_by_class'     => 'by class',
			'acc_illustrative' => 'Illustrative example by asset class — educational, not advice.',
			'acc_saved'        => 'Saved',
			'acc_noprofile_t'  => 'You haven\'t taken the quiz yet',
			'acc_noprofile_b'  => 'In ~2 minutes, discover your profile and an example portfolio by asset class.',
			'acc_dc_comp_t'    => 'Deposit comparator',
			'acc_dc_comp_d'    => 'Compare term rates, no small print.',
			'acc_dc_gloss_t'   => 'Glossary',
			'acc_dc_gloss_d'   => '~54 terms, in plain language.',
			'acc_dc_news_t'    => 'News',
			'acc_dc_news_d'    => 'Finance, explained calmly.',
			'acc_dc_ebook_t'   => 'Free ebook',
			'acc_dc_ebook_d'   => 'The basics, in a PDF to keep.',
			'acc_dc_open'      => 'Open →',
			'acc_guest_eyebrow' => 'Free account',
			'acc_guest_title'  => 'Create a free account to:',
			'acc_guest_intro'  => 'The quiz and lessons work without an account. An account only saves what\'s yours — and you can delete it whenever you like.',
			'acc_guest_b1_t'   => 'Save your investor profile',
			'acc_guest_b1_d'   => 'Your archetype and illustrative portfolio by asset class, always at hand.',
			'acc_guest_b2_t'   => 'Earn badges as you learn',
			'acc_guest_b2_d'   => 'Track your course progress, module by module.',
			'acc_guest_b3_t'   => 'Sync across devices',
			'acc_guest_b3_d'   => 'Pick up where you left off, on phone or laptop.',
			'acc_guest_b4_t'   => 'Manage your data (GDPR)',
			'acc_guest_b4_d'   => 'Export or delete everything anytime, no questions asked.',
			'acct_back'        => '← My account',
			'exp_eyebrow'      => 'Portability · GDPR Art. 20',
			'exp_intro'        => 'Download a copy of everything we store about you, in a machine-readable file (JSON). It\'s yours, and you can take it anywhere.',
			'exp_included'     => 'Included in the file',
			'exp_item1'        => 'Account data (name, email, sign-up date)',
			'exp_item2'        => 'Quiz answers and assigned profile',
			'exp_item3'        => 'Consent and communication preferences',
			'exp_btn'          => 'Prepare and download (JSON)',
			'exp_done_t'       => 'Download started',
			'exp_done_b'       => 'We saved howtoinvest-data-export.json to your downloads.',
			'del_title'        => 'Delete your account',
			'del_perm'         => 'This action is permanent and irreversible. Deleting your account removes, in cascade:',
			'del_c1'           => 'Your saved profile and quiz answers',
			'del_c2'           => 'Your account data and preferences',
			'del_c3'           => 'Associated email subscriptions',
			'del_confirm_lbl'  => 'To confirm, type %s below.',
			'del_word'         => 'DELETE',
			'del_btn'          => 'Delete account permanently',
			'del_sched_t'      => 'Deletion scheduled',
			'del_sched_b'      => 'Your account and all data will be permanently deleted in 30 days. Until then you can cancel anytime and everything stays as it was.',
			'nl_eyebrow'       => 'Communications',
			'nl_title'         => 'Manage the newsletter',
			'nl_intro'         => 'Choose how often you want the digest and the topics that interest you most. Change this anytime.',
			'nl_daily_desc'    => 'Every morning, at 7am',
			'nl_weekly_desc'   => 'One summary, on Sunday',
			'nl_topics_lbl'    => 'Topics you want to receive',
			'nl_save'          => 'Save preferences',
			'nl_saved_t'       => 'Preferences saved',
			'nl_unsub'         => 'Unsubscribe',
			'onb_eyebrow'      => 'Welcome to HowToInvest',
			'onb_lang_label'   => 'Preferred language',
			'onb_digest_t'     => 'News digest by email',
			'onb_digest_d'     => 'A calm, jargon-free weekly email. No spam — cancel anytime.',
			'onb_finish_long'  => 'Finish and go to my account →',
			'onb_skip'         => 'Not now',
			'onb_disclaimer'   => 'Educational content, not financial advice.',
			'onb_q_optional'   => 'Optional — it helps us understand where to start with you.',
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
