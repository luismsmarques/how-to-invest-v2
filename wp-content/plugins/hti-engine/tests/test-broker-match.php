<?php
/**
 * Tests for the deterministic archetype → brokers matching (the post-result
 * partner module), plus the regression guard that keeps brokers OUT of the
 * PDF export and the emails.
 *
 *   php wp-content/plugins/hti-engine/tests/test-broker-match.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/../includes/class-broker-match.php';

use HTI\Engine\Broker_Match;

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
 * Fixture broker record.
 *
 * @param string       $slug    Slug.
 * @param int          $order   Editorial order.
 * @param array<int>   $fit     Archetypes.
 * @param array<string> $classes Holdable classes.
 * @param array<string> $uses    Use cases.
 * @return array<string,mixed>
 */
function broker( string $slug, int $order, array $fit, array $classes, array $uses = array() ): array {
	return array(
		'slug'          => $slug,
		'menu_order'    => $order,
		'profile_fit'   => $fit,
		'asset_classes' => $classes,
		'use_cases'     => $uses,
	);
}

$all  = array( 'global_equity', 'bonds', 'cash', 'reits_alt' );
$pool = array(
	broker( 'alpha', 10, array( 1, 2, 3, 4, 5 ), $all, array( 'beginners' ) ),
	broker( 'bravo', 20, array( 1, 2, 3, 4, 5 ), array_merge( $all, array( 'crypto' ) ), array( 'beginners' ) ),
	broker( 'charlie', 30, array( 3, 4, 5 ), $all ),
	broker( 'delta', 40, array( 4, 5 ), array( 'global_equity', 'reits_alt', 'crypto' ) ),
	broker( 'echo', 50, array( 1, 2 ), array( 'global_equity', 'cash' ), array( 'beginners' ) ),
);

// A balanced archetype-3 allocation: equity 55, bonds 30, reits 8, cash 5, crypto 2.
$alloc3 = array(
	array( 'class' => 'global_equity', 'pct' => 55 ),
	array( 'class' => 'bonds', 'pct' => 30 ),
	array( 'class' => 'reits_alt', 'pct' => 8 ),
	array( 'class' => 'cash', 'pct' => 5 ),
	array( 'class' => 'crypto', 'pct' => 2 ),
);

echo "\nNeeded classes\n";
check( array( 'global_equity', 'bonds' ) === Broker_Match::needed_classes( $alloc3 ), 'only classes ≥10% required; cash always excluded; tiny crypto slice not required' );

echo "\nSelection matrix\n";

$picked = Broker_Match::pick( $pool, 3, $alloc3 );
check( array( 'alpha', 'bravo', 'charlie' ) === array_column( $picked, 'slug' ), 'archetype 3 → editorial order, fit + coverage respected' );
check( count( $picked ) <= 3, 'never more than three' );

// Archetype 5 with a crypto slice ≥10 → only brokers holding crypto qualify.
$alloc5 = array(
	array( 'class' => 'global_equity', 'pct' => 75 ),
	array( 'class' => 'crypto', 'pct' => 10 ),
	array( 'class' => 'bonds', 'pct' => 10 ),
	array( 'class' => 'cash', 'pct' => 5 ),
);
$picked5 = Broker_Match::pick( $pool, 5, $alloc5 );
check( array( 'bravo' ) !== array() && in_array( 'bravo', array_column( $picked5, 'slug' ), true ), 'crypto ≥10% keeps only crypto-capable brokers (bravo)' );
check( ! in_array( 'charlie', array_column( $picked5, 'slug' ), true ), 'charlie lacks crypto → excluded on coverage' );
// bravo is the only full match → the module tops up from the beginners pool.
check( count( $picked5 ) >= 2, 'topped up to the minimum of two' );
check( 'alpha' === $picked5[1]['slug'], 'top-up comes from beginners, editorial order' );

// Conservative archetype 1: echo fits (1) but lacks bonds coverage → excluded.
$alloc1 = array(
	array( 'class' => 'global_equity', 'pct' => 20 ),
	array( 'class' => 'bonds', 'pct' => 60 ),
	array( 'class' => 'cash', 'pct' => 20 ),
);
$picked1 = Broker_Match::pick( $pool, 1, $alloc1 );
check( array( 'alpha', 'bravo' ) === array_column( $picked1, 'slug' ), 'archetype 1 → bonds coverage excludes echo' );

// No archetype fit anywhere → beginners fallback only, capped at MIN_ITEMS.
$strangers = array(
	broker( 'x1', 10, array( 4 ), $all ),
	broker( 'x2', 20, array( 4 ), $all, array( 'beginners' ) ),
	broker( 'x3', 30, array( 4 ), $all, array( 'beginners' ) ),
);
$picked_none = Broker_Match::pick( $strangers, 1, $alloc1 );
check( array( 'x2', 'x3' ) === array_column( $picked_none, 'slug' ), 'no fit at all → two beginners as fallback' );

// Empty pool → empty result (module hidden client-side).
check( array() === Broker_Match::pick( array(), 3, $alloc3 ), 'empty pool → empty pick' );

// Determinism: same inputs, same output.
check( Broker_Match::pick( $pool, 3, $alloc3 ) === Broker_Match::pick( $pool, 3, $alloc3 ), 'pick is deterministic' );

echo "\nContainment regression (PDF + emails stay broker-free)\n";

$pdf    = (string) file_get_contents( __DIR__ . '/../includes/class-pdf.php' );
$emails = (string) file_get_contents( __DIR__ . '/../includes/class-emails.php' );
check( false === strpos( $pdf, 'partner_module' ) && false === stripos( $pdf, 'Brokers::' ), 'class-pdf.php has no partner-module/broker rendering' );
check( false === strpos( $emails, 'partner_module' ) && false === stripos( $emails, 'Brokers::' ), 'class-emails.php has no partner-module/broker rendering' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
