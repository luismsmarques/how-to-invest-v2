<?php
/**
 * Test for the Brevo payload builder (transactional email).
 *
 *   php wp-content/plugins/hti-engine/tests/test-mailer.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

use HTI\Engine\Mailer;

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

echo "\n=== Brevo payload ===\n";

$payload = Mailer::build_payload(
	'user@example.com',
	'Confirm your email',
	'<p>Hello</p>',
	array(
		'email' => 'no-reply@howtoinvest.pro',
		'name'  => 'HowToInvest',
	)
);

check( 'no-reply@howtoinvest.pro' === $payload['sender']['email'], 'sender email set' );
check( 'HowToInvest' === $payload['sender']['name'], 'sender name set' );
check( array( array( 'email' => 'user@example.com' ) ) === $payload['to'], 'recipient set in Brevo shape' );
check( 'Confirm your email' === $payload['subject'], 'subject set' );
check( '<p>Hello</p>' === $payload['htmlContent'], 'html body set' );
check( false !== json_encode( $payload ), 'payload is json-encodable' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
