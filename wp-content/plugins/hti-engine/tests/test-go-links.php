<?php
/**
 * Tests for the owner-managed /go/ links (Go_Links pure helpers).
 *
 *   php wp-content/plugins/hti-engine/tests/test-go-links.php
 *
 * Guards the rules that keep the redirector safe: slugs match the route regex,
 * destinations are https-only, parked links stop redirecting, and the store
 * stays bounded.
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-go-links.php';
require_once __DIR__ . '/../includes/class-broker-go.php';

use HTI\Engine\Broker_Go;
use HTI\Engine\Go_Links;

$passes   = 0;
$failures = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond  Condition.
 * @param string $label Description.
 */
function check( bool $cond, string $label ): void {
	global $passes, $failures;
	if ( $cond ) {
		++$passes;
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "  \033[31m✗ {$label}\033[0m\n";
	}
}

echo "\nSlugs\n";
check( 'xm' === Go_Links::clean_slug( ' XM ' ), 'trims and lowercases' );
check( 'xm-telegram' === Go_Links::clean_slug( 'XM Telegram' ), 'spaces become hyphens' );
check( 'xm-2' === Go_Links::clean_slug( 'xm_2' ), 'underscores are normalized away' );
check( 'xm' === Go_Links::clean_slug( '--xm--' ), 'leading/trailing hyphens dropped' );
check( 'xm-a' === Go_Links::clean_slug( 'xm/../a' ), 'path traversal cannot survive a slug' );
check( '' === Go_Links::clean_slug( '   ' ), 'blank input yields no slug' );

// Whatever clean_slug returns must be matchable by the live route, otherwise
// the admin could mint a link that 404s forever. Derive the test from the
// route itself so the two can never drift apart.
$route = '#' . Broker_Go::pattern() . '#';
foreach ( array( 'XM', 'xm telegram', 'Trading 212', 'a_b-c', 'ÁÉÍ xm' ) as $raw ) {
	$slug = Go_Links::clean_slug( $raw );
	if ( '' === $slug ) {
		continue;
	}
	check( 1 === preg_match( $route, 'go/' . $slug ), "slug from '{$raw}' is matched by the live /go/ route ({$slug})" );
}

echo "\nDestinations\n";
check( 'https://x.example/a?b=1' === Go_Links::clean_url( ' https://x.example/a?b=1 ' ), 'https URL kept (trimmed)' );
check( '' === Go_Links::clean_url( 'http://x.example' ), 'plain http rejected' );
check( '' === Go_Links::clean_url( 'javascript:alert(1)' ), 'javascript: rejected' );
check( '' === Go_Links::clean_url( '/relative' ), 'relative URL rejected' );
check( '' === Go_Links::clean_url( "https://x.example/a b" ), 'URL with whitespace rejected' );

echo "\nStore\n";
$links = Go_Links::upsert( array(), 'xm', 'https://xm.example/aff', 'XM — Telegram', true );
check( isset( $links['xm'] ) && 'https://xm.example/aff' === $links['xm']['url'], 'upsert stores the link' );
check( 'XM — Telegram' === $links['xm']['label'], 'label kept' );

$links = Go_Links::upsert( $links, 'xm', 'https://xm.example/new', 'XM', true );
check( 'https://xm.example/new' === $links['xm']['url'], 'upsert replaces the destination' );
check( 1 === count( $links ), 'replacing does not duplicate the slug' );

check( $links === Go_Links::upsert( $links, 'bad', 'http://insecure.example', '', true ), 'a non-https destination is refused' );
check( $links === Go_Links::upsert( $links, '', 'https://x.example', '', true ), 'an empty slug is refused' );

echo "\nResolution\n";
check( 'https://xm.example/new' === Go_Links::resolve( $links, 'xm' ), 'active link resolves' );
check( 'https://xm.example/new' === Go_Links::resolve( $links, ' XM ' ), 'resolution normalizes the requested slug' );
check( '' === Go_Links::resolve( $links, 'nope' ), 'unknown slug yields nothing (404)' );

$parked = Go_Links::upsert( $links, 'xm', 'https://xm.example/new', 'XM', false );
check( '' === Go_Links::resolve( $parked, 'xm' ), 'parked link stops redirecting' );

$tampered = array( 'xm' => array( 'url' => 'http://xm.example', 'active' => true ) );
check( '' === Go_Links::resolve( $tampered, 'xm' ), 'a non-https destination never redirects, even if stored' );

echo "\nNormalization + bounds\n";
$dirty = array(
	'XM'      => array( 'url' => 'https://a.example', 'active' => true ),
	'bad url' => array( 'url' => 'ftp://b.example', 'active' => true ),
	'ok'      => array( 'url' => 'https://c.example', 'active' => false ),
	''        => array( 'url' => 'https://d.example', 'active' => true ),
);
$clean = Go_Links::normalize( $dirty );
check( array( 'xm', 'ok' ) === array_keys( $clean ), 'normalize keeps only valid rows, slugs cleaned' );
check( false === $clean['ok']['active'], 'active flag preserved' );

$full = array();
for ( $i = 0; $i < Go_Links::MAX_LINKS; $i++ ) {
	$full = Go_Links::upsert( $full, 'slug-' . $i, 'https://x.example/' . $i, '', true );
}
check( Go_Links::MAX_LINKS === count( $full ), 'store fills to the cap' );
$over = Go_Links::upsert( $full, 'one-more', 'https://x.example/more', '', true );
check( ! isset( $over['one-more'] ), 'a new slug beyond the cap is refused' );
check( 'https://x.example/0-updated' === Go_Links::upsert( $full, 'slug-0', 'https://x.example/0-updated', '', true )['slug-0']['url'], 'existing slugs still update at the cap' );

$removed = Go_Links::remove( $links, 'XM' );
check( ! isset( $removed['xm'] ), 'remove normalizes the slug it deletes' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
