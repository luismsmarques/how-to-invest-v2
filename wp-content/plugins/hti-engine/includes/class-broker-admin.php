<?php
/**
 * "Broker data" metabox for the `broker` CPT.
 *
 * All structured broker data (regulator, products, affiliate state, CFD flag…)
 * lives in `hti_broker_*` post meta on the DEFAULT-LANGUAGE (EN) post only —
 * translations are content-only, so a deal change is edited in exactly one
 * place. Renderers read meta through the EN post (see Brokers::records()).
 *
 * Compliance notes baked into the field help (broker-affiliate skill):
 * an affiliate URL only counts when "deal active" is ticked; the official URL
 * is the mandatory fallback; CFD brokers need their real loss percentage.
 *
 * @package HTI_Engine
 */

namespace HTI\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and persists the broker data metabox.
 */
class Broker_Admin {

	/**
	 * Meta prefix for every broker field.
	 */
	public const PREFIX = 'hti_broker_';

	/**
	 * Product tags a broker can offer (allowlist).
	 */
	public const PRODUCTS = array( 'stocks', 'etf', 'funds', 'crypto', 'interest', 'savings' );

	/**
	 * Affiliate networks (allowlist). 'own' = the broker's in-house program.
	 */
	public const NETWORKS = array( 'none', 'own', 'financeads', 'impact', 'awin', 'everflow', 'tapfiliate' );

	/**
	 * Wire up the metabox + save handler.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_broker', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_broker', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Register the metabox.
	 */
	public static function add_box(): void {
		add_meta_box(
			'hti-broker-data',
			__( 'Broker data', 'hti-engine' ),
			array( __CLASS__, 'render' ),
			'broker',
			'normal',
			'high'
		);
	}

	/**
	 * Read one broker meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Field key (without prefix).
	 */
	public static function get( int $post_id, string $key ): string {
		return (string) get_post_meta( $post_id, self::PREFIX . $key, true );
	}

