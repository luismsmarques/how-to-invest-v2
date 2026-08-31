<?php
/**
 * The magic link: how a run stops being anonymous, if the player wants it to.
 *
 * Nothing about the games needs an account — the cookie is enough to play
 * every day forever. An account buys exactly one thing: the run survives a
 * cleared browser and follows the player to another device. So the flow asks
 * for the one field that can deliver that (an email), and nothing else: no
 * password to choose, no profile to fill in, no name.
 *
 * This follows class-verification.php in hti-engine, which is already a
 * working magic-link sign-in on this site — hashed token in user meta, an
 * expiry, wp_set_auth_cookie() on success — rather than class-subscribe.php,
 * which is a newsletter double opt-in and a different problem wearing similar
 * clothes.
 *
 * ---------------------------------------------------------------------------
 * The email says nothing about whether the address has an account
 * ---------------------------------------------------------------------------
 *
 * /games/link answers identically in every branch that DEPENDS ON THE ADDRESS:
 * new address, existing account, caught by the honeypot. A response that
 * differed would turn a public endpoint into an account-enumeration oracle —
 * "is lm@example.com registered here?" answered a thousand times a minute. The
 * person learns the outcome in their inbox, which is the one place only they
 * can read.
 *
 * The two branches that do answer differently — 429 from the rate limiter, 503
 * when the cross-device link is switched off — are properties of the site and
 * of the caller's own IP, identical for every address, so neither says
 * anything about whether an address has an account. Any NEW branch has to pass
 * that test before it may differ.
 *
 * What this design cannot hide is that a first request for an unknown address
 * creates the account, so the second one takes a measurably different path
 * through wp_insert_user(). Closing that would mean holding the pending
 * address somewhere until the link is confirmed, which trades an enumeration
 * timing signal for a store of unverified email addresses — a worse position
 * under minimisation. Noted rather than fixed; see the QA checklist.
 *
 * ---------------------------------------------------------------------------
 * Mail scanners burn single-use tokens. This is not hypothetical.
 * ---------------------------------------------------------------------------
 *
 * Outlook Safe Links, Proofpoint, Mimecast and Gmail's image proxy all fetch
 * URLs out of incoming mail before a human sees them, and a single-use sign-in
 * link consumed by that fetch is a link that is already dead when the person
 * clicks it. It is one of the most common ways magic-link auth fails in
 * production, and it fails in the most confusing way possible: it works for
 * the developer and not for the customer.
 *
 * Two mitigations, both here:
 *   1. Requests announcing themselves as prefetches (`Sec-Purpose: prefetch`,
 *      the older `Purpose: prefetch`, and HEAD) are ignored outright — the
 *      token is not even looked at, let alone spent. The guard cannot itself
 *      become a bypass: an attacker who sends those headers gets the early
 *      return, which signs nobody in and changes nothing.
 *   2. The token is consumed only after a sign-in actually succeeds — never on
 *      a request that merely looked at it and failed. A guessed or expired
 *      token therefore cannot be used to burn a real one.
 *
 * The residual case, stated plainly because a comment that overstates a
 * control is worse than no comment: a scanner that announces nothing and
 * follows the link with a GET is indistinguishable from the recipient, so it
 * completes the sign-in and spends the token. Mitigation 2 does not save that
 * link, and nothing here can without an interstitial the person has to click.
 * It is on the staging QA list as a real-inbox test.
 *
 * @package HTI_Games
 */

namespace HTI\Games;

defined( 'ABSPATH' ) || exit;

use HTI\Engine\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Passwordless sign-in for the games section.
 */
class Auth {

	/**
	 * User meta holding the sha256 of the live token, and its expiry.
	 *
	 * The plaintext is never stored: a leaked database must not be a set of
	 * working sign-in links. These two keys are also cleaned up by
	 * uninstall.php, which deletes them across all users.
	 */
	public const META_TOKEN   = 'hti_games_link_token';
	public const META_EXPIRES = 'hti_games_link_expires';

	/**
	 * Fifteen minutes. Long enough for a mail to be delivered and read, short
	 * enough that a link sitting in an unattended inbox stops being a key.
	 */
	private const TTL = 900;

	/**
	 * Query args carrying the link.
	 */
	private const ARG_TOKEN = 'hti_game_link';
	private const ARG_UID   = 'hti_game_uid';

	/**
	 * Hook the confirmation handler onto the front end.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ) );
	}

	/* ---------------------------------------------------------------- */
	/* Request                                                           */
	/* ---------------------------------------------------------------- */

