<?php
/**
 * The shipped scenario library: its address, and the install that reproduces
 * it without a shell.
 *
 * What this file is really guarding.
 *
 * FIRST, that shipping three integers instead of a megabyte of JSON is a safe
 * trade. It is only safe while (seed, count) reproduces the identical charts
 * on every host forever, so the library is built twice here and compared as
 * the bytes that actually reach the database — the JSON of the quad list
 * create() stores — not as a summary of them. The day that comparison fails,
 * the plugin has stopped shipping a library and started shipping a promise.
 *
 * SECOND, that the address is the PAIR. STC_Generator::batch() draws its class
 * plan from the count before it draws a single scenario, so a shorter library
 * is not a prefix of a longer one. Config::LIBRARY_SEED without
 * Config::LIBRARY_COUNT would name nothing.
 *
 * THIRD, that the install can be interrupted. On cPanel it will be: 365 posts
 * with twelve meta rows each is past a shared PHP process budget, so the run
 * is sliced and its position stored. Everything about resuming has to be a
 * pure function of the address and the offset — which is what the assertions
 * on lesson_indexes() and state() are for. A run that remembered anything
 * else would give two sites different charts from the same seed.
 *
 *   php wp-content/plugins/hti-games/tests/test-library-install.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-cpt.php';
require_once __DIR__ . '/../includes/class-strings.php';
require_once __DIR__ . '/../includes/class-stc-engine.php';
require_once __DIR__ . '/../includes/class-stc-generator.php';
require_once __DIR__ . '/../includes/class-installer.php';

use HTI\Games\Config;
use HTI\Games\CPT;
use HTI\Games\Installer;
use HTI\Games\Strings;
use HTI\Games\STC_Generator;

/**
 * A library reduced to the bytes each scenario is stored as.
 *
 * Deliberately the stored form — the JSON of quads() that create() writes to
 * `hti_stc_ticks` — so "identical" here means identical in the database and
 * not merely equal under some looser comparison. One library is held at a
 * time, because two years of candles in memory at once is a test that fails
 * on somebody's laptop for a reason that has nothing to do with the code.
 *
 * @param int $count Library size.
 * @param int $seed  Run seed.
 * @return array<int,string> One digest per scenario, in library order.
 */
function hti_lib_bytes( int $count, int $seed ): array {
	$out = array();
	foreach ( STC_Generator::batch( $count, $seed ) as $scenario ) {
		$out[] = md5( (string) wp_json_encode( STC_Generator::quads( (array) $scenario['candles'] ) ) );
	}
	return $out;
}

/**
 * The plugin's own source, for the assertions that are about what the code
 * is allowed to do rather than what it returns.
 *
 * @param string $rel Path relative to the plugin root.
 */
