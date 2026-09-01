<?php
/**
 * The schema, asserted as text.
 *
 * There is no database in this harness, and there does not need to be: what
 * matters about these two tables is what their definition says, and a string
 * is something a test can read on any machine, in CI, before a deploy.
 *
 * The assertion this file exists for is `UNIQUE KEY one_per_day`. That index
 * is the "one game per day" rule and the "a decision is final once submitted"
 * rule, both of them, enforced by MySQL rather than by whichever branch of
 * whichever endpoint happens to run first. Someone tidying up the schema one
 * day — "the REST layer already checks this" — must be met with a red test
 * and this comment, because the check they are trusting cannot see the second
 * request that arrives 4 ms after the first.
 *
 *   php wp-content/plugins/hti-games/tests/test-store-schema.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-store.php';

use HTI\Games\Config;
use HTI\Games\Store;

$sql     = Store::create_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' );
$players = $sql['players'] ?? '';
$runs    = $sql['runs'] ?? '';
$both    = $players . "\n" . $runs;

echo "create_sql() is pure and returns both tables\n";
hti_games_check( is_array( $sql ) && 2 === count( $sql ), 'two CREATE TABLE statements come back' );
hti_games_check( str_contains( $players, 'CREATE TABLE wp_hti_games_players (' ), 'the players table is named from the prefix it was given' );
hti_games_check( str_contains( $runs, 'CREATE TABLE wp_hti_games_runs (' ), 'so is the runs table' );
hti_games_check( str_contains( $players, 'DEFAULT CHARACTER SET utf8mb4' ) && str_contains( $runs, 'DEFAULT CHARACTER SET utf8mb4' ), 'the collation clause is appended to both' );
hti_games_check( Store::create_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' ) === $sql, 'the same arguments always give the same statements — nothing is read from the environment' );
hti_games_check( str_contains( Store::create_sql( 'xyz9_', '' )['players'], 'CREATE TABLE xyz9_hti_games_players (' ), 'a different prefix gives a different table, so a multisite or a test prefix is not a special case' );

echo "\nThe index that IS the one-game-per-day rule\n";
hti_games_check(
	str_contains( $runs, 'UNIQUE KEY one_per_day (player_id, game, day_key)' ),
	'UNIQUE KEY one_per_day (player_id, game, day_key) — do not remove: this index is the only thing that stops a second run, or a second decision, on the same day'
);
hti_games_check( str_contains( $runs, 'KEY board (game, day_key, board_score)' ), 'the leaderboard has an index to read' );
hti_games_check( str_contains( $runs, 'KEY player_hist (player_id, game, created_at)' ), 'so does a player looking at their own history' );

echo "\nnickname_key is NULL-able on purpose\n";
hti_games_check( str_contains( $players, 'nickname_key varchar(24) NULL DEFAULT NULL' ), 'the column is NULL-able and defaults to NULL' );
hti_games_check( str_contains( $players, 'UNIQUE KEY nickname_key (nickname_key)' ), 'and it is UNIQUE — MySQL allows many NULLs but only one empty string, which is why it is not NOT NULL DEFAULT \'\'' );
hti_games_check( str_contains( $players, 'UNIQUE KEY uuid (uuid)' ), 'a player uuid is unique too' );

echo "\nThe defaults agree with Config — the schema is not a second opinion\n";
hti_games_check( str_contains( $players, 'stc_capital int(11) NOT NULL DEFAULT ' . Config::CAPITAL_START ), 'a new player starts Survive the Charts on Config::CAPITAL_START' );
hti_games_check( str_contains( $players, 'rev_capital int(11) NOT NULL DEFAULT ' . Config::CAPITAL_START ), 'and The Reveal on the same figure' );
hti_games_check( str_contains( $players, 'rev_index_cap int(11) NOT NULL DEFAULT ' . Config::CAPITAL_START ), 'the index player starts level with them, or the comparison means nothing' );
hti_games_check( str_contains( $players, "lang char(2) NOT NULL DEFAULT 'en'" ), 'language defaults to en' );
hti_games_check( str_contains( $runs, 'touch_idx smallint(6) NOT NULL DEFAULT -1' ), 'touch_idx defaults to -1: "never touched" is not candle zero' );
hti_games_check( str_contains( $runs, 'multiplier tinyint(3) unsigned NOT NULL DEFAULT 1' ), 'the stake multiplier defaults to 1, not to the doubled stake' );

echo "\nConsent is recorded, identity is not\n";
hti_games_check( str_contains( $players, 'ack_at datetime NULL DEFAULT NULL' ) && str_contains( $players, "ack_ver varchar(8) NOT NULL DEFAULT ''" ), 'the onboarding acknowledgement and its version are stored — GDPR Art. 7(1) asks us to demonstrate it, not to remember having shown it' );
hti_games_check( ! str_contains( strtolower( $both ), 'email' ), 'no email column anywhere: the address lives in wp_users and is covered by the account deletion cascade' );
hti_games_check( ! preg_match( '/\bip(_address)?\b/i', $both ), 'no IP column either — it would make every leaderboard a personal-data export and buy nothing' );
hti_games_check( str_contains( $players, 'user_id bigint(20) unsigned NOT NULL DEFAULT 0' ), 'an account, when there is one, is a user_id and nothing more' );

echo "\nEvery writable column exists in the table it belongs to\n";
$missing_player = array_filter(
	array_keys( Store::PLAYER_COLUMNS ),
	static fn( string $column ): bool => ! str_contains( $players, "\n\t" . $column . ' ' )
);
hti_games_check( array() === $missing_player, 'PLAYER_COLUMNS has no column the players table lacks (' . implode( ', ', $missing_player ) . ')' );

$missing_run = array_filter(
	array_keys( Store::RUN_COLUMNS ),
	static fn( string $column ): bool => ! str_contains( $runs, "\n\t" . $column . ' ' )
);
hti_games_check( array() === $missing_run, 'RUN_COLUMNS has no column the runs table lacks (' . implode( ', ', $missing_run ) . ')' );

hti_games_check( ! isset( Store::PLAYER_COLUMNS['id'], Store::RUN_COLUMNS['id'] ), 'id is not writable: it is AUTO_INCREMENT and nothing outside the database may set it' );

echo "\nThe format array is derived from the column map, never hand-written\n";
hti_games_check( array( '%d', '%s' ) === Store::formats( 'runs', array( 'player_id' => 7, 'day_key' => '2026-08-30' ) ), 'an integer column gets %d and a string column %s, in the order given' );
hti_games_check( array( '%s', '%d' ) === Store::formats( 'runs', array( 'day_key' => '2026-08-30', 'player_id' => 7 ) ), 'the order follows the data, not the map' );
hti_games_check( array( '%d' ) === Store::formats( 'players', array( 'stc_capital' => 9000 ) ), 'capital is written as an integer, so a numeric string cannot land as 0' );
hti_games_check( array( 'stc_capital' => 1 ) === Store::filter_columns( 'players', array( 'stc_capital' => 1, 'dropped_by_typo' => 2 ) ), 'a column that does not exist is dropped rather than guessed at' );
hti_games_check( array() === Store::filter_columns( 'players', array( 'id' => 5 ) ), 'id is not accepted as data' );
hti_games_check( array( 'id' => 5 ) === Store::filter_columns( 'players', array( 'id' => 5 ), true ), 'but it is accepted in a WHERE clause' );
hti_games_check( array( '%d' ) === Store::formats( 'players', array( 'id' => 5 ), true ), 'and it is matched as an integer there' );

echo "\nThe two tables are addressed by nickname, and only two exist\n";
hti_games_check( array( 'players', 'runs' ) === array_keys( $sql ), 'create_sql() is keyed players/runs — the same words insert()/update() take' );

hti_games_done();
