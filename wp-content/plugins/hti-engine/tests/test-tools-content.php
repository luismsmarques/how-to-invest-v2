<?php
/**
 * Tests for the tools content table (Tools_Content).
 *
 * This table is the single source for the calculators' slugs, titles, intros
 * and hub copy, consumed by the seeder, the redirects and the schema. Two
 * things must never rot: EN+PT parity (bilingual is a project invariant, and
 * the forex section's English-only exemption does not extend here) and slug
 * hygiene (a PT slug that is not sanitize_title-shaped silently becomes a
 * different URL than the 301 points at).
 *
 *   php wp-content/plugins/hti-engine/tests/test-tools-content.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/../includes/class-tools-content.php';

use HTI\Engine\Tools_Content;

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

$tools = Tools_Content::tools();

echo "\n=== Forma da tabela ===\n";
check( 8 === count( $tools ), 'oito calculadoras (tem ' . count( $tools ) . ')' );
check( Tools_Content::slugs() === array_keys( $tools ), 'slugs() devolve as chaves por ordem' );
check( 'tools' === Tools_Content::HUB_SLUG, 'o slug do hub EN é tools' );
check( 'ferramentas' === Tools_Content::HUB_SLUG_PT, 'o slug do hub PT é ferramentas' );

echo "\n=== Cada entrada está completa nas duas línguas ===\n";
$required = array( 'name', 'pt_slug', 'tier', 'icon', 'title_en', 'title_pt', 'intro_en', 'intro_pt', 'card_en', 'card_pt' );
foreach ( $tools as $slug => $tool ) {
	$missing = array();
	foreach ( $required as $key ) {
		if ( ! isset( $tool[ $key ] ) || '' === trim( (string) $tool[ $key ] ) ) {
			$missing[] = $key;
		}
	}
	check( array() === $missing, $slug . ' tem todos os campos (em falta: ' . ( $missing ? implode( ', ', $missing ) : 'nenhum' ) . ')' );
}

echo "\n=== Paridade EN/PT: nenhuma língua reutiliza o texto da outra ===\n";
foreach ( $tools as $slug => $tool ) {
	check(
		$tool['title_en'] !== $tool['title_pt'] && $tool['intro_en'] !== $tool['intro_pt'] && $tool['card_en'] !== $tool['card_pt'],
		$slug . ' foi mesmo traduzido, não copiado'
	);
}

echo "\n=== Slugs ===\n";
$pt_slugs = Tools_Content::pt_slugs();
check( count( $pt_slugs ) === count( $tools ), 'pt_slugs() cobre todas as ferramentas' );
check( count( array_unique( $pt_slugs ) ) === count( $pt_slugs ), 'os slugs PT são únicos' );
check( count( array_unique( array_keys( $tools ) ) ) === count( $tools ), 'os slugs EN são únicos' );

foreach ( $tools as $slug => $tool ) {
	$pt_slug = (string) $tool['pt_slug'];
	check(
		(bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ),
		'slug EN em minúsculas e hífenes: ' . $slug
	);
	check(
		(bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $pt_slug ),
		'slug PT sem acentos nem maiúsculas: ' . $pt_slug
	);
	check(
		$slug !== $pt_slug,
		'o slug PT difere do EN: ' . $slug
	);
}

echo "\n=== Caminhos hierárquicos ===\n";
foreach ( Tools_Content::slugs() as $slug ) {
	check(
		'tools/' . $slug === Tools_Content::path( $slug ),
		'path() põe ' . $slug . ' sob o hub'
	);
}

echo "\n=== Camadas do hub ===\n";
$tiers = array_count_values( array_column( $tools, 'tier' ) );
check( isset( $tiers['core'] ) && 3 === $tiers['core'], 'três cartões grandes (tem ' . ( $tiers['core'] ?? 0 ) . ')' );
check( isset( $tiers['more'] ) && 5 === $tiers['more'], 'cinco minicards (tem ' . ( $tiers['more'] ?? 0 ) . ')' );
check(
	array() === array_diff( array_keys( $tiers ), array( 'core', 'more' ) ),
	'nenhuma camada desconhecida'
);

echo "\n=== Nomes de shortcode ===\n";
$names = array_column( $tools, 'name' );
check( count( array_unique( $names ) ) === count( $names ), 'cada ferramenta tem um name distinto' );
foreach ( $names as $name ) {
	check( (bool) preg_match( '/^[a-z0-9_]+$/', $name ), 'name utilizável em [hti_tool name="…"]: ' . $name );
}

echo "\n=== {$passes} passed, {$failures} failed ===\n";

exit( $failures > 0 ? 1 : 0 );
