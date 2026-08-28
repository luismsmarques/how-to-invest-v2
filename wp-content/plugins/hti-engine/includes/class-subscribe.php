<?php
/**
 * Newsletter subscription with double opt-in, backed by Brevo contacts.
 *
 * Flow: the [hti_subscribe] form posts to /subscribe → we email a branded
 * confirmation with a stateless HMAC link → following it upserts the contact
 * into the Brevo list and sends a "confirmed" email. Every newsletter email
 * carries an unsubscribe link that removes the contact from the list. No
 * subscriber data is stored on the site; Brevo is the source of truth.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribe form, double opt-in confirm/unsubscribe, and the related emails.
 */
class Subscribe {

	private const SHORTCODE = 'hti_subscribe';

	/**
	 * Hook the shortcode, assets, and the confirm/unsubscribe link handler.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_shortcode( 'hti_subscribe_confirmed', array( __CLASS__, 'render_confirmed' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ) );
		add_action( 'wp_footer', array( __CLASS__, 'result_toast' ) );
		// Unsubscribe executes only on POST (the GET link renders a confirm
		// page) — see render_unsub_confirm() for why.
		add_action( 'admin_post_hti_unsub', array( __CLASS__, 'handle_unsub_post' ) );
		add_action( 'admin_post_nopriv_hti_unsub', array( __CLASS__, 'handle_unsub_post' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * Whether the current view is the dedicated confirmation landing page
	 * (the seeded page carrying [hti_subscribe_confirmed]).
	 */
	private static function on_confirmed_page(): bool {
		if ( ! is_singular( 'page' ) ) {
			return false;
		}
		$content = (string) get_post_field( 'post_content', get_queried_object_id() );
		return has_shortcode( $content, 'hti_subscribe_confirmed' );
	}

