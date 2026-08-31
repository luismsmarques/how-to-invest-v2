<?php
/**
 * What the API key can actually run, asked of the API instead of guessed.
 *
 * The image and embedding model names used to be typed into a settings field
 * and left there. Google retires model names on a schedule the plugin has no
 * way of knowing, so a field that was correct at install time silently stops
 * working months later — which is exactly how this plugin lost its featured
 * images. ListModels is the only source of truth about a key's models, and it
 * is one GET away.
 *
 * The parsing half is pure and covered by tests; the fetching half caches for
 * an hour so opening the settings screen does not hit the API every time.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and groups the Generative Language API's model list.
 */
class Model_Catalog {

	private const TRANSIENT = 'rssai_model_catalog';
	private const TTL       = HOUR_IN_SECONDS;
	private const MAX_PAGES = 5;

	/**
	 * Capability buckets, in the order the settings screen shows them.
	 */
	public const BUCKETS = array( 'image_generate', 'image_predict', 'embed', 'text' );

	/**
	 * Model names this plugin has shipped that Google has since retired.
	 *
	 * Keyed by the setting they live in, so the one-off migration and the
	 * settings screen warn about exactly the same list.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function retired(): array {
		return array(
			'image_model'      => array(
				'imagen-3.0-generate-001',
				'imagen-3.0-generate-002',
				'imagen-3.0-fast-generate-001',
				'imagen-4.0-generate-001',
				'imagen-4.0-generate-preview-06-06',
				'imagen-4.0-fast-generate-001',
				'imagen-4.0-ultra-generate-001',
			),
			'image_base_model' => array(),
			'embedding_model'  => array(
				'embedding-001',
				'text-embedding-001',
				'text-embedding-004',
				'text-embedding-preview-0409',
			),
		);
	}

	/**
	 * What a retired name should become.
	 *
	 * @return array<string,string>
	 */
	public static function replacements(): array {
		return array(
			'image_model'      => 'gemini-2.5-flash-image',
			'image_base_model' => 'gemini-2.5-flash-image',
			'embedding_model'  => 'gemini-embedding-001',
		);
	}

	/**
	 * Whether a configured value is a name we know to be retired.
	 *
	 * @param string $setting Setting key.
	 * @param string $value   Configured model name.
	 */
	public static function is_retired( string $setting, string $value ): bool {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return false;
		}
		$list = self::retired()[ $setting ] ?? array();
		return in_array( $value, $list, true );
	}

	/* -------------------------------------------------------------------------
	 * Pure parsing
	 * ---------------------------------------------------------------------- */

	/**
	 * Flatten one ListModels page into id / label / methods rows.
	 *
	 * @param array<string,mixed> $json Decoded response.
	 * @return array<int,array{id:string,label:string,methods:array<int,string>}>
	 */
	public static function parse( array $json ): array {
		$out = array();
		foreach ( (array) ( $json['models'] ?? array() ) as $model ) {
			if ( ! is_array( $model ) ) {
				continue;
			}
			$id = (string) ( $model['name'] ?? '' );
			if ( 0 === strpos( $id, 'models/' ) ) {
				$id = substr( $id, 7 );
			}
			$id = trim( $id );
			if ( '' === $id ) {
				continue;
			}
			$methods = array();
			foreach ( (array) ( $model['supportedGenerationMethods'] ?? array() ) as $method ) {
				if ( is_string( $method ) && '' !== $method ) {
					$methods[] = $method;
				}
			}
			$out[] = array(
				'id'      => $id,
				'label'   => trim( (string) ( $model['displayName'] ?? $id ) ),
				'methods' => $methods,
			);
		}
		return $out;
	}

	/**
	 * Sort models into the buckets the settings screen offers.
	 *
	 * ListModels does not publish output modalities, so which models can return
	 * an image is a name heuristic ("imagen" or an id ending in "-image"). It is
	 * the same rule Image_Client uses to pick an endpoint, kept deliberately in
	 * step with it; a model that slips through the heuristic is still usable by
	 * typing its name into the field.
	 *
	 * @param array<int,array{id:string,label:string,methods:array<int,string>}> $models Parsed rows.
	 * @return array<string,array<int,array{id:string,label:string,methods:array<int,string>}>>
	 */
	public static function group( array $models ): array {
		$out = array_fill_keys( self::BUCKETS, array() );
		foreach ( $models as $model ) {
			$id      = strtolower( $model['id'] );
			$methods = $model['methods'];

			if ( in_array( 'embedContent', $methods, true ) || in_array( 'batchEmbedContents', $methods, true ) ) {
				$out['embed'][] = $model;
				continue;
			}
			$is_imagen = false !== strpos( $id, 'imagen' );
			$is_image  = $is_imagen || false !== strpos( $id, '-image' );

			if ( $is_imagen && in_array( 'predict', $methods, true ) ) {
				$out['image_predict'][] = $model;
				continue;
			}
			if ( $is_image && in_array( 'generateContent', $methods, true ) ) {
				$out['image_generate'][] = $model;
				continue;
			}
			if ( in_array( 'generateContent', $methods, true ) ) {
				$out['text'][] = $model;
			}
		}
		return $out;
	}

	/**
	 * Human label for a bucket.
	 *
	 * @param string $bucket Bucket key.
	 */
	public static function bucket_label( string $bucket ): string {
		switch ( $bucket ) {
			case 'image_generate':
				return __( 'Image generation (:generateContent)', 'hti-rss-ai' );
			case 'image_predict':
				return __( 'Image generation (:predict / Imagen)', 'hti-rss-ai' );
			case 'embed':
				return __( 'Embeddings (:embedContent)', 'hti-rss-ai' );
			default:
				return __( 'Text generation', 'hti-rss-ai' );
		}
	}

	/* -------------------------------------------------------------------------
	 * Fetching
	 * ---------------------------------------------------------------------- */

	/**
	 * The key's models, cached.
	 *
	 * @param bool $force Skip the cache.
	 * @return array<int,array{id:string,label:string,methods:array<int,string>}>|\WP_Error
	 */
	public static function fetch( bool $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$key = Gemini_Client::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'rssai_no_key', __( 'No Gemini API key configured.', 'hti-rss-ai' ) );
		}

		$models = array();
		$token  = '';
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$url = 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200&key=' . rawurlencode( $key );
			if ( '' !== $token ) {
				$url .= '&pageToken=' . rawurlencode( $token );
			}

			$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( $code < 200 || $code >= 300 ) {
				$message = is_array( $json ) && isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $code;
				return new \WP_Error( 'rssai_models_api', $message );
			}
			$json   = is_array( $json ) ? $json : array();
			$models = array_merge( $models, self::parse( $json ) );

			$token = (string) ( $json['nextPageToken'] ?? '' );
			if ( '' === $token ) {
				break;
			}
		}

		usort(
			$models,
			static function ( array $a, array $b ): int {
				return strcmp( $a['id'], $b['id'] );
			}
		);

		set_transient( self::TRANSIENT, $models, self::TTL );
		return $models;
	}

	/**
	 * Drop the cached list (after a refresh request).
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The cached list without going to the network. Empty when never fetched.
	 *
	 * @return array<int,array{id:string,label:string,methods:array<int,string>}>
	 */
	public static function cached(): array {
		$cached = get_transient( self::TRANSIENT );
		return is_array( $cached ) ? $cached : array();
	}
}
