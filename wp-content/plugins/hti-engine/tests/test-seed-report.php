<?php
/**
 * Tests for the seed report's Tools-migration severity.
 *
 * The wp-admin "Seed content" button runs the same migration the CLI does, but
 * until now it discarded the log: a run that skipped an edited hub, or one where
 * wp_unique_post_slug() renamed a page on re-parent — leaving it a 404 behind
 * its own 301 — reported plain green success. This asserts the one decision that
 * makes the notice look different: whether the log contains anything a human
 * has to act on.
 *
 *   php wp-content/plugins/hti-engine/tests/test-seed-report.php
 *
 * @package HTI_Engine
 */

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/../includes/class-tools-content.php';
require_once __DIR__ . '/../includes/class-seeder.php';

use HTI\Engine\Seeder;

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

echo "\n=== Uma corrida limpa é sucesso ===\n";
check( 'success' === Seeder::log_severity( array() ), 'log vazio (nada a fazer)' );
check(
	'success' === Seeder::log_severity(
		array(
			'moved compound-interest-calculator under 10.',
			'moved compound-interest-calculator (PT) under 11.',
			'added the questionnaire CTA to inflation-calculator (PT).',
			'replaced the body of hub (EN) with [hti_tools_hub].',
		)
	),
	'só linhas de movimento e criação'
);

echo "\n=== O que exige atenção fica em aviso ===\n";
check(
	'warning' === Seeder::log_severity(
		array(
			'moved compound-interest-calculator under 10.',
			'WARNING inflation-calculator — slug changed on re-parent: a → b.',
		)
	),
	'um slug reescrito ao re-parentar'
);
check(
	'warning' === Seeder::log_severity(
		array( 'SKIPPED hub (EN) — the page looks edited (blocks: paragraph, heading).' )
	),
	'um hub editado à mão, deixado como está'
);
check(
	'warning' === Seeder::log_severity(
		array( '  WARNING com espaços à frente.' )
	),
	'a indentação não esconde o aviso'
);

echo "\n=== Nem tudo o que menciona um problema é um aviso ===\n";
check(
	'success' === Seeder::log_severity(
		array( 'moved a page that once had a WARNING in its title.' )
	),
	'só conta o início da linha, não o texto todo'
);
check(
	'success' === Seeder::log_severity( array( 'skip allocation-visualizer — page not found.' ) ),
	'"skip" em minúsculas é informativo, não bloqueante'
);

echo "\n=== {$passes} passed, {$failures} failed ===\n";

exit( $failures > 0 ? 1 : 0 );
