<?php
/**
 * The security and data-protection controls, asserted against the source.
 *
 * Everything in this file is a control that CANNOT be checked by reading the
 * code once and being satisfied. Each one is a property that holds today and
 * that an ordinary, well-meant edit six months from now would break silently:
 * a tenth REST route added without a permission callback, a `$wpdb->query()`
 * built by concatenation because the value "is obviously an integer", a new
 * option that uninstall.php never hears about, an `echo $thing` in an admin
 * notice. None of those fails loudly. All of them fail here.
 *
 * Most of it is therefore a STATIC AUDIT — the plugin read as text — because
 * that is what can be asserted with no WordPress, no database and no HTTP.
 * What the harness cannot see is on the manual staging list in
 * docs/QA_RGPD_Checklist.md: the real REST auth, the real deletion cascade,
 * and the magic link through a real inbox.
 *
 * Companion files, not duplicates: test-anticheat.php proves the payload
 * whitelists withhold the answer, and test-rest-contract.php proves the rate
 * limiter is armed. This one is about authorisation, injection, output and
 * erasure.
 *
 *   php wp-content/plugins/hti-games/tests/test-security.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Stand-in for the WordPress salt: the day handle is an HMAC under it.
	 *
	 * @param string $scheme Salt scheme.
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-day.php';
require_once __DIR__ . '/../includes/class-stc-engine.php';
require_once __DIR__ . '/../includes/class-importer.php';
require_once __DIR__ . '/../includes/class-store.php';
require_once __DIR__ . '/../includes/class-leaderboard.php';
require_once __DIR__ . '/../includes/class-player.php';
require_once __DIR__ . '/../includes/class-rest.php';

use HTI\Games\Leaderboard;
use HTI\Games\Player;
use HTI\Games\REST;
use HTI\Games\Store;

$plugin = dirname( __DIR__ );

/* ------------------------------------------------------------------ */
/* Reading the plugin as text                                          */
/* ------------------------------------------------------------------ */

/**
 * A source file as text, '' when it is not there.
 *
 * @param string $path Absolute path.
 */
