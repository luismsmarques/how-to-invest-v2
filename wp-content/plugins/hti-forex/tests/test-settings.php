<?php
/**
 * Settings normalization + CTA gating tests (pure, no WordPress).
 *
 *   php wp-content/plugins/hti-forex/tests/test-settings.php
 *
 * @package HTI_Forex
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-settings.php';

use HTI\Forex\Settings;

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

$d = Settings::defaults();

// --- Defaults posture -------------------------------------------------------
check( false === $d['cta_enabled'], 'CTA is disabled by default (safety posture)' );
check( '' === $d['cta_url'], 'affiliate URL is empty by default' );
check( true === $d['email_enabled'], 'email capture is on by default' );

// --- URL enforcement --------------------------------------------------------
$r = Settings::normalize_settings( array( 'cta_url' => 'http://example.com/aff' ), $d );
check( '' === $r['value']['cta_url'], 'plain-http affiliate URL is cleared' );
check( count( $r['errors'] ) >= 1, 'plain-http URL is reported as an error' );

$r = Settings::normalize_settings( array( 'cta_url' => 'javascript:alert(1)' ), $d );
check( '' === $r['value']['cta_url'], 'javascript: URL is cleared' );

$r = Settings::normalize_settings( array( 'cta_url' => 'https://partner.example.com/visit/?id=123' ), $d );
check( 'https://partner.example.com/visit/?id=123' === $r['value']['cta_url'], 'https URL is kept intact' );
check( array() === $r['errors'], 'valid https URL produces no errors' );

// --- Label ------------------------------------------------------------------
$r = Settings::normalize_settings( array( 'cta_label' => "  Try it\non a demo  " ), $d );
check( 'Try it on a demo' === $r['value']['cta_label'], 'label is flattened and trimmed' );
$r = Settings::normalize_settings( array( 'cta_label' => '' ), $d );
check( $d['cta_label'] === $r['value']['cta_label'], 'empty label falls back to the default' );

// --- Sub-id param + sources -------------------------------------------------
$r = Settings::normalize_settings( array( 'sub_param' => 'Click ID!' ), $d );
check( 'clickid' === $r['value']['sub_param'], 'sub_param is sanitized to a key' );
$r = Settings::normalize_settings( array( 'sub_param' => '' ), $d );
check( 'clickid' === $r['value']['sub_param'], 'empty sub_param falls back to the default' );

$r = Settings::normalize_settings( array( 'sub_sources' => ' ClickID , utm_campaign, clickid ,,gclid ' ), $d );
check( array( 'clickid', 'utm_campaign', 'gclid' ) === $r['value']['sub_sources'], 'sub_sources parsed, sanitized and deduped' );
$r = Settings::normalize_settings( array( 'sub_sources' => 'a,b,c,d,e,f,g' ), $d );
check( 5 === count( $r['value']['sub_sources'] ), 'sub_sources capped at 5 entries' );
$r = Settings::normalize_settings( array( 'sub_sources' => ' , ,' ), $d );
check( $d['sub_sources'] === $r['value']['sub_sources'], 'empty sub_sources falls back to defaults' );

// --- Rate overrides ---------------------------------------------------------
$r = Settings::normalize_settings( array( 'rate_override_usdinr' => '88.1234' ), $d );
check( 88.1234 === $r['value']['rate_override_usdinr'], 'plausible USDINR override is kept' );
$r = Settings::normalize_settings( array( 'rate_override_usdinr' => '8.3' ), $d );
check( 0.0 === $r['value']['rate_override_usdinr'], 'implausible USDINR override is cleared' );
check( count( $r['errors'] ) >= 1, 'implausible override is reported' );
$r = Settings::normalize_settings( array( 'rate_override_usdjpy' => '' ), $d );
check( 0.0 === $r['value']['rate_override_usdjpy'], 'blank override means automatic' );

// --- CTA gating (kill-switch precedence) ------------------------------------
$on = array_merge(
	$d,
	array(
		'cta_enabled' => true,
		'cta_url'     => 'https://partner.example.com/x',
		'cta_label'   => 'Demo',
	)
);

check( null !== Settings::cta_for( 'position_size', $on ), 'CTA renders when enabled + URL + tool toggle on' );
check(
	array(
		'url'      => 'https://partner.example.com/x',
		'label'    => 'Demo',
		'headline' => '',
		'brand'    => '',
		'logo'     => '',
	) === Settings::cta_for( 'pip_value', $on ),
	'cta_for returns url, label, headline, brand and logo'
);

$off = array_merge( $on, array( 'cta_enabled' => false ) );
check( null === Settings::cta_for( 'position_size', $off ), 'global kill-switch beats everything' );

$no_url = array_merge( $on, array( 'cta_url' => '' ) );
check( null === Settings::cta_for( 'position_size', $no_url ), 'no URL → no CTA even when enabled' );

$tool_off = array_merge( $on, array( 'cta_sessions' => false ) );
check( null === Settings::cta_for( 'sessions', $tool_off ), 'per-tool toggle disables just that tool' );
check( null !== Settings::cta_for( 'pip_value', $tool_off ), 'other tools keep their CTA' );

check( null === Settings::cta_for( 'unknown_tool', $on ), 'unknown tool never gets a CTA' );
check( null !== Settings::cta_for( 'profit_loss', $on ), 'profit_loss is a CTA-capable tool' );
$pl_off = array_merge( $on, array( 'cta_profit_loss' => false ) );
check( null === Settings::cta_for( 'profit_loss', $pl_off ), 'profit_loss toggle disables its CTA' );

// --- Long labels become headlines (the button-overflow fix) -----------------
$short = Settings::cta_for( 'pip_value', $on );
check( 'Demo' === $short['label'] && '' === $short['headline'], 'a short label stays on the button' );

$long_text = 'Claim a $30 no-deposit trading bonus by opening and verifying a new real account on XM';
$long      = Settings::cta_for( 'pip_value', array_merge( $on, array( 'cta_label' => $long_text ) ) );
check( $long_text === $long['headline'], 'a long label becomes the headline' );
check( 'Open a free account' === $long['label'], 'the button falls back to a short action' );
check( mb_strlen( $long['label'] ) <= Settings::LABEL_MAX, 'the button label never exceeds the button budget' );

$edge = Settings::cta_for( 'pip_value', array_merge( $on, array( 'cta_label' => str_repeat( 'a', Settings::LABEL_MAX ) ) ) );
check( '' === $edge['headline'], 'a label exactly at the limit still stays on the button' );

// --- Brand + logo -----------------------------------------------------------
check( '' === $d['cta_brand'] && '' === $d['cta_logo_url'], 'brand and logo are empty by default' );
$r = Settings::normalize_settings( array( 'cta_logo_url' => 'https://cdn.example/xm-logo.svg' ), $d );
check( 'https://cdn.example/xm-logo.svg' === $r['value']['cta_logo_url'], 'https logo URL is kept' );
$r = Settings::normalize_settings( array( 'cta_logo_url' => 'http://cdn.example/xm-logo.svg' ), $d );
check( '' === $r['value']['cta_logo_url'], 'plain-http logo URL is cleared' );
check( count( $r['errors'] ) >= 1, 'plain-http logo URL is reported' );
$r = Settings::normalize_settings( array( 'cta_brand' => '  <b>XM</b>  ' ), $d );
check( 'XM' === $r['value']['cta_brand'], 'brand name is sanitized and trimmed' );

// --- Ad slots ---------------------------------------------------------------
check( false === $d['ads_enabled'], 'ads are disabled by default' );
$r = Settings::normalize_settings( array( 'ads_enabled' => '1', 'ad_code_desktop' => '  <iframe src="https://x.example/468x60"></iframe>  ' ), $d );
check( true === $r['value']['ads_enabled'], 'ads_enabled maps to true' );
check( '<iframe src="https://x.example/468x60"></iframe>' === $r['value']['ad_code_desktop'], 'ad code stored raw, only trimmed' );
check( '' === $r['value']['ad_code_mobile'], 'missing ad code stays empty' );
$r = Settings::normalize_settings( array( 'ad_code_mobile' => str_repeat( 'x', 10001 ) ), $d );
check( '' === $r['value']['ad_code_mobile'], 'oversized ad code is cleared' );
check( count( $r['errors'] ) >= 1, 'oversized ad code is reported' );

// --- Top (600×90) ad slot ---------------------------------------------------
check( '' === $d['ad_code_top'] && '' === $d['ad_code_top_mobile'], 'top banner codes are empty by default' );
$r = Settings::normalize_settings( array( 'ad_code_top' => '  <iframe src="https://x.example/600x90"></iframe>  ' ), $d );
check( '<iframe src="https://x.example/600x90"></iframe>' === $r['value']['ad_code_top'], 'top banner code stored raw, only trimmed' );
check( '' === $r['value']['ad_code_top_mobile'], 'missing top mobile code stays empty' );
$r = Settings::normalize_settings( array( 'ad_code_top' => str_repeat( 'x', 10001 ) ), $d );
check( '' === $r['value']['ad_code_top'], 'oversized top banner code is cleared' );

// --- Propeller pixel --------------------------------------------------------
check( '' === $d['propeller_partner'], 'Propeller pixel is off by default' );
$hash = str_repeat( 'ca91f99d', 8 );
$r    = Settings::normalize_settings( array( 'propeller_partner' => '  ' . strtoupper( $hash ) . '  ' ), $d );
check( $hash === $r['value']['propeller_partner'], 'valid 64-hex partner id is kept (lowercased, trimmed)' );
$r = Settings::normalize_settings( array( 'propeller_partner' => 'not-a-hash' ), $d );
check( '' === $r['value']['propeller_partner'], 'invalid partner id is cleared' );
check( count( $r['errors'] ) >= 1, 'invalid partner id is reported' );
$r = Settings::normalize_settings( array( 'propeller_partner' => '<script>' . $hash . '</script>' ), $d );
check( '' === $r['value']['propeller_partner'], 'markup around the id never survives' );

// --- Flags ------------------------------------------------------------------
$r = Settings::normalize_settings( array( 'cta_enabled' => '1', 'email_enabled' => '' ), $d );
check( true === $r['value']['cta_enabled'], 'checkbox "1" maps to true' );
check( false === $r['value']['email_enabled'], 'missing/empty checkbox maps to false' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