	/**
	 * POST /games/link — send a sign-in link. Always answers the same way.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_link( WP_REST_Request $request ) {
		// The neutral body. Built once, returned from every path below that is
		// not a hard input error, so no branch can accidentally differ.
		$lang    = Player::lang( (string) $request->get_param( 'lang' ) );
		$neutral = new WP_REST_Response(
			array(
				'sent'    => true,
				'message' => Strings::get( 'link_sent_body', $lang ),
			),
			200
		);

		// The kill-switch, server-side, before anything else — the settings
		// screen promises that with the cross-device link off "no email address
		// is ever collected by this section", and a route that keeps accepting
		// addresses and minting WordPress users would make that untrue. Checked
		// ahead of the limiter so a switched-off endpoint does no work at all.
		if ( ! self::link_enabled() ) {
			return new WP_Error(
				'hti_game_link_disabled',
				Strings::get( 'st_error', $lang ),
				array( 'status' => 503 )
			);
		}

		if ( RateLimit::exceeded( 'game_link' ) ) {
			return new WP_Error(
				'hti_rate_limited',
				Strings::get( 'st_rate_limited', $lang ),
				array( 'status' => 429 )
			);
		}

		// Honeypot: a bot fills every field it finds. Report success and do
		// nothing — telling it that it was caught only teaches it.
		if ( '' !== trim( (string) $request->get_param( 'hti_hp' ) ) ) {
			return $neutral;
		}

		// Consent and a valid address are the two things the visitor can
		// actually correct, so these two — and only these two — are 422s.
		if ( true !== rest_sanitize_boolean( $request->get_param( 'consent' ) ) ) {
			return new WP_Error( 'hti_game_no_consent', __( 'Please agree to receive the sign-in email.', 'hti-games' ), array( 'status' => 422 ) );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'hti_game_invalid_email',
				Strings::get( 'link_bad_email', $lang ),
				array( 'status' => 422 )
			);
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user instanceof \WP_User ) {
			$user_id = wp_insert_user(
				array(
					'user_login' => $email,
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 32, true, true ),
					'role'       => 'subscriber',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return $neutral; // Stay neutral even when we failed.
			}
			$user = get_user_by( 'id', $user_id );
		}

		if ( ! $user instanceof \WP_User ) {
			return $neutral;
		}

		self::send_link( $user, self::issue_token( (int) $user->ID ), $lang );

		// The newsletter box is the separate, optional, unticked one — the only
		// consent in this flow that is actually freely given. It is handed
		// straight to hti-engine's existing double opt-in so Brevo stays the
		// single source of truth for subscriptions and no subscriber PII is
		// stored on this site. `source` says which game brought them, which is
		// what makes the first campaign make sense to them.
		// Gated on the setting as well as on the tick: an opt-in the owner has
		// switched off is a consent surface that is not on offer, and a request
		// carrying `newsletter: true` to a closed one — a stale tab, a replayed
		// body, a script — must not subscribe anybody.
		if ( self::newsletter_enabled() && true === rest_sanitize_boolean( $request->get_param( 'newsletter' ) ) ) {
			self::forward_newsletter( $email, $lang, sanitize_key( (string) $request->get_param( 'game' ) ) );
		}

		self::bump( 'game_link_request', 'link_request' );

		return $neutral;
	}

	/**
	 * Whether the cross-device link is switched on.
	 *
	 * Fails OPEN only when the settings class is not loaded at all, which is a
	 * fresh install with no stored option and every feature on by default.
	 */
	private static function link_enabled(): bool {
		return ! class_exists( __NAMESPACE__ . '\\Settings' ) || ! empty( Settings::settings()['email_link_enabled'] );
	}

	/**
	 * Whether the newsletter opt-in is on offer. Defaults to OFF, unlike the
	 * link: a marketing surface is not something a missing settings class
	 * should switch on.
	 */
	private static function newsletter_enabled(): bool {
		return class_exists( __NAMESPACE__ . '\\Settings' ) && ! empty( Settings::settings()['newsletter_optin'] );
	}

	/**
	 * Mint a token: store its hash and expiry, return the plaintext.
	 *
	 * A fresh request replaces the previous token, so the newest email in the
	 * inbox is always the one that works — the behaviour people expect when
	 * they click "send it again".
	 *
	 * @param int $user_id User id.
	 */
	private static function issue_token( int $user_id ): string {
		$plain = wp_generate_password( 40, false );
		update_user_meta( $user_id, self::META_TOKEN, hash( 'sha256', $plain ) );
		update_user_meta( $user_id, self::META_EXPIRES, time() + self::TTL );
		return $plain;
	}

