<?php
/**
 * Structural checks over the Learn content pipeline.
 *
 * The importer is idempotent but not forgiving: a missing `<!-- PT -->`, a
 * quiz question with two correct answers, or a `glossary:` slug with no file
 * behind it all import cleanly and only show up as a broken page. This walks
 * every content/learn/*.md and fails the suite before that reaches a deploy.
 *
 * It also keeps learn-plan.csv honest — a row marked `written` with no file
 * (or the reverse) means the editorial spine is lying about what exists.
 *
 *   php wp-content/plugins/hti-engine/tests/test-learn-content.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

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

$content_dir = __DIR__ . '/../content';
$learn_dir   = $content_dir . '/learn';
$plan_file   = $content_dir . '/learn-plan.csv';

$files = glob( $learn_dir . '/*.md' );
sort( $files );

/**
 * Split a chapter file into frontmatter and the two language bodies.
 *
 * @param string $raw File contents.
 * @return array{meta:array<string,string>,en:string,pt:string}
 */
function parse_chapter( string $raw ): array {
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

	$en  = '';
	$pt  = '';
	$pos = strpos( $body, '<!-- PT -->' );
	if ( false !== $pos ) {
		$en = substr( $body, 0, $pos );
		$pt = substr( $body, $pos );
	} else {
		$en = $body;
	}

	return array(
		'meta' => $meta,
		'en'   => str_replace( '<!-- EN -->', '', $en ),
		'pt'   => str_replace( '<!-- PT -->', '', $pt ),
	);
}

/**
 * Validate the quiz block of one language body.
 *
 * @param string $body    Language body.
 * @param string $heading Quiz heading for this language.
 * @return string Empty when valid, otherwise the reason.
 */
function quiz_problem( string $body, string $heading ): string {
	$pos = strpos( $body, "## {$heading}" );
	if ( false === $pos ) {
		return ''; // The quiz is optional.
	}

	$quiz  = substr( $body, $pos + strlen( "## {$heading}" ) );
	$lines = explode( "\n", trim( $quiz ) );

	$questions = 0;
	$options   = 0;
	$correct   = 0;

	$flush = static function () use ( &$questions, &$options, &$correct ): string {
		if ( 0 === $questions ) {
			return '';
		}
		if ( $options < 2 ) {
			return "pergunta {$questions} tem menos de 2 opções";
		}
		if ( 1 !== $correct ) {
			return "pergunta {$questions} tem {$correct} respostas certas";
		}
		return '';
	};

	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( preg_match( '/^\d+\.\s+\S/', $line ) ) {
			$problem = $flush();
			if ( '' !== $problem ) {
				return $problem;
			}
			++$questions;
			$options = 0;
			$correct = 0;
			continue;
		}
		if ( preg_match( '/^- \[( |x)\]\s+\S/', $line, $m ) ) {
			++$options;
			if ( 'x' === $m[1] ) {
				++$correct;
			}
		}
	}

	$problem = $flush();
	if ( '' !== $problem ) {
		return $problem;
	}

	return $questions > 0 ? '' : 'bloco de quiz sem perguntas';
}

echo "\n=== Ficheiros de capítulo ===\n";
check( count( $files ) > 0, 'existem capítulos em content/learn' );

$required = array( 'slug', 'slug_pt', 'module', 'order', 'topic', 'status', 'title_en', 'title_pt', 'excerpt_en', 'excerpt_pt' );
$seen     = array();

foreach ( $files as $file ) {
	$name    = basename( $file, '.md' );
	$chapter = parse_chapter( (string) file_get_contents( $file ) );
	$meta    = $chapter['meta'];

	$missing = array();
	foreach ( $required as $key ) {
		if ( ! isset( $meta[ $key ] ) || '' === $meta[ $key ] ) {
			$missing[] = $key;
		}
	}
	check( ! $missing, "{$name}: frontmatter completo" . ( $missing ? ' (falta ' . implode( ', ', $missing ) . ')' : '' ) );

	check( ( $meta['slug'] ?? '' ) === $name, "{$name}: o slug do frontmatter bate com o nome do ficheiro" );
	check( '' !== trim( $chapter['pt'] ), "{$name}: tem secção PT" );
	check( str_contains( $chapter['en'], "\n> " ) || str_starts_with( ltrim( $chapter['en'] ), '> ' ), "{$name}: EN tem TL;DR" );
	check( str_contains( $chapter['pt'], "\n> " ), "{$name}: PT tem TL;DR" );
	check( str_contains( $chapter['en'], '## Key takeaways' ), "{$name}: EN tem Key takeaways" );
	check( str_contains( $chapter['pt'], '## Pontos-chave' ), "{$name}: PT tem Pontos-chave" );

	$en_problem = quiz_problem( $chapter['en'], 'Quiz' );
	$pt_problem = quiz_problem( $chapter['pt'], 'Questionário' );
	check( '' === $en_problem, "{$name}: quiz EN válido" . ( '' !== $en_problem ? " ({$en_problem})" : '' ) );
	check( '' === $pt_problem, "{$name}: quiz PT válido" . ( '' !== $pt_problem ? " ({$pt_problem})" : '' ) );

	// One H1 is the post title; the body must not introduce another.
	check( ! preg_match( '/^# \S/m', $chapter['en'] . $chapter['pt'] ), "{$name}: sem H1 no corpo" );

	$seen[ $name ] = $meta;
}

