<?php
/**
 * Tests for the /go/{slug} affiliate redirector's pure pieces: the route
 * regex and the destination-selection rules (affiliate only while active and
 * https; official https fallback; '' → 404).
 *
 *   php wp-content/plugins/hti-engine/tests/test-broker-go.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/../includes/class-broker-go.php';

use HTI\Engine\Broker_Go;

$failures = 0;
$passes   = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond  Condition.
 * @param string $label Description.
 */
function check( bool $cond, string $label ): void {
	global $failures, $passes;
	if ( $cond ) {
		++$passes;
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "  \033[31m✗ {$label}\033[0m\n";
	}
}

echo "\n/go/ route regex\n";

$re = '#' . Broker_Go::pattern() . '#';

check( 1 === preg_match( $re, 'go/xtb', $m ) && 'xtb' === $m[1], 'matches go/xtb' );
check( 1 === preg_match( $re, 'go/trading-212/', $m ) && 'trading-212' === $m[1], 'matches trailing slash + hyphens' );
check( 0 === preg_match( $re, 'go/XTB' ), 'rejects uppercase' );
check( 0 === preg_match( $re, 'go/xtb/extra' ), 'rejects extra path segments' );
check( 0 === preg_match( $re, 'go/' ), 'rejects an empty slug' );
check( 0 === preg_match( $re, 'going/xtb' ), 'anchored at ^go/' );

echo "\nDestination selection\n";

$aff = 'https://partners.example.com/track?id=42';
$off = 'https://www.example-broker.com';

check( $aff === Broker_Go::choose( $aff, $off, true ), 'active deal → affiliate URL' );
check( $off === Broker_Go::choose( $aff, $off, false ), 'inactive deal → official URL' );
check( $off === Broker_Go::choose( '', $off, true ), 'active but empty affiliate URL → official URL' );
check( $off === Broker_Go::choose( 'http://insecure.example.com', $off, true ), 'non-https affiliate URL → official URL' );
check( '' === Broker_Go::choose( '', 'http://insecure.example.com', false ), 'non-https official URL → empty (404)' );
check( '' === Broker_Go::choose( '', '', true ), 'no URLs at all → empty (404)' );

echo "\nCampaign id passthrough\n";

// The affiliate URL is never printed, so a campaign can only reach the network
// if the click hands it to /go/ and /go/ re-attaches it on the way out.
check( 'b2_7f3a' === Broker_Go::cid( 'b2_7f3a' ), 'a clean campaign id survives' );
check( 'b2_7f3a' === Broker_Go::cid( ' b2_7f3a<>&" ' ), 'anything outside [A-Za-z0-9_-] is stripped' );
check( 64 === strlen( Broker_Go::cid( str_repeat( 'a', 200 ) ) ), 'a campaign id is capped at 64 characters' );
check( '' === Broker_Go::cid( '' ), 'no campaign id stays empty' );

check(
	'https://partners.example.com/track?id=42&sub1=b2_7f3a' === Broker_Go::with_sub_id( $aff, 'sub1', 'b2_7f3a' ),
	'the sub-id is appended to an URL that already has a query string'
);
check(
	'https://www.example-broker.com?clickid=b2' === Broker_Go::with_sub_id( $off, 'clickid', 'b2' ),
	'a URL with no query string gets its first parameter'
);

// Both halves are required, and the failure mode is "unchanged", never a
// malformed URL: a network that never told us its field name is indistinguishable
// from one we guessed wrong, so we send nothing rather than guess.
check( $aff === Broker_Go::with_sub_id( $aff, '', 'b2' ), 'no parameter name → the URL is untouched' );
check( $aff === Broker_Go::with_sub_id( $aff, 'sub1', '' ), 'no campaign id → the URL is untouched' );
check( '' === Broker_Go::with_sub_id( '', 'sub1', 'b2' ), 'no URL → nothing to tag' );

echo "\nClick locations\n";
check( in_array( 'result', Broker_Go::LOCATIONS, true ) && in_array( 'compare', Broker_Go::LOCATIONS, true ), 'expected loc values allowlisted' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
