<?php
/**
 * PHP that escaped into the page.
 *
 *   php wp-content/plugins/hti-forex/tests/test-templates.php
 *
 * These files switch between PHP and HTML on almost every line, and a
 * statement written one line after a `?>` is not an error — it is text, and
 * the page prints it. `php -l` is happy, every test passes, and the admin
 * screen shows a line of source code where a table should be.
 *
 * That is exactly what happened: `self::render_log( $log );` was printed to
 * the settings screen for a whole release, so the broadcast history never
 * appeared and its absence was read as "this version doesn't have it".
 *
 * The tokenizer can see the difference between code and text, which is the
 * one thing a linter cannot do here.
 *
 * @package HTI_Forex
 */

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
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		++$failures;
		echo "  \033[31m✗\033[0m {$label}\n";
	}
}

/**
 * Lines of literal page output that read like PHP statements.
 *
 * @param string $file Path.
 * @return array<int,string> Offending lines.
 */
function code_in_output( string $file ): array {
	$found = array();

	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
		if ( ! is_array( $token ) || T_INLINE_HTML !== $token[0] ) {
			continue;
		}

		foreach ( explode( "\n", $token[1] ) as $i => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// A statement, not markup: ends in a semicolon and calls something.
			if ( preg_match( '/^(self::|static::|\$[a-z_]+\s*(=|->)|[A-Z][A-Za-z_]*::)/', $line ) && str_ends_with( $line, ';' ) ) {
				$found[] = ( $token[2] + $i ) . ': ' . $line;
			}
		}
	}

	return $found;
}

echo "\n=== Nenhum PHP impresso como texto ===\n";

$files = glob( __DIR__ . '/../includes/*.php' );
check( count( $files ) > 10, 'os ficheiros do plugin foram lidos (' . count( $files ) . ')' );

$offenders = array();
foreach ( $files as $file ) {
	foreach ( code_in_output( $file ) as $line ) {
		$offenders[] = basename( $file ) . ':' . $line;
	}
}

check(
	array() === $offenders,
	'nenhuma chamada PHP fica fora das tags' . ( $offenders ? " (\n    " . implode( "\n    ", $offenders ) . "\n  )" : '' )
);

// The check has to be able to fail, or it is decoration.
$fixture = tempnam( sys_get_temp_dir(), 'hti' ) . '.php';
file_put_contents( $fixture, "<?php\nfunction f() {\n\t?>\n\t<p>ok</p>\n\tself::render_log( \$log );\n\t<?php\n}\n" );
$caught = code_in_output( $fixture );
unlink( $fixture );

check( array() !== $caught, 'e o teste apanha o caso real que passou despercebido' );
check( str_contains( $caught[0] ?? '', 'render_log' ), 'nomeando a linha' );

echo "\n=== {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
