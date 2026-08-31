<?php
/**
 * Identity: the uuid, the nickname, and what happens when a run meets an account.
 *
 * Three things are worth a test here, and all three are pure functions that a
 * harness with no database can reach.
 *
 * The uuid check, because it is the gate every request walks through. It is
 * strict on purpose — the value arrives from the open web on every call — and
 * the interesting cases are the near-misses a loose regex would wave past.
 *
 * The nickname rules, because the nickname is the one free-text field a player
 * can put on a public page. Validated at input, escaped at output, and the
 * charset is what makes both of those cheap.
 *
 * And the merge, because signing in must never cost somebody a run. It is the
 * union model hti-engine's Learn uses for guest → account progress, adapted
 * for a thing that is not a set: capital, streak and last day only mean
 * anything together, so the better RUN survives whole rather than the better
 * numbers being cherry-picked field by field.
 *
 *   php wp-content/plugins/hti-games/tests/test-player.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-player.php';

use HTI\Games\Config;
use HTI\Games\Player;

/**
 * A player row with the fields the merge reads.
 *
 * @param array<string,mixed> $over Overrides.
 * @return array<string,mixed>
 */
function row( array $over = array() ): array {
	return array_merge(
		array(
			'id'              => 1,
			'uuid'            => '11111111-1111-4111-8111-111111111111',
			'user_id'         => 0,
			'nickname'        => '',
			'nickname_key'    => null,
			'lang'            => 'en',
			'ack_at'          => '2026-08-01 10:00:00',
			'ack_ver'         => '1',
			'newsletter'      => 0,
			'stc_capital'     => Config::CAPITAL_START,
			'stc_streak'      => 0,
			'stc_best_streak' => 0,
			'stc_deaths'      => 0,
			'stc_last_day'    => '',
			'rev_capital'     => Config::CAPITAL_START,
			'rev_index_cap'   => Config::CAPITAL_START,
			'rev_streak'      => 0,
			'rev_best_streak' => 0,
			'rev_deaths'      => 0,
			'rev_last_day'    => '',
		),
		$over
	);
}

echo "A uuid arriving from the open web is checked, not trusted\n";
hti_games_check( Player::is_uuid( '3f2504e0-4f89-41d3-9a0c-0305e82c3301' ), 'a real v4 uuid passes' );
hti_games_check( Player::is_uuid( '3F2504E0-4F89-41D3-9A0C-0305E82C3301' ), 'and so does the same one in upper case' );
hti_games_check( ! Player::is_uuid( '3f2504e0-4f89-11d3-9a0c-0305e82c3301' ), 'a version-1 uuid does not — wp_generate_uuid4() never makes one' );
hti_games_check( ! Player::is_uuid( '3f2504e0-4f89-41d3-ca0c-0305e82c3301' ), 'nor does a wrong variant nibble' );
hti_games_check( ! Player::is_uuid( '3f2504e04f8941d39a0c0305e82c3301' ), 'nor the same value without hyphens' );
hti_games_check( ! Player::is_uuid( "3f2504e0-4f89-41d3-9a0c-0305e82c3301' OR 1=1" ), 'nor anything with SQL appended' );
hti_games_check( ! Player::is_uuid( '' ), 'nor an empty string' );
hti_games_check( ! Player::is_uuid( '../../etc/passwd' ), 'nor a path' );

echo "\nNicknames: 3–24, letters, digits, - and _\n";
hti_games_check( Player::validate_nickname( 'quiet_trader' )['ok'], 'an ordinary handle is fine' );
hti_games_check( Player::validate_nickname( 'ab-1' )['ok'], 'so are hyphens and digits' );
hti_games_check( 'quiet_trader' === Player::validate_nickname( '  quiet_trader  ' )['nickname'], 'surrounding whitespace is trimmed' );
hti_games_check( 'short' === Player::validate_nickname( 'ab' )['code'], 'two characters is too short' );
hti_games_check( 'long' === Player::validate_nickname( str_repeat( 'a', 25 ) )['code'], 'twenty-five is too long' );
hti_games_check( Player::validate_nickname( str_repeat( 'a', 24 ) )['ok'], 'twenty-four is not' );
hti_games_check( 'chars' === Player::validate_nickname( 'my name' )['code'], 'an internal space is rejected rather than silently deleted' );
hti_games_check( 'chars' === Player::validate_nickname( '<script>x</script>' )['code'], 'markup cannot be a name' );
hti_games_check( 'chars' === Player::validate_nickname( 'joão' )['code'], 'accents are rejected for now — a public board plus arbitrary Unicode is homoglyph impersonation' );
hti_games_check( 'edges' === Player::validate_nickname( '_lead' )['code'], 'a leading separator is rejected' );
hti_games_check( 'edges' === Player::validate_nickname( 'trail-' )['code'], 'and so is a trailing one' );