echo "\n=== Slugs de glossário referidos existem ===\n";
foreach ( $seen as $name => $meta ) {
	$slugs = array_filter( array_map( 'trim', preg_split( '/[,;]/', $meta['glossary'] ?? '' ) ?: array() ) );
	$gone  = array();
	foreach ( $slugs as $slug ) {
		if ( ! is_file( $content_dir . "/glossary/{$slug}.md" ) ) {
			$gone[] = $slug;
		}
	}
	if ( $slugs ) {
		check( ! $gone, "{$name}: glossário" . ( $gone ? ' — sem ficheiro: ' . implode( ', ', $gone ) : '' ) );
	}
}

echo "\n=== Tokens [glossary:…] inline apontam para termos reais ===\n";
foreach ( $files as $file ) {
	$name = basename( $file, '.md' );
	$raw  = (string) file_get_contents( $file );
	preg_match_all( '/\[glossary:([a-z0-9-]+)\|/', $raw, $m );
	$gone = array();
	foreach ( array_unique( $m[1] ) as $slug ) {
		if ( ! is_file( $content_dir . "/glossary/{$slug}.md" ) ) {
			$gone[] = $slug;
		}
	}
	if ( $m[1] ) {
		check( ! $gone, "{$name}: tokens inline" . ( $gone ? ' — sem ficheiro: ' . implode( ', ', $gone ) : '' ) );
	}
}

echo "\n=== prev/next formam uma cadeia válida ===\n";
foreach ( $seen as $name => $meta ) {
	foreach ( array( 'prev', 'next' ) as $key ) {
		$target = $meta[ $key ] ?? '';
		if ( '' === $target ) {
			continue;
		}
		check(
			is_file( $learn_dir . "/{$target}.md" ) || isset( $seen[ $target ] ) || plan_has( $plan_file, $target ),
			"{$name}: {$key} → {$target} existe no plano"
		);
	}
}

/**
 * Whether the editorial plan lists a slug.
 *
 * @param string $plan_file Path to learn-plan.csv.
 * @param string $slug      Chapter slug.
 */
function plan_has( string $plan_file, string $slug ): bool {
	static $slugs = null;
	if ( null === $slugs ) {
		$slugs  = array();
		$handle = fopen( $plan_file, 'r' );
		if ( $handle ) {
			$header = fgetcsv( $handle );
			$index  = is_array( $header ) ? array_search( 'slug', $header, true ) : false;
			while ( false !== $index && ( $row = fgetcsv( $handle ) ) ) {
				if ( isset( $row[ $index ] ) ) {
					$slugs[] = $row[ $index ];
				}
			}
			fclose( $handle );
		}
	}
	return in_array( $slug, $slugs, true );
}

echo "\n=== learn-plan.csv está sincronizado com os ficheiros ===\n";
$handle = fopen( $plan_file, 'r' );
check( false !== $handle, 'learn-plan.csv é legível' );
if ( $handle ) {
	$header = fgetcsv( $handle );
	$cols   = is_array( $header ) ? array_flip( $header ) : array();
	$drift  = array();
	while ( ( $row = fgetcsv( $handle ) ) ) {
		if ( ! isset( $row[ $cols['slug'] ], $row[ $cols['status'] ] ) ) {
			continue;
		}
		$slug    = $row[ $cols['slug'] ];
		$written = 'written' === $row[ $cols['status'] ];
		$exists  = is_file( $learn_dir . "/{$slug}.md" );
		if ( $written !== $exists ) {
			$drift[] = $slug . ( $written ? ' (written sem .md)' : ' (.md sem written)' );
		}
	}
	fclose( $handle );
	check( ! $drift, 'estado no plano bate com os ficheiros' . ( $drift ? ': ' . implode( ' · ', $drift ) : '' ) );
}

echo "\n=== {$passes} passed, {$failures} failed ===\n";

exit( $failures > 0 ? 1 : 0 );
