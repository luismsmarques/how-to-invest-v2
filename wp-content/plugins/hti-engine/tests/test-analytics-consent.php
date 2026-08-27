<?php
/**
 * Consent-mode gating for GA4 (Analytics::consent_mode) + the funnel allowlist
 * entry the forex tools depend on.
 *
 * The default matters legally, not just technically: with consent mode off,
 * gtag.js is never injected before the visitor accepts, which is what the
 * launch QA gate and the RGPD checklist describe. This test pins that default
 * so it can only change deliberately.
 *
 *   php wp-content/plugins/hti-engine/tests/test-analytics-consent.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

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

require_once __DIR__ . '/../includes/class-analytics.php';
require_once __DIR__ . '/../includes/class-metrics.php';

use HTI\Engine\Analytics;
use HTI\Engine\Metrics;

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

// --- The default is the hard block ------------------------------------------
$GLOBALS['__hti_options']['htinvest_settings'] = array();
check( false === Analytics::consent_mode(), 'consent mode is OFF by default (gtag never loads pre-consent)' );

$GLOBALS['__hti_options']['htinvest_settings'] = array( 'ga_consent_mode' => true );
check( true === Analytics::consent_mode(), 'the setting switches consent mode on' );

$GLOBALS['__hti_options']['htinvest_settings'] = array( 'ga_consent_mode' => '' );
check( false === Analytics::consent_mode(), 'an empty checkbox value stays off' );

// --- Measurement id remains config-only -------------------------------------
$GLOBALS['__hti_options']['htinvest_settings'] = array( 'ga_consent_mode' => true );
check( '' === Analytics::measurement_id(), 'consent mode alone never invents a measurement id' );

// --- The JS contract --------------------------------------------------------
// The defaults live in analytics.js; assert the file states the four Consent
// Mode v2 signals as denied and never grants an advertising one, since no
// banner category covers advertising.
$js = (string) file_get_contents( __DIR__ . '/../assets/js/analytics.js' );
foreach ( array( 'ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage' ) as $signal ) {
	check( 1 === preg_match( '/' . $signal . ":\s*'denied'/", $js ), "consent default denies {$signal}" );
}
check( 1 === preg_match( "/'update',\s*\{\s*analytics_storage:\s*'granted'\s*\}/", $js ), 'the consent update grants analytics storage only' );
check( ! preg_match( "/ad_(storage|user_data|personalization):\s*'granted'/", $js ), 'no advertising signal is ever granted' );
check( str_contains( $js, "'set', 'ads_data_redaction', true" ), 'ad data is redacted while ad storage is denied' );

// --- The funnel event the forex tools report --------------------------------
check( in_array( 'forex_tool_use', Metrics::events(), true ), 'forex_tool_use is on the funnel allowlist' );
check( in_array( 'cta_click', Metrics::events(), true ), 'cta_click is still on the allowlist' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
