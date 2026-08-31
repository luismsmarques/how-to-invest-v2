<?php
/**
 * The admin surface for models and pipeline health.
 *
 * Two questions this plugin could not answer about itself until now: which
 * models does my key actually have, and what has been failing. Both were
 * discoverable only by reading a server log after the fact — which is how a
 * retired image model went unnoticed for two weeks.
 *
 * Everything here is admin-only, nonce-checked and capability-gated; the API
 * key never leaves the server.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * "Which models do I have" + "what is failing" panel and its handlers.
 */
class Model_Tools {

	private const REFRESH_ACTION = 'rssai_models_refresh';
	private const TEST_ACTION    = 'rssai_model_test';
	private const CLEAR_ACTION   = 'rssai_health_clear';
	private const RESULT_PREFIX  = 'rssai_model_test_';

	/**
	 * Hook the three admin-post handlers.
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( __CLASS__, 'handle_clear' ) );
	}

	/**
	 * The tests offered, and what each one actually calls.
	 *
	 * @return array<string,string>
	 */
	public static function tests(): array {
		return array(
			'image'      => __( 'Text-to-image', 'hti-rss-ai' ),
			'image_base' => __( 'Image-to-image', 'hti-rss-ai' ),
			'vision'     => __( 'Vision (image brief)', 'hti-rss-ai' ),
			'embed'      => __( 'Embeddings', 'hti-rss-ai' ),
		);
	}

	/* -------------------------------------------------------------------------
	 * Handlers
	 * ---------------------------------------------------------------------- */

	/**
	 * Re-ask the API what models the key has.
	 */
	public static function handle_refresh(): void {
		self::guard( self::REFRESH_ACTION );

		Model_Catalog::flush();
		$models = Model_Catalog::fetch( true );

		if ( is_wp_error( $models ) ) {
			self::store_result( 'catalog', false, $models->get_error_message() );
		} else {
			self::store_result(
				'catalog',
				true,
				sprintf(
					/* translators: %d: number of models the key can use. */
					__( '%d models available to this key.', 'hti-rss-ai' ),
					count( $models )
				)
			);
		}
		self::back();
	}

	/**
	 * Run one real call against a configured model and report what happened.
	 */
	public static function handle_test(): void {
		self::guard( self::TEST_ACTION );

		$which = isset( $_POST['which'] ) ? sanitize_key( wp_unslash( $_POST['which'] ) ) : '';
		if ( ! array_key_exists( $which, self::tests() ) ) {
			self::back();
		}

		[ $ok, $message ] = self::run_test( $which );
		self::store_result( $which, $ok, $message );
		self::back();
	}

	/**
	 * Reset the health counters once the operator has fixed the cause.
	 */
	public static function handle_clear(): void {
		self::guard( self::CLEAR_ACTION );
		Health::clear();
		self::back();
	}

	/**
	 * Execute one test.
	 *
	 * @param string $which Test key.
	 * @return array{0:bool,1:string} [ok, message]
	 */
	private static function run_test( string $which ): array {
		switch ( $which ) {
			case 'image':
				$result = Image_Client::generate( 'A single flat geometric shape, centred on a plain background. No text, no people, no logos.', '16:9' );
				return is_wp_error( $result )
					? array( false, $result->get_error_message() )
					: array( true, self::bytes_message( strlen( (string) $result ) ) );

			case 'image_base':
				$base = Fallback_Card::render( 'model test', 'test' );
				if ( null === $base ) {
					return array( false, __( 'GD is unavailable, so there is no base image to send.', 'hti-rss-ai' ) );
				}
				$result = Image_Client::generate_from_image( 'Restyle this simple shape. No text, no people, no logos.', $base, 'image/png' );
				return is_wp_error( $result )
					? array( false, $result->get_error_message() )
					: array( true, self::bytes_message( strlen( (string) $result ) ) );

			case 'vision':
				$base = Fallback_Card::render( 'model test', 'test' );
				if ( null === $base ) {
					return array( false, __( 'GD is unavailable, so there is no image to describe.', 'hti-rss-ai' ) );
				}
				$brief = Image_Brief::describe_image( $base, 'image/png' );
				return is_wp_error( $brief )
					? array( false, $brief->get_error_message() )
					: array( true, __( 'Brief returned:', 'hti-rss-ai' ) . ' ' . Image_Brief::to_text( $brief ) );

			default:
				$result = Gemini_Client::embed( array( 'A short sentence to embed.' ) );
				if ( is_wp_error( $result ) ) {
					return array( false, $result->get_error_message() );
				}
				$dims = isset( $result[0] ) ? count( (array) $result[0] ) : 0;
				return $dims > 0
					? array(
						true,
						sprintf(
							/* translators: %d: vector length. */
							__( 'Vector of %d dimensions returned.', 'hti-rss-ai' ),
							$dims
						),
					)
					: array( false, __( 'The call succeeded but returned no vector.', 'hti-rss-ai' ) );
		}
	}

	/**
	 * Success message for a call that returned image bytes.
	 *
	 * @param int $bytes Byte count.
	 */
	private static function bytes_message( int $bytes ): string {
		return sprintf(
			/* translators: %s: human-readable size. */
			__( 'Image returned (%s).', 'hti-rss-ai' ),
			size_format( $bytes )
		);
	}

	/* -------------------------------------------------------------------------
	 * Panel
	 * ---------------------------------------------------------------------- */

