<?php
/**
 * Test for the Google id_token (JWT) payload decoder.
 *
 *   php wp-content/plugins/hti-engine/tests/test-google.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Engine\Google;

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
 * base64url-encode a string (no padding).
 *
 * @param string $data Data.
 */
function b64url( string $data ): string {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

echo "\n=== Google id_token decode ===\n";

$payload = array(
	'sub'            => '1234567890',
	'email'          => 'person@example.com',
	'email_verified' => true,
	'name'           => 'A Person',
);
$jwt = b64url( '{"alg":"RS256"}' ) . '.' . b64url( (string) json_encode( $payload ) ) . '.' . b64url( 'signature' );

$decoded = Google::decode_jwt_payload( $jwt );
check( is_array( $decoded ), 'decodes a valid JWT payload' );
check( 'person@example.com' === ( $decoded['email'] ?? null ), 'extracts the email' );
check( true === ( $decoded['email_verified'] ?? null ), 'extracts email_verified' );
check( '1234567890' === ( $decoded['sub'] ?? null ), 'extracts the subject id' );

check( null === Google::decode_jwt_payload( 'not-a-jwt' ), 'rejects a non-JWT string' );
check( null === Google::decode_jwt_payload( 'a.b' ), 'rejects a token without 3 segments' );
check( null === Google::decode_jwt_payload( b64url( 'x' ) . '.' . b64url( 'not-json' ) . '.' . b64url( 's' ) ), 'rejects a non-JSON payload' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
