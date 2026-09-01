<?php
/**
 * The image brief: a small, fixed-shape JSON description of the scene an
 * article's featured image should show.
 *
 * Two roads lead to the same shape. When the feed item carries a photo, a
 * vision call reads it and returns the brief. When it does not, the text model
 * drafts the brief from the headline and summary. Either way the illustration
 * is generated from the brief, never from the photograph itself — a description
 * of a scene is a long way from a derivative of someone else's file, and for a
 * site that republishes agency-sourced news that distance is the point.
 *
 * Everything here except the two acquisition methods is pure, so the schema and
 * its normalisation are covered by the test suite.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Schema, normalisation and acquisition of the image brief.
 */
class Image_Brief {

	/**
	 * Post meta the brief is stored under, so an editor can see what the model
	 * understood and a regeneration can skip re-reading the photo.
	 */
	public const META_KEY = 'rssai_image_brief';

	/**
	 * Single-value fields, in the order they read best.
	 */
	private const STRING_KEYS = array( 'subject', 'setting', 'composition', 'mood' );

	/**
	 * List fields and how many entries each keeps.
	 */
	private const LIST_KEYS = array(
		'palette'  => 5,
		'elements' => 8,
	);

	private const MAX_STRING = 220;
	private const MAX_ENTRY  = 60;

	/**
	 * Every key a normalised brief carries.
	 *
	 * @return array<int,string>
	 */
	public static function keys(): array {
		return array_merge( self::STRING_KEYS, array_keys( self::LIST_KEYS ) );
	}

	/**
	 * Normalise raw model output into the fixed shape, or reject it.
	 *
	 * Unknown keys are dropped, strings are trimmed and capped, lists are
	 * flattened to short strings. A brief without a subject is not a brief, so
	 * that is the one hard requirement.
	 *
	 * @param mixed $raw Decoded model output.
	 * @return array<string,mixed>|null Normalised brief, or null when unusable.
	 */
	public static function parse( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		// Tolerate a model that wraps the object in an envelope.
		if ( ! isset( $raw['subject'] ) && isset( $raw['brief'] ) && is_array( $raw['brief'] ) ) {
			$raw = $raw['brief'];
		}

		$out = array();
		foreach ( self::STRING_KEYS as $key ) {
			$out[ $key ] = self::clean_string( $raw[ $key ] ?? '', self::MAX_STRING );
		}
		foreach ( self::LIST_KEYS as $key => $max ) {
			$out[ $key ] = self::clean_list( $raw[ $key ] ?? array(), $max );
		}

		if ( '' === $out['subject'] ) {
			return null;
		}
		return $out;
	}

	/**
	 * Whether an array is already a valid brief.
	 *
	 * @param mixed $brief Candidate.
	 */
	public static function is_valid( $brief ): bool {
		return null !== self::parse( $brief );
	}

	/**
	 * A deterministic brief built from the article alone — no API call.
	 *
	 * This is what keeps the pipeline moving when both the vision call and the
	 * text call are unavailable: the illustration still gets a subject to work
	 * from instead of the generator being handed an empty prompt.
	 *
	 * @param array<string,mixed> $data Validated article.
	 * @return array<string,mixed>
	 */
	public static function from_article( array $data ): array {
		$headline = self::clean_string( $data['headline'] ?? '', self::MAX_STRING );
		$category = self::clean_string( $data['suggested_category'] ?? '', self::MAX_ENTRY );
		$dek      = self::clean_string( $data['dek'] ?? '', self::MAX_STRING );

		$elements = array();
		foreach ( (array) ( $data['tags'] ?? array() ) as $tag ) {
			$elements[] = $tag;
		}

		return array(
			'subject'     => '' !== $headline ? $headline : ( '' !== $category ? $category : 'financial markets' ),
			'setting'     => '' !== $category ? $category : 'financial news',
			'composition' => 'wide 16:9 editorial illustration, subject centred with generous negative space',
			'mood'        => '' !== $dek ? 'neutral and informative' : 'neutral',
			'palette'     => array(),
			'elements'    => self::clean_list( $elements, self::LIST_KEYS['elements'] ),
		);
	}

