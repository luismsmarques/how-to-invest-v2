<?php
/**
 * JSON-LD for the forex tool pages: WebApplication (INR) + FAQPage +
 * BreadcrumbList in one @graph on wp_head.
 *
 * No collision with hti-engine's SEO class: its web_application() gate
 * requires the [hti_tool] shortcode, ours requires [hti_forex_tool] (or the
 * seeder's hti_forex_page meta, which also covers the hub page that embeds
 * no tool). The publisher references the site-wide #organization @id that
 * RankMath/hti-engine already anchor — computed locally so this plugin
 * stands alone. FAQPage nodes are built from the same Config::faqs() array
 * the seeder rendered into the page, so content and schema agree at seed
 * time (drift caveat documented in README.md).
 *
 * @package HTI_Forex
 */

namespace HTI\Forex;

defined( 'ABSPATH' ) || exit;

/**
 * Structured-data emitter.
 */
class Schema {

	public const PAGE_META = 'hti_forex_page';

	/**
	 * Hook the emitter (same wp_head slot as hti-engine's SEO class).
	 */
	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'emit' ), 20 );
	}

	/**
	 * Which forex page the current view is, or '' when it is none.
	 * Prefers the seeder's meta; falls back to sniffing the shortcode so a
	 * hand-made page still gets its schema.
	 *
	 * @param \WP_Post $post Current page.
	 */
	public static function detect_page( \WP_Post $post ): string {
		$meta = (string) get_post_meta( $post->ID, self::PAGE_META, true );
		if ( '' !== $meta ) {
			return $meta;
		}
		if ( preg_match( '/\[hti_forex_tool\s+name="([a-z_]+)"/', (string) $post->post_content, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Emit the JSON-LD graph on forex pages.
	 */
	public static function emit(): void {
		if ( ! is_page() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$page = self::detect_page( $post );
		if ( '' === $page ) {
			return;
		}

		$graph = self::build_graph(
			array(
				'page'      => $page,
				'url'       => (string) get_permalink( $post ),
				'title'     => wp_strip_all_tags( (string) get_the_title( $post ) ),
				'faqs'      => Config::faqs( $page ),
				'home_url'  => home_url( '/' ),
				'hub_url'   => home_url( '/forex/' ),
				'hub_title' => 'Forex tools',
			)
		);

		if ( empty( $graph ) ) {
			return;
		}

		echo '<script type="application/ld+json">'
			. wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => $graph,
				),
				JSON_UNESCAPED_SLASHES
			)
			. '</script>' . "\n";
	}

	/**
	 * Pure graph builder (unit-tested without WordPress).
	 *
	 * @param array{page:string,url:string,title:string,faqs:array<int,array{q:string,a:string}>,home_url:string,hub_url:string,hub_title:string} $ctx Context.
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_graph( array $ctx ): array {
		$graph  = array();
		$is_hub = 'hub' === $ctx['page'];

		// Tools are apps, not articles. The hub is a plain collection page —
		// it only carries the FAQPage + breadcrumbs.
		if ( ! $is_hub ) {
			$graph[] = array(
				'@type'               => 'WebApplication',
				'@id'                 => $ctx['url'] . '#app',
				'name'                => $ctx['title'],
				'url'                 => $ctx['url'],
				'applicationCategory' => 'FinanceApplication',
				'operatingSystem'     => 'Web',
				'inLanguage'          => 'en',
				'isAccessibleForFree' => true,
				'offers'              => array(
					'@type'         => 'Offer',
					'price'         => 0,
					'priceCurrency' => 'INR',
				),
				'publisher'           => array( '@id' => $ctx['home_url'] . '#organization' ),
			);
		}

		if ( ! empty( $ctx['faqs'] ) ) {
			$questions = array();
			foreach ( $ctx['faqs'] as $faq ) {
				$questions[] = array(
					'@type'          => 'Question',
					'name'           => $faq['q'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $faq['a'],
					),
				);
			}
			$graph[] = array(
				'@type'      => 'FAQPage',
				'@id'        => $ctx['url'] . '#faq',
				'mainEntity' => $questions,
			);
		}

		$crumbs = array(
			array( 'Home', $ctx['home_url'] ),
			array( $ctx['hub_title'], $ctx['hub_url'] ),
		);
		if ( ! $is_hub ) {
			$crumbs[] = array( $ctx['title'], $ctx['url'] );
		}

		$items = array();
		foreach ( $crumbs as $i => $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $crumb[0],
				'item'     => $crumb[1],
			);
		}
		$graph[] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $ctx['url'] . '#breadcrumbs',
			'itemListElement' => $items,
		);

		return $graph;
	}
}
