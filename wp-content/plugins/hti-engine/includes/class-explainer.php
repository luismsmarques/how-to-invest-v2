<?php
/**
 * Orchestrates the explanation: try the LLM, validate, else fall back.
 *
 * The deterministic decision is already made; this only produces the text.
 * Rules decide, the LLM explains — and if the LLM is unavailable, fails, or
 * its output is rejected, the curated fallback ships instead. The numbers are
 * never affected.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a validated explanation object for a deterministic result.
 */
class Explainer {

	/**
	 * Build the explanation for a result.
	 *
	 * @param array{archetype_id:int,allocation:list<array{class:string,pct:int}>,safety_flags:list<string>} $result          Engine result.
	 * @param array<string,mixed>                                                                            $answers         Questionnaire answers.
	 * @param string                                                                                         $locale          'en' or 'pt'.
	 * @param string                                                                                         $archetype_label Archetype label.
	 * @return array{explanation:array{why_archetype:string,class_notes:array<string,string>,safety_message:?string},source:string}
	 */
	public static function explain( array $result, array $answers, string $locale, string $archetype_label ): array {
		// Transport is provider-agnostic (WP AI Client → Gemini fallback); the
		// validation + curated fallback below are unchanged regardless.
		$data = LLM::generate(
			Prompt::SYSTEM,
			Prompt::build_user( $result, $answers, $locale, $archetype_label )
		);

		if ( is_array( $data ) && Validator::is_valid( $data, $result, $locale ) ) {
			return array(
				'explanation' => self::normalize( $data ),
				'source'      => 'llm',
			);
		}

		return array(
			'explanation' => Fallback::build( $result, $locale ),
			'source'      => 'fallback',
		);
	}

	/**
	 * Coerce a validated LLM object into the canonical shape (trim strings).
	 *
	 * @param array<string,mixed> $data Validated LLM output.
	 * @return array{why_archetype:string,class_notes:array<string,string>,safety_message:?string}
	 */
	private static function normalize( array $data ): array {
		$class_notes = array();
		foreach ( (array) $data['class_notes'] as $class => $note ) {
			$class_notes[ (string) $class ] = trim( (string) $note );
		}

		$safety = $data['safety_message'];

		return array(
			'why_archetype'  => trim( (string) $data['why_archetype'] ),
			'class_notes'    => $class_notes,
			'safety_message' => ( null === $safety ) ? null : trim( (string) $safety ),
		);
	}
}