echo "\nAnd nobody gets to look like us\n";
hti_games_check( 'blocked' === Player::validate_nickname( 'admin' )['code'], 'admin is blocked' );
hti_games_check( 'blocked' === Player::validate_nickname( 'ADMIN' )['code'], 'in any case' );
hti_games_check( 'blocked' === Player::validate_nickname( 'a-d-m-i-n' )['code'], 'with separators sprinkled through it' );
hti_games_check( 'blocked' === Player::validate_nickname( '4dm1n' )['code'], 'and with the usual digit substitutions' );
hti_games_check( 'blocked' === Player::validate_nickname( 'HowToInvest' )['code'], 'so is the site name' );
hti_games_check( Player::validate_nickname( 'admiral' )['ok'], 'but a real word that merely starts the same way is fine' );

echo "\nUniqueness is case-insensitive, and the key is what the index holds\n";
hti_games_check( 'quiettrader' === Player::nickname_key( 'QuietTrader' ), 'the key is the lower-cased name' );
hti_games_check( Player::nickname_key( 'ABC' ) === Player::nickname_key( 'abc' ), 'two casings share one key, so the UNIQUE index catches them' );
hti_games_check( Player::nickname_key( 'a-b' ) !== Player::nickname_key( 'ab' ), 'but separators still distinguish two names' );

echo "\nA duplicate-key error is recognised for what it is\n";
hti_games_check( Player::is_duplicate( "Duplicate entry 'abc' for key 'nickname_key'" ), 'MySQL 1062 is read as a collision' );
hti_games_check( Player::is_duplicate( 'DUPLICATE ENTRY ...' ), 'whatever its casing' );
hti_games_check( ! Player::is_duplicate( 'Table does not exist' ), 'and a real failure is not mistaken for one' );
hti_games_check( ! Player::is_duplicate( '' ), 'nor is silence' );

echo "\nMerging two identities: the better run survives whole\n";
$account = row(
	array(
		'id'              => 7,
		'user_id'         => 42,
		'nickname'        => 'AccountName',
		'nickname_key'    => 'accountname',
		'stc_capital'     => 9000,
		'stc_streak'      => 2,
		'stc_best_streak' => 4,
		'stc_deaths'      => 1,
		'stc_last_day'    => '2026-08-20',
	)
);
$anon = row(
	array(
		'id'              => 9,
		'stc_capital'     => 24000,
		'stc_streak'      => 11,
		'stc_best_streak' => 11,
		'stc_deaths'      => 2,
		'stc_last_day'    => '2026-08-30',
	)
);

$merged = Player::merge_rows( $account, $anon );
hti_games_check( 24000 === $merged['stc_capital'], 'the richer run wins the capital' );
hti_games_check( 11 === $merged['stc_streak'], 'and its streak comes with it, not the other one' );
hti_games_check( 11 === $merged['stc_best_streak'], 'the personal best is the higher of the two' );
hti_games_check( 3 === $merged['stc_deaths'], 'deaths are summed — a death that happened, happened' );
hti_games_check( '2026-08-30' === $merged['stc_last_day'], 'the last day is the LATER of the two, so today cannot be applied twice after a merge' );
hti_games_check( 'AccountName' === $merged['nickname'], "the account's name survives, because it is the one already on the board" );

echo "\nOrder must not decide the outcome\n";
$other = Player::merge_rows( $anon, $account );
hti_games_check( $other['stc_capital'] === $merged['stc_capital'], 'merging the other way round keeps the same capital' );
hti_games_check( $other['stc_deaths'] === $merged['stc_deaths'], 'and the same deaths' );
hti_games_check( $other['stc_best_streak'] === $merged['stc_best_streak'], 'and the same personal best' );

