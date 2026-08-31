<?php
/**
 * The REST contract — and above all, that the rate limiter is actually armed.
 *
 * hti-engine's RateLimit::exceeded() returns FALSE for an action it does not
 * know. It fails OPEN. That is a defensible default for a shared limiter, and
 * it means a mistyped or never-registered key here does not raise anything,
 * does not log anything and does not break anything: it silently removes the
 * limit from a public endpoint. The only way to find out would be an incident.
 *
 * So this file compares three lists that have to agree and are maintained in
 * three different places:
 *
 *   1. the keys the plugin bootstrap registers through `hti_rate_limits`;
 *   2. the keys REST::routes() declares each route uses;
 *   3. the keys the handler source actually passes to RateLimit::exceeded().
 *
 * The same argument applies to metrics: hti-engine silently DROPS an event
 * name that is not on its allowlist, so an unregistered bump() is a counter
 * that reads zero forever. That list is compared too.
 *
 * The bootstrap and the engine's limiter are read as TEXT rather than
 * executed. Deliberately: this file must be able to fail for its own reasons
 * and no others, and booting the plugin would pull in every sibling class —
 * any one of which being half-written would turn this red for something it is
 * not testing.
 *
 *   php wp-content/plugins/hti-games/tests/test-rest-contract.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-day.php';
require_once __DIR__ . '/../includes/class-rest.php';

use HTI\Games\REST;

$plugin_dir = dirname( __DIR__ );

/**
 * A source file as text.
 *
 * @param string $path Absolute path.
 */