function hti_lib_src( string $rel ): string {
	$path = dirname( __DIR__ ) . '/' . $rel;
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

echo "The shipped library has an address, and the address is a pair\n";
$address = Config::library();
hti_games_check( array( 'seed', 'count', 'version' ) === array_keys( $address ), 'the address is seed + count + version' );
hti_games_check( 365 === Config::LIBRARY_COUNT, 'a year of charts, so a returning player does not meet last month’s again' );
hti_games_check( Config::LIBRARY_SEED > 0, 'the seed is a real number and not a placeholder' );
hti_games_check( Config::LIBRARY_VERSION >= 1, 'and the library is versioned, so "installed" cannot quietly mean two different libraries' );
hti_games_check(
	Config::LIBRARY_COUNT <= 500,
	'the library fits inside the 500-post ceiling Library::published_ids() queries with — past it the pool would silently serve a truncated year'
);
hti_games_check(
	Config::LIBRARY_COUNT >= Config::REAL_CLAIM_MIN_POOL,
	'and it is far bigger than the pool size the landing page measures, so an installed site is never a thin one'
);

echo "\nThe same address rebuilds the same bytes\n";
$small_a = STC_Generator::batch( 12, 4242 );
$small_b = STC_Generator::batch( 12, 4242 );
hti_games_check( 12 === count( $small_a ), 'a small library is the size it was asked for' );
hti_games_check(
	array_column( $small_a, 'candles' ) === array_column( $small_b, 'candles' ),
	'every candle of every scenario is identical on the second build — not similar, identical'
);
hti_games_check(
	array_column( $small_a, 'seed' ) === array_column( $small_b, 'seed' ),
	'and each scenario landed at the same seed, which is its identity in the database'
);
hti_games_check(
	array_column( $small_a, 'class' ) === array_column( $small_b, 'class' ),
	'in the same order, so slice 2 of one install is slice 2 of every other'
);

$shipped_a = hti_lib_bytes( Config::LIBRARY_COUNT, Config::LIBRARY_SEED );
$shipped_b = hti_lib_bytes( Config::LIBRARY_COUNT, Config::LIBRARY_SEED );
hti_games_check( Config::LIBRARY_COUNT === count( $shipped_a ), 'the shipped library builds all ' . Config::LIBRARY_COUNT . ' of its scenarios' );
hti_games_check(
	$shipped_a === $shipped_b,
	'and rebuilds byte-for-byte as stored — this is the whole reason the plugin may ship a seed instead of a data file'
);
hti_games_check(
	count( array_unique( $shipped_a ) ) === count( $shipped_a ),
	'with no two scenarios carrying the same candles, so a year is a year and not a year of repeats'
);

echo "\nThe count is part of the address, not just the length\n";
$short = hti_lib_bytes( 12, Config::LIBRARY_SEED );
hti_games_check(
	$short !== array_slice( $shipped_a, 0, 12 ),
	'a 12-scenario library is not the first twelve of the shipped one — the count reshuffles the plan'
);

echo "\nThe addresses are the library, without building it\n";
$addresses = STC_Generator::addresses( 12, 4242 );
hti_games_check( 12 === count( $addresses ), 'there is one address per scenario' );
hti_games_check(
	array_column( $addresses, 'seed' ) === array_column( $small_a, 'seed' ),
	'and they are exactly the seeds batch() draws, in order — which is what makes resuming at scenario 200 cheap'
);
hti_games_check(
	array_column( $addresses, 'class' ) === array_column( $small_a, 'class' ),
	'with the same class each, so a slice cannot drift from the plan'
);
$one = STC_Generator::scenario( (string) $addresses[7]['class'], (int) $addresses[7]['seed'] );
hti_games_check(
	$one['candles'] === $small_a[7]['candles'],
	'building a single scenario from its address alone gives the chart the full run would have given it'
);

echo "\nThe shipped library keeps the 40/35/25 curriculum\n";
$mix = STC_Generator::mix( STC_Generator::batch( Config::LIBRARY_COUNT, Config::LIBRARY_SEED ) );
hti_games_check( array_sum( $mix ) === Config::LIBRARY_COUNT, 'every scenario is counted in the mix' );
foreach ( STC_Generator::MIX_BP as $class => $target ) {
	$observed = intdiv( $mix[ $class ] * 10000, Config::LIBRARY_COUNT );
	hti_games_check(
		abs( $observed - $target ) <= 100,
		sprintf(
			'%s is %s%% of the shipped library, against the documented %s%% (within 1 point)',
			$class,
			number_format( $observed / 100, 1 ),
			number_format( $target / 100, 1 )
		)
	);
}
hti_games_check( $mix['reasonable'] > $mix['ambiguous'], 'most days are legible, or the game teaches that reading a chart is pointless' );
hti_games_check( $mix['ambiguous'] > $mix['trap'], 'and the days that answer nothing outnumber the traps' );

echo "\nA lesson belongs to a position, not to an install run\n";
$lessons = Installer::lesson_indexes( $addresses );
hti_games_check( count( $lessons ) === count( $addresses ), 'every scenario gets one' );
hti_games_check( 0 === $lessons[0], 'the first scenario of the library takes the first lesson of its class' );

$per_class = array_fill_keys( CPT::SCENARIO_CLASSES, array() );
foreach ( $addresses as $i => $entry ) {
	$per_class[ $entry['class'] ][] = $lessons[ $i ];
}
$sequential = true;
foreach ( $per_class as $seen ) {
	$sequential = $sequential && $seen === range( 0, count( $seen ) - 1 );
}
hti_games_check( $sequential, 'each class walks its lessons 0, 1, 2 … in library order, so all eight are used before any repeats' );

// run_slice() rebuilds the address list and the index list from the stored
// address on every request and then reads position `done` out of them. Nothing
// survives the request that stopped, so a resumed slice can only agree with an
// uninterrupted one if both are functions of the address alone. Asserted by
// doing precisely that, at each slice boundary.
$shipped_addresses = STC_Generator::addresses( Config::LIBRARY_COUNT, Config::LIBRARY_SEED );
$full              = Installer::lesson_indexes( $shipped_addresses );
$resumed           = count( $full ) === Config::LIBRARY_COUNT;

for ( $at = 0; $at < Config::LIBRARY_COUNT; $at += Installer::BATCH_MAX ) {
	$rebuilt = Installer::lesson_indexes( STC_Generator::addresses( Config::LIBRARY_COUNT, Config::LIBRARY_SEED ) );
	$resumed = $resumed && $rebuilt === $full && $rebuilt[ $at ] === $full[ $at ];
}
hti_games_check( $resumed, 'and a scenario installed on the fourth click carries the lesson it would have had on the first' );

echo "\nThe run state survives whatever is in the option row\n";
$blank = Installer::blank();
hti_games_check( Config::LIBRARY_SEED === $blank['seed'] && Config::LIBRARY_COUNT === $blank['count'], 'a fresh run starts at the shipped address' );
hti_games_check( 0 === $blank['done'] && 0 === $blank['created'] && 0 === $blank['skipped'], 'with nothing done' );
hti_games_check( Installer::is_shipped( $blank ), 'and it is recognised as the shipped library' );
hti_games_check( ! Installer::is_complete( $blank ), 'and as unfinished' );

hti_games_check( Installer::state( 'nonsense' ) === $blank, 'a corrupt option row normalizes to a fresh run rather than fatalling' );
hti_games_check( Installer::state( array() ) === $blank, 'so does an empty one' );

$stale = Installer::state( array( 'done' => 9999, 'created' => -4 ) );
hti_games_check(
	$stale['done'] === Config::LIBRARY_COUNT,
	'a stored position past the end of the library is clamped, so the panel never reports 9999 of 365 and no slice indexes off the end of the address list'
);
hti_games_check( 0 === $stale['created'], 'and a negative tally is floored at zero' );

$zero = Installer::state( array( 'count' => 0, 'done' => 40 ) );
hti_games_check( $zero['count'] === Config::LIBRARY_COUNT && 0 === $zero['done'], 'a stored count of zero is a row written before the address was, and starts over rather than dividing a progress bar by nothing' );

$other = Installer::state( array( 'seed' => 1, 'count' => 60, 'done' => 60 ) );
hti_games_check( ! Installer::is_shipped( $other ), 'a run at another address is not the shipped library' );
hti_games_check( Installer::is_complete( $other ), 'though it can still be a finished run of its own' );

$half = Installer::state( array( 'done' => intdiv( Config::LIBRARY_COUNT, 2 ) ) );
hti_games_check( 49 === Installer::percent( $half ), 'progress reads as a percentage a person can act on' );
hti_games_check( 100 === Installer::percent( Installer::state( array( 'done' => Config::LIBRARY_COUNT ) ) ), 'and reaches exactly 100 when the run is done' );
hti_games_check( 0 === Installer::percent( array( 'done' => 5, 'count' => 0 ) ), 'and never divides by zero' );

echo "\nA slice is bounded by the host's own clock\n";
hti_games_check( Installer::BUDGET_MAX === Installer::budget( 0 ), 'a host declaring no execution limit still gets a ceiling — an unbounded loop is not a plan' );
hti_games_check( 15 === Installer::budget( 30 ), 'the usual 30-second limit buys a 15-second slice, leaving the other half for WordPress and the redirect' );
hti_games_check( Installer::BUDGET_MAX === Installer::budget( 600 ), 'a generous limit is still capped' );
hti_games_check( Installer::budget( 3 ) >= 1, 'and a hostile one never budgets zero seconds, which would be a button that does nothing' );
hti_games_check( Installer::budget( 10 ) < Installer::budget( 30 ), 'a tighter host gets a shorter slice' );
hti_games_check(
	Installer::BATCH_MAX > 0 && Installer::BATCH_MAX < Config::LIBRARY_COUNT,
	'and by a count as well as a clock, so no single request is asked for the whole library’s worth of database writes — the two bound different limits a shared host enforces'
);

echo "\nThe install publishes, and says what it published\n";
hti_games_check( 'publish' === Installer::STATUS, 'the shipped library goes live: 365 drafts nobody publishes is the same empty pool with extra steps' );

$gen_src = hti_lib_src( 'includes/class-stc-generator.php' );
hti_games_check(
	str_contains( $gen_src, "'hti_stc_real'       => '0'" ),
	'every generated scenario is stored as NOT real market data, whatever status it is created with'
);
hti_games_check(
	1 === substr_count( $gen_src, "'hti_stc_real'" ),
	'and there is exactly one place that key is written, so publishing cannot have opened a second one that says 1'
);
hti_games_check(
	str_contains( $gen_src, "'post_status' => in_array( \$status, array( 'draft', 'publish' ), true ) ? \$status : 'draft'" ),
	'the status is checked against the two it may be, so a caller cannot invent a third'
);

$inst_src = hti_lib_src( 'includes/class-installer.php' );
hti_games_check(
	str_contains( $inst_src, 'STC_Generator::create(' ) && str_contains( $inst_src, 'STC_Generator::seed_exists(' ),
	'the installer reuses the generator’s insert and dedupe rather than keeping a second copy of what a scenario is'
);
hti_games_check(
	! str_contains( $inst_src, 'wp_insert_post(' ) && ! str_contains( $inst_src, 'update_post_meta(' ),
	'and writes no post or meta of its own, so there is one definition of a stored scenario'
);
hti_games_check(
	str_contains( $inst_src, 'wp_schedule_single_event(' ),
	'an unattended run is carried on by a single event'
);
hti_games_check(
	! str_contains( $inst_src, 'wp_schedule_event(' ) && ! str_contains( $inst_src, 'cron_schedules' ),
	'and never by a recurring schedule — WP-Cron here is disabled and driven from outside, so a recurring job is a job nobody runs'
);
hti_games_check(
	str_contains( $inst_src, "current_user_can( 'manage_options' )" ) && str_contains( $inst_src, 'check_admin_referer(' ),
	'the button is behind a capability and a nonce'
);
hti_games_check(
	str_contains( $inst_src, 'update_option( self::OPTION, $state, false )' ),
	'the position is written after every slice, which is what makes the run resumable — and not autoloaded on every request'
);
hti_games_check(
	str_contains( $inst_src, 'Library::flush( Config::GAME_STC )' ),
	'and the pool cache is dropped when something was created, or the game stays empty for twelve hours after a successful install'
);

echo "\nThe readiness panel says the one thing that stands between a deploy and a working game\n";
delete_option( Installer::OPTION );
$fresh = Installer::readiness_row( 0 );
hti_games_check( 'fail' === $fresh[0], 'a site with no library and no scenarios fails readiness, loudly' );
hti_games_check( str_contains( $fresh[2], 'NOT INSTALLED' ), 'and says so in the message, not only in the colour of a dot' );
hti_games_check( str_contains( $fresh[2], 'No shell, no CLI' ), 'and tells the owner the fix is a button, which is the whole point of this workstream' );

$imported = Installer::readiness_row( 40 );
hti_games_check( 'warn' === $imported[0], 'a site whose charts came from the importer is not failing — the shipped library is an option there, not a requirement' );

update_option( Installer::OPTION, array_merge( Installer::blank(), array( 'done' => 100, 'created' => 100, 'started' => '2026-08-30 10:00' ) ) );
$partial = Installer::readiness_row( 100 );
hti_games_check( 'warn' === $partial[0], 'a half-finished install warns' );
hti_games_check( str_contains( $partial[2], 'Half-installed' ) && str_contains( $partial[2], '100 of 365' ), 'and says exactly how far it got, so "press Continue" is an instruction and not a guess' );

update_option( Installer::OPTION, array_merge( Installer::blank(), array( 'done' => Config::LIBRARY_COUNT, 'created' => Config::LIBRARY_COUNT ) ) );
$done_row = Installer::readiness_row( Config::LIBRARY_COUNT );
hti_games_check( 'ok' === $done_row[0], 'a finished install passes readiness' );
hti_games_check( str_contains( $done_row[2], (string) Config::LIBRARY_SEED ), 'and names the seed, which is the whole library in one number' );

update_option(
	Installer::OPTION,
	array_merge( Installer::blank(), array( 'done' => Config::LIBRARY_COUNT, 'created' => Config::LIBRARY_COUNT - 3, 'failed' => 3 ) )
);
$holes = Installer::readiness_row( Config::LIBRARY_COUNT - 3 );
hti_games_check( 'warn' === $holes[0], 'a run that finished with holes in it is not "ok" — a library short of the charts it claims wraps sooner than the page says' );
hti_games_check( str_contains( $holes[2], '3 of 365 missing' ), 'and says how many are missing rather than reporting a clean install' );
hti_games_check(
	str_contains( hti_lib_src( 'includes/class-installer.php' ), 'Retry the scenarios that failed' ),
	'with a button that walks the library again and rebuilds only the missing ones, so a partial failure is not a dead end'
);
delete_option( Installer::OPTION );

echo "\nAn empty pool is told as a fact, not served as a failure\n";
foreach ( Strings::LANGS as $lang ) {
	$copy = Strings::get( 'st_no_content', $lang );
	hti_games_check( '' !== trim( $copy ), "the empty-pool sentence exists in {$lang}" );
	hti_games_check( ! str_contains( strtolower( $copy ), 'error' ) && ! str_contains( strtolower( $copy ), 'erro' ), "and does not call it an error in {$lang} — an uninstalled library is not a fault the reader caused" );
}

$front_src = hti_lib_src( 'includes/class-frontend.php' );
hti_games_check(
	str_contains( $front_src, 'Library::published_ids( $game )' ),
	'the shortcode checks the pool before mounting a game, so an empty library is a sentence rather than an empty chart with a broken control bar under it'
);
hti_games_check(
	substr_count( $front_src, "Strings::get( 'st_no_content', \$lang )" ) >= 2,
	'and says the same thing the kill-switch and the API say, from the same copy table'
);

$settings_src = hti_lib_src( 'includes/class-settings.php' );
hti_games_check(
	str_contains( $settings_src, 'Installer::readiness_row( $stc )' ),
	'the readiness panel carries the library row rather than leaving "0 scenarios" to look like a content backlog'
);

hti_games_done();