echo "\nThe two games merge independently\n";
$split_a = row( array( 'stc_capital' => 30000, 'rev_capital' => 2000, 'rev_index_cap' => 2500, 'rev_streak' => 1 ) );
$split_b = row( array( 'stc_capital' => 5000, 'rev_capital' => 40000, 'rev_index_cap' => 11000, 'rev_streak' => 9 ) );
$split   = Player::merge_rows( $split_a, $split_b );
hti_games_check( 30000 === $split['stc_capital'], 'the better chart run wins the chart game' );
hti_games_check( 40000 === $split['rev_capital'], 'and the better dossier run wins the other one' );
hti_games_check( 11000 === $split['rev_index_cap'], 'the index travels with the capital it is measured against, never crossed over' );
hti_games_check( 9 === $split['rev_streak'], "and so does that run's streak" );

echo "\nA tie is broken by the streak, then by the surviving row\n";
$tie_a = row( array( 'stc_capital' => 10000, 'stc_streak' => 3 ) );
$tie_b = row( array( 'stc_capital' => 10000, 'stc_streak' => 8 ) );
hti_games_check( 8 === Player::merge_rows( $tie_a, $tie_b )['stc_streak'], 'same capital, longer streak wins' );
hti_games_check( 3 === Player::merge_rows( $tie_a, row( array( 'stc_capital' => 10000, 'stc_streak' => 3 ) ) )['stc_streak'], 'a total tie leaves the surviving row alone' );

echo "\nA nickname is picked up when only one side has one\n";
$named   = row( array( 'nickname' => 'Solo', 'nickname_key' => 'solo' ) );
$nameless = row();
hti_games_check( 'Solo' === Player::merge_rows( $nameless, $named )['nickname'], 'the anonymous row hands its name to the account' );
hti_games_check( 'solo' === Player::merge_rows( $nameless, $named )['nickname_key'], 'and the uniqueness key comes with it' );
hti_games_check( null === Player::merge_rows( $nameless, $nameless )['nickname_key'], 'two nameless rows produce a NULL key, not an empty string that would collide' );

echo "\nThe acknowledgement record keeps the earliest date, not the newest\n";
$early = row( array( 'ack_at' => '2026-01-01 09:00:00', 'ack_ver' => '1' ) );
$late  = row( array( 'ack_at' => '2026-08-01 09:00:00', 'ack_ver' => '2' ) );
hti_games_check( '2026-01-01 09:00:00' === Player::merge_rows( $late, $early )['ack_at'], 'when they were actually shown the warning is what is recorded' );
hti_games_check( '1' === Player::merge_rows( $late, $early )['ack_ver'], 'and the version they read at the time comes with it' );

echo "\nThe newsletter flag is a union, never a reset\n";
hti_games_check( 1 === Player::merge_rows( row(), row( array( 'newsletter' => 1 ) ) )['newsletter'], 'a yes on either side is a yes' );
hti_games_check( 0 === Player::merge_rows( row(), row() )['newsletter'], 'and two nos stay a no' );

echo "\nThe public shape is a whitelist too\n";
$public = Player::public_row( row( array( 'user_id' => 42, 'nickname' => 'Someone', 'stc_capital' => 7777 ) ) );
hti_games_check( ! isset( $public['id'] ), 'the internal row id never reaches the client' );
hti_games_check( ! isset( $public['user_id'] ), 'nor does the WordPress user id' );
hti_games_check( ! isset( $public['ack_at'] ), 'nor the raw acknowledgement timestamp' );
hti_games_check( true === $public['linked'], 'the account link is reported as a boolean instead' );
hti_games_check( 7777 === $public['stc']['capital'], 'the numbers that matter are there' );

$blank = Player::public_row( null );
hti_games_check( '' === $blank['uuid'], 'a visitor who never onboarded has no identity to report' );
hti_games_check( false === $blank['onboarded'], 'and is not marked as onboarded' );
hti_games_check( Config::CAPITAL_START === $blank['stc']['capital'], 'but still sees what a run would start at' );

echo "\nLanguage is reduced to the two we serve\n";
hti_games_check( 'pt' === Player::lang( 'pt_PT_ao90' ), 'the site locale resolves to pt' );
hti_games_check( 'pt' === Player::lang( 'PT' ), 'in any case' );
hti_games_check( 'en' === Player::lang( 'fr_FR' ), 'anything else is en' );
hti_games_check( 'en' === Player::lang( '' ), 'including nothing at all' );

hti_games_done();
