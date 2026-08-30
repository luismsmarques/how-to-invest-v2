<?php
/**
 * The games carry no broker, anywhere.
 *
 * CLAUDE.md invariant 9. The reasoning is not stylistic: ESMA's product
 * intervention measures prohibit monetary and non-monetary benefits offered in
 * connection with the marketing of CFDs to retail clients, and a competitive
 * leaderboard that rewards leverage, sitting next to a broker CTA, is close
 * enough to that line that it belongs on the far side of it. The /forex/
 * exemption is scoped to /forex/ and does not extend here by analogy.
 *
 * A rule in a document is a wish. This file is the control: it reads every
 * source file the plugin ships and fails if an affiliate surface appears in
 * one. It runs on the source rather than on rendered HTML because the pure
 * harness has no WordPress — a rendered-page sweep is on the staging QA list,
 * and this catches the mistake at the commit that makes it.
 *
 *   php wp-content/plugins/hti-games/tests/test-no-brokers.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

/**
 * Every source file the plugin ships, tests excluded — this file names the
 * forbidden strings itself and would otherwise flag itself.
 *
 * @return array<int,string>
 */
function hti_games_sources( string $root ): array {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$path = $file->getPathname();
		if ( str_contains( $path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
			continue;
		}
		if ( preg_match( '/\.(php|js|mjs|css|html)$/', $path ) ) {
			$out[] = $path;
		}
	}
	sort( $out );
	return $out;
}

$sources = hti_games_sources( $root );

echo "There is something to audit\n";
hti_games_check( count( $sources ) >= 5, sprintf( 'found %d source files in the plugin', count( $sources ) ) );

echo "\nNo outbound affiliate surface\n";
// The redirector, the rel that only ever accompanies a paid link, and the
// query parameter the /go/ links carry.
$outbound = array(
	'/go/'                => 'the affiliate redirector',
	'rel="sponsored'      => 'a sponsored link rel',
	"rel='sponsored"      => 'a sponsored link rel',
	'hti_broker'          => 'broker meta',
	'HTI\\Engine\\Brokers' => 'the brokers class',
	'Broker_Go'           => 'the broker redirector class',
	'partner_module'      => 'the post-result partner module',
	'cta_url'             => 'a partner CTA url',
);
foreach ( $outbound as $needle => $what ) {
	$hits = array();
	foreach ( $sources as $file ) {
		if ( str_contains( (string) file_get_contents( $file ), $needle ) ) {
			$hits[] = basename( $file );
		}
	}
	hti_games_check( array() === $hits, "no {$what} (" . ( $hits ? implode( ', ', $hits ) : 'clean' ) . ')' );
}

echo "\nNo broker is named in the shipped source\n";
// The slugs of the brokers the editorial section carries. A game screen that
// mentioned one would be marketing it, whether or not it linked anywhere.
$brokers = array( 'xtb', 'etoro', 'degiro', 'trading212', 'interactive brokers', 'plus500', 'avatrade', 'exness', 'octafx', 'xm.com' );
$named   = array();
foreach ( $sources as $file ) {
	$body = strtolower( (string) file_get_contents( $file ) );
	foreach ( $brokers as $broker ) {
		if ( str_contains( $body, $broker ) ) {
			$named[] = basename( $file ) . ': ' . $broker;
		}
	}
}
hti_games_check( array() === $named, 'no broker name appears (' . ( $named ? implode( '; ', $named ) : 'clean' ) . ')' );

echo "\nNothing offers the player money\n";
// "No prizes" is half of what keeps a scored trading game clear of the
// inducement rules; the other half is that the money is never real. The
// phrases below are offer-shaped on purpose: bare "payout" and "withdraw" are
// ordinary trading and banking vocabulary and appear in engine comments about
// a trade's 1.5R reward, which is not an inducement and should not read as one.
$inducements = array(
	'cash out',
	'cash prize',
	'prize pool',
	'prémio em dinheiro',
	'win real',
	'ganha dinheiro',
	'ganhar dinheiro real',
	'withdraw your',
	'levantar o teu saldo',
	'sign-up bonus',
	'bónus de registo',
	'deposit and',
	'deposita e',
);
$found = array();
foreach ( $sources as $file ) {
	$body = strtolower( (string) file_get_contents( $file ) );
	foreach ( $inducements as $phrase ) {
		if ( str_contains( $body, $phrase ) ) {
			$found[] = basename( $file ) . ': ' . $phrase;
		}
	}
}
hti_games_check( array() === $found, 'nothing offers a prize, a bonus or money (' . ( $found ? implode( '; ', $found ) : 'clean' ) . ')' );

// And the promise is actually made somewhere the player can read it.
$promise = false;
foreach ( $sources as $file ) {
	$body = strtolower( (string) file_get_contents( $file ) );
	if ( str_contains( $body, 'no real money' ) || str_contains( $body, 'sem dinheiro real' ) ) {
		$promise = true;
		break;
	}
}
hti_games_check( $promise, 'the copy states there is no real money involved' );

hti_games_done();
