<?php
/**
 * Tests for the Content_Sync deploy-detection signature (pure helper).
 *
 *   php wp-content/plugins/hti-engine/tests/test-content-sync.php
 *
 * The signature drives the auto-sync-after-deploy gate: it must be
 * deterministic, order-independent (glob order can differ between hosts) and
 * sensitive to any file change (mtime/size/path) and to the plugin version.
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-content-sync.php';

use HTI\Engine\Content_Sync;

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

echo "\nContent sync signature\n";

$manifest = array(
	'/p/includes/class-broker-seeder.php|1756200000|91234',
	'/p/content/learn/what-is-an-etf.md|1756200000|6100',
	'/p/content/glossary/etf.md|1756200000|4200',
);

$sig = Content_Sync::signature_from( $manifest, '0.11.0' );

check( 32 === strlen( $sig ) && ctype_xdigit( $sig ), 'signature is an md5 hex string' );
check( $sig === Content_Sync::signature_from( $manifest, '0.11.0' ), 'deterministic for the same manifest' );
check( $sig === Content_Sync::signature_from( array_reverse( $manifest ), '0.11.0' ), 'independent of file order (glob order varies by host)' );

$touched      = $manifest;
$touched[1]   = '/p/content/learn/what-is-an-etf.md|1756286400|6100';
$resized      = $manifest;
$resized[2]   = '/p/content/glossary/etf.md|1756200000|4300';
$new_file     = $manifest;
$new_file[]   = '/p/content/glossary/reit.md|1756200000|3900';
$missing      = $manifest;
$missing[0]   = '/p/includes/class-broker-seeder.php|missing';

check( $sig !== Content_Sync::signature_from( $touched, '0.11.0' ), 'changes when a file mtime changes (deploy rewrites files)' );
check( $sig !== Content_Sync::signature_from( $resized, '0.11.0' ), 'changes when a file size changes' );
check( $sig !== Content_Sync::signature_from( $new_file, '0.11.0' ), 'changes when a file is added' );
check( $sig !== Content_Sync::signature_from( $missing, '0.11.0' ), 'changes when a file goes missing' );
check( $sig !== Content_Sync::signature_from( $manifest, '0.11.1' ), 'changes with the plugin version' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