function src( string $path ): string {
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

/**
 * The captures of a pattern over a string, deduplicated.
 *
 * @param string $pattern Regex with one capture group.
 * @param string $subject Text.
 * @return array<int,string>
 */
function all_of( string $pattern, string $subject ): array {
	preg_match_all( $pattern, $subject, $m );
	return array_values( array_unique( $m[1] ?? array() ) );
}

$boot     = src( $plugin_dir . '/hti-games.php' );
$engine   = src( dirname( $plugin_dir ) . '/hti-engine/includes/class-rate-limit.php' );
$handlers = array(
	'class-rest'    => src( $plugin_dir . '/includes/class-rest.php' ),
	'class-auth'    => src( $plugin_dir . '/includes/class-auth.php' ),
	'class-privacy' => src( $plugin_dir . '/includes/class-privacy.php' ),
);

echo "The files this contract is read from are all there\n";
hti_games_check( '' !== $boot, 'the plugin bootstrap is readable' );
hti_games_check( '' !== $engine, "hti-engine's rate limiter is readable" );
hti_games_check( '' === implode( '', array_map( fn( $s ) => '' === $s ? 'x' : '', $handlers ) ), 'every handler file is readable' );

/* ------------------------------------------------------------------ */
/* Rate limits                                                         */
/* ------------------------------------------------------------------ */

// The bootstrap's hti_rate_limits block: 'game_x' => array( n, n ).
$registered = all_of( "/'(game_[a-z_]+)'\s*=>\s*array\(\s*\d+\s*,\s*\d+\s*\)/", $boot );

echo "\nThe bootstrap registers a limit for every game key\n";
hti_games_check( count( $registered ) >= 8, 'at least eight game rate-limit keys are registered (' . count( $registered ) . ')' );

$routes = REST::routes();
$used   = array_values( array_filter( array_column( $routes, 'rate' ) ) );

echo "\nEvery key a route declares is one the bootstrap registered\n";
foreach ( $routes as $route ) {
	if ( '' === $route['rate'] ) {
		continue;
	}
	hti_games_check(
		in_array( $route['rate'], $registered, true ),
		"{$route['path']} declares '{$route['rate']}', which is registered — an unregistered key would fail OPEN and silently remove the limit"
	);
}

echo "\nAnd every key the handlers actually call is one of those too\n";
$called = array();
foreach ( $handlers as $text ) {
	$called = array_merge( $called, all_of( "/RateLimit::exceeded\(\s*'([a-z_]+)'\s*\)/", $text ) );
}
$called = array_values( array_unique( $called ) );

hti_games_check( array() !== $called, 'the handlers call the limiter at all (' . count( $called ) . ' distinct keys)' );
foreach ( $called as $key ) {
	hti_games_check( in_array( $key, $registered, true ), "RateLimit::exceeded('{$key}') names a registered key" );
}

echo "\nDeclared and called are the same set — no route quietly skips its limit\n";
sort( $used );
sort( $called );
hti_games_check( $used === $called, 'the route table and the code agree on which keys are used (' . implode( ', ', array_diff( $used, $called ) ) . implode( ', ', array_diff( $called, $used ) ) . ')' );

echo "\nAnd none of them collides with a key hti-engine already owns\n";
$engine_keys = all_of( "/'([a-z_]+)'\s*=>\s*array\(\s*\d+\s*,\s*\d+\s*\)/", $engine );
$collisions  = array_values( array_intersect( $registered, $engine_keys ) );
hti_games_check( array() === $collisions, 'no game key overwrites an engine limit (' . implode( ', ', $collisions ) . ')' );

/* ------------------------------------------------------------------ */
/* Metrics                                                             */
/* ------------------------------------------------------------------ */

// The bootstrap's hti_metrics_events block is a plain list of names.
$events_block = '';
$at           = strpos( $boot, 'hti_metrics_events' );
if ( false !== $at ) {
	$events_block = substr( $boot, $at );
}
$events = all_of( "/'(game_[a-z_]+)',/", $events_block );

echo "\nMetrics: every event the code counts is on the engine's allowlist\n";
hti_games_check( count( $events ) >= 10, 'the bootstrap registers the game event vocabulary (' . count( $events ) . ' names)' );

$bumped = array();
foreach ( $handlers as $text ) {
	$bumped = array_merge( $bumped, all_of( "/(?:self::|Metrics::)bump\(\s*'([a-z_]+)'/", $text ) );
}
$bumped = array_values( array_unique( $bumped ) );

hti_games_check( array() !== $bumped, 'the handlers count something (' . count( $bumped ) . ' distinct events)' );
foreach ( $bumped as $event ) {
	hti_games_check( in_array( $event, $events, true ), "bump('{$event}') names a registered event — an unregistered one is dropped in silence" );
}

echo "\nAnd the detail label is written by us, never by a visitor\n";
$dirty = array();
foreach ( $handlers as $name => $text ) {
	preg_match_all( "/bump\(\s*'[a-z_]+'\s*,([^)]*)\)/", $text, $m );
	foreach ( $m[1] ?? array() as $arg ) {
		if ( str_contains( $arg, 'get_param' ) || str_contains( $arg, '_POST' ) || str_contains( $arg, '_GET' ) ) {
			$dirty[] = $name;
		}
	}
}
hti_games_check( array() === $dirty, 'no metric location is built from request input (' . implode( ', ', $dirty ) . ')' );

/* ------------------------------------------------------------------ */
/* The route table                                                     */
/* ------------------------------------------------------------------ */

echo "\nThe route table is well-formed\n";
hti_games_check( 9 === count( $routes ), 'nine routes are declared (' . count( $routes ) . ')' );

$paths = array_column( $routes, 'path' );
hti_games_check( count( $paths ) === count( array_unique( $paths ) ), 'no path is registered twice' );

$outside = array_values( array_filter( $paths, fn( $p ) => ! str_starts_with( $p, '/games/' ) ) );
hti_games_check( array() === $outside, 'every path lives under /games/ (' . implode( ', ', $outside ) . ')' );

foreach ( $routes as $route ) {
	$ok = isset( $route['path'], $route['methods'], $route['callback'], $route['permission'], $route['rate'], $route['args'] )
		&& is_array( $route['callback'] )
		&& 2 === count( $route['callback'] )
		&& is_string( $route['callback'][0] )
		&& is_string( $route['callback'][1] )
		&& is_array( $route['args'] );
	hti_games_check( $ok, "{$route['path']} declares a complete, well-shaped entry" );
}

echo "\nEvery permission callback is one of hti-engine's two public statics\n";
foreach ( $routes as $route ) {
	hti_games_check(
		in_array( $route['permission'], array( 'check_nonce', 'check_auth' ), true ),
		"{$route['path']} is guarded by {$route['permission']}"
	);
}

echo "\nThe one route that needs an account asks for one\n";
$auth_paths = array_values( array_column( array_filter( $routes, fn( $r ) => 'check_auth' === $r['permission'] ), 'path' ) );
hti_games_check( array( '/games/claim' ) === $auth_paths, 'only /games/claim requires a signed-in user (' . implode( ', ', $auth_paths ) . ')' );

echo "\nMethods are what the design says they are\n";
$by_path = array_column( $routes, 'methods', 'path' );
hti_games_check( 'POST' === ( $by_path['/games/session'] ?? '' ), '/games/session is a POST — it writes an acknowledgement' );
hti_games_check( 'GET' === ( $by_path['/games/leaderboard'] ?? '' ), '/games/leaderboard is a GET' );
hti_games_check( 'DELETE' === ( $by_path['/games/me'] ?? '' ), '/games/me is a DELETE — erasure is not a POST' );
hti_games_check( 'POST' === ( $by_path['/games/stc|reveal/decision'] ?? $by_path['/games/(?P<game>stc|reveal)/decision'] ?? '' ), '/games/{game}/decision is a POST' );

echo "\nThe game segment is closed at the route, not merely checked after it\n";
$game_routes = array_values( array_filter( $paths, fn( $p ) => str_contains( $p, '<game>' ) ) );
hti_games_check( 2 === count( $game_routes ), 'both per-game routes carry a game segment' );
foreach ( $game_routes as $path ) {
	hti_games_check( str_contains( $path, '(?P<game>stc|reveal)' ), "{$path} matches only the two games — anything else is a 404 from WordPress before a handler runs" );
}

/* ------------------------------------------------------------------ */
/* Values that have to fit the columns they are stored in              */
/* ------------------------------------------------------------------ */

echo "\nEvery decision fits the varchar(8) it is stored in\n";
foreach ( array_merge( REST::STC_DECISIONS, REST::REVEAL_DECISIONS ) as $decision ) {
	hti_games_check( strlen( $decision ) <= 8, "'{$decision}' fits decision VARCHAR(8)" );
}
hti_games_check( in_array( 'pass', REST::STC_DECISIONS, true ), 'passing is one of the chart game\'s choices, not an absence of one' );
hti_games_check( in_array( 'pass', REST::REVEAL_DECISIONS, true ), 'and one of the dossier game\'s' );

echo "\nThe status codes in use are the ones the house conventions allow\n";
$statuses = array();
foreach ( $handlers as $text ) {
	$statuses = array_merge( $statuses, all_of( "/'status'\s*=>\s*(\d{3})/", $text ) );
}
$allowed = array( '401', '403', '404', '409', '422', '429', '500', '503' );
$odd     = array_values( array_diff( array_unique( $statuses ), $allowed ) );
hti_games_check( array() === $odd, 'no handler invents a status code (' . implode( ', ', $odd ) . ')' );
hti_games_check( in_array( '429', $statuses, true ), 'a rate limit answers 429' );
hti_games_check( in_array( '409', $statuses, true ), 'a second run of the day, and a day that moved, answer 409' );
hti_games_check( in_array( '422', $statuses, true ), 'bad input answers 422' );

/* ------------------------------------------------------------------ */
/* The streak rule                                                     */
/* ------------------------------------------------------------------ */

echo "\nThe streak counts days shown up for, not days won\n";
hti_games_check( 4 === REST::next_streak( 3, '2026-08-29', '2026-08-30', false ), 'a run the day after the last one extends the streak' );
hti_games_check( 1 === REST::next_streak( 9, '2026-08-27', '2026-08-30', false ), 'a gap restarts it at one' );
hti_games_check( 1 === REST::next_streak( 0, '', '2026-08-30', false ), 'and so does a first ever run' );
hti_games_check( 0 === REST::next_streak( 40, '2026-08-29', '2026-08-30', true ), 'a death ends it, however long it was' );
hti_games_check( 1 === REST::next_streak( 5, 'not-a-date', '2026-08-30', false ), 'an unparseable last day restarts rather than throws' );
hti_games_check( 4 === REST::next_streak( 3, '2026-08-29', '2026-08-30', false ), 'nothing about the decision itself is consulted — a pass extends it exactly as a win does' );

hti_games_done();
