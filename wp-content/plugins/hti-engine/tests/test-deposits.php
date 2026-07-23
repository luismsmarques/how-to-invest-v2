<?php
/**
 * Tests for the bilingual term-deposit comparator interface. Guards the
 * PT/EN string tables against key drift (a missing key would surface as an
 * undefined-index warning inside render()/card()) and confirms the English
 * meta is complete.
 *
 *   php wp-content/plugins/hti-engine/tests/test-deposits.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/../includes/class-deposits.php';

use HTI\Engine\Deposits;

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

/**
 * Call a private static method via reflection.
 *
 * @param string            $method Method name.
 * @param array<int,mixed>  $args   Arguments.
 * @return mixed
 */
function dep_call( string $method, array $args = array() ) {
	$ref = new ReflectionMethod( Deposits::class, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

echo "\nDeposits — bilingual interface\n";

$pt = dep_call( 'strings', array( 'pt' ) );
$en = dep_call( 'strings', array( 'en' ) );

check( is_array( $pt ) && is_array( $en ), 'strings() returns arrays for both languages' );

$missing_in_en = array_diff( array_keys( $pt ), array_keys( $en ) );
$missing_in_pt = array_diff( array_keys( $en ), array_keys( $pt ) );
check( array() === $missing_in_en, 'every PT key exists in EN (' . implode( ',', $missing_in_en ) . ')' );
check( array() === $missing_in_pt, 'every EN key exists in PT (' . implode( ',', $missing_in_pt ) . ')' );

// No empty values (would render as blank labels).
$empty = array();
foreach ( $en as $k => $v ) {
	if ( '' === (string) $v ) {
		$empty[] = $k;
	}
}
check( array() === $empty, 'no empty EN strings (' . implode( ',', $empty ) . ')' );

// The two languages must actually differ (guards against a copy-paste PT into EN).
check( $pt['filters'] !== $en['filters'], 'PT and EN diverge (filters label)' );

// Keys render() and card() rely on must be present.
$needed = array(
	'eyebrow', 'amount_label', 'filters', 'clear', 'term', 'bank', 'sort_label',
	'deposits_word', 'beats_word', 'disclaimer_t', 'disclaimer_d', 'updated_label',
	'method_link', 'top_badge', 'month', 'months', 'tanb', 'est_label', 'gross',
	'min_prefix', 'min_none', 'max_prefix', 'max_none', 'mob_yes', 'mob_notes', 'mob_no',
);
$absent = array();
foreach ( $needed as $k ) {
	if ( ! isset( $en[ $k ], $pt[ $k ] ) ) {
		$absent[] = $k;
	}
}
check( array() === $absent, 'all render/card keys present (' . implode( ',', $absent ) . ')' );

// English meta is complete.
$em = dep_call( 'en_meta' );
check( isset( $em['title'], $em['intro'], $em['footer'] ), 'en_meta() has title, intro, footer' );
check( '' !== (string) ( $em['title'] ?? '' ), 'en_meta title is non-empty' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";

exit( $failures > 0 ? 1 : 0 );
