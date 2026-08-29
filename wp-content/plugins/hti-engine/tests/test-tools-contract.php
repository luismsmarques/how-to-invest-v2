<?php
/**
 * Contract test: the calculators' PHP config and their JavaScript must agree.
 *
 * class-tools.php renders `[data-field="x"]` inputs and `[data-out="y"]` slots
 * from Tools::config(); tools.js reads exactly those identifiers. Nothing
 * enforces the match at runtime — rename one side and the calculator silently
 * stops updating, with no error anywhere. That is the failure a redesign of the
 * markup invites, so it is asserted here instead of hoped for.
 *
 * Deliberately parses the real tools.js rather than mocking it: the point is to
 * catch a drift between two files, and a mock would drift with them.
 *
 *   php wp-content/plugins/hti-engine/tests/test-tools-contract.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'HTI_ENGINE_URL' ) ) {
	define( 'HTI_ENGINE_URL', 'https://example.test/' );
}

require_once __DIR__ . '/../includes/class-tools-content.php';
require_once __DIR__ . '/../includes/class-tools.php';

use HTI\Engine\Tools;

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
 * Extract the balanced braces block starting at the first '{' at or after $from.
 *
 * @param string $src  Source.
 * @param int    $from Offset to start looking from.
 * @return string Block contents without the outer braces, or '' when unbalanced.
 */
function balanced_block( string $src, int $from ): string {
	$start = strpos( $src, '{', $from );
	if ( false === $start ) {
		return '';
	}
	$depth = 0;
	$len   = strlen( $src );
	for ( $i = $start; $i < $len; $i++ ) {
		if ( '{' === $src[ $i ] ) {
			++$depth;
		} elseif ( '}' === $src[ $i ] ) {
			--$depth;
			if ( 0 === $depth ) {
				return substr( $src, $start + 1, $i - $start - 1 );
			}
		}
	}
	return '';
}

$js = (string) file_get_contents( __DIR__ . '/../assets/js/tools.js' );
check( '' !== $js, 'tools.js é legível' );

$compute = balanced_block( $js, (int) strpos( $js, 'var COMPUTE =' ) );
check( '' !== $compute, 'o mapa COMPUTE foi extraído' );

// Split COMPUTE into one body per tool key.
$bodies = array();
$offset = 0;
while ( preg_match( '/([a-z_0-9]+)\s*:\s*function\s*\(\s*r\s*\)\s*/', $compute, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
	$key            = $m[1][0];
	$at             = (int) $m[0][1] + strlen( $m[0][0] );
	$bodies[ $key ] = balanced_block( $compute, $at );
	$offset         = $at + max( 1, strlen( $bodies[ $key ] ) );
}

$config = Tools::config();

echo "\n=== Cobertura ===\n";
check( array() !== $bodies, count( $bodies ) . ' ferramentas encontradas no COMPUTE' );
check(
	array() === array_diff( array_keys( $config ), array_keys( $bodies ) ),
	'toda a ferramenta em config() tem uma função no COMPUTE (em falta: '
		. ( implode( ', ', array_diff( array_keys( $config ), array_keys( $bodies ) ) ) ?: 'nenhuma' ) . ')'
);
check(
	array() === array_diff( array_keys( $bodies ), array_keys( $config ) ),
	'toda a função do COMPUTE tem entrada em config() (a mais: '
		. ( implode( ', ', array_diff( array_keys( $bodies ), array_keys( $config ) ) ) ?: 'nenhuma' ) . ')'
);

echo "\n=== Campos: [data-field] renderizado === field() lido ===\n";
foreach ( $config as $tool => $def ) {
	if ( ! isset( $bodies[ $tool ] ) ) {
		continue;
	}
	preg_match_all( '/field\(\s*r\s*,\s*\'([a-z_0-9]+)\'\s*\)/', $bodies[ $tool ], $m );
	$read      = array_values( array_unique( $m[1] ) );
	$rendered  = array_keys( (array) $def['fields'] );
	sort( $read );
	sort( $rendered );
	check(
		$read === $rendered,
		$tool . ' — lidos [' . implode( ', ', $read ) . '] vs renderizados [' . implode( ', ', $rendered ) . ']'
	);
}

echo "\n=== Saídas: [data-out] renderizado === chaves de out ===\n";
foreach ( $config as $tool => $def ) {
	if ( ! isset( $bodies[ $tool ] ) ) {
		continue;
	}
	$out_at = strpos( $bodies[ $tool ], 'out:' );
	$block  = false === $out_at ? '' : balanced_block( $bodies[ $tool ], (int) $out_at );

	// Top-level keys only — the out objects are flat, but guard anyway.
	$written = array();
	$depth   = 0;
	foreach ( preg_split( '/([{}])/', $block, -1, PREG_SPLIT_DELIM_CAPTURE ) as $chunk ) {
		if ( '{' === $chunk ) {
			++$depth;
			continue;
		}
		if ( '}' === $chunk ) {
			--$depth;
			continue;
		}
		if ( 0 !== $depth ) {
			continue;
		}
		preg_match_all( '/([a-z_0-9]+)\s*:/', $chunk, $km );
		$written = array_merge( $written, $km[1] );
	}
	$written  = array_values( array_unique( $written ) );
	$rendered = array_keys( (array) $def['outputs'] );
	sort( $written );
	sort( $rendered );
	check(
		$written === $rendered,
		$tool . ' — escritos [' . implode( ', ', $written ) . '] vs renderizados [' . implode( ', ', $rendered ) . ']'
	);
}

echo "\n=== Exatamente um resultado principal por ferramenta ===\n";
foreach ( $config as $tool => $def ) {
	$primary = 0;
	foreach ( (array) $def['outputs'] as $output ) {
		if ( ! empty( $output['primary'] ) ) {
			++$primary;
		}
	}
	check( 1 === $primary, $tool . ' tem um output primary (tem ' . $primary . ')' );
}

echo "\n=== Rótulos bilingues em todos os campos e saídas ===\n";
foreach ( $config as $tool => $def ) {
	$bad = array();
	foreach ( array( 'fields', 'outputs' ) as $group ) {
		foreach ( (array) $def[ $group ] as $key => $item ) {
			if ( '' === trim( (string) ( $item['en'] ?? '' ) ) || '' === trim( (string) ( $item['pt'] ?? '' ) ) ) {
				$bad[] = $group . '.' . $key;
			}
		}
	}
	check( array() === $bad, $tool . ' tem EN+PT em tudo (em falta: ' . ( $bad ? implode( ', ', $bad ) : 'nada' ) . ')' );
}

echo "\n=== {$passes} passed, {$failures} failed ===\n";

exit( $failures > 0 ? 1 : 0 );