	/**
	 * Hand a newsletter opt-in to hti-engine's double opt-in endpoint.
	 *
	 * Called in-process rather than over HTTP: the site calling itself would
	 * need a nonce it does not have, and a loopback request on cPanel is a
	 * timeout waiting to happen.
	 *
	 * @param string $email Address.
	 * @param string $lang  'en' or 'pt'.
	 * @param string $game  Game id the opt-in came from.
	 */
	private static function forward_newsletter( string $email, string $lang, string $game ): void {
		if ( ! class_exists( '\\HTI\\Engine\\Subscribe' ) || ! class_exists( '\\WP_REST_Request' ) ) {
			return;
		}

		$source = Config::is_game( $game ) ? 'game-' . $game : 'game';

		$sub = new \WP_REST_Request( 'POST', '/htinvest/v1/subscribe' );
		$sub->set_param( 'email', $email );
		$sub->set_param( 'consent', true );
		$sub->set_param( 'locale', $lang );
		$sub->set_param( 'source', $source );

		\HTI\Engine\Subscribe::request_optin( $sub );
	}

	/* ---------------------------------------------------------------- */
	/* Confirm                                                           */
	/* ---------------------------------------------------------------- */

	/**
	 * Handle the link: ignore prefetches, validate, sign in, claim, redirect.
	 */
	public static function handle_link(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token in the URL is the capability; a nonce cannot travel in an email.
		if ( empty( $_GET[ self::ARG_TOKEN ] ) || empty( $_GET[ self::ARG_UID ] ) ) {
			return;
		}

		// A scanner, not a person. Leave without touching the token: consuming
		// it here is exactly the bug this guard exists to prevent.
		if ( self::is_prefetch() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$uid = absint( wp_unslash( $_GET[ self::ARG_UID ] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$token = sanitize_text_field( wp_unslash( $_GET[ self::ARG_TOKEN ] ) );

		$user = $uid ? get_user_by( 'id', $uid ) : false;
		$lang = Player::lang( (string) determine_locale() );

		if ( ! $user instanceof \WP_User || ! self::token_valid( $uid, $token ) ) {
			wp_safe_redirect( add_query_arg( 'link_error', '1', self::destination( $lang ) ) );
			exit;
		}

		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true );

		// Consumed only now — after the sign-in has actually happened. A token
		// that is invalidated on first sight is a token any mail scanner can
		// spend on the recipient's behalf.
		delete_user_meta( $uid, self::META_TOKEN );
		delete_user_meta( $uid, self::META_EXPIRES );

		// Bind whatever run this browser has been playing to the account. The
		// surviving row may be the account's rather than the cookie's, so the
		// cookie is re-pointed at it.
		$player = Player::claim_for_user( Player::read_uuid(), $uid );
		if ( $player ) {
			Player::set_cookie( (string) $player['uuid'] );
		}

		self::bump( 'game_link_confirmed', 'link_confirmed' );

		wp_safe_redirect( add_query_arg( 'linked', '1', self::destination( $lang ) ) );
		exit;
	}

	/**
	 * Whether this request is a machine pre-opening the link.
	 *
	 * `Sec-Purpose: prefetch` is the standard header modern clients send;
	 * `Purpose: prefetch` is the older one still emitted by several mail
	 * gateways and by Chrome's prerender. HEAD is in here because a link
	 * checker that fetches headers only is the same problem in another shape.
	 */
	private static function is_prefetch(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'HEAD' === $method ) {
			return true;
		}

		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = strtolower( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
			if ( str_contains( $value, 'prefetch' ) || str_contains( $value, 'prerender' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Constant-time check of a token against the stored hash, within expiry.
	 *
	 * hash_equals, not `===`: the comparison is against a value an attacker
	 * supplies and can time.
	 *
	 * @param int    $user_id User id.
	 * @param string $token   Plaintext token from the URL.
	 */
	private static function token_valid( int $user_id, string $token ): bool {
		$stored  = (string) get_user_meta( $user_id, self::META_TOKEN, true );
		$expires = (int) get_user_meta( $user_id, self::META_EXPIRES, true );

		return '' !== $stored
			&& time() < $expires
			&& hash_equals( $stored, hash( 'sha256', $token ) );
	}

	/* ---------------------------------------------------------------- */
	/* URLs                                                              */
	/* ---------------------------------------------------------------- */

	/**
	 * The sign-in URL for a user and a plaintext token.
	 *
	 * @param int    $user_id User id.
	 * @param string $token   Plaintext token.
	 */
	private static function link_url( int $user_id, string $token ): string {
		return add_query_arg(
			array(
				self::ARG_TOKEN => rawurlencode( $token ),
				self::ARG_UID   => $user_id,
			),
			home_url( '/' )
		);
	}

	/**
	 * Where a confirmed link lands: the player's own profile page.
	 *
	 * Built from Config::pages() rather than hard-coded so the slug lives in
	 * one place, and prefixed with /pt/ in Portuguese the same way the rest of
	 * the site is.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function destination( string $lang ): string {
		return home_url( self::page_path( 'profile', $lang ) );
	}

	/**
	 * The path of a games page, walking its parent chain. Pure.
	 *
	 * @param string $key  Page key in Config::pages().
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function page_path( string $key, string $lang ): string {
		$pages = Config::pages();
		$lang  = 'pt' === $lang ? 'pt' : 'en';
		$parts = array();

		// Bounded walk: a mis-edited table with a parent cycle must not hang a
		// request. The table is five entries deep at most.
		$guard = 0;
		while ( isset( $pages[ $key ] ) && $guard < 10 ) {
			array_unshift( $parts, (string) $pages[ $key ][ $lang ] );
			$key = (string) ( $pages[ $key ]['parent'] ?? '' );
			++$guard;
		}

		if ( ! $parts ) {
			return '/';
		}

		return ( 'pt' === $lang ? '/pt/' : '/' ) . implode( '/', $parts ) . '/';
	}

	/* ---------------------------------------------------------------- */
	/* Email                                                             */
	/* ---------------------------------------------------------------- */

	/**
	 * Send the sign-in email, built from hti-engine's email primitives so it
	 * looks like every other message this site sends.
	 *
	 * @param \WP_User $user  Recipient.
	 * @param string   $token Plaintext token.
	 * @param string   $lang  'en' or 'pt'.
	 */
	private static function send_link( \WP_User $user, string $token, string $lang ): void {
		if ( ! class_exists( '\\HTI\\Engine\\Emails' ) || ! class_exists( '\\HTI\\Engine\\Mailer' ) ) {
			return;
		}

		$pt  = 'pt' === $lang;
		$url = self::link_url( (int) $user->ID, $token );

		$subject = $pt ? 'A tua ligação de acesso — HowToInvest' : 'Your sign-in link — HowToInvest';
		$heading = $pt ? 'Entra e guarda o teu progresso' : 'Sign in and keep your progress';
		$intro   = $pt
			? 'Carrega no botão para entrares e ligares o teu progresso nos jogos a esta conta. A ligação é válida durante 15 minutos e só pode ser usada uma vez.'
			: 'Tap the button to sign in and attach your game progress to this account. The link is valid for 15 minutes and can only be used once.';
		$btn     = $pt ? 'Entrar' : 'Sign in';
		$note    = $pt
			? 'Os jogos usam dinheiro virtual e são apenas educativos. Se não foste tu a pedir isto, podes ignorar esta mensagem em segurança.'
			: 'The games use virtual money and are educational only. If you didn’t request this, you can safely ignore this message.';

		$inner = \HTI\Engine\Emails::row(
			\HTI\Engine\Emails::icon_circle( '&#9654;', '#EFEBFF', '#7C5CFC' )
				. \HTI\Engine\Emails::h1( $heading )
				. \HTI\Engine\Emails::lead( esc_html( $intro ) ),
			'44px 48px 0',
			true
		)
			. \HTI\Engine\Emails::row( \HTI\Engine\Emails::button( $btn, $url ), '28px 48px 6px', true )
			. \HTI\Engine\Emails::row( \HTI\Engine\Emails::url_fallback( $url, $lang ), '18px 48px 8px' )
			. \HTI\Engine\Emails::row( \HTI\Engine\Emails::note( $note ), '18px 48px 44px', true );

		\HTI\Engine\Mailer::send( $user->user_email, $subject, \HTI\Engine\Emails::layout( $lang, $inner, $heading ) );
	}

	/**
	 * Count an event, if hti-engine's metrics are around.
	 *
	 * @param string $event    Registered event name.
	 * @param string $location Fixed detail label.
	 */
	private static function bump( string $event, string $location = '' ): void {
		if ( ! class_exists( '\\HTI\\Engine\\Metrics' ) ) {
			return;
		}
		\HTI\Engine\Metrics::bump( $event, '' !== $location ? array( 'location' => $location ) : array() );
	}
}