	/**
	 * Keep the confirmation landing page out of the index (it is a flow page,
	 * like the questionnaire/result).
	 *
	 * @param array<string,bool|string> $robots Robots directives.
	 * @return array<string,bool|string>
	 */
	public static function robots( array $robots ): array {
		if ( self::on_confirmed_page() ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/* ---------- stateless tokens ---------- */

	/**
	 * An email-bound HMAC token for an action ('optin' | 'unsub').
	 *
	 * @param string $email  Email.
	 * @param string $action Action.
	 */
	public static function token( string $email, string $action ): string {
		return substr( hash_hmac( 'sha256', $action . '|' . strtolower( trim( $email ) ), wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * Verify a token in constant time.
	 *
	 * @param string $email  Email.
	 * @param string $action Action.
	 * @param string $token  Provided token.
	 */
	private static function token_valid( string $email, string $action, string $token ): bool {
		return '' !== $token && hash_equals( self::token( $email, $action ), $token );
	}

	/**
	 * The confirm/unsubscribe link for an email.
	 *
	 * @param string $action 'optin' | 'unsub'.
	 * @param string $email  Email.
	 * @param string $locale Locale.
	 */
	public static function link( string $action, string $email, string $locale ): string {
		return add_query_arg(
			array(
				'hti_sub' => $action,
				'e'       => rawurlencode( $email ),
				't'       => self::token( $email, $action ),
				'l'       => 'pt' === $locale ? 'pt' : 'en',
			),
			home_url( '/' )
		);
	}

	/* ---------- assets + form ---------- */

	private static function locale(): string {
		// Prefer Polylang's current language (reliable on CPT archives); the bare
		// get_locale() can miss the page language, so the form would post 'en'.
		if ( function_exists( 'pll_current_language' ) ) {
			$slug = (string) pll_current_language( 'slug' );
			if ( '' !== $slug ) {
				return str_starts_with( strtolower( $slug ), 'pt' ) ? 'pt' : 'en';
			}
		}
		return str_starts_with( strtolower( (string) determine_locale() ), 'pt' ) ? 'pt' : 'en';
	}

	/**
	 * Register (don't enqueue) the assets; the shortcode enqueues on render so
	 * it works inside post content and block templates (e.g. archives) alike.
	 */
	public static function register_assets(): void {
		$locale = self::locale();
		wp_register_style( 'hti-subscribe', HTI_ENGINE_URL . 'assets/css/subscribe.css', array(), VERSION );
		wp_register_script( 'hti-subscribe', HTI_ENGINE_URL . 'assets/js/subscribe.js', array( 'hti-track' ), VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'hti-subscribe',
			'HTI_SUBSCRIBE',
			array(
				'restUrl' => esc_url_raw( rest_url( 'htinvest/v1/subscribe' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'locale'  => $locale,
				'strings' => self::js_strings( 'pt' === $locale ),
			)
		);
	}

	/**
	 * JS status strings.
	 *
	 * @param bool $pt Portuguese.
	 * @return array<string,string>
	 */
	private static function js_strings( bool $pt ): array {
		return $pt
			? array(
				'sending' => 'A enviar…',
				'sent'    => 'Quase! Confirma a subscrição no email que te enviámos.',
				'invalid' => 'Introduz um email válido.',
				'consent' => 'Para subscreveres, aceita receber os emails.',
				'error'   => 'Não foi possível subscrever. Tenta novamente.',
				'rate'    => 'Demasiadas tentativas. Aguarda um momento.',
			)
			: array(
				'sending' => 'Sending…',
				'sent'    => 'Almost there! Confirm your subscription in the email we just sent.',
				'invalid' => 'Please enter a valid email.',
				'consent' => 'Please agree to receive the emails to subscribe.',
				'error'   => 'Could not subscribe. Please try again.',
				'rate'    => 'Too many attempts. Please wait a moment.',
			);
	}

	/**
	 * `[hti_subscribe]` form. Attributes: title, intro (optional overrides).
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		wp_enqueue_style( 'hti-subscribe' );
		wp_enqueue_script( 'hti-subscribe' );

		$atts   = shortcode_atts(
			array(
				'title'   => '',
				'intro'   => '',
				'variant' => 'default',
				// Where this opt-in came from. Reaches the REST endpoint, which
				// already stores it for Brevo attribution and for the
				// hti_lead_magnet filter — until now the form never sent one.
				'source'  => '',
			),
			is_array( $atts ) ? $atts : array()
		);
		$source = sanitize_key( (string) $atts['source'] );
		$pt     = 'pt' === self::locale();
		$title  = '' !== $atts['title'] ? $atts['title'] : ( $pt ? 'Recebe o resumo na tua caixa de entrada' : 'Get the roundup in your inbox' );
		$intro  = '' !== $atts['intro'] ? $atts['intro'] : ( $pt ? 'Notícias e aprendizagem financeira, sem jargão. Podes cancelar quando quiseres.' : 'Financial news and learning, jargon-free. Unsubscribe anytime.' );
		$email  = $pt ? 'O teu email' : 'Your email';
		$send   = $pt ? 'Subscrever' : 'Subscribe';
		$cons   = $pt ? 'Aceito receber emails da HowToInvest e li a Política de Privacidade.' : 'I agree to receive emails from HowToInvest and have read the Privacy Policy.';
		$privacy = get_privacy_policy_url();
		if ( $privacy ) {
			$cons = $pt
				? sprintf( 'Aceito receber emails da HowToInvest e li a <a href="%s" target="_blank" rel="noopener">Política de Privacidade</a>.', esc_url( $privacy ) )
				: sprintf( 'I agree to receive emails from HowToInvest and have read the <a href="%s" target="_blank" rel="noopener">Privacy Policy</a>.', esc_url( $privacy ) );
		}

		if ( 'digest' === $atts['variant'] ) {
			return self::render_digest( $pt, $cons, $source );
		}

		// Unique per instance: two forms on one page would otherwise share
		// id="hti-subscribe-email" and every <label for=…> would point at the
		// first one.
		$id_email   = wp_unique_id( 'hti-subscribe-email-' );
		$id_hp      = wp_unique_id( 'hti-subscribe-hp-' );
		$id_consent = wp_unique_id( 'hti-subscribe-consent-' );

		ob_start();
		?>
		<form class="hti-subscribe" data-hti-subscribe data-source="<?php echo esc_attr( $source ); ?>" novalidate>
			<h2 class="hti-subscribe__title"><?php echo esc_html( $title ); ?></h2>
			<p class="hti-subscribe__intro"><?php echo esc_html( $intro ); ?></p>
			<div class="hti-subscribe__row">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id_email ); ?>"><?php echo esc_html( $email ); ?></label>
				<input class="hti-subscribe__input" type="email" id="<?php echo esc_attr( $id_email ); ?>" name="email" placeholder="<?php echo esc_attr( $email ); ?>" autocomplete="email" required>
				<button class="hti-subscribe__submit" type="submit"><?php echo esc_html( $send ); ?></button>
			</div>
			<p class="hti-subscribe__trap" aria-hidden="true">
				<label for="<?php echo esc_attr( $id_hp ); ?>"><?php esc_html_e( 'Leave this field blank', 'hti-engine' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $id_hp ); ?>" name="hti_hp" tabindex="-1" autocomplete="off">
			</p>
			<p class="hti-subscribe__consent">
				<input type="checkbox" id="<?php echo esc_attr( $id_consent ); ?>" name="consent" value="1" required>
				<label for="<?php echo esc_attr( $id_consent ); ?>"><?php echo wp_kses( $cons, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></label>
			</p>
			<p class="hti-subscribe__status" role="status" aria-live="polite"></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Coral "daily roundup" banner variant (matches the news-hub design).
	 *
	 * @param bool   $pt     Whether Portuguese.
	 * @param string $cons   Consent label HTML (with the privacy link).
	 * @param string $source Opt-in source key ('' when unset).
	 */
	private static function render_digest( bool $pt, string $cons, string $source = '' ): string {
		$badge = $pt ? 'Diário · 7h' : 'Daily · 7am';
		$title = $pt ? 'O resumo do dia, nas finanças.' : 'The day’s roundup, in finance.';
		$intro = $pt
			? 'Todas as manhãs, um email curto e calmo com o que aconteceu no mundo das finanças — e o que significa para ti. Sem ruído, sem jargão.'
			: 'Every morning, a short, calm email on what happened in finance — and what it means for you. No noise, no jargon.';
		$ph   = $pt ? 'o-teu-email@exemplo.pt' : 'you@example.com';
		$send = $pt ? 'Subscrever o resumo diário' : 'Subscribe to the daily roundup';
		$fine = $pt ? 'Grátis. Cancelas quando quiseres, num clique.' : 'Free. Unsubscribe anytime, in one click.';
		$lbl  = $pt ? 'O teu email para o resumo diário' : 'Your email for the daily roundup';

		$id_email   = wp_unique_id( 'hti-subscribe-email-' );
		$id_hp      = wp_unique_id( 'hti-subscribe-hp-' );
		$id_consent = wp_unique_id( 'hti-subscribe-consent-' );

		ob_start();
		?>
		<form class="hti-subscribe hti-subscribe--digest" data-hti-subscribe data-source="<?php echo esc_attr( '' !== $source ? $source : 'digest' ); ?>" novalidate>
			<div class="hti-digest__text">
				<span class="hti-digest__badge"><span class="hti-digest__dot"></span><?php echo esc_html( $badge ); ?></span>
				<h2 class="hti-digest__title"><?php echo esc_html( $title ); ?></h2>
				<p class="hti-digest__intro"><?php echo esc_html( $intro ); ?></p>
			</div>
			<div class="hti-digest__form">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id_email ); ?>"><?php echo esc_html( $lbl ); ?></label>
				<input class="hti-digest__input" type="email" id="<?php echo esc_attr( $id_email ); ?>" name="email" placeholder="<?php echo esc_attr( $ph ); ?>" autocomplete="email" required>
				<button class="hti-digest__submit hti-subscribe__submit" type="submit"><?php echo esc_html( $send ); ?></button>
				<p class="hti-subscribe__trap" aria-hidden="true">
					<label for="<?php echo esc_attr( $id_hp ); ?>"><?php esc_html_e( 'Leave this field blank', 'hti-engine' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id_hp ); ?>" name="hti_hp" tabindex="-1" autocomplete="off">
				</p>
				<p class="hti-digest__consent">
					<input type="checkbox" id="<?php echo esc_attr( $id_consent ); ?>" name="consent" value="1" required>
					<label for="<?php echo esc_attr( $id_consent ); ?>"><?php echo wp_kses( $cons, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></label>
				</p>
				<p class="hti-digest__fine"><?php echo esc_html( $fine ); ?></p>
				<p class="hti-subscribe__status" role="status" aria-live="polite"></p>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- REST: request the double opt-in email ---------- */

	/**
	 * POST /subscribe — validate and email a double opt-in confirmation.
	 * Neutral response (never reveals whether the email is already subscribed).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function request_optin( \WP_REST_Request $request ) {
		if ( RateLimit::exceeded( 'subscribe' ) ) {
			return new \WP_Error( 'hti_rate_limited', __( 'Too many requests. Please wait a moment.', 'hti-engine' ), array( 'status' => 429 ) );
		}
		if ( '' !== trim( (string) $request->get_param( 'hti_hp' ) ) ) {
			return new \WP_REST_Response( array( 'sent' => true ), 200 ); // Honeypot.
		}
		if ( true !== rest_sanitize_boolean( $request->get_param( 'consent' ) ) ) {
			return new \WP_Error( 'hti_no_consent', __( 'Please agree to receive the emails to subscribe.', 'hti-engine' ), array( 'status' => 422 ) );
		}

		$email  = sanitize_email( (string) $request->get_param( 'email' ) );
		$locale = str_starts_with( strtolower( (string) $request->get_param( 'locale' ) ), 'pt' ) ? 'pt' : 'en';
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'hti_invalid_email', __( 'Please enter a valid email.', 'hti-engine' ), array( 'status' => 422 ) );
		}

		self::send_optin_email( $email, $locale );

		// Remember where this opt-in came from so the confirmation step can
		// (a) attribute the contact in Brevo and (b) deliver the right lead
		// magnet — the ebook for "ebook-…" sources, or whatever a plugin
		// offers via the `hti_lead_magnet` filter (e.g. hti-forex's cheat
		// sheet for "forex-…"). Delivery stays gated behind the double opt-in.
		$source = sanitize_key( (string) $request->get_param( 'source' ) );
		if ( '' !== $source ) {
			self::pending_source_set( $email, $source );
		}

		return new \WP_REST_Response( array( 'sent' => true ), 200 );
	}

	/**
	 * Durable option holding "these emails are owed the ebook" as
	 * hash => expiry. Kept in the DB (not a transient) so a persistent object
	 * cache under memory pressure can't evict the flag between opt-in and
	 * confirmation and silently downgrade the subscriber to the plain welcome.
	 */
	private const EBOOK_PENDING_OPTION = 'hti_ebook_pending';

	/**
	 * Per-email hash used as the durable-store key.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function ebook_pending_hash( string $email ): string {
		return md5( strtolower( trim( $email ) ) );
	}

	/**
	 * Flag that this email is awaiting the ebook (set on the ebook gate).
	 * Prunes expired entries on write so the option stays small.
	 *
	 * @param string $email Email.
	 */
	private static function ebook_pending_set( string $email ): void {
		$store = get_option( self::EBOOK_PENDING_OPTION, array() );
		$store = is_array( $store ) ? $store : array();
		$now   = time();
		foreach ( $store as $h => $exp ) {
			if ( (int) $exp < $now ) {
				unset( $store[ $h ] );
			}
		}
		$store[ self::ebook_pending_hash( $email ) ] = $now + WEEK_IN_SECONDS;
		update_option( self::EBOOK_PENDING_OPTION, $store, false );
	}

	/**
	 * Consume the pending-ebook flag: returns whether it was set (and still
	 * valid), removing it either way.
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	private static function ebook_pending_take( string $email ): bool {
		$store = get_option( self::EBOOK_PENDING_OPTION, array() );
		if ( ! is_array( $store ) ) {
			return false;
		}
		$hash  = self::ebook_pending_hash( $email );
		$valid = isset( $store[ $hash ] ) && (int) $store[ $hash ] >= time();
		if ( isset( $store[ $hash ] ) ) {
			unset( $store[ $hash ] );
			update_option( self::EBOOK_PENDING_OPTION, $store, false );
		}
		return $valid;
	}

	/**
	 * Durable option mapping hash(email) => [source, expiry]: which form the
	 * pending opt-in came from. Generalizes the ebook flag (which stays as a
	 * read fallback for opt-ins already in flight when this shipped) so any
	 * source can be attributed in Brevo and matched to a lead magnet.
	 */
	private const PENDING_SOURCE_OPTION = 'hti_pending_source';

	/**
	 * Remember the source of a pending opt-in. Prunes expired entries on
	 * write so the option stays small.
	 *
	 * @param string $email  Email.
	 * @param string $source Sanitized source key (e.g. "ebook-page", "forex-pip_value").
	 */
	private static function pending_source_set( string $email, string $source ): void {
		$store = get_option( self::PENDING_SOURCE_OPTION, array() );
		$store = is_array( $store ) ? $store : array();
		$now   = time();
		foreach ( $store as $h => $entry ) {
			if ( (int) ( $entry['x'] ?? 0 ) < $now ) {
				unset( $store[ $h ] );
			}
		}
		$store[ self::ebook_pending_hash( $email ) ] = array(
			's' => $source,
			'x' => $now + WEEK_IN_SECONDS,
		);
		update_option( self::PENDING_SOURCE_OPTION, $store, false );
	}

	/**
	 * Consume the pending source: returns it if set and still valid ('' when
	 * not), removing the entry either way.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function pending_source_take( string $email ): string {
		$store = get_option( self::PENDING_SOURCE_OPTION, array() );
		if ( ! is_array( $store ) ) {
			return '';
		}
		$hash   = self::ebook_pending_hash( $email );
		$source = '';
		if ( isset( $store[ $hash ] ) ) {
			if ( (int) ( $store[ $hash ]['x'] ?? 0 ) >= time() ) {
				$source = (string) ( $store[ $hash ]['s'] ?? '' );
			}
			unset( $store[ $hash ] );
			update_option( self::PENDING_SOURCE_OPTION, $store, false );
		}
		return $source;
	}

	/**
	 * Prefix of the per-email one-shot delivery locks (individual options, so
	 * MySQL's unique key on option_name is the mutex).
	 */
	public const DELIVERY_LOCK_PREFIX = 'hti_dlv_';

	/**
	 * How long one confirmation "owns" the delivery, in seconds (15 min). A
	 * genuine re-request after this window delivers again.
	 */
	private const DELIVERY_LOCK_TTL = 900;

	/**
	 * Whether THIS request is the one allowed to send the post-confirmation
	 * email. Mail scanners (Outlook SafeLinks, Proofpoint…) prefetch the
	 * confirmation link several times within the same second, and the
	 * pending-source store is a non-atomic read-modify-write — so without a
	 * real mutex two concurrent confirmations both read the source and both
	 * send the ebook/lead magnet. `add_option()` is an INSERT guarded by the
	 * unique key on option_name: of N concurrent calls exactly one succeeds.
	 *
	 * @param string $email Email.
	 */
	private static function delivery_guard_pass( string $email ): bool {
		$key    = self::DELIVERY_LOCK_PREFIX . self::ebook_pending_hash( $email );
		$expiry = time() + self::DELIVERY_LOCK_TTL;
		if ( add_option( $key, $expiry, '', false ) ) {
			return true;
		}
		// Lock exists: pass only when it expired (a stale lock is not the
		// scanner-burst case, so the small race here is acceptable).
		if ( (int) get_option( $key, 0 ) < time() ) {
			update_option( $key, $expiry, false );
			return true;
		}
		return false;
	}

	/**
	 * Delete expired delivery locks (called from the daily Cron::prune()).
	 * Each lock is its own option row, so the store cleans itself here rather
	 * than on every write.
	 */
	public static function prune_delivery_locks(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return; // Test harness / early bootstrap.
		}
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options table cleanup, no WP API for LIKE on names.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::DELIVERY_LOCK_PREFIX ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			if ( (int) get_option( (string) $name, 0 ) < time() ) {
				delete_option( (string) $name );
			}
		}
	}

	/**
	 * Public URL of the ebook PDF for a locale. Themes/plugins can override via
	 * the `hti_ebook_url` filter; defaults to the file bundled in the theme.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function ebook_url( string $locale ): string {
		$file    = 'pt' === $locale ? 'howtoinvest-como-comecar-a-investir.pdf' : 'howtoinvest-how-to-start-investing.pdf';
		$default = function_exists( 'get_theme_file_uri' ) ? get_theme_file_uri( 'assets/ebook/' . $file ) : '';
		return (string) apply_filters( 'hti_ebook_url', $default, $locale );
	}

	/* ---------- confirm / unsubscribe links ---------- */

	/**
	 * Handle the opt-in confirmation and unsubscribe links.
	 */
	public static function handle_link(): void {
		$action = isset( $_GET['hti_sub'] ) ? sanitize_key( wp_unslash( $_GET['hti_sub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability.
		if ( 'optin' !== $action && 'unsub' !== $action ) {
			return;
		}
		$email  = isset( $_GET['e'] ) ? sanitize_email( rawurldecode( wp_unslash( $_GET['e'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token  = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$locale = ( isset( $_GET['l'] ) && 'pt' === $_GET['l'] ) ? 'pt' : 'en'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! is_email( $email ) || ! self::token_valid( $email, $action, $token ) ) {
			self::redirect_result( 'error', $locale );
		}

		if ( 'optin' === $action ) {
			// Where the opt-in came from: the generalized store first, the
			// legacy ebook flag as a fallback for opt-ins already in flight
			// when the store shipped.
			$src = self::pending_source_take( $email );
			if ( '' === $src && self::ebook_pending_take( $email ) ) {
				$src = 'ebook';
			}

			$attributes = array(
				'LANGUAGE' => strtoupper( $locale ),
				'OPTIN_AT' => gmdate( 'Y-m-d' ),
			);
			if ( '' !== $src ) {
				// Requires a SOURCE text attribute in the Brevo dashboard;
				// unknown attributes are silently ignored by the API.
				$attributes['SOURCE'] = strtoupper( substr( $src, 0, 40 ) );
			}

			$ok = Brevo::upsert_contact(
				$email,
				$attributes,
				array_filter( array( Brevo::list_id( $locale ) ) )
			);
			$source = 'newsletter';
			if ( $ok ) {
				// Now that the subscription is confirmed, deliver the matching
				// lead magnet: the ebook for ebook-* sources, whatever a plugin
				// offers via `hti_lead_magnet` otherwise, or the plain welcome.
				// The source is carried into the redirect so the analytics
				// event can attribute the confirmation. Exactly ONE of the
				// concurrent confirmations of the same link may send email
				// (delivery_guard_pass) — the lock is only taken after a
				// successful upsert so a failed attempt can be retried.
				$deliver = self::delivery_guard_pass( $email );
				$magnet  = str_starts_with( $src, 'ebook' ) ? null : apply_filters( 'hti_lead_magnet', null, $src, $locale );
				if ( str_starts_with( $src, 'ebook' ) ) {
					$source = 'ebook';
					if ( $deliver ) {
						self::send_ebook_email( $email, $locale );
					}
				} elseif ( is_array( $magnet ) && ! empty( $magnet['url'] ) ) {
					$source = strtok( $src, '-' );
					if ( $deliver ) {
						self::send_lead_magnet_email( $email, $locale, (string) $magnet['url'], (string) ( $magnet['name'] ?? 'your download' ) );
					}
				} elseif ( $deliver ) {
					self::send_confirmed_email( $email, $locale );
				}
			}
			self::redirect_result( $ok ? 'confirmed' : 'error', $locale, $source );
		}

		// Unsubscribe: the GET link only renders a confirmation page — mail
		// scanners prefetch every GET link in an email, and executing here
		// would let a scanner silently remove the contact from the list. The
		// actual removal happens in handle_unsub_post() (scanners don't POST).
		self::render_unsub_confirm( $email, $token, $locale );
	}

	/**
	 * Minimal standalone confirmation page for the unsubscribe link (GET).
	 * Prints and exits.
	 *
	 * @param string $email  Email (token-verified).
	 * @param string $token  The unsub token, re-embedded in the POST form.
	 * @param string $locale Locale.
	 */
	private static function render_unsub_confirm( string $email, string $token, string $locale ): void {
		$pt    = 'pt' === $locale;
		$title = $pt ? 'Cancelar subscrição' : 'Unsubscribe';
		$lead  = $pt
			? sprintf( 'Queres deixar de receber os emails da HowToInvest em %s?', $email )
			: sprintf( 'Stop receiving HowToInvest emails at %s?', $email );
		$btn   = $pt ? 'Sim, cancelar subscrição' : 'Yes, unsubscribe';
		$back  = $pt ? 'Afinal não — voltar ao site' : 'Never mind — back to the site';
		$home  = home_url( $pt ? '/pt/' : '/' );

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		status_header( 200 );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( $pt ? 'pt' : 'en' ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title ); ?> — HowToInvest</title>
<style>
	body{margin:0;font:400 16px/1.6 system-ui,Arial,sans-serif;background:#FFF6F1;color:#2A2438;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}
	.card{background:#fff;border:1px solid #F2E4DD;border-radius:16px;padding:36px 32px;max-width:420px;text-align:center}
	h1{font-size:22px;margin:0 0 10px}
	p{margin:0 0 22px;color:#6E6680}
	button{background:#C9362C;color:#fff;border:0;border-radius:999px;font:700 15px system-ui,Arial,sans-serif;padding:12px 26px;cursor:pointer}
	a{display:block;margin-top:16px;color:#7C5CFC;font-size:14px}
</style>
</head>
<body>
	<div class="card">
		<h1><?php echo esc_html( $title ); ?></h1>
		<p><?php echo esc_html( $lead ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="hti_unsub">
			<input type="hidden" name="e" value="<?php echo esc_attr( $email ); ?>">
			<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="l" value="<?php echo esc_attr( $pt ? 'pt' : 'en' ); ?>">
			<button type="submit"><?php echo esc_html( $btn ); ?></button>
		</form>
		<a href="<?php echo esc_url( $home ); ?>"><?php echo esc_html( $back ); ?></a>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Execute the unsubscribe (POST from the confirmation page above). The
	 * HMAC token is the capability, same as the GET handler.
	 */
	public static function handle_unsub_post(): void {
		$email  = isset( $_POST['e'] ) ? sanitize_email( wp_unslash( $_POST['e'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- token is the capability.
		$token  = isset( $_POST['t'] ) ? sanitize_text_field( wp_unslash( $_POST['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$locale = ( isset( $_POST['l'] ) && 'pt' === $_POST['l'] ) ? 'pt' : 'en'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! is_email( $email ) || ! self::token_valid( $email, 'unsub', $token ) ) {
			self::redirect_result( 'error', $locale );
		}

		// Unsubscribe from the language list they subscribed via.
		$ok = Brevo::remove_from_list( $email, Brevo::list_id( $locale ) );
		self::redirect_result( $ok ? 'unsubscribed' : 'error', $locale, 'newsletter' );
	}

	/**
	 * Redirect to the confirmation landing page (confirmed state) or home
	 * (everything else, rendered as the footer toast), then stop.
	 *
	 * @param string $state  Result state.
	 * @param string $locale Locale.
	 */
	private static function redirect_result( string $state, string $locale, string $source = '' ): void {
		$args = array( 'hti_sub_done' => $state, 'l' => $locale );
		if ( '' !== $source ) {
			$args['src'] = $source;
		}
		$target = 'confirmed' === $state ? self::confirmed_page_url( $locale ) : '';
		if ( '' === $target ) {
			$target = home_url( '/' );
		}
		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}

	/**
	 * URL of the seeded confirmation landing page in a locale ('' when the
	 * page doesn't exist yet — the caller falls back to the home toast).
	 *
	 * @param string $locale Locale.
	 */
	private static function confirmed_page_url( string $locale ): string {
		$page = get_page_by_path( 'subscription-confirmed', OBJECT, 'page' );
		if ( ! $page instanceof \WP_Post ) {
			return '';
		}
		$id = (int) $page->ID;
		if ( 'pt' === $locale && function_exists( 'pll_get_post' ) ) {
			$pt_id = (int) pll_get_post( $id, 'pt' );
			if ( $pt_id > 0 ) {
				$id = $pt_id;
			}
		}
		return (string) get_permalink( $id );
	}

	/**
	 * `[hti_subscribe_confirmed]` — body of the confirmation landing page:
	 * welcome copy, the immediate lead-magnet download when this confirmation
	 * owes one (resolved from the redirect's `src`, never from user input
	 * beyond that key), a funnel CTA and the unsubscribe note.
	 */
	public static function render_confirmed(): string {
		wp_enqueue_style( 'hti-subscribe' );

		$pt    = 'pt' === self::locale();
		$state = isset( $_GET['hti_sub_done'] ) ? sanitize_key( wp_unslash( $_GET['hti_sub_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only state set by our own redirect.
		$src   = isset( $_GET['src'] ) ? sanitize_key( wp_unslash( $_GET['src'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$done  = 'confirmed' === $state;

		// The page carries no wp:post-title (page-confirmation template), so
		// the component owns the H1 — and it can differ per state.
		$title = $done
			? ( $pt ? 'Subscrição confirmada' : 'You’re subscribed' )
			: ( $pt ? 'Confirmação de subscrição' : 'Subscription confirmation' );
		$lead  = $done
			? ( $pt
				? 'Bem-vindo. A partir de agora recebes as nossas novidades e aprendizagem financeira — sem jargão, sem produtos, sem promessas.'
				: 'Welcome aboard. From now on you’ll get our updates and jargon-free financial learning — no products, no promises.' )
			: ( $pt
				? 'Esta é a página que confirma subscrições da newsletter. Para subscreveres, usa um dos formulários do site: enviamos-te primeiro um email para confirmares.'
				: 'This is the page that confirms newsletter subscriptions. To subscribe, use one of the forms on the site — we email you a confirmation link first.' );

		$out  = '<div class="hti-sub-confirmed">';
		$out .= '<div class="hti-sub-confirmed__hero">';

		if ( $done ) {
			$out .= '<span class="hti-sub-confirmed__eyebrow"><span class="hti-sub-confirmed__dot"></span>'
				. esc_html( $pt ? 'Estás dentro' : 'You’re in' ) . '</span>';
			$out .= '<span class="hti-sub-confirmed__check">' . self::icon( '<path d="M20 6 9 17l-5-5"/>' ) . '</span>';
		}

		$out .= '<h1 class="hti-sub-confirmed__h">' . esc_html( $title ) . '</h1>';
		$out .= '<p class="hti-sub-confirmed__lead">' . esc_html( $lead ) . '</p>';
		$out .= '</div>';

		if ( $done ) {
			$out .= self::confirmed_magnet_html( $src, $pt );
			$out .= self::confirmed_next_html( $pt );
		}

		$out .= '<p class="hti-sub-confirmed__note">'
			. esc_html(
				$pt
					? 'Podes cancelar quando quiseres — todos os nossos emails trazem um link de cancelamento.'
					: 'You can unsubscribe anytime — every email we send carries an unsubscribe link.'
			)
			. '</p>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * The immediate-download panel for the lead magnet owed by this
	 * confirmation, '' when the source owes none (plain newsletter). Uses the
	 * dark lead-magnet treatment the Learn hub already uses for the ebook.
	 *
	 * @param string $src Source key from the redirect ('ebook', 'forex', …).
	 * @param bool   $pt  Whether the page is Portuguese.
	 */
	private static function confirmed_magnet_html( string $src, bool $pt ): string {
		$locale  = $pt ? 'pt' : 'en';
		$url     = '';
		$name    = '';
		$other   = '';
		$other_l = '';

		if ( str_starts_with( $src, 'ebook' ) ) {
			$url     = self::ebook_url( $locale );
			$name    = $pt ? 'Como começar a investir' : 'How to start investing';
			$other   = self::ebook_url( $pt ? 'en' : 'pt' );
			$other_l = $pt ? 'Prefiro a versão em inglês' : 'I’d prefer the Portuguese version';
		} elseif ( '' !== $src && 'newsletter' !== $src ) {
			$magnet = apply_filters( 'hti_lead_magnet', null, $src, $locale );
			if ( is_array( $magnet ) && ! empty( $magnet['url'] ) ) {
				$url  = (string) $magnet['url'];
				$name = (string) ( $magnet['name'] ?? ( $pt ? 'O teu download' : 'Your download' ) );
			}
		}

		if ( '' === $url ) {
			return '';
		}

		$html  = '<section class="hti-sub-confirmed__magnet" aria-labelledby="hti-sub-magnet-h">';
		$html .= '<span class="hti-sub-confirmed__tile">' . self::icon( '<path d="M12 3v12"/><path d="m7 14 5 5 5-5"/><path d="M5 21h14"/>' ) . '</span>';
		$html .= '<div class="hti-sub-confirmed__magnet-body">';
		$html .= '<h2 class="hti-sub-confirmed__magnet-h" id="hti-sub-magnet-h">' . esc_html( $pt ? 'O teu download está pronto' : 'Your download is ready' ) . '</h2>';
		$html .= '<p class="hti-sub-confirmed__magnet-name">' . esc_html( $name ) . ' <span>(PDF)</span></p>';
		$html .= '<p class="hti-sub-confirmed__actions">'
			. '<a class="hti-sub-confirmed__btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
			. esc_html( $pt ? 'Descarregar o PDF' : 'Download the PDF' ) . '</a>';
		if ( '' !== $other ) {
			$html .= '<a class="hti-sub-confirmed__btn hti-sub-confirmed__btn--ghost" href="' . esc_url( $other ) . '" target="_blank" rel="noopener">'
				. esc_html( $other_l ) . '</a>';
		}
		$html .= '</p>';
		$html .= '<p class="hti-sub-confirmed__mailed">'
			. esc_html( $pt ? 'Também to enviámos por email, para o teres sempre à mão.' : 'We also emailed it to you, so it’s always at hand.' )
			. '</p>';
		$html .= '</div></section>';

		return $html;
	}

	/**
	 * The "next step" card pointing at the questionnaire ('' when the page
	 * isn't seeded). Mirrors the Learn hub's purple next-step card.
	 *
	 * @param bool $pt Whether the page is Portuguese.
	 */
	private static function confirmed_next_html( bool $pt ): string {
		$url = self::quiz_url( $pt );
		if ( '' === $url ) {
			return '';
		}

		$html  = '<section class="hti-sub-confirmed__next" aria-labelledby="hti-sub-next-h">';
		$html .= '<span class="hti-sub-confirmed__tile hti-sub-confirmed__tile--accent">'
			. self::icon( '<path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/>' ) . '</span>';
		$html .= '<div>';
		$html .= '<h2 class="hti-sub-confirmed__next-h" id="hti-sub-next-h">' . esc_html( $pt ? 'Enquanto esperas pelo próximo email' : 'While you wait for the next email' ) . '</h2>';
		$html .= '<p class="hti-sub-confirmed__next-p">'
			. esc_html(
				$pt
					? 'Responde ao questionário e vê que perfil de investidor te descreve — e um exemplo ilustrativo de carteira por classes de ativos. Leva cerca de 3 minutos.'
					: 'Take the questionnaire to see which investor profile describes you — and an illustrative portfolio by asset class. It takes about 3 minutes.'
			)
			. '</p>';
		$html .= '<p class="hti-sub-confirmed__actions"><a class="hti-sub-confirmed__btn hti-sub-confirmed__btn--accent" href="' . esc_url( $url ) . '">'
			. esc_html( $pt ? 'Descobrir o meu perfil' : 'Discover my profile' ) . '</a></p>';
		$html .= '</div></section>';

		return $html;
	}

	/**
	 * Inline Feather-style icon, using the theme's icon signature.
	 *
	 * @param string $path Raw SVG path markup (author-controlled, never input).
	 */
	private static function icon( string $path ): string {
		return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. $path . '</svg>';
	}

	/**
	 * Localized URL of the questionnaire page ('' when unseeded).
	 *
	 * @param bool $pt Whether Portuguese.
	 */
	private static function quiz_url( bool $pt ): string {
		$page = get_page_by_path( 'investor-profile-quiz', OBJECT, 'page' );
		if ( ! $page instanceof \WP_Post ) {
			return '';
		}
		$id = (int) $page->ID;
		if ( $pt && function_exists( 'pll_get_post' ) ) {
			$pt_id = (int) pll_get_post( $id, 'pt' );
			if ( $pt_id > 0 ) {
				$id = $pt_id;
			}
		}
		return (string) get_permalink( $id );
	}

	/**
	 * Render a small fixed toast after a confirm/unsubscribe redirect.
	 */
	public static function result_toast(): void {
		$state = isset( $_GET['hti_sub_done'] ) ? sanitize_key( wp_unslash( $_GET['hti_sub_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $state ) {
			return;
		}
		$pt  = isset( $_GET['l'] ) && 'pt' === $_GET['l']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'confirmed'    => $pt ? 'Subscrição confirmada. Bem-vindo!' : 'Subscription confirmed. Welcome aboard!',
			'unsubscribed' => $pt ? 'Subscrição cancelada. Não voltarás a receber estes emails.' : 'You’ve been unsubscribed. You won’t receive these emails again.',
			'error'        => $pt ? 'Esse link é inválido ou expirou.' : 'That link is invalid or has expired.',
		);
		$msg = $map[ $state ] ?? '';
		if ( '' === $msg ) {
			return;
		}
		// On the dedicated confirmation landing page the content says it all —
		// keep only the analytics event below, not the floating toast.
		if ( ! self::on_confirmed_page() ) {
			$bg = 'error' === $state ? '#C0392B' : '#147A57';
			printf(
				'<div role="status" style="position:fixed;left:50%%;bottom:24px;transform:translateX(-50%%);z-index:9999;max-width:90vw;background:%s;color:#fff;font:600 14px system-ui,Arial,sans-serif;padding:12px 20px;border-radius:999px;box-shadow:0 6px 24px rgba(0,0,0,.18);">%s</div>',
				esc_attr( $bg ),
				esc_html( $msg )
			);
		}

		// Report the confirm / unsubscribe outcome to analytics (first-party +
		// GTM dataLayer), once the tracking helper has loaded. Carries source
		// (ebook vs newsletter) and status so the GA4 event can attribute it.
		$event = array(
			'confirmed'    => 'newsletter_confirmed',
			'unsubscribed' => 'newsletter_unsubscribe',
		)[ $state ] ?? '';
		if ( '' !== $event ) {
			$status  = 'confirmed' === $state ? 'confirmed' : 'unsubscribed';
			$src     = isset( $_GET['src'] ) ? sanitize_key( wp_unslash( $_GET['src'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$payload = array( 'status' => $status );
			if ( '' !== $src ) {
				$payload['source'] = $src;
			}
			printf(
				'<script>document.addEventListener("DOMContentLoaded",function(){if(window.HTITrack){window.HTITrack.event(%s,%s);}});</script>',
				wp_json_encode( $event ),
				wp_json_encode( $payload )
			);
		}
	}

	/* ---------- emails ---------- */

	/**
	 * Send the branded double opt-in confirmation email.
	 *
	 * @param string $email  Email.
	 * @param string $locale Locale.
	 */
	private static function send_optin_email( string $email, string $locale ): void {
		$pt   = 'pt' === $locale;
		$url  = self::link( 'optin', $email, $locale );

		$subject = $pt ? 'Confirma a tua subscrição — HowToInvest' : 'Confirm your subscription — HowToInvest';
		$heading = $pt ? 'Confirma a tua subscrição' : 'Confirm your subscription';
		$lead    = $pt
			? 'Falta só um passo. Confirma que queres receber as novidades da HowToInvest no botão abaixo.'
			: 'Just one step left. Confirm you want to receive HowToInvest updates using the button below.';
		$btn     = $pt ? 'Confirmar subscrição' : 'Confirm subscription';
		$note    = $pt
			? 'Se não foste tu a pedir isto, podes ignorar este email com segurança.'
			: "If you didn't request this, you can safely ignore this email.";

		$inner = Emails::row(
			Emails::icon_circle( '&#9993;', '#EAF6F0', '#147A57' ) . Emails::h1( $heading ) . Emails::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. Emails::row( Emails::button( $btn, $url ), '28px 48px 6px', true )
			. Emails::row( Emails::url_fallback( $url, $locale ), '18px 48px 8px' )
			. Emails::row( Emails::note( $note ), '18px 48px 44px', true );

		Mailer::send( $email, $subject, Emails::layout( $locale, $inner, $heading ) );
	}

	/**
	 * Deliver the ebook: a branded email with the download button (and the
	 * other language as a secondary link). Sent on the ebook lead-magnet gate.
	 *
	 * @param string $email  Email.
	 * @param string $locale Locale.
	 */
	private static function send_ebook_email( string $email, string $locale ): void {
		$pt  = 'pt' === $locale;
		$url = self::ebook_url( $locale );
		if ( '' === $url ) {
			return;
		}
		$other_locale = $pt ? 'en' : 'pt';
		$other_url    = self::ebook_url( $other_locale );

		$subject = $pt ? 'O teu ebook chegou — Como começar a investir' : 'Your ebook is here — How to start investing';
		$heading = $pt ? 'O teu ebook está pronto' : 'Your ebook is ready';
		$lead    = $pt
			? 'Aqui tens o guia “Como começar a investir” — as bases reunidas num só sítio, sem produtos e sem promessas. Carrega no botão para o descarregar (PDF).'
			: 'Here is your guide “How to start investing” — the essentials in one place, with no products and no promises. Use the button to download it (PDF).';
		$btn   = $pt ? 'Descarregar o ebook (PDF)' : 'Download the ebook (PDF)';
		$other = $pt
			? '<a href="' . esc_url( $other_url ) . '" style="font:400 13px Arial,sans-serif;color:#7C5CFC;">Prefere a versão em inglês? Descarrega aqui.</a>'
			: '<a href="' . esc_url( $other_url ) . '" style="font:400 13px Arial,sans-serif;color:#7C5CFC;">Prefer the Portuguese version? Download here.</a>';
		// This email doubles as the post-confirmation welcome, so it carries the
		// disclaimer + an unsubscribe link.
		$note    = $pt
			? 'Conteúdo educativo, não constitui aconselhamento financeiro. Exemplos só por classe de ativos.'
			: 'Educational content, not financial advice. Examples by asset class only.';
		$unsub   = self::link( 'unsub', $email, $locale );
		$unlabel = $pt ? 'Cancelar subscrição' : 'Unsubscribe';

		$inner = Emails::row(
			Emails::icon_circle( '&#128214;', '#EFE9FE', '#6A4BE0' ) . Emails::h1( $heading ) . Emails::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. Emails::row( Emails::button( $btn, $url ), '28px 48px 6px', true )
			. Emails::row( $other, '14px 48px 8px', true )
			. Emails::row( Emails::note( $note ), '18px 48px 10px', true )
			. Emails::row( '<a href="' . esc_url( $unsub ) . '" style="font:400 12.5px Arial,sans-serif;color:#9A93A8;">' . esc_html( $unlabel ) . '</a>', '0 48px 44px', true );

		Mailer::send( $email, $subject, Emails::layout( $locale, $inner, $heading ) );
	}

	/**
	 * Deliver a plugin-provided lead magnet (via the `hti_lead_magnet`
	 * filter): a branded email with the download button. Mirrors the ebook
	 * email and doubles as the post-confirmation welcome, so it carries the
	 * disclaimer and an unsubscribe link.
	 *
	 * @param string $email  Email.
	 * @param string $locale Locale.
	 * @param string $url    Download URL.
	 * @param string $name   Human name of the download (e.g. "INR lot size cheat sheet").
	 */
	private static function send_lead_magnet_email( string $email, string $locale, string $url, string $name ): void {
		$pt = 'pt' === $locale;

		$subject = ( $pt ? 'O teu download chegou — ' : 'Your download is here — ' ) . $name;
		$heading = $pt ? 'O teu download está pronto' : 'Your download is ready';
		$lead    = $pt
			? sprintf( 'Aqui tens “%s”. Carrega no botão para descarregar (PDF).', $name )
			: sprintf( 'Here is your “%s”. Use the button to download it (PDF).', $name );
		$btn     = $pt ? 'Descarregar (PDF)' : 'Download (PDF)';
		$note    = $pt
			? 'Conteúdo educativo, não constitui aconselhamento financeiro.'
			: 'Educational content, not financial advice.';
		$unsub   = self::link( 'unsub', $email, $locale );
		$unlabel = $pt ? 'Cancelar subscrição' : 'Unsubscribe';

		$inner = Emails::row(
			Emails::icon_circle( '&#128200;', '#EAF6F0', '#147A57' ) . Emails::h1( $heading ) . Emails::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. Emails::row( Emails::button( $btn, $url ), '28px 48px 6px', true )
			. Emails::row( Emails::url_fallback( $url, $locale ), '18px 48px 8px' )
			. Emails::row( Emails::note( $note ), '18px 48px 10px', true )
			. Emails::row( '<a href="' . esc_url( $unsub ) . '" style="font:400 12.5px Arial,sans-serif;color:#9A93A8;">' . esc_html( $unlabel ) . '</a>', '0 48px 44px', true );

		Mailer::send( $email, $subject, Emails::layout( $locale, $inner, $heading ) );
	}

	/**
	 * Send the "you're subscribed" welcome email (with an unsubscribe link).
	 *
	 * @param string $email  Email.
	 * @param string $locale Locale.
	 */
	private static function send_confirmed_email( string $email, string $locale ): void {
		$pt    = 'pt' === $locale;
		$unsub = self::link( 'unsub', $email, $locale );

		$subject = $pt ? 'Estás subscrito — HowToInvest' : "You're subscribed — HowToInvest";
		$heading = $pt ? 'Estás dentro!' : 'You’re in!';
		$lead    = $pt
			? 'Obrigado por confirmares. A partir de agora vais receber as nossas novidades e aprendizagem financeira, sem jargão.'
			: 'Thanks for confirming. From now on you’ll receive our updates and jargon-free financial learning.';
		$unlabel = $pt ? 'Cancelar subscrição' : 'Unsubscribe';

		$inner = Emails::row(
			Emails::icon_circle( '&#10003;', '#EAF6F0', '#147A57' ) . Emails::h1( $heading ) . Emails::lead( esc_html( $lead ) ),
			'44px 48px 0',
			true
		)
			. Emails::row( Emails::button( $pt ? 'Explorar a plataforma' : 'Explore the platform', home_url( $pt ? '/pt/' : '/' ) ), '28px 48px 8px', true )
			. Emails::row( '<a href="' . esc_url( $unsub ) . '" style="font:400 12.5px Arial,sans-serif;color:#9A93A8;">' . esc_html( $unlabel ) . '</a>', '8px 48px 44px', true );

		Mailer::send( $email, $subject, Emails::layout( $locale, $inner, $heading ) );
	}
}
