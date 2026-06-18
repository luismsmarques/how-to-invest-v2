<?php
/**
 * Server-side PDF export of a saved result (Criterios §4).
 *
 * Builds the result as a self-contained HTML document and renders it to PDF
 * with Dompdf when available (composer dependency, installed at deploy). If the
 * library is absent it streams the same document as printable HTML so the
 * feature still works ("Print → Save as PDF").
 *
 * Triggered by a POST to admin-post.php (keeps the session token out of the
 * URL). Authorized for the profile's owner (account) or the anonymous holder
 * of its session token.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and streams the result PDF.
 */
class PdfExport {

	private const ACTION = 'hti_pdf';

	private const COLORS = array(
		'global_equity' => '#FF6B5E',
		'bonds'         => '#7C5CFC',
		'reits_alt'     => '#D69A1E',
		'crypto'        => '#22C3A6',
		'cash'          => '#B7AEC4',
	);

	/**
	 * Hook the admin-post handlers (logged-in and anonymous).
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle the export request: verify, authorize, build, stream.
	 */
	public static function handle(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'hti-engine' ), '', array( 'response' => 403 ) );
		}

		$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
		$token      = isset( $_POST['session_token'] ) ? sanitize_text_field( wp_unslash( $_POST['session_token'] ) ) : '';

		$post = $profile_id ? get_post( $profile_id ) : null;
		if ( ! $post || 'htinvest_profile' !== $post->post_type ) {
			wp_die( esc_html__( 'Result not found.', 'hti-engine' ), '', array( 'response' => 404 ) );
		}

		if ( ! self::authorized( $profile_id, $token ) ) {
			wp_die( esc_html__( 'You are not allowed to export this result.', 'hti-engine' ), '', array( 'response' => 403 ) );
		}

		$html = self::build_html( $profile_id );
		self::stream( $html, 'howtoinvest-profile-' . $profile_id );
	}

	/**
	 * Owner (account) or holder of the anonymous session token may export.
	 *
	 * @param int    $profile_id Profile id.
	 * @param string $token      Provided session token.
	 */
	private static function authorized( int $profile_id, string $token ): bool {
		$owner = (int) get_post_meta( $profile_id, 'hti_user_id', true );
		if ( $owner && is_user_logged_in() && get_current_user_id() === $owner ) {
			return true;
		}
		$stored = (string) get_post_meta( $profile_id, 'hti_session_token', true );
		return '' !== $stored && '' !== $token && hash_equals( $stored, $token );
	}

	/**
	 * Build the self-contained result document.
	 *
	 * @param int $profile_id Profile id.
	 */
	private static function build_html( int $profile_id ): string {
		$locale      = (string) get_post_meta( $profile_id, 'hti_locale', true );
		$locale      = str_starts_with( strtolower( $locale ), 'pt' ) ? 'pt' : 'en';
		$label       = (string) get_post_meta( $profile_id, 'hti_archetype_label', true );
		$allocation  = (array) get_post_meta( $profile_id, 'hti_allocation', true );
		$explanation = (array) get_post_meta( $profile_id, 'hti_explanation', true );
		$generated   = (string) get_post_meta( $profile_id, 'hti_generated_at', true );

		$classes = Questions::payload( $locale )['classes'];
		$ui      = Questions::payload( $locale )['ui'];

		$rows = '';
		foreach ( $allocation as $slice ) {
			$class = $slice['class'] ?? '';
			$pct   = (int) ( $slice['pct'] ?? 0 );
			$color = self::COLORS[ $class ] ?? '#999999';
			$name  = $classes[ $class ] ?? $class;
			$rows .= sprintf(
				'<tr><td class="name">%1$s</td><td class="bar"><span style="display:inline-block;height:10px;width:%2$d%%;background:%3$s;"></span></td><td class="pct">%2$d%%</td></tr>',
				esc_html( $name ),
				$pct,
				esc_attr( $color )
			);
		}

		$notes = '';
		foreach ( $allocation as $slice ) {
			$class = $slice['class'] ?? '';
			$note  = $explanation['class_notes'][ $class ] ?? '';
			if ( '' !== $note ) {
				$notes .= '<p><strong>' . esc_html( $classes[ $class ] ?? $class ) . ':</strong> ' . esc_html( $note ) . '</p>';
			}
		}

		$safety = ! empty( $explanation['safety_message'] )
			? '<div class="safety">' . esc_html( $explanation['safety_message'] ) . '</div>'
			: '';

		$why = ! empty( $explanation['why_archetype'] )
			? '<h2>' . esc_html( $ui['why_archetype'] ) . '</h2><p>' . esc_html( $explanation['why_archetype'] ) . '</p>'
			: '';

		$disclaimer = Disclaimer::contextual( $locale );
		$date       = $generated ? esc_html( substr( $generated, 0, 10 ) ) : '';

		$css = '
			body{font-family:DejaVu Sans, sans-serif;color:#2A2438;font-size:12px;line-height:1.5;}
			h1{font-size:20px;margin:0 0 4px;}
			h2{font-size:15px;margin:18px 0 6px;}
			.disclaimer{background:#2A2438;color:#C9D6E3;border-radius:6px;padding:10px 12px;margin:10px 0;font-size:11px;}
			.safety{background:#FFF3D6;border-left:4px solid #D69A1E;padding:10px 12px;margin:10px 0;}
			table.alloc{width:100%;border-collapse:collapse;margin:6px 0;}
			table.alloc td{padding:4px 0;border-bottom:1px solid #F2E4DD;}
			table.alloc td.bar{width:60%;}
			table.alloc td.pct{text-align:right;font-weight:bold;width:48px;}
			.foot{margin-top:24px;color:#6E6680;font-size:10px;border-top:1px solid #F2E4DD;padding-top:8px;}
		';

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
			. '<h1>' . esc_html( $ui['result_heading'] . ': ' . $label ) . '</h1>'
			. ( $date ? '<p style="color:#6E6680;margin:0 0 8px;">' . $date . '</p>' : '' )
			. $safety
			. '<div class="disclaimer">' . esc_html( $disclaimer ) . '</div>'
			. '<h2>' . esc_html( $ui['example_structure'] ) . '</h2>'
			. '<table class="alloc">' . $rows . '</table>'
			. $why
			. ( $notes ? '<h2>' . esc_html( $ui['what_classes_mean'] ) . '</h2>' . $notes : '' )
			. '<div class="foot">HowToInvest · ' . esc_html( $ui['short_disclaimer'] ) . '</div>'
			. '</body></html>';
	}

	/**
	 * Stream the document as PDF (Dompdf) or printable HTML (fallback).
	 *
	 * @param string $html     Document HTML.
	 * @param string $filename Base filename (no extension).
	 */
	private static function stream( string $html, string $filename ): void {
		if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
			$dompdf = new \Dompdf\Dompdf( array( 'isRemoteEnabled' => false ) );
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( 'A4' );
			$dompdf->render();
			$dompdf->stream( $filename . '.pdf', array( 'Attachment' => true ) );
			exit;
		}

		// Fallback: printable HTML (Print → Save as PDF).
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped while building.
		exit;
	}
}
