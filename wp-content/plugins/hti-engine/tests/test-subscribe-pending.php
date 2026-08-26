<?php
/**
 * Pending-source store tests (the generalized opt-in attribution added for
 * the forex lead magnet): set/take round-trip, expiry, pruning, and the
 * legacy ebook flag still behaving as the read fallback.
 *
 *   php wp-content/plugins/hti-engine/tests/test-subscribe-pending.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

// In-memory options (same shim as test-metrics.php).
$GLOBALS['__hti_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $key     Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		return $GLOBALS['__hti_options'][ $key ] ?? $default;
	}
	/**
	 * @param string $key      Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Autoload flag.
	 * @return bool
	 */
	function update_option( $key, $value, $autoload = true ) {
		$GLOBALS['__hti_options'][ $key ] = $value;
		return true;
	}
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}

require_once __DIR__ . '/../includes/class-subscribe.php';

use HTI\Engine\Subscribe;

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
 * Invoke a private static Subscribe method.
 *
 * @param string $method Method name.
 * @param mixed  ...$args Arguments.
 * @return mixed
 */
function sub( string $method, ...$args ) {
	$ref = new ReflectionMethod( Subscribe::class, $method );
	$ref->setAccessible( true );
	return $ref->invoke( null, ...$args );
}

$email = 'Trader@Example.com ';

// --- Round trip -------------------------------------------------------------
sub( 'pending_source_set', $email, 'forex-pip_value' );
check( 'forex-pip_value' === sub( 'pending_source_take', $email ), 'take returns the stored source' );
check( '' === sub( 'pending_source_take', $email ), 'second take returns empty (consumed)' );

// Case/whitespace-insensitive keying (hash of lowercased trimmed email).
sub( 'pending_source_set', 'trader@example.com', 'ebook-page' );
check( 'ebook-page' === sub( 'pending_source_take', $email ), 'email normalization matches variants' );

// --- Expiry -----------------------------------------------------------------
sub( 'pending_source_set', $email, 'forex-sessions' );
$store = get_option( 'hti_pending_source' );
$hash  = array_key_first( $store );
$store[ $hash ]['x'] = time() - 10;
update_option( 'hti_pending_source', $store );
check( '' === sub( 'pending_source_take', $email ), 'expired entry yields empty' );
$store = get_option( 'hti_pending_source' );
check( ! isset( $store[ $hash ] ), 'expired entry is removed on take' );

// --- Pruning on write -------------------------------------------------------
sub( 'pending_source_set', 'a@example.com', 'forex-a' );
$store = get_option( 'hti_pending_source' );
$hash  = array_key_first( $store );
$store[ $hash ]['x'] = time() - 10;
update_option( 'hti_pending_source', $store );
sub( 'pending_source_set', 'b@example.com', 'forex-b' );
$store = get_option( 'hti_pending_source' );
check( 1 === count( $store ), 'expired entries are pruned when writing' );
check( 'forex-b' === sub( 'pending_source_take', 'b@example.com' ), 'fresh entry survives the prune' );

// --- Malformed store --------------------------------------------------------
update_option( 'hti_pending_source', 'garbage' );
check( '' === sub( 'pending_source_take', $email ), 'non-array store yields empty' );
update_option( 'hti_pending_source', array( md5( 'trader@example.com' ) => 'not-an-array' ) );
check( '' === sub( 'pending_source_take', $email ), 'malformed entry yields empty and is consumed' );

// --- Legacy ebook flag unchanged (the read fallback path) -------------------
sub( 'ebook_pending_set', $email );
check( true === sub( 'ebook_pending_take', $email ), 'legacy ebook flag still round-trips' );
check( false === sub( 'ebook_pending_take', $email ), 'legacy ebook flag consumed on take' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
