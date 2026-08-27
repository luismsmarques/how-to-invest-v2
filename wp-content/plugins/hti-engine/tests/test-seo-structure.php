<?php
/**
 * SEO structure audit over the repo-managed content.
 *
 * Enforces the internal-linking and meta conventions from the seo-content
 * skill so they cannot silently regress: every inline token must resolve to
 * a real target, every glossary term must link to its Learn pillar, and any
 * curated meta title/description must fit the SERP limits (≤60 / ≤160
 * chars). Pure file walking — no WordPress.
 *
 * STRICT mode (flipped on once the content mesh lands): every learn chapter
 * must carry seo_title_en/pt and at least MIN_LINKS inline tokens in its EN
 * body.
 *
 *   php wp-content/plugins/hti-engine/tests/test-seo-structure.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

// Flipped to true by the content pass that adds meta titles + the link mesh.
const HTI_SEO_STRICT = false;
const HTI_MIN_LINKS  = 3;

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
 * Split a content file into frontmatter and the two language bodies.
 *
 * @param string $raw File contents.
 * @return array{meta:array<string,string>,en:string,pt:string}
 */
function parse_md( string $raw ): array {
	$meta = array();
	$body = $raw;

	if ( preg_match( '/^---\n(.*?)\n---\n(.*)$/s', $raw, $m ) ) {
		foreach ( explode( "\n", $m[1] ) as $line ) {
			if ( preg_match( '/^([a-z_]+):\s*(.*)$/', $line, $kv ) ) {
				$meta[ $kv[1] ] = trim( $kv[2], " \"'" );
			}
		}
		$body = $m[2];
	}

	$en = $body;
	$pt = '';
	if ( false !== strpos( $body, '<!-- PT -->' ) ) {
		$parts = explode( '<!-- PT -->', $body, 2 );
		$en    = $parts[0];
		$pt    = $parts[1] ?? '';
	}

	return array(
		'meta' => $meta,
		'en'   => trim( str_replace( '<!-- EN -->', '', $en ) ),
		'pt'   => trim( $pt ),
	);
}

/**
 * All [glossary:slug|…] / [learn:slug|…] tokens in a body.
 *
 * @param string $body Body text.
 * @return array<int,array{0:string,1:string}> Type + slug pairs.
 */
function tokens_in( string $body ): array {
	preg_match_all( '/\[(glossary|learn):([a-z0-9-]+)\|/', $body, $m, PREG_SET_ORDER );
	return array_map(
		static fn( array $t ): array => array( $t[1], $t[2] ),
		$m
	);
}

$content_dir = __DIR__ . '/../content';
$learn       = array();
$glossary    = array();

foreach ( glob( $content_dir . '/learn/*.md' ) ?: array() as $path ) {
	$learn[ basename( $path ) ] = parse_md( (string) file_get_contents( $path ) );
}
foreach ( glob( $content_dir . '/glossary/*.md' ) ?: array() as $path ) {
	$glossary[ basename( $path ) ] = parse_md( (string) file_get_contents( $path ) );
}

echo "test-seo-structure: " . count( $learn ) . " chapters, " . count( $glossary ) . " terms\n";
check( count( $learn ) >= 24, 'all learn chapters present' );
check( count( $glossary ) >= 54, 'all glossary terms present' );

// Every token target must exist as a real .md slug (EN slugs are canonical).
$learn_slugs = array();
$gloss_slugs = array();
foreach ( $learn as $c ) {
	$learn_slugs[ (string) ( $c['meta']['slug'] ?? '' ) ] = true;
}
foreach ( $glossary as $t ) {
	$gloss_slugs[ (string) ( $t['meta']['slug'] ?? '' ) ] = true;
}

$dangling = array();
foreach ( array_merge( $learn, $glossary ) as $file => $piece ) {
	foreach ( tokens_in( $piece['en'] . "\n" . $piece['pt'] ) as $tok ) {
		$known = 'learn' === $tok[0] ? isset( $learn_slugs[ $tok[1] ] ) : isset( $gloss_slugs[ $tok[1] ] );
		if ( ! $known ) {
			$dangling[] = "{$file} → [{$tok[0]}:{$tok[1]}]";
		}
	}
}
check( array() === $dangling, 'no dangling link tokens' . ( $dangling ? ' (' . implode( ', ', array_slice( $dangling, 0, 5 ) ) . ')' : '' ) );