function hti_sec_src( string $path ): string {
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

/**
 * Every PHP file the plugin ships, tests excluded.
 *
 * @param string $root Plugin directory.
 * @return array<string,string> Relative path => contents.
 */
function hti_sec_php( string $root ): array {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$path = $file->getPathname();
		if ( str_contains( $path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
			continue;
		}
		if ( str_ends_with( $path, '.php' ) ) {
			$out[ ltrim( str_replace( $root, '', $path ), DIRECTORY_SEPARATOR ) ] = (string) file_get_contents( $path );
		}
	}
	ksort( $out );
	return $out;
}

/**
 * The captures of a pattern over a string, deduplicated.
 *
 * @param string $pattern Regex with one capture group.
 * @param string $subject Text.
 * @return array<int,string>
 */
function hti_sec_all( string $pattern, string $subject ): array {
	preg_match_all( $pattern, $subject, $m );
	return array_values( array_unique( $m[1] ?? array() ) );
}

$php       = hti_sec_php( $plugin );
$boot      = hti_sec_src( $plugin . '/hti-games.php' );
$uninstall = hti_sec_src( $plugin . '/uninstall.php' );

echo "There is a plugin to audit\n";
hti_games_check( count( $php ) >= 20, sprintf( 'read %d PHP files', count( $php ) ) );
hti_games_check( '' !== $boot, 'the bootstrap is readable' );
hti_games_check( '' !== $uninstall, 'uninstall.php is readable' );

/* ------------------------------------------------------------------ */
/* 1. Authorisation: every route is guarded                            */
/* ------------------------------------------------------------------ */

echo "\nEvery REST route declares a permission callback\n";
$routes = REST::routes();

foreach ( $routes as $route ) {
	hti_games_check(
		isset( $route['permission'] ) && '' !== $route['permission'],
		"{$route['path']} declares a permission"
	);
}
foreach ( $routes as $route ) {
	hti_games_check(
		in_array( $route['permission'], array( 'check_nonce', 'check_auth' ), true ),
		"{$route['path']} is guarded by one of hti-engine's two vetted callbacks, not a local copy"
	);
}

// The registration loop is what turns the table into real routes, so it is
// the line that would have to be edited to sneak an unguarded one past the
// table above.
$rest_src = $php['includes/class-rest.php'] ?? '';
hti_games_check(
	1 === substr_count( $rest_src, "'permission_callback'" ),
	'there is exactly one permission_callback in the file — every route goes through the same registration'
);
hti_games_check(
	str_contains( $rest_src, "'permission_callback' => array( \\HTI\\Engine\\REST::class, \$route['permission'] )" ),
	'and it is resolved from the route table, so a route cannot register without one'
);

echo "\nNothing in the plugin waves a request through\n";
$open = array();
foreach ( $php as $rel => $text ) {
	if ( str_contains( $text, '__return_true' ) ) {
		$open[] = $rel;
	}
}
hti_games_check( array() === $open, 'no __return_true anywhere (' . ( $open ? implode( ', ', $open ) : 'clean' ) . ')' );

echo "\nThe routes that change something ask for the right thing\n";
$by_path = array();
foreach ( $routes as $route ) {
	$by_path[ $route['path'] ] = $route;
}
hti_games_check(
	'check_auth' === ( $by_path['/games/claim']['permission'] ?? '' ),
	'POST /games/claim requires a signed-in user — it binds a run to an account and a nonce alone cannot say which account'
);
hti_games_check(
	'DELETE' === ( $by_path['/games/me']['methods'] ?? '' ) && 'check_nonce' === ( $by_path['/games/me']['permission'] ?? '' ),
	'DELETE /games/me is nonce-guarded and anonymous by design — an anonymous player is the one nobody else can erase for them'
);
// The authorisation for the erase is possession of the identity, which is what
// Player::resolve() reads. A handler that took an id from the request instead
// would let one player erase another.
$privacy_src = $php['includes/class-privacy.php'] ?? '';
hti_games_check(
	str_contains( $privacy_src, 'Player::resolve( $request )' ),
	'the erase resolves the caller from their own cookie/header, never from a request parameter'
);
hti_games_check(
	! preg_match( "/rest_forget.*?get_param\(\s*'(player|id|uuid|user)/s", $privacy_src ),
	'and takes no player, id, uuid or user parameter that could name somebody else'
);

echo "\nEvery anonymous route is rate limited, and every key it names is registered\n";
$registered = hti_sec_all( "/'(game_[a-z_]+)'\s*=>\s*array\(\s*\d+\s*,\s*\d+\s*\)/", $boot );
foreach ( $routes as $route ) {
	if ( 'check_auth' === $route['permission'] ) {
		continue;
	}
	hti_games_check( '' !== $route['rate'], "{$route['path']} declares a rate-limit key" );
	hti_games_check(
		in_array( $route['rate'], $registered, true ),
		"{$route['path']}'s key '{$route['rate']}' is registered — RateLimit::exceeded() fails OPEN for one that is not"
	);
}

echo "\nEvery admin_post handler checks a capability and a nonce\n";
foreach ( $php as $rel => $text ) {
	foreach ( hti_sec_all( "/add_action\(\s*'admin_post_([a-z_]+)'\s*,\s*array\(\s*__CLASS__,\s*'([a-z_]+)'/", $text ) as $action ) {
		hti_games_check( true, "found admin_post_{$action} in {$rel}" );
	}
	preg_match_all( "/add_action\(\s*'admin_post_[a-z_]+'\s*,\s*array\(\s*__CLASS__,\s*'([a-z_]+)'\s*\)/", $text, $m );
	foreach ( $m[1] ?? array() as $method ) {
		// The handler body: from its signature to the next method at the same
		// indentation. Enough to see the two guards, which are always first.
		$body = '';
		if ( preg_match( '/function ' . preg_quote( $method, '/' ) . '\(.*?\n\t\}/s', $text, $found ) ) {
			$body = $found[0];
		}
		hti_games_check( '' !== $body, "{$rel}::{$method}() is readable" );
		hti_games_check( str_contains( $body, 'current_user_can(' ), "{$rel}::{$method}() checks a capability" );
		hti_games_check(
			str_contains( $body, 'check_admin_referer(' ) || str_contains( $body, 'wp_verify_nonce(' ),
			"{$rel}::{$method}() checks a nonce"
		);
	}
}

/* ------------------------------------------------------------------ */
/* 2. Injection: nothing reaches SQL unprepared                        */
/* ------------------------------------------------------------------ */

echo "\nEvery \$wpdb read is prepared\n";
// The one sanctioned exception, named here so adding a second one is a
// deliberate edit to this list rather than a silent habit: uninstall's DROP
// TABLE, whose only interpolation is $wpdb->prefix plus a literal suffix, and
// which cannot be a placeholder because an identifier never can.
$unprepared_ok = array( 'uninstall.php' => 1 );

$unprepared = array();
foreach ( $php as $rel => $text ) {
	preg_match_all( '/\$wpdb->(query|get_var|get_row|get_col|get_results)\(\s*(.{0,80})/s', $text, $m, PREG_SET_ORDER );
	$seen = 0;
	foreach ( $m as $hit ) {
		if ( str_contains( $hit[2], '$wpdb->prepare' ) ) {
			continue;
		}
		++$seen;
		if ( $seen > ( $unprepared_ok[ $rel ] ?? 0 ) ) {
			$unprepared[] = $rel . ': $wpdb->' . $hit[1] . '()';
		}
	}
}
hti_games_check(
	array() === $unprepared,
	'no unprepared read outside the allowlist (' . ( $unprepared ? implode( '; ', $unprepared ) : 'clean' ) . ')'
);

echo "\nAnd every \$wpdb write names its column formats\n";
$unformatted = array();
foreach ( $php as $rel => $text ) {
	preg_match_all( '/\$wpdb->(insert|update|delete)\((.{0,500})/s', $text, $m, PREG_SET_ORDER );
	foreach ( $m as $hit ) {
		// A format array is either built from the column map or written out.
		if ( preg_match( "/formats\(|'%d'|'%s'|'%f'/", $hit[2] ) ) {
			continue;
		}
		$unformatted[] = $rel . ': $wpdb->' . $hit[1] . '()';
	}
}
hti_games_check(
	array() === $unformatted,
	'every insert/update/delete passes a format array — without one $wpdb infers %s and an int column takes a string (' . ( $unformatted ? implode( '; ', $unformatted ) : 'clean' ) . ')'
);

echo "\nA table name is never built from anything a request carries\n";
$interpolated = hti_sec_all( '/(?:FROM|INTO|UPDATE(?: IGNORE)?|JOIN)\s+`\{\$([a-z_]+)\}`/', implode( "\n", $php ) );
$allowed      = array( 'table', 'runs', 'players' );
$odd          = array_values( array_diff( $interpolated, $allowed ) );
hti_games_check(
	array() === $odd,
	'the only identifiers interpolated into SQL are the two table names (' . ( $odd ? implode( ', ', $odd ) : implode( ', ', $interpolated ) ) . ')'
);
$store_src = $php['includes/class-store.php'] ?? '';
hti_games_check(
	str_contains( $store_src, "return \$wpdb->prefix . 'hti_games_players';" )
		&& str_contains( $store_src, "return \$wpdb->prefix . 'hti_games_runs';" ),
	'and both of those come from $wpdb->prefix plus a literal — there is no path from input to a table name'
);

echo "\nThe closed sets the public queries depend on are memberships, not ranges\n";
hti_games_check( Leaderboard::is_board( 'daily' ) && Leaderboard::is_board( 'survival' ), 'the two boards are accepted' );
hti_games_check( ! Leaderboard::is_board( 'daily OR 1=1' ) && ! Leaderboard::is_board( '' ), 'and nothing else is' );
hti_games_check( \HTI\Games\Config::is_game( 'stc' ) && \HTI\Games\Config::is_game( 'reveal' ), 'the two games are accepted' );
hti_games_check( ! \HTI\Games\Config::is_game( 'stc; DROP' ) && ! \HTI\Games\Config::is_game( 'STC' ), 'and nothing else is' );
hti_games_check( \HTI\Games\Config::is_risk_bp( 200 ) && ! \HTI\Games\Config::is_risk_bp( 199 ), 'a risk tier is checked against the offered set, never clamped into it' );
hti_games_check( \HTI\Games\Config::is_size( 25 ) && ! \HTI\Games\Config::is_size( 26 ), 'and so is a commitment size' );

echo "\nThe leaderboard day is bounded, so an anonymous GET cannot choose an unbounded cache key\n";
$today = '2026-08-30';
hti_games_check( Leaderboard::is_servable_day( $today, $today ), 'today is servable' );
hti_games_check( Leaderboard::is_servable_day( '2026-08-01', $today ), 'and a day inside the window' );
hti_games_check( ! Leaderboard::is_servable_day( '2026-08-31', $today ), 'tomorrow is not — a board for a day nobody has played is a free cache row' );
hti_games_check( ! Leaderboard::is_servable_day( '1970-01-02', $today ), 'nor is a day from 1970' );
hti_games_check( ! Leaderboard::is_servable_day( '2026-02-30', $today ), 'nor a date that does not exist' );
hti_games_check( ! Leaderboard::is_servable_day( "2026-08-30' OR '1", $today ), 'nor anything that is not a date at all' );
hti_games_check(
	! Leaderboard::is_servable_day( gmdate( 'Y-m-d', strtotime( $today ) - ( ( Leaderboard::MAX_BACK_DAYS + 1 ) * 86400 ) ), $today ),
	'and the window has an edge, one day past MAX_BACK_DAYS'
);
hti_games_check(
	str_contains( $rest_src, 'Leaderboard::is_servable_day( $day, Day::key() )' ),
	'and the route uses it before the day reaches a query or a transient key'
);

/* ------------------------------------------------------------------ */
/* 3. Output: the one free-text field a player controls                */
/* ------------------------------------------------------------------ */

echo "\nA nickname cannot carry markup into a public page\n";
$hostile = array(
	'<script>alert(1)</script>',
	'" onload="x',
	"'; DROP TABLE--",
	'a<b>c',
	'ana&amp;',
	'ana joão',
);
foreach ( $hostile as $raw ) {
	$check = Player::validate_nickname( $raw );
	hti_games_check( ! $check['ok'], 'the validator refuses ' . var_export( $raw, true ) );
}
foreach ( $hostile as $raw ) {
	$safe = Leaderboard::safe_nickname( $raw );
	hti_games_check(
		1 !== preg_match( '/[^A-Za-z0-9_-]/', $safe ),
		'and the renderer strips it to the charset anyway: ' . var_export( $safe, true )
	);
}
hti_games_check( 'Ana_2' === Leaderboard::safe_nickname( 'Ana_2' ), 'a legitimate name survives both' );
hti_games_check( 24 >= strlen( Leaderboard::safe_nickname( str_repeat( 'a', 400 ) ) ), 'and a long one is cut to the column width' );
// Belt and braces on purpose: the validator is what CAN be stored today, the
// renderer is what a row written by a looser validator can still put on a page.
$board_src = $php['includes/class-leaderboard.php'] ?? '';
hti_games_check(
	4 <= substr_count( $board_src, 'self::safe_nickname(' ),
	'every nickname the board returns goes through it — the top rows and the pinned "me" row of both boards'
);

echo "\nNo admin screen echoes a raw variable\n";
$admin_files = array(
	'includes/class-importer.php',
	'includes/class-case-admin.php',
	'includes/class-scenario-admin.php',
	'includes/class-settings.php',
	'includes/class-seeder.php',
	'includes/class-installer.php',
);
$escapers = '/esc_html|esc_attr|esc_url|esc_textarea|esc_js|wp_kses|absint|\(int\)|intval|checked\(|selected\(|submit_button|settings_fields|wp_nonce_field|_e\(/';
$raw_echo = array();
foreach ( $admin_files as $rel ) {
	foreach ( explode( "\n", $php[ $rel ] ?? '' ) as $n => $line ) {
		if ( 1 !== preg_match( '/\b(echo|printf|print)\b/', $line ) ) {
			continue;
		}
		// A printf placeholder is not a variable: "%1$s" is format syntax.
		$stripped = (string) preg_replace( '/%\d*\$?[sd]/', '', $line );
		if ( ! str_contains( $stripped, '$' ) ) {
			continue;
		}
		if ( 1 === preg_match( $escapers, $line ) ) {
			continue;
		}
		$raw_echo[] = $rel . ':' . ( $n + 1 );
	}
}
hti_games_check(
	array() === $raw_echo,
	sprintf(
		'nothing is printed unescaped in the %d admin files (%s)',
		count( $admin_files ),
		$raw_echo ? implode( ', ', $raw_echo ) : 'clean'
	)
);
// A checker that never fires is a checker nobody can trust.
hti_games_check(
	str_contains( '<?php echo $missing_field; ?>', '$' )
		&& 1 !== preg_match( $escapers, 'echo $missing_field;' )
		&& 1 === preg_match( $escapers, 'echo esc_html( $field );' ),
	'and the check can tell an escaped print from an unescaped one'
);

/* ------------------------------------------------------------------ */
/* 4. The anti-cheat boundary is a whitelist                           */
/* ------------------------------------------------------------------ */

echo "\nThe public payload builders never emit a stored meta key\n";
// Deliberately worse than any real row: every secret present, and poison
// planted inside the nested structures too, where a blacklist would miss it.
$poison = array(
	'hti_stc_ticks'              => (string) wp_json_encode(
		array_map(
			static fn( $i ) => array( 100000 + $i, 100050 + $i, 99950 + $i, 100010 + $i, 'symbol' => 'EURUSD' ),
			range( 0, 119 )
		)
	),
	'hti_stc_visible'            => 80,
	'hti_stc_outcome'            => 40,
	'hti_stc_scale'              => 100000,
	'hti_stc_symbol'             => 'EURUSD',
	'hti_stc_class'              => 'trap',
	'hti_stc_pass_right'         => '1',
	'hti_stc_real'               => '1',
	'hti_stc_source'             => 'import:secret.csv',
	'hti_stc_seed'               => 'seed:abc',
	'hti_stc_checksum'           => 'deadbeef',
	'hti_stc_lesson_en'          => 'The answer was to sell.',
	'hti_rev_company'            => 'Nokia Corporation',
	'hti_rev_year'               => 2007,
	'hti_rev_return_5y_bp'       => -8500,
	'hti_rev_index_return_5y_bp' => 1200,
	'hti_rev_sector_en'          => 'Technology hardware',
	'hti_rev_revenue_band_en'    => 'Over $50bn',
	'hti_rev_fundamentals'       => (string) wp_json_encode(
		array( array( 'key' => 'pe', 'label_en' => 'P/E', 'value_en' => '18x', 'sector_avg_en' => '15x', 'tint' => 'good', 'company' => 'Nokia Corporation' ) )
	),
	'hti_rev_headlines'          => (string) wp_json_encode( array( array( 'en' => 'A record quarter', 'note' => 'Nokia Corporation' ) ) ),
	'hti_rev_context_en'         => 'It fell 85%.',
	'hti_rev_lesson_en'          => 'Dominance is not a moat.',
	'hti_rev_source_url'         => 'https://example.com/nokia-annual-report-2007.pdf',
	'hti_rev_verified'           => '1',
);

$payloads = array(
	'stc'    => REST::public_challenge_stc( $poison, array() ),
	'reveal' => REST::public_challenge_reveal( $poison, array() ),
);

foreach ( $payloads as $game => $payload ) {
	$json = (string) wp_json_encode( $payload );

	hti_games_check(
		0 === preg_match( '/"hti_(?:stc|rev)_/', $json ),
		"the {$game} payload carries no key named like a stored meta field"
	);
	foreach ( array( 'EURUSD', 'Nokia', 'seed:abc', 'deadbeef', 'import:secret.csv', 'annual-report' ) as $secret ) {
		hti_games_check(
			! str_contains( $json, $secret ),
			"the {$game} payload does not contain '{$secret}'"
		);
	}
	hti_games_check( ! isset( $payload['id'] ), "the {$game} payload carries no post id" );
	hti_games_check( true !== ( $payload['played'] ?? null ) || false === $payload['played'], "the {$game} payload starts unplayed" );
}

echo "\nThe day handle is an HMAC, not a post id\n";
$ref = $payloads['stc']['ref'] ?? '';
hti_games_check( 1 === preg_match( '/^[0-9a-f]{16}$/', $ref ), 'the ref is 16 hex characters' );
hti_games_check( (string) (int) $ref !== $ref, 'it is not a number, so it is not an id anything can be enumerated from' );
hti_games_check(
	str_contains( $rest_src, "hash_hmac( 'sha256'" ) && str_contains( $rest_src, "wp_salt( 'auth' )" ),
	'and it is derived with hash_hmac under wp_salt, so it cannot be computed off-site'
);

echo "\nThe outcome is not reachable from outside the class\n";
$reflect = new ReflectionClass( REST::class );
foreach ( array( 'outcome_ticks', 'run_result', 'source', 'block' ) as $method ) {
	hti_games_check(
		$reflect->hasMethod( $method ) && $reflect->getMethod( $method )->isPrivate(),
		"REST::{$method}() is private — it reads the fields the whitelists refuse to touch"
	);
}
hti_games_check(
	$reflect->getMethod( 'run_result' )->isPrivate()
		&& 1 === preg_match( '/\$run = \$row \? self::find_run\(/', $rest_src ),
	'and the only caller reaches it with a recorded run in hand'
);

echo "\nA second decision on the same day cannot re-apply capital\n";
$sql = Store::create_sql( 'wp_', '' );
hti_games_check(
	str_contains( $sql['runs'], 'UNIQUE KEY one_per_day (player_id, game, day_key)' ),
	'the runs table carries the UNIQUE key that makes a second insert fail'
);
hti_games_check(
	! preg_match( "/\\\$wpdb->replace|ON DUPLICATE KEY|INSERT IGNORE INTO `\{\\\$runs\}`/", $rest_src ),
	'and nothing in the write path turns that refusal into an upsert'
);
hti_games_check(
	2 === substr_count( $rest_src, 'last_day IS NULL OR' ),
	'both capital updates carry the "not already applied today" guard in their WHERE clause'
);

/* ------------------------------------------------------------------ */
/* 5. The kill-switches actually switch something off                  */
/* ------------------------------------------------------------------ */

echo "\nEvery kill-switch is enforced on the server, not only in the renderer\n";
$auth_src = $php['includes/class-auth.php'] ?? '';
hti_games_check( 2 === substr_count( $rest_src, 'self::game_enabled( $game )' ), 'both /today and /decision check the per-game switch' );
hti_games_check( str_contains( $rest_src, 'self::board_enabled()' ), '/leaderboard checks the board switch before it serves nicknames' );
hti_games_check( str_contains( $auth_src, 'self::link_enabled()' ), '/link checks the cross-device switch before it collects an address' );
hti_games_check( str_contains( $auth_src, 'self::newsletter_enabled() &&' ), 'and the newsletter forward checks its own, separate switch' );
hti_games_check(
	1 === preg_match( "/function newsletter_enabled\(\): bool \{\s*return class_exists\(/", $auth_src ),
	'the marketing switch fails CLOSED when settings are unavailable (no leading `!`), unlike the gameplay ones'
);

/* ------------------------------------------------------------------ */
/* 6. RGPD: what is stored, and that all of it can be erased           */
/* ------------------------------------------------------------------ */

echo "\nNeither table holds an email or an IP address\n";
foreach ( array( 'players', 'runs' ) as $which ) {
	$columns = array();
	foreach ( explode( "\n", $sql[ $which ] ) as $line ) {
		if ( 1 === preg_match( '/^\s+([a-z_]+)\s+(?:bigint|int|smallint|tinyint|char|varchar|datetime|text)/', $line, $m ) ) {
			$columns[] = $m[1];
		}
	}
	hti_games_check( count( $columns ) >= 5, "the {$which} table's columns are readable (" . count( $columns ) . ')' );
	$personal = array_values(
		array_filter(
			$columns,
			static fn( $c ) => 1 === preg_match( '/^(email|user_email|ip|ip_address|user_ip|remote_addr|ua|user_agent|name|full_name)$/', $c )
		)
	);
	hti_games_check(
		array() === $personal,
		"the {$which} table has no identifying column (" . ( $personal ? implode( ', ', $personal ) : 'clean' ) . ')'
	);
}
hti_games_check(
	str_contains( $sql['players'], 'user_id bigint(20) unsigned NOT NULL DEFAULT 0' ),
	'an account is a join to wp_users and nothing more, so the email lives in exactly one place'
);

echo "\nThe acknowledgement is recorded, and is never treated as a consent basis\n";
$player_src = $php['includes/class-player.php'] ?? '';
hti_games_check( str_contains( $sql['players'], 'ack_at datetime' ) && str_contains( $sql['players'], 'ack_ver varchar' ), 'ack_at and ack_ver are columns' );
hti_games_check( 1 === preg_match( "/'ack_ver'\s*=>\s*Player::ACK_VERSION/", $rest_src ), 'the version is stamped server-side, never taken from the client' );
hti_games_check( str_contains( $privacy_src, 'This is an acknowledgement, not a consent basis.' ), 'the export says in words what the record is and is not' );
hti_games_check(
	! preg_match( "/'consent'\s*=>\s*\\\$?(row|ctx)\['ack/", $player_src . $privacy_src ),
	'and nothing anywhere reads ack_* as a consent field'
);

echo "\nThe newsletter opt-in is separate, optional and off by default\n";
$settings_src = $php['includes/class-settings.php'] ?? '';
$frontend_src = $php['includes/class-frontend.php'] ?? '';
hti_games_check( 1 === preg_match( "/'newsletter_optin'\s*=>\s*false,/", $settings_src ), 'it defaults to off' );
// Matched on the input element rather than on the whole line: the markup
// around it is another workstream's to change, the absence of `checked` is not.
preg_match_all( '/<input[^>]*name="newsletter"[^>]*>/', $frontend_src, $boxes );
hti_games_check( array() !== ( $boxes[0] ?? array() ), 'the opt-in is a real checkbox in the markup' );
foreach ( $boxes[0] ?? array() as $box ) {
	hti_games_check(
		! str_contains( $box, 'checked' ),
		'and carries no checked attribute — an unticked box is the only kind that can carry consent'
	);
}
hti_games_check(
	str_contains( $auth_src, 'Subscribe::request_optin' ),
	'and the address goes to hti-engine\'s double opt-in rather than to a list of our own'
);
hti_games_check(
	! preg_match( "/(?:'|\")(?:subscriber|newsletter)_email(?:'|\")/", implode( "\n", $php ) ),
	'no subscriber address is stored on this site'
);

echo "\nErasure reaches every row, on every one of the four paths\n";
hti_games_check( str_contains( $privacy_src, "add_filter( 'hti_export_data'" ), 'the export filter is hooked' );
hti_games_check( str_contains( $privacy_src, "add_action( 'hti_account_hard_delete'" ), 'the account hard-delete action is hooked' );
hti_games_check( str_contains( $privacy_src, "add_filter( 'wp_privacy_personal_data_exporters'" ), 'core\'s exporter is registered' );
hti_games_check( str_contains( $privacy_src, "add_filter( 'wp_privacy_personal_data_erasers'" ), 'core\'s eraser is registered' );
hti_games_check( str_contains( $privacy_src, "add_action( 'hti_prune_profiles'" ), 'and retention rides an already-scheduled job' );
hti_games_check(
	str_contains( $privacy_src, 'foreach ( Player::ids_for_user( $user_id ) as $id )' ),
	'erase_user() enumerates every row for the account — Player::by_user() is LIMIT 1 and nothing in the schema forbids a second row'
);
hti_games_check(
	str_contains( $privacy_src, 'self::runs_for_user( (int) $user_id )' ),
	'and the export reads every row\'s runs for the same reason'
);
hti_games_check(
	str_contains( $privacy_src, '$wpdb->delete( Store::runs_table()' ) && str_contains( $privacy_src, '$wpdb->delete( Store::players_table()' ),
	'erase_player() deletes from both tables, runs first'
);
hti_games_check(
	str_contains( $privacy_src, 'Player::clear_cookie();' ),
	'and the self-serve erase drops the identity cookie whether or not there was a row'
);

echo "\nThe retention prune is bounded and honours the configured window\n";
hti_games_check( str_contains( $privacy_src, 'LIMIT %d' ) && str_contains( $privacy_src, 'self::BATCH' ), 'the prune reads a bounded batch' );
hti_games_check( str_contains( $privacy_src, 'WHERE user_id = 0 AND last_seen < %s' ), 'and only anonymous rows idle past the cutoff' );
hti_games_check( str_contains( $privacy_src, "apply_filters( 'hti_games_retention_days', self::retention_days() )" ), 'the window comes from the setting, not from a constant nothing reads' );
hti_games_check(
	str_contains( $privacy_src, 'max( Settings::RETENTION_MIN, min( Settings::RETENTION_MAX, $days ) )' ),
	'clamped to its own bounds, so a hand-edited option cannot set a window of zero and delete every anonymous row'
);

echo "\nNo PII, and nothing at all, is written to a log\n";
$logging = array();
foreach ( $php as $rel => $text ) {
	if ( 1 === preg_match( '/\b(error_log|var_dump|print_r|var_export)\s*\(/', $text ) ) {
		$logging[] = $rel;
	}
}
hti_games_check( array() === $logging, 'no logging or dumping call anywhere (' . ( $logging ? implode( ', ', $logging ) : 'clean' ) . ')' );

/* ------------------------------------------------------------------ */
/* 7. The identity cookie and the magic link                           */
/* ------------------------------------------------------------------ */

echo "\nThe identity cookie is not readable by script and does not ride a cross-site POST\n";
hti_games_check( 2 === preg_match_all( "/'httponly'\s*=>\s*true,/", $player_src ), 'HttpOnly on both the set and the clear' );
hti_games_check( 2 === preg_match_all( "/'samesite'\s*=>\s*'Lax',/", $player_src ), 'SameSite=Lax on both' );
hti_games_check( 2 === preg_match_all( "/'secure'\s*=>\s*is_ssl\(\),/", $player_src ), 'and Secure whenever the request is' );
hti_games_check( Player::is_uuid( '3f2504e0-4f89-41d3-9a0c-0305e82c3301' ), 'a v4 uuid is accepted as an identity' );
foreach ( array( '', 'x', '../../etc/passwd', "3f2504e0-4f89-41d3-9a0c-0305e82c3301' OR '1", '3f2504e04f8941d39a0c0305e82c3301' ) as $bad ) {
	hti_games_check( ! Player::is_uuid( $bad ), 'and ' . var_export( $bad, true ) . ' is not' );
}
hti_games_check(
	str_contains( $player_src, '$row = self::by_uuid( (string) ( $ctx[\'uuid\'] ?? \'\' ) );' ),
	'a client-presented uuid is only ever a lookup key — it never becomes the uuid of a new row'
);

echo "\nThe magic-link token is high entropy, hashed at rest, expiring and single use\n";
hti_games_check( str_contains( $auth_src, "wp_generate_password( 40, false )" ), 'the token is 40 characters from the alphanumeric alphabet' );
hti_games_check( 1 === preg_match( "/update_user_meta\(\s*\\\$user_id,\s*self::META_TOKEN,\s*hash\( 'sha256'/", $auth_src ), 'only its sha256 is stored — a dumped database is not a set of working links' );
hti_games_check( ! preg_match( '/META_TOKEN,\s*\$plain\s*\)/', $auth_src ), 'and the plaintext is never written anywhere' );
hti_games_check( str_contains( $auth_src, 'hash_equals(' ), 'the comparison is constant-time' );
hti_games_check( str_contains( $auth_src, 'time() < $expires' ), 'the expiry is checked' );
hti_games_check( str_contains( $auth_src, 'private const TTL = 900;' ), 'and it is fifteen minutes' );
hti_games_check(
	strpos( $auth_src, 'wp_set_auth_cookie( $uid, true );' ) < strpos( $auth_src, 'delete_user_meta( $uid, self::META_TOKEN );' ),
	'the token is consumed AFTER the sign-in, so a failed attempt cannot burn a live link'
);
hti_games_check( str_contains( $auth_src, 'if ( self::is_prefetch() ) {' ), 'an announced prefetch is refused before the token is even read' );
hti_games_check(
	str_contains( $auth_src, "'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ'" ) && str_contains( $auth_src, "'HEAD' === \$method" ),
	'across the three headers scanners send, and HEAD'
);
// The guard must not become the bypass. Its whole body is a bare return: it
// signs nobody in, spends nothing, and changes no state — so an attacker who
// simply sends the header gets exactly nothing for it.
hti_games_check(
	1 === preg_match( '/if \( self::is_prefetch\(\) \) \{\s*return;\s*\}/', $auth_src ),
	'and the prefetch branch does nothing but return — the guard cannot itself become a bypass'
);

echo "\nAnd it tells a stranger nothing about whether an address has an account\n";
hti_games_check( str_contains( $auth_src, '$neutral = new WP_REST_Response(' ), 'one neutral body is built once' );
hti_games_check( 4 <= substr_count( $auth_src, 'return $neutral' ), 'and returned from every address-dependent branch (' . substr_count( $auth_src, 'return $neutral' ) . ')' );
hti_games_check( str_contains( $auth_src, "trim( (string) \$request->get_param( 'hti_hp' ) )" ), 'the honeypot answers success rather than announcing itself' );

/* ------------------------------------------------------------------ */
/* 8. The upload                                                       */
/* ------------------------------------------------------------------ */

echo "\nThe candle importer refuses what it should\n";
$importer_src = $php['includes/class-importer.php'] ?? '';
hti_games_check( str_contains( $importer_src, 'is_uploaded_file( $upload[\'tmp_name\'] )' ), 'the file has to be a real upload — a forged tmp_name cannot make it read /etc/passwd' );
hti_games_check( str_contains( $importer_src, 'private const MAX_UPLOAD = 8388608;' ), 'there is a size cap' );
hti_games_check( str_contains( $importer_src, 'filesize( $upload[\'tmp_name\'] )' ), 'and it is measured against the file on disk, not only against the multipart header' );
hti_games_check( str_contains( $importer_src, 'sanitize_file_name(' ), 'the filename is sanitised before it is stored as provenance' );
hti_games_check( ! preg_match( '/move_uploaded_file|wp_handle_upload|copy\(/', $importer_src ), 'nothing is ever moved into the web root — the temp file is read and dropped' );
hti_games_check( str_contains( $importer_src, "'post_status' => 'draft'," ), 'everything it creates is a draft, so nothing it parses reaches a player unreviewed' );
hti_games_check( str_contains( $importer_src, 'in_array( $scale, self::SCALES, true )' ), 'the declared scale is checked against a closed set' );
hti_games_check( str_contains( $importer_src, 'max( 1, min( 1000, (int)' ), 'and the stride is bounded' );

// A malformed file must not be able to produce a scenario the engine chokes on.
$malformed = \HTI\Games\Importer::parse( "notatime,x,y,z,w\n1,2,3\n", 'csv', 100000 );
hti_games_check( array() !== $malformed['errors'], 'a nonsense CSV comes back as errors' );
hti_games_check( array() === \HTI\Games\Importer::slice( $malformed['rows'], \HTI\Games\Importer::WINDOW, 40 ), 'and yields no window to build a scenario from' );
$flat = array_fill( 0, 200, array( 'ts' => 1, 'o' => 100, 'h' => 100, 'l' => 100, 'c' => 100 ) );
$kept = \HTI\Games\Importer::screen( \HTI\Games\Importer::slice( $flat, \HTI\Games\Importer::WINDOW, 40 ) );
hti_games_check( array() === $kept['keep'], 'a flat series is dropped rather than published as a chart with a zero ATR' );

/* ------------------------------------------------------------------ */
/* 9. Uninstall leaves nothing                                         */
/* ------------------------------------------------------------------ */

echo "\nuninstall.php names every table, option, post type and meta key the plugin creates\n";
foreach ( hti_sec_all( "/\\\$wpdb->prefix \. '(hti_games_[a-z_]+)'/", implode( "\n", $php ) ) as $table ) {
	hti_games_check( str_contains( $uninstall, "'" . $table . "'" ), "the {$table} table is dropped" );
}
foreach ( hti_sec_all( "/const\s+OPTION[A-Z_]*\s*=\s*'(hti_games_[a-z_]+)'/", implode( "\n", $php ) ) as $option ) {
	hti_games_check( str_contains( $uninstall, "'" . $option . "'" ), "the {$option} option is deleted" );
}
foreach ( array( 'hti_stc_scenario', 'hti_reveal_case' ) as $type ) {
	hti_games_check( str_contains( $uninstall, "'" . $type . "'" ), "the {$type} posts are deleted, and their meta with them" );
}
foreach ( hti_sec_all( "/const\s+META_[A-Z_]+\s*=\s*'(hti_games_[a-z_]+)'/", implode( "\n", $php ) ) as $meta ) {
	hti_games_check( str_contains( $uninstall, "'" . $meta . "'" ), "the {$meta} user meta is deleted across all users" );
}

echo "\nAnd every transient it coins is inside the one prefix uninstall sweeps\n";
hti_games_check(
	str_contains( $uninstall, "'_transient_hti_games_'" ) && str_contains( $uninstall, "'_transient_timeout_hti_games_'" ),
	'uninstall deletes both the value and the timeout row by prefix'
);
// Resolved from the call sites rather than guessed at from constant names:
// every identifier handed to set/get/delete_transient(), traced back to the
// literal it was built from in the same file.
$prefixes = array();
foreach ( $php as $rel => $text ) {
	preg_match_all( '/(?:set|get|delete)_transient\(\s*(?:self::([A-Z_]+)|\$([a-z_]+))/', $text, $m, PREG_SET_ORDER );
	foreach ( $m as $hit ) {
		$name    = '' !== $hit[1] ? $hit[1] : ( $hit[2] ?? '' );
		$pattern = '' !== $hit[1]
			? "/const\s+{$name}\s*=\s*'([^']*)'/"
			: "/\\\${$name}\s*=\s*'([^']*)'/";
		foreach ( hti_sec_all( $pattern, $text ) as $literal ) {
			$prefixes[] = $rel . ': ' . $literal;
		}
	}
}
$prefixes = array_values( array_unique( $prefixes ) );
hti_games_check( count( $prefixes ) >= 5, 'the transient keys resolve to their literals (' . count( $prefixes ) . ')' );
$stray = array_values( array_filter( $prefixes, static fn( $p ) => ! str_contains( $p, ': hti_games_' ) ) );
hti_games_check(
	array() === $stray,
	'every one starts with hti_games_, which is what makes the prefix sweep complete (' . ( $stray ? implode( '; ', $stray ) : 'clean' ) . ')'
);

echo "\nNothing else this plugin writes survives it\n";
hti_games_check(
	! preg_match( '/add_option\(|update_site_option\(|update_network_option\(/', implode( "\n", $php ) ),
	'no option is written by a route uninstall does not cover'
);
hti_games_check(
	! preg_match( "/update_user_meta\(\s*[^,]+,\s*'(?!hti_games_)/", implode( "\n", $php ) ),
	'and no user meta is written under a key outside the plugin namespace'
);

hti_games_done();
