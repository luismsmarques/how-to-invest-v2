<?php
/**
 * Tests for Logger::format_fatal (the fatal-watch formatter).
 *
 * Out-of-memory and the execution limit kill PHP uncatchably: no Throwable,
 * no log entry, only WordPress's anonymous critical-error page. The watch
 * records those deaths at shutdown; this formatter decides what counts as
 * fatal and what the recorded line says.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-logger.php';

use HTI\RssAI\Logger;

rssai_ok( null === Logger::format_fatal( null, 'Group now' ), 'no last error → nothing to record' );

$warning = array(
	'type'    => E_WARNING,
	'message' => 'Undefined array key',
	'file'    => '/srv/wp-content/plugins/x/y.php',
	'line'    => 10,
);
rssai_ok( null === Logger::format_fatal( $warning, 'Group now' ), 'a warning is not a death — not recorded' );

$oom = array(
	'type'    => E_ERROR,
	'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 65536 bytes)',
	'file'    => '/home/site/wp-content/plugins/hti-rss-ai/includes/class-grouping.php',
	'line'    => 123,
);
$msg = Logger::format_fatal( $oom, 'Group now' );
rssai_ok( is_string( $msg ), 'an E_ERROR (OOM) is recorded' );
rssai_ok( str_contains( (string) $msg, 'Group now' ), 'the record names what was running' );
rssai_ok( str_contains( (string) $msg, 'memory size' ), 'the record carries the real message' );
rssai_ok( str_contains( (string) $msg, 'class-grouping.php:123' ), 'the record points at file:line' );
rssai_ok( ! str_contains( (string) $msg, '/home/site/' ), 'only the basename — no server paths in the log' );

$timeout = array(
	'type'    => E_ERROR,
	'message' => 'Maximum execution time of 30 seconds exceeded',
	'file'    => '/x/class-grouping.php',
	'line'    => 400,
);
rssai_ok( str_contains( (string) Logger::format_fatal( $timeout, 'group cron' ), 'execution time' ), 'a timeout death is recorded too' );

rssai_done( 'logger' );
