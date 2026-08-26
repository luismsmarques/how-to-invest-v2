<?php
/**
 * Schema graph tests (pure, no WordPress).
 *
 *   php wp-content/plugins/hti-forex/tests/test-schema.php
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-schema.php';

use HTI\Forex\Config;
use HTI\Forex\Schema;

$passes   = 0;
$failures = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond  Condition.
 * @param string $label Label.
 */
function check( bool $cond, string $label ): void {
	global $passes, $failures;
	if ( $cond ) {
		++$passes;
		echo "\033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "\033[31m✗\033[0m {$label}\n";
	}
}

/**
 * Find the first node of a @type in a graph.
 *
 * @param array<int,array<string,mixed>> $graph Graph.
 * @param string                         $type  Node type.
 * @return array<string,mixed>|null
 */
function node( array $graph, string $type ): ?array {
	foreach ( $graph as $n ) {
		if ( ( $n['@type'] ?? '' ) === $type ) {
			return $n;
		}
	}
	return null;
}

$ctx = array(
	'page'      => 'pip_value',
	'url'       => 'https://example.com/forex/pip-value-calculator/',
	'title'     => 'Pip value calculator in Indian rupees (INR)',
	'faqs'      => Config::faqs( 'pip_value' ),
	'home_url'  => 'https://example.com/',
	'hub_url'   => 'https://example.com/forex/',
	'hub_title' => 'Forex tools',
);

$graph = Schema::build_graph( $ctx );

// --- Tool page --------------------------------------------------------------
$app = node( $graph, 'WebApplication' );
check( null !== $app, 'tool page has a WebApplication node' );
check( 'FinanceApplication' === $app['applicationCategory'], 'category is FinanceApplication' );
check( 'INR' === $app['offers']['priceCurrency'] && 0 === $app['offers']['price'], 'offer is free, priced in INR' );
check( 'en' === $app['inLanguage'], 'inLanguage is en' );
check( true === $app['isAccessibleForFree'], 'isAccessibleForFree' );
check( 'https://example.com/#organization' === $app['publisher']['@id'], 'publisher anchors the site-wide #organization id' );
check( $ctx['url'] . '#app' === $app['@id'], 'WebApplication @id derives from the page URL' );

$faq = node( $graph, 'FAQPage' );
check( null !== $faq, 'tool page has a FAQPage node' );
check( count( Config::faqs( 'pip_value' ) ) === count( $faq['mainEntity'] ), 'FAQPage question count matches Config::faqs()' );
check( 'How much is 1 pip in Indian rupees?' === $faq['mainEntity'][0]['name'], 'first question is the literal search query' );
check( '' !== $faq['mainEntity'][0]['acceptedAnswer']['text'], 'answers carry text' );

$bc = node( $graph, 'BreadcrumbList' );
check( null !== $bc, 'tool page has breadcrumbs' );
check( 3 === count( $bc['itemListElement'] ), 'tool breadcrumbs: Home → hub → page' );
check( 'Forex tools' === $bc['itemListElement'][1]['name'], 'second crumb is the hub' );
check( 3 === $bc['itemListElement'][2]['position'], 'positions are 1-based and ordered' );

// --- Hub page ---------------------------------------------------------------
$hub_graph = Schema::build_graph(
	array_merge(
		$ctx,
		array(
			'page'  => 'hub',
			'url'   => 'https://example.com/forex/',
			'title' => 'Free forex tools for Indian traders',
			'faqs'  => Config::faqs( 'hub' ),
		)
	)
);

check( null === node( $hub_graph, 'WebApplication' ), 'hub has no WebApplication node' );
check( null !== node( $hub_graph, 'FAQPage' ), 'hub still carries its FAQPage (legal-context copy)' );
$hub_bc = node( $hub_graph, 'BreadcrumbList' );
check( 2 === count( $hub_bc['itemListElement'] ), 'hub breadcrumbs: Home → hub' );

// --- No FAQs → no FAQPage ---------------------------------------------------
$bare = Schema::build_graph( array_merge( $ctx, array( 'faqs' => array() ) ) );
check( null === node( $bare, 'FAQPage' ), 'no FAQs → no FAQPage node' );
check( null !== node( $bare, 'WebApplication' ), 'WebApplication still present without FAQs' );

// --- JSON sanity ------------------------------------------------------------
check( false !== json_encode( $graph ), 'graph is JSON-encodable' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
