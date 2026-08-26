<?php
/**
 * Tests for the broker seed data (broker-affiliate skill compliance).
 *
 *   php wp-content/plugins/hti-engine/tests/test-broker-seeder.php
 *
 * Guards the curated launch records: complete bilingual entries, allowlisted
 * meta, https official URLs, and — critically — that every record ships with
 * NO affiliate URL and the deal inactive (deals are flipped manually in
 * wp-admin once signed and verified).
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

// The seeder builds block markup through esc_html at data-build time.
if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

require_once __DIR__ . '/../includes/class-broker-admin.php';
require_once __DIR__ . '/../includes/class-broker-seeder.php';

use HTI\Engine\Broker_Admin;
use HTI\Engine\Broker_Seeder;
use HTI\Engine\Engine;

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

$brokers   = Broker_Seeder::brokers();
$use_cases = Broker_Seeder::use_cases();

echo "\nBroker seed data\n";

check( 10 === count( $brokers ), 'exactly 10 launch brokers (' . count( $brokers ) . ')' );

$slugs = array_column( $brokers, 'slug' );
check( count( $slugs ) === count( array_unique( $slugs ) ), 'slugs are unique' );
check( ! in_array( 'freedom24', $slugs, true ) && ! in_array( 'webull', $slugs, true ), 'study-excluded brokers (Freedom24, Webull) are absent' );

$bad = array();
foreach ( $brokers as $b ) {
	$slug = (string) ( $b['slug'] ?? '?' );
	$meta = (array) ( $b['meta'] ?? array() );
	$pt   = (array) ( $b['pt'] ?? array() );

	foreach ( array( 'slug', 'title', 'excerpt', 'content', 'menu_order', 'use_cases', 'seo', 'pt', 'meta' ) as $key ) {
		if ( empty( $b[ $key ] ) ) {
			$bad[] = "{$slug}: missing {$key}";
		}
	}
	foreach ( array( 'title', 'excerpt', 'content', 'seo' ) as $key ) {
		if ( empty( $pt[ $key ] ) ) {
			$bad[] = "{$slug}: missing pt.{$key}";
		}
	}
	if ( ( $pt['content'] ?? '' ) === ( $b['content'] ?? '' ) ) {
		$bad[] = "{$slug}: PT content equals EN content";
	}

	// Compliance defaults: nothing monetized at seed time.
	if ( '' !== (string) ( $meta['affiliate_url'] ?? '' ) ) {
		$bad[] = "{$slug}: seeded with an affiliate URL";
	}
	if ( '1' === (string) ( $meta['affiliate_active'] ?? '' ) ) {
		$bad[] = "{$slug}: seeded with the deal active";
	}
	if ( ! str_starts_with( (string) ( $meta['official_url'] ?? '' ), 'https://' ) ) {
		$bad[] = "{$slug}: official_url is not https";
	}
	if ( '' === (string) ( $meta['regulator'] ?? '' ) ) {
		$bad[] = "{$slug}: regulator missing";
	}
	if ( ! in_array( (string) ( $meta['affiliate_network'] ?? '' ), Broker_Admin::NETWORKS, true ) ) {
		$bad[] = "{$slug}: unknown affiliate_network";
	}
	if ( ! in_array( (string) ( $meta['cfd'] ?? '' ), array( '', '1' ), true ) ) {
		$bad[] = "{$slug}: cfd must be '' or '1'";
	}

	foreach ( array_filter( explode( ',', (string) ( $meta['products'] ?? '' ) ) ) as $p ) {
		if ( ! in_array( $p, Broker_Admin::PRODUCTS, true ) ) {
			$bad[] = "{$slug}: unknown product {$p}";
		}
	}
	$classes = array_filter( explode( ',', (string) ( $meta['asset_classes'] ?? '' ) ) );
	if ( array() === $classes ) {
		$bad[] = "{$slug}: no asset_classes";
	}
	foreach ( $classes as $c ) {
		if ( ! in_array( $c, Engine::CLASSES, true ) ) {
			$bad[] = "{$slug}: unknown asset class {$c}";
		}
	}
	$fits = array_filter( explode( ',', (string) ( $meta['profile_fit'] ?? '' ) ) );
	if ( array() === $fits ) {
		$bad[] = "{$slug}: no profile_fit";
	}
	foreach ( $fits as $f ) {
		if ( ! in_array( $f, array( '1', '2', '3', '4', '5' ), true ) ) {
			$bad[] = "{$slug}: profile_fit out of range ({$f})";
		}
	}
	foreach ( (array) $b['use_cases'] as $uc ) {
		if ( ! isset( $use_cases[ $uc ] ) ) {
			$bad[] = "{$slug}: unknown use case {$uc}";
		}
	}
	$rating = (string) ( $meta['rating'] ?? '' );
	if ( '' !== $rating && ( ! is_numeric( $rating ) || (float) $rating < 0 || (float) $rating > 5 ) ) {
		$bad[] = "{$slug}: rating out of range";
	}
	// Bilingual pairing of the language-dependent notes.
	if ( ( '' === (string) ( $meta['fees_note'] ?? '' ) ) !== ( '' === (string) ( $meta['fees_note_pt'] ?? '' ) ) ) {
		$bad[] = "{$slug}: fees_note EN/PT pair incomplete";
	}
	if ( ( '' === (string) ( $meta['interest_rate_note'] ?? '' ) ) !== ( '' === (string) ( $meta['interest_rate_note_pt'] ?? '' ) ) ) {
		$bad[] = "{$slug}: interest_rate_note EN/PT pair incomplete";
	}
}
check( array() === $bad, 'all records complete and allowlisted (' . implode( ' | ', $bad ) . ')' );

echo "\nUse cases\n";
check( 5 === count( $use_cases ), 'five comparison categories' );
$uc_bad = array();
foreach ( $use_cases as $slug => $names ) {
	if ( empty( $names['en'] ) || empty( $names['pt'] ) ) {
		$uc_bad[] = $slug;
	}
}
check( array() === $uc_bad, 'every use case has EN + PT names (' . implode( ',', $uc_bad ) . ')' );

echo "\nPT slugs\n";
check( 'xtb-analise' === Broker_Seeder::pt_slug( 'xtb' ), 'pt_slug appends -analise' );
check( 'trading-212-analise' === Broker_Seeder::pt_slug( 'trading-212' ), 'pt_slug keeps the brand slug' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
