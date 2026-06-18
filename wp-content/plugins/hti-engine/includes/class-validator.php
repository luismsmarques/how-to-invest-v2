<?php
/**
 * Validation of the LLM's explanation against the schema and the absolute
 * rules (Prompt_LLM_Schema §4). Any failure → caller uses the fallback.
 *
 * Conservative by design: when in doubt, reject. The deterministic numbers
 * are never affected — only whether the LLM's text is trusted.
 *
 * Pure PHP (no WordPress, no LLM) so it is fully unit-testable.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Schema + semantic validation of explanation objects.
 */
class Validator {

	private const ALLOWED_KEYS = array( 'why_archetype', 'class_notes', 'safety_message' );

	/**
	 * Substrings that signal a named instrument/product/company (lowercase).
	 */
	private const INSTRUMENT_BLOCKLIST = array(
		's&p', 's & p', 'sp500', 'sp 500', 'nasdaq', 'dow jones', 'ftse', 'msci',
		'bitcoin', 'ethereum', 'solana', 'dogecoin',
		'vwce', 'vusa', 'vti', 'voo', 'spy', 'qqq', 'iwda',
		'apple', 'tesla', 'microsoft', 'amazon', 'nvidia', 'alphabet', 'berkshire',
	);

	/**
	 * Uppercase acronyms that are NOT tickers (so the ticker regex skips them).
	 */
	private const ACRONYM_ALLOWLIST = array( 'ESG', 'REIT', 'REITS', 'AI', 'USA', 'US', 'EU', 'UK', 'PT', 'EN', 'HTI', 'GDPR', 'RGPD' );

	/**
	 * Convenience boolean wrapper around errors().
	 *
	 * @param mixed                                                                 $data   LLM output (decoded).
	 * @param array{allocation:list<array{class:string,pct:int}>,safety_flags:list<string>} $result Engine result.
	 * @param string                                                                $locale Locale.
	 */
	public static function is_valid( $data, array $result, string $locale ): bool {
		return array() === self::errors( $data, $result, $locale );
	}

	/**
	 * Return a list of validation errors. Empty list == valid.
	 *
	 * @param mixed                                                                 $data   LLM output (decoded).
	 * @param array{allocation:list<array{class:string,pct:int}>,safety_flags:list<string>} $result Engine result.
	 * @param string                                                                $locale Locale.
	 * @return list<string>
	 */
	public static function errors( $data, array $result, string $locale ): array {
		$errors = array();

		// --- Schema ---
		if ( ! is_array( $data ) ) {
			return array( 'not an object' );
		}
		foreach ( self::ALLOWED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$errors[] = "missing key: {$key}";
			}
		}
		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				$errors[] = "unexpected key: {$key}";
			}
		}
		if ( $errors ) {
			return $errors;
		}

		$why = $data['why_archetype'];
		if ( ! is_string( $why ) || mb_strlen( $why ) < 20 || mb_strlen( $why ) > 600 ) {
			$errors[] = 'why_archetype length out of bounds';
		}

		if ( ! is_array( $data['class_notes'] ) ) {
			$errors[] = 'class_notes is not an object';
			return $errors;
		}
		foreach ( $data['class_notes'] as $note ) {
			if ( ! is_string( $note ) || mb_strlen( $note ) < 10 || mb_strlen( $note ) > 400 ) {
				$errors[] = 'a class_note length is out of bounds';
				break;
			}
		}

		$safety = $data['safety_message'];
		if ( null !== $safety && ( ! is_string( $safety ) || mb_strlen( $safety ) > 600 ) ) {
			$errors[] = 'safety_message invalid';
		}

		// --- Semantic ---
		$alloc_classes = array_column( $result['allocation'], 'class' );
		$note_keys     = array_keys( $data['class_notes'] );
		sort( $alloc_classes );
		sort( $note_keys );
		if ( $alloc_classes !== $note_keys ) {
			$errors[] = 'class_notes keys do not match the allocation classes';
		}

		if ( ! empty( $result['safety_flags'] ) && null === $safety ) {
			$errors[] = 'safety_message is null but a safety trap fired';
		}

		$text = trim( $why . ' ' . implode( ' ', $data['class_notes'] ) . ' ' . (string) $safety );

		if ( self::contains_instrument( $text ) ) {
			$errors[] = 'text appears to name a financial instrument';
		}

		$allowed_pcts = array_map( 'intval', array_column( $result['allocation'], 'pct' ) );
		if ( self::has_foreign_percentage( $text, $allowed_pcts ) ) {
			$errors[] = 'text contains a percentage not in the fixed allocation';
		}

		if ( self::wrong_language( $text, $locale ) ) {
			$errors[] = 'text is not in the requested language';
		}

		return $errors;
	}

	/**
	 * Detect a named instrument via blocklist or a ticker-like token.
	 *
	 * @param string $text Combined text.
	 */
	private static function contains_instrument( string $text ): bool {
		$lower = mb_strtolower( $text );
		foreach ( self::INSTRUMENT_BLOCKLIST as $needle ) {
			if ( str_contains( $lower, $needle ) ) {
				return true;
			}
		}
		// Ticker-like: 3–5 uppercase letters, excluding known acronyms.
		if ( preg_match_all( '/\b[A-Z]{3,5}\b/', $text, $m ) ) {
			foreach ( $m[0] as $token ) {
				if ( ! in_array( $token, self::ACRONYM_ALLOWLIST, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Detect a percentage value not present in the fixed allocation.
	 *
	 * @param string     $text     Combined text.
	 * @param list<int>  $allowed  Allowed percentage values.
	 */
	private static function has_foreign_percentage( string $text, array $allowed ): bool {
		if ( preg_match_all( '/(\d{1,3})\s*(?:%|percent|per cent|por cento)/iu', $text, $m ) ) {
			foreach ( $m[1] as $num ) {
				if ( ! in_array( (int) $num, $allowed, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Lenient language mismatch detection.
	 *
	 * @param string $text   Combined text.
	 * @param string $locale Requested locale.
	 */
	private static function wrong_language( string $text, string $locale ): bool {
		$is_pt          = str_starts_with( strtolower( $locale ), 'pt' );
		$has_pt_marks   = (bool) preg_match( '/[ãõçáéíóúâêô]/iu', $text );
		$has_pt_words   = (bool) preg_match( '/\b(não|você|carteira|ações|obrigações|que|uma|para)\b/iu', $text );

		if ( $is_pt ) {
			// Portuguese expected: require some Portuguese signal.
			return ! ( $has_pt_marks || $has_pt_words );
		}
		// English expected: Portuguese-only diacritics are a strong signal of the wrong language.
		return $has_pt_marks;
	}
}