	/**
	 * Render the fields.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render( \WP_Post $post ): void {
		wp_nonce_field( 'hti_broker_data', 'hti_broker_nonce' );

		if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_default_language' ) ) {
			$lang    = (string) pll_get_post_language( $post->ID );
			$default = (string) pll_default_language( 'slug' );
			if ( '' !== $lang && '' !== $default && $lang !== $default ) {
				printf(
					'<p><strong>%s</strong></p>',
					esc_html__( 'This is a translation: broker data is managed on the default-language (EN) version and shared by all renderers. Fields saved here are ignored.', 'hti-engine' )
				);
			}
		}

		$text = static function ( string $key, string $label, string $help = '' ) use ( $post ): void {
			printf(
				'<p><label for="hti_broker_%1$s"><strong>%2$s</strong></label><br /><input type="text" class="widefat" id="hti_broker_%1$s" name="hti_broker_%1$s" value="%3$s" />%4$s</p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( self::get( $post->ID, $key ) ),
				'' !== $help ? '<span class="description">' . esc_html( $help ) . '</span>' : ''
			);
		};

		$checkbox_group = static function ( string $key, string $label, array $options ) use ( $post ): void {
			$current = array_filter( explode( ',', self::get( $post->ID, $key ) ) );
			echo '<p><strong>' . esc_html( $label ) . '</strong><br />';
			foreach ( $options as $opt => $opt_label ) {
				printf(
					'<label style="margin-right:12px"><input type="checkbox" name="hti_broker_%1$s[]" value="%2$s" %3$s /> %4$s</label>',
					esc_attr( $key ),
					esc_attr( (string) $opt ),
					checked( in_array( (string) $opt, $current, true ), true, false ),
					esc_html( $opt_label )
				);
			}
			echo '</p>';
		};

		$text( 'regulator', __( 'Regulator(s)', 'hti-engine' ), __( 'E.g. "CMVM nº 341 (branch) · KNF". Only top-tier EU regulators are listed on the site.', 'hti-engine' ) );
		$text( 'min_deposit', __( 'Minimum deposit', 'hti-engine' ), __( 'E.g. "0 €".', 'hti-engine' ) );
		$text( 'fees_note', __( 'Costs, in one line (EN)', 'hti-engine' ), __( 'Factual, e.g. "Commission-free stocks/ETFs up to a monthly volume limit".', 'hti-engine' ) );
		$text( 'fees_note_pt', __( 'Costs, in one line (PT)', 'hti-engine' ) );
		$text( 'interest_rate_note', __( 'Interest on cash, in one line (EN)', 'hti-engine' ), __( 'Leave empty when the broker pays none.', 'hti-engine' ) );
		$text( 'interest_rate_note_pt', __( 'Interest on cash, in one line (PT)', 'hti-engine' ) );
		$text( 'rating', __( 'Editorial rating (0–5)', 'hti-engine' ), __( 'Shown visually only — never emitted as Review/AggregateRating schema.', 'hti-engine' ) );
		$text( 'verified', __( 'Data verified on (YYYY-MM-DD)', 'hti-engine' ), __( 'Unverified numbers must not be published.', 'hti-engine' ) );

		$products = array();
		foreach ( self::PRODUCTS as $p ) {
			$products[ $p ] = $p;
		}
		$checkbox_group( 'products', __( 'Products offered', 'hti-engine' ), $products );

		$classes = array();
		foreach ( Engine::CLASSES as $c ) {
			$classes[ $c ] = $c;
		}
		$checkbox_group( 'asset_classes', __( 'Engine asset classes the platform can hold', 'hti-engine' ), $classes );

		$fits = array();
		foreach ( range( 1, 5 ) as $a ) {
			$fits[ (string) $a ] = (string) $a;
		}
		$checkbox_group( 'profile_fit', __( 'Archetype fit (1 Preservation … 5 Aggressive Growth) — curated, drives the post-result module', 'hti-engine' ), $fits );

		printf(
			'<p><label><input type="checkbox" name="hti_broker_cfd" value="1" %s /> <strong>%s</strong></label><br /><span class="description">%s</span></p>',
			checked( '1' === self::get( $post->ID, 'cfd' ), true, false ),
			esc_html__( 'Offers CFDs', 'hti-engine' ),
			esc_html__( 'Every surface that shows this broker will carry the ESMA risk warning.', 'hti-engine' )
		);
		$text( 'cfd_risk_pct', __( 'CFD: % of retail accounts losing money', 'hti-engine' ), __( 'Digits only, from the broker\'s own current disclosure. Empty → the generic ESMA wording is used until confirmed.', 'hti-engine' ) );

		$text( 'official_url', __( 'Official site URL (https) — mandatory fallback', 'hti-engine' ), __( 'Used by /go/ when no affiliate deal is active.', 'hti-engine' ) );
		$text( 'affiliate_url', __( 'Affiliate URL (https)', 'hti-engine' ), __( 'Your tracking link from the network. Only used while "deal active" is ticked. Never printed in page HTML — /go/ redirects to it.', 'hti-engine' ) );
		printf(
			'<p><label><input type="checkbox" name="hti_broker_affiliate_active" value="1" %s /> <strong>%s</strong></label><br /><span class="description">%s</span></p>',
			checked( '1' === self::get( $post->ID, 'affiliate_active' ), true, false ),
			esc_html__( 'Affiliate deal active', 'hti-engine' ),
			esc_html__( 'Switches /go/ to the affiliate URL, adds rel="sponsored" and the "Partner · Ad" label.', 'hti-engine' )
		);

		$text(
			'affiliate_sub_param',
			__( 'Sub-id parameter name', 'hti-engine' ),
			__( 'What THIS network calls its tracking field (clickid, sub1, tag…) — copy the name from their panel. /go/ appends it to the affiliate URL, carrying the ?cid= that the click arrived with, so the network can tell you which campaign produced an account. Leave empty and no sub-id is sent.', 'hti-engine' )
		);

		$network = self::get( $post->ID, 'affiliate_network' );
		echo '<p><label for="hti_broker_affiliate_network"><strong>' . esc_html__( 'Affiliate network', 'hti-engine' ) . '</strong></label><br /><select id="hti_broker_affiliate_network" name="hti_broker_affiliate_network">';
		foreach ( self::NETWORKS as $n ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $n ), selected( $network, $n, false ) );
		}
		echo '</select></p>';

		$text( 'guide_page', __( 'Guide page ID ("How to open an account…")', 'hti-engine' ), __( 'Filled by the broker seeder; links review ↔ guide.', 'hti-engine' ) );
	}

	/**
	 * Persist the fields.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['hti_broker_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['hti_broker_nonce'] ) ), 'hti_broker_data' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || 'broker' !== $post->post_type ) {
			return;
		}

		$texts = array( 'regulator', 'min_deposit', 'fees_note', 'fees_note_pt', 'interest_rate_note', 'interest_rate_note_pt' );
		foreach ( $texts as $key ) {
			self::put( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ self::PREFIX . $key ] ?? '' ) ) );
		}

		$rating = (string) ( wp_unslash( $_POST['hti_broker_rating'] ?? '' ) );
		$rating = is_numeric( $rating ) ? (string) min( 5, max( 0, round( (float) $rating, 1 ) ) ) : '';
		self::put( $post_id, 'rating', $rating );

		$verified = sanitize_text_field( wp_unslash( $_POST['hti_broker_verified'] ?? '' ) );
		self::put( $post_id, 'verified', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $verified ) ? $verified : '' );

		self::put( $post_id, 'products', self::csv_from( $_POST['hti_broker_products'] ?? array(), self::PRODUCTS ) );
		self::put( $post_id, 'asset_classes', self::csv_from( $_POST['hti_broker_asset_classes'] ?? array(), Engine::CLASSES ) );
		self::put( $post_id, 'profile_fit', self::csv_from( $_POST['hti_broker_profile_fit'] ?? array(), array( '1', '2', '3', '4', '5' ) ) );

		self::put( $post_id, 'cfd', isset( $_POST['hti_broker_cfd'] ) ? '1' : '' );
		$pct = preg_replace( '/\D/', '', (string) wp_unslash( $_POST['hti_broker_cfd_risk_pct'] ?? '' ) );
		self::put( $post_id, 'cfd_risk_pct', substr( (string) $pct, 0, 2 ) );

		self::put( $post_id, 'official_url', self::https_url( (string) wp_unslash( $_POST['hti_broker_official_url'] ?? '' ) ) );
		self::put( $post_id, 'affiliate_url', self::https_url( (string) wp_unslash( $_POST['hti_broker_affiliate_url'] ?? '' ) ) );
		self::put( $post_id, 'affiliate_active', isset( $_POST['hti_broker_affiliate_active'] ) ? '1' : '' );

		// A query-parameter name: sanitize_key is exactly the right shape, and
		// the cap keeps a paste accident out of every outbound URL.
		$sub_param = sanitize_key( (string) wp_unslash( $_POST['hti_broker_affiliate_sub_param'] ?? '' ) );
		self::put( $post_id, 'affiliate_sub_param', substr( $sub_param, 0, 32 ) );

		$network = sanitize_key( wp_unslash( $_POST['hti_broker_affiliate_network'] ?? 'none' ) );
		self::put( $post_id, 'affiliate_network', in_array( $network, self::NETWORKS, true ) ? $network : 'none' );

		self::put( $post_id, 'guide_page', (string) absint( wp_unslash( $_POST['hti_broker_guide_page'] ?? 0 ) ) );
	}

	/**
	 * Store one field (deleting empties keeps the meta table lean).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Field key (without prefix).
	 * @param string $value   Sanitized value.
	 */
	private static function put( int $post_id, string $key, string $value ): void {
		if ( '' === $value || ( '0' === $value && 'guide_page' === $key ) ) {
			delete_post_meta( $post_id, self::PREFIX . $key );
			return;
		}
		update_post_meta( $post_id, self::PREFIX . $key, $value );
	}

	/**
	 * Reduce a submitted checkbox array to an allowlisted CSV string.
	 *
	 * @param mixed              $raw     Submitted value.
	 * @param array<int,string>  $allowed Allowlist.
	 */
	private static function csv_from( $raw, array $allowed ): string {
		$raw  = is_array( $raw ) ? array_map( 'sanitize_key', wp_unslash( $raw ) ) : array();
		$keep = array_values( array_intersect( $allowed, $raw ) );
		return implode( ',', $keep );
	}

	/**
	 * Accept only an https URL; anything else becomes empty.
	 *
	 * @param string $url Raw URL.
	 */
	private static function https_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		return str_starts_with( $url, 'https://' ) ? $url : '';
	}
}