// Hub-and-spoke: every glossary term links to a Learn pillar (EN section).
$unlinked = array();
foreach ( $glossary as $file => $t ) {
	$has_learn = false;
	foreach ( tokens_in( $t['en'] ) as $tok ) {
		if ( 'learn' === $tok[0] ) {
			$has_learn = true;
			break;
		}
	}
	if ( ! $has_learn ) {
		$unlinked[] = $file;
	}
}
check( array() === $unlinked, 'every glossary term links to its Learn pillar' . ( $unlinked ? ' (' . implode( ', ', array_slice( $unlinked, 0, 5 ) ) . ')' : '' ) );

// Curated meta must fit the SERP limits whenever present.
$too_long = array();
foreach ( array_merge( $learn, $glossary ) as $file => $piece ) {
	foreach ( array( 'seo_title_en', 'seo_title_pt' ) as $key ) {
		$v = (string) ( $piece['meta'][ $key ] ?? '' );
		if ( '' !== $v && mb_strlen( $v ) > 60 ) {
			$too_long[] = "{$file} {$key} (" . mb_strlen( $v ) . ')';
		}
	}
	foreach ( array( 'seo_desc_en', 'seo_desc_pt' ) as $key ) {
		$v = (string) ( $piece['meta'][ $key ] ?? '' );
		if ( '' !== $v && mb_strlen( $v ) > 160 ) {
			$too_long[] = "{$file} {$key} (" . mb_strlen( $v ) . ')';
		}
	}
}
check( array() === $too_long, 'meta titles ≤60 and descriptions ≤160 chars' . ( $too_long ? ' (' . implode( ', ', array_slice( $too_long, 0, 5 ) ) . ')' : '' ) );

// SEO meta pairs: a piece declaring one language's title must declare both.
$half_pairs = array();
foreach ( array_merge( $learn, $glossary ) as $file => $piece ) {
	$has_en = '' !== (string) ( $piece['meta']['seo_title_en'] ?? '' );
	$has_pt = '' !== (string) ( $piece['meta']['seo_title_pt'] ?? '' );
	if ( $has_en !== $has_pt ) {
		$half_pairs[] = $file;
	}
}
check( array() === $half_pairs, 'seo_title declared for both languages or neither' . ( $half_pairs ? ' (' . implode( ', ', array_slice( $half_pairs, 0, 5 ) ) . ')' : '' ) );

if ( HTI_SEO_STRICT ) {
	// Every chapter carries curated meta titles.
	$missing_meta = array();
	foreach ( $learn as $file => $c ) {
		if ( '' === (string) ( $c['meta']['seo_title_en'] ?? '' ) || '' === (string) ( $c['meta']['seo_title_pt'] ?? '' ) ) {
			$missing_meta[] = $file;
		}
	}
	check( array() === $missing_meta, 'every learn chapter has seo_title_en/pt' . ( $missing_meta ? ' (' . implode( ', ', array_slice( $missing_meta, 0, 5 ) ) . ')' : '' ) );

	// Every chapter meets the inline-link minimum in its EN body ([page:]
	// tokens — e.g. the broker-comparison further-reading link — count too).
	$thin = array();
	foreach ( $learn as $file => $c ) {
		$n = count( tokens_in( $c['en'] ) ) + preg_match_all( '/\[page:[a-z0-9\/-]+\|/', $c['en'] );
		if ( $n < HTI_MIN_LINKS ) {
			$thin[] = "{$file} ({$n})";
		}
	}
	check( array() === $thin, 'every learn chapter has ≥' . HTI_MIN_LINKS . ' inline links (EN)' . ( $thin ? ' (' . implode( ', ', array_slice( $thin, 0, 5 ) ) . ')' : '' ) );
}

echo "\n" . ( $failures ? "\033[31m" : "\033[32m" ) . "{$passes} passed, {$failures} failed\033[0m\n";
exit( $failures ? 1 : 0 );
