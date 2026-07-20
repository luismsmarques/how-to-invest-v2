<?php
/**
 * GDPR account-deletion tests (P0 flow) — the self-contained pieces of the
 * "delete my account" journey: the 30-day grace scheduling, cancellation, the
 * stateless cancel-link token, and the question-log erasure. Heavier DB cascade
 * (wp_delete_user, posts, Brevo) needs a WordPress integration harness.
 *
 *   php wp-content/plugins/hti-engine/tests/test-account-gdpr.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

// --- Minimal WP shims (user meta, options, salt, URLs) ---------------------
$GLOBALS['__hti_usermeta'] = array();
if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * @param int    $uid User id.
	 * @param string $key Meta key.
	 * @param mixed  $val Value.
	 * @return bool
	 */
	function update_user_meta( $uid, $key, $val ) {
		$GLOBALS['__hti_usermeta'][ $uid ][ $key ] = $val;
		return true;
	}
	/**
	 * @param int    $uid    User id.
	 * @param string $key    Meta key.
	 * @param bool   $single Single.
	 * @return mixed
	 */
	function get_user_meta( $uid, $key, $single = false ) {
		return $GLOBALS['__hti_usermeta'][ $uid ][ $key ] ?? '';
	}
	/**
	 * @param int    $uid User id.
	 * @param string $key Meta key.
	 * @return bool
	 */
	function delete_user_meta( $uid, $key ) {
		unset( $GLOBALS['__hti_usermeta'][ $uid ][ $key ] );
		return true;
	}
}
// Return null so schedule_deletion skips the email branch (Mailer/Emails).
if ( ! function_exists( 'get_user_by' ) ) {
	/**
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return null
	 */
	function get_user_by( $field, $value ) {
		return null;
	}
}
$GLOBALS['__hti_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		return $GLOBALS['__hti_options'][ $key ] ?? $default;
	}
	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( $key, $value ) {
		$GLOBALS['__hti_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * @param string $scheme Scheme.
	 * @return string
	 */
	function wp_salt( $scheme = '' ) {
		return 'test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * @param array<string,mixed> $args Args.
	 * @param string              $url  URL.
	 * @return string
	 */
	function add_query_arg( $args, $url ) {
		return $url . '?' . http_build_query( $args );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-account.php';

use HTI\Engine\Account;

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

echo "\nAccount — GDPR deletion flow\n";

$ref     = new ReflectionClass( Account::class );
$grace   = (int) $ref->getConstant( 'GRACE_DAYS' );
$opt_q   = (string) $ref->getConstant( 'OPTION_QUESTIONS' );

// 1) Scheduling sets a future deletion ~GRACE_DAYS ahead and persists it.
$at   = Account::schedule_deletion( 7 );
$days = ( $at - time() ) / DAY_IN_SECONDS;
check( $at > time(), 'schedule_deletion returns a future timestamp' );
check( abs( $days - $grace ) < 1.0, 'grace period matches GRACE_DAYS (~' . $grace . 'd)' );
check( Account::deletion_at( 7 ) === $at, 'deletion_at reads back the scheduled time' );

// 2) Cancelling clears the schedule.
Account::cancel_deletion( 7 );
check( 0 === Account::deletion_at( 7 ), 'cancel_deletion clears the schedule' );
check( 0 === Account::deletion_at( 999 ), 'deletion_at is 0 for a user with no schedule' );

// 3) Stateless cancel-link token is deterministic and verifiable (as
//    handle_cancel_deletion recomputes it).
$make_link = $ref->getMethod( 'cancel_link' );
$make_link->setAccessible( true );
$at2      = time() + 100;
$link     = (string) $make_link->invoke( null, 42, $at2 );
$expected = substr( hash_hmac( 'sha256', '42|' . $at2 . '|cancel', wp_salt( 'auth' ) ), 0, 40 );
check( false !== strpos( $link, $expected ), 'cancel link embeds the correct HMAC token' );
check( false !== strpos( $link, 'u=42' ), 'cancel link binds the user id' );
$link2 = (string) $make_link->invoke( null, 42, $at2 );
check( $link === $link2, 'cancel link is stable for the same user + time' );

// 4) forget_questions erases only the deleted user's rows from the research log.
$GLOBALS['__hti_options'][ $opt_q ] = array(
	array( 'uid' => 7, 'q' => 'a' ),
	array( 'uid' => 9, 'q' => 'b' ),
	array( 'uid' => 7, 'q' => 'c' ),
);
$forget = $ref->getMethod( 'forget_questions' );
$forget->setAccessible( true );
$forget->invoke( null, 7 );
$left = array_values( (array) $GLOBALS['__hti_options'][ $opt_q ] );
check( 1 === count( $left ) && 9 === (int) $left[0]['uid'], 'forget_questions removes only the deleted user rows' );

echo "\n";
if ( $failures ) {
	echo "\033[31mFAILED\033[0m {$passes} passed, {$failures} failed\n";
	exit( 1 );
}
echo "\033[32mPASSED\033[0m {$passes} checks\n";
exit( 0 );