	/**
	 * Flatten a brief into the descriptive sentence the image prompt embeds.
	 *
	 * @param array<string,mixed> $brief Normalised brief.
	 */
	public static function to_text( array $brief ): string {
		$parts = array();
		$label = array(
			'subject'     => 'Subject',
			'setting'     => 'Setting',
			'composition' => 'Composition',
			'mood'        => 'Mood',
		);
		foreach ( $label as $key => $name ) {
			$value = self::clean_string( $brief[ $key ] ?? '', self::MAX_STRING );
			if ( '' !== $value ) {
				$parts[] = $name . ': ' . rtrim( $value, '.' ) . '.';
			}
		}
		$elements = self::clean_list( $brief['elements'] ?? array(), self::LIST_KEYS['elements'] );
		if ( $elements ) {
			$parts[] = 'Key elements: ' . implode( ', ', $elements ) . '.';
		}
		$palette = self::clean_list( $brief['palette'] ?? array(), self::LIST_KEYS['palette'] );
		if ( $palette ) {
			$parts[] = 'Colours observed in the source scene: ' . implode( ', ', $palette ) . '.';
		}
		return implode( ' ', $parts );
	}

	/**
	 * The JSON shape both roads must produce, as prompt text.
	 */
	public static function schema_instruction(): string {
		return implode(
			"\n",
			array(
				'Respond with ONLY a JSON object (no markdown, no commentary) of this exact shape:',
				'{',
				'  "subject": string (what the picture is about, one short phrase),',
				'  "setting": string (where the scene takes place),',
				'  "composition": string (framing, camera distance, where the subject sits),',
				'  "mood": string (lighting and tone),',
				'  "palette": [string] (up to 5 dominant colours),',
				'  "elements": [string] (up to 8 concrete objects present)',
				'}',
				'',
				'HARD RULES for every field:',
				'- Never name or identify a person. Describe people only generically ("a person in a suit", "two analysts at a desk").',
				'- Never name a company, brand, publication, logo or ticker, and never transcribe text visible in the image.',
				'- Describe only what is shown. Do not infer news, opinions or outcomes.',
				'- Plain descriptive English, no instructions to an artist, no style names.',
			)
		);
	}

	/* -------------------------------------------------------------------------
	 * Acquisition (these two talk to the API)
	 * ---------------------------------------------------------------------- */

	/**
	 * Read a feed photo into a brief with a vision call.
	 *
	 * @param string $bytes Image bytes.
	 * @param string $mime  Image MIME type.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function describe_image( string $bytes, string $mime ) {
		$result = Gemini_Client::describe_image(
			self::schema_instruction(),
			'Describe this photograph so an illustrator who cannot see it could redraw the same scene from scratch.',
			$bytes,
			$mime
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$brief = self::parse( Gemini_Client::extract_json( (string) $result['text'] ) );
		if ( null === $brief ) {
			return new \WP_Error( 'rssai_brief_parse', __( 'The vision model did not return a usable image brief.', 'hti-rss-ai' ) );
		}
		return $brief;
	}

	/**
	 * Draft a brief from the article's own words, for items with no feed photo.
	 *
	 * @param array<string,mixed> $data Validated article.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function draft_from_article( array $data ) {
		$headline = self::clean_string( $data['headline'] ?? '', self::MAX_STRING );
		$category = self::clean_string( $data['suggested_category'] ?? '', self::MAX_ENTRY );
		$dek      = self::clean_string( $data['dek'] ?? '', self::MAX_STRING );
		if ( '' === $headline ) {
			return new \WP_Error( 'rssai_brief_empty', __( 'No headline to build an image brief from.', 'hti-rss-ai' ) );
		}

		$user = "Headline: {$headline}\n"
			. ( '' !== $category ? "Category: {$category}\n" : '' )
			. ( '' !== $dek ? "Summary: {$dek}\n" : '' )
			. "\nInvent one calm, conceptual editorial scene that could illustrate this article, and describe it as the JSON object.";

		$result = Gemini_Client::generate(
			self::schema_instruction() . "\n\nYou are describing an imagined scene for an editorial illustration about a financial-news article.",
			$user
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$brief = self::parse( Gemini_Client::extract_json( (string) $result['text'] ) );
		if ( null === $brief ) {
			return new \WP_Error( 'rssai_brief_parse', __( 'The model did not return a usable image brief.', 'hti-rss-ai' ) );
		}
		return $brief;
	}

	/* -------------------------------------------------------------------------
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * Trim, strip control characters and cap one string field.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum length.
	 */
	private static function clean_string( $value, int $max ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', (string) $value );
		$value = trim( (string) $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * Normalise one list field to short, non-empty, unique strings.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum number of entries.
	 * @return array<int,string>
	 */
	private static function clean_list( $value, int $max ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s*,\s*/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			$entry = self::clean_string( $entry, self::MAX_ENTRY );
			if ( '' !== $entry && ! in_array( $entry, $out, true ) ) {
				$out[] = $entry;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}
}