	/**
	 * Render the health + models panel under the settings form.
	 */
	public static function render_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<hr />';
		self::render_health();
		self::render_models();
	}

	/**
	 * The 24-hour failure counters.
	 */
	private static function render_health(): void {
		$state = Health::all();

		echo '<h2>' . esc_html__( 'Pipeline health (last 24 hours)', 'hti-rss-ai' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Image generation and embeddings are best-effort: when they fail the article is still written. That is deliberate, and it is also how a retired model can go unnoticed for weeks — so the failures are counted here.', 'hti-rss-ai' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:820px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Subsystem', 'hti-rss-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'OK', 'hti-rss-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Failed', 'hti-rss-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Last error', 'hti-rss-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( Health::SUBSYSTEMS as $subsystem ) {
			$entry = $state[ $subsystem ];
			$fails = (int) $entry['fail_24h'];
			printf(
				'<tr><td><strong>%s</strong></td><td>%d</td><td style="color:%s;font-weight:%s;">%d</td><td><code style="font-size:11px;">%s</code></td></tr>',
				esc_html( Health::label( $subsystem ) ),
				(int) $entry['ok_24h'],
				$fails > 0 ? '#b32d2e' : 'inherit',
				$fails > 0 ? '700' : '400',
				$fails,
				esc_html( '' !== (string) $entry['last_error'] ? (string) $entry['last_error'] : '—' )
			);
		}
		echo '</tbody></table>';

		printf(
			'<form method="post" action="%s" style="margin-top:8px;">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::CLEAR_ACTION ) );
		wp_nonce_field( self::CLEAR_ACTION );
		submit_button( __( 'Reset counters', 'hti-rss-ai' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The model catalogue and the per-model test buttons.
	 */
	private static function render_models(): void {
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Models available to this key', 'hti-rss-ai' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Google retires model names on its own schedule, and a name that was right at install time simply stops working later — with no warning anywhere but the log. Ask the API what it has instead of guessing, and paste a name from this list into the fields above.', 'hti-rss-ai' ) . '</p>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::REFRESH_ACTION ) );
		wp_nonce_field( self::REFRESH_ACTION );
		submit_button( __( 'List available models', 'hti-rss-ai' ), 'secondary', 'submit', false );
		echo '</form>';

		$result = self::read_result( 'catalog' );
		if ( null !== $result ) {
			self::notice( $result );
		}

		$models = Model_Catalog::cached();
		if ( $models ) {
			$grouped = Model_Catalog::group( $models );
			echo '<div style="display:flex;flex-wrap:wrap;gap:24px;margin-top:12px;">';
			foreach ( Model_Catalog::BUCKETS as $bucket ) {
				$rows = $grouped[ $bucket ];
				echo '<div style="min-width:260px;">';
				printf( '<h4 style="margin:0 0 4px;">%s</h4>', esc_html( Model_Catalog::bucket_label( $bucket ) ) );
				if ( ! $rows ) {
					echo '<p class="description">' . esc_html__( 'None.', 'hti-rss-ai' ) . '</p>';
				} else {
					echo '<ul style="margin:0;font-family:monospace;font-size:12px;">';
					foreach ( $rows as $row ) {
						printf( '<li>%s</li>', esc_html( $row['id'] ) );
					}
					echo '</ul>';
				}
				echo '</div>';
			}
			echo '</div>';
		}

		echo '<h3 style="margin-top:24px;">' . esc_html__( 'Test the configured models', 'hti-rss-ai' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Each button makes one real API call with the model saved above. It costs a call and tells you the truth.', 'hti-rss-ai' ) . '</p>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;">';
		foreach ( self::tests() as $key => $label ) {
			printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
			printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::TEST_ACTION ) );
			printf( '<input type="hidden" name="which" value="%s" />', esc_attr( $key ) );
			wp_nonce_field( self::TEST_ACTION );
			submit_button( $label, 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';

		foreach ( array_keys( self::tests() ) as $key ) {
			$result = self::read_result( $key );
			if ( null !== $result ) {
				self::notice( $result, self::tests()[ $key ] );
			}
		}
	}

	/**
	 * Print one stored test outcome.
	 *
	 * @param array{ok:bool,message:string} $result Stored outcome.
	 * @param string                        $label  Optional prefix.
	 */
	private static function notice( array $result, string $label = '' ): void {
		printf(
			'<div class="notice notice-%s inline" style="margin:8px 0;"><p>%s%s</p></div>',
			$result['ok'] ? 'success' : 'error',
			'' !== $label ? '<strong>' . esc_html( $label ) . ':</strong> ' : '',
			esc_html( $result['message'] )
		);
	}

	/* -------------------------------------------------------------------------
	 * Plumbing
	 * ---------------------------------------------------------------------- */

	/**
	 * Capability + nonce, or die.
	 *
	 * @param string $action Action name (also the nonce name).
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hti-rss-ai' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Stash an outcome for the redirect that follows.
	 *
	 * @param string $key     Result slot.
	 * @param bool   $ok      Whether it worked.
	 * @param string $message What to show.
	 */
	private static function store_result( string $key, bool $ok, string $message ): void {
		set_transient(
			self::RESULT_PREFIX . $key . '_' . get_current_user_id(),
			array(
				'ok'      => $ok,
				'message' => Health::trim_message( $message ),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and consume a stored outcome.
	 *
	 * @param string $key Result slot.
	 * @return array{ok:bool,message:string}|null
	 */
	private static function read_result( string $key ) {
		$name  = self::RESULT_PREFIX . $key . '_' . get_current_user_id();
		$value = get_transient( $name );
		if ( ! is_array( $value ) || ! isset( $value['message'] ) ) {
			return null;
		}
		delete_transient( $name );
		return array(
			'ok'      => ! empty( $value['ok'] ),
			'message' => (string) $value['message'],
		);
	}

	/**
	 * Back to the settings screen.
	 */
	private static function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) );
		exit;
	}
}
