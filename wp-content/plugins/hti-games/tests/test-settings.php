<?php
/**
 * The settings normalizer, asserted without WordPress.
 *
 * Settings::normalize() is the whole reason the settings screen can be
 * trusted: it is the one place a submitted value becomes a stored value, and
 * it is pure so that "what happens when somebody types 100000 rows" is a
 * question answered at commit time rather than in production.
 *
 * The posture it encodes is worth stating. An out-of-range number is REVERTED
 * to its default and reported, never clamped. Clamping looks like it worked —
 * the screen saves, the field shows a plausible number, and the owner never
 * learns that the figure they typed was refused. Reverting plus an error is
 * the only version of this that tells the truth.
 *
 * There is deliberately no setting in this table that could put a partner
 * link, a payment or a prize on a game page. That is not an omission to be
 * filled in later: the section is sealed from the monetised half of the site
 * by design, and the last assertion here is what keeps it that way when
 * somebody adds a field in a hurry.
 *
 *   php wp-content/plugins/hti-games/tests/test-settings.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-strings.php';
require_once __DIR__ . '/../includes/class-settings.php';

use HTI\Games\Config;
use HTI\Games\Settings;

$defaults = Settings::defaults();

echo "The defaults are a working, conservative install\n";
hti_games_check( true === $defaults['stc_enabled'] && true === $defaults['reveal_enabled'], 'both games are on — the section works with nothing configured' );
hti_games_check( true === $defaults['leaderboard_enabled'], 'so is the leaderboard' );
hti_games_check( false === $defaults['newsletter_optin'], 'but the newsletter opt-in is off: a marketing surface inside a game is a decision somebody has to take on purpose' );
hti_games_check( 20 === $defaults['board_size'], 'the board shows twenty rows' );
hti_games_check( $defaults['board_size'] >= Settings::BOARD_MIN && $defaults['board_size'] <= Settings::BOARD_MAX, 'and the default is inside its own bounds' );
hti_games_check( $defaults['retention_days'] >= Settings::RETENTION_MIN && $defaults['retention_days'] <= Settings::RETENTION_MAX, 'so is the retention window' );
hti_games_check( Settings::RETENTION_MAX <= 1095, 'which is capped at three years — a game has no business remembering longer' );

echo "\nEvery boolean default is declared as a flag\n";
$undeclared = array();
foreach ( $defaults as $key => $value ) {
	if ( is_bool( $value ) && ! in_array( $key, Settings::flags(), true ) ) {
		$undeclared[] = $key;
	}
}
hti_games_check( array() === $undeclared, 'or the form would render a checkbox the normalizer never reads (' . ( $undeclared ? implode( ', ', $undeclared ) : 'all declared' ) . ')' );
$phantom = array_diff( Settings::flags(), array_keys( $defaults ) );
hti_games_check( array() === $phantom, 'and no flag exists without a default (' . ( $phantom ? implode( ', ', $phantom ) : 'none' ) . ')' );

echo "\nThe normalizer returns a usable value and a list of what was wrong\n";
$result = Settings::normalize( array() );
hti_games_check( array( 'value', 'errors' ) === array_keys( $result ), 'the shape is value + errors, as hti-engine uses' );
hti_games_check( is_array( $result['value'] ) && is_array( $result['errors'] ), 'both halves are arrays' );
hti_games_check( array_keys( $defaults ) === array_keys( $result['value'] ), 'the value always has exactly the default keys — no more, no fewer' );

echo "\nAn unticked checkbox is off, not absent\n";
$empty = Settings::normalize( array() )['value'];
hti_games_check( false === $empty['stc_enabled'], 'an unsubmitted checkbox reads as off' );
hti_games_check( false === $empty['leaderboard_enabled'], 'for every flag, not just the first' );
$ticked = Settings::normalize( array( 'stc_enabled' => '1', 'reveal_enabled' => 'on', 'newsletter_optin' => 1 ) )['value'];
hti_games_check( true === $ticked['stc_enabled'] && true === $ticked['reveal_enabled'], 'and the browser\'s "1" and "on" both read as on' );
hti_games_check( true === $ticked['newsletter_optin'], 'including the one that defaults off' );
hti_games_check( false === $ticked['share_enabled'], 'while the ones not submitted stay off' );

echo "\nNumbers outside their range are refused, not quietly clamped\n";
$big = Settings::normalize( array( 'board_size' => 100000 ) );
hti_games_check( 20 === $big['value']['board_size'], 'a huge board size reverts to the default' );
hti_games_check( array() !== $big['errors'], 'and says so rather than saving something the owner never chose' );
hti_games_check( str_contains( $big['errors'][0], '100000' ), 'the message names the value that was refused' );

$small = Settings::normalize( array( 'board_size' => 1 ) );
hti_games_check( 20 === $small['value']['board_size'], 'a board of one reverts too' );
hti_games_check( array() !== $small['errors'], 'with its own error' );

$ok = Settings::normalize( array( 'board_size' => 50 ) );
hti_games_check( 50 === $ok['value']['board_size'], 'a value inside the range is kept' );
hti_games_check( array() === array_filter( $ok['errors'], fn( $e ) => str_contains( $e, 'board_size' ) ), 'with nothing to report about it' );

hti_games_check( 3 === Settings::normalize( array( 'board_size' => Settings::BOARD_MIN ) )['value']['board_size'], 'the minimum itself is accepted, not off by one' );
hti_games_check( 100 === Settings::normalize( array( 'board_size' => Settings::BOARD_MAX ) )['value']['board_size'], 'and so is the maximum' );

$ret = Settings::normalize( array( 'retention_days' => 7 ) );
hti_games_check( 400 === $ret['value']['retention_days'], 'a week of retention reverts — it would delete a run while its streak is still live' );
hti_games_check( 90 === Settings::normalize( array( 'retention_days' => 90 ) )['value']['retention_days'], 'ninety days is accepted' );
hti_games_check( 400 === Settings::normalize( array( 'retention_days' => 4000 ) )['value']['retention_days'], 'eleven years is not' );

echo "\nAn empty field means the default, not zero\n";
$blank = Settings::normalize( array( 'board_size' => '', 'retention_days' => '' ) );
hti_games_check( 20 === $blank['value']['board_size'] && 400 === $blank['value']['retention_days'], 'a cleared number box falls back to the default' );
hti_games_check( array() === array_filter( $blank['errors'], fn( $e ) => str_contains( $e, 'board_size' ) ), 'and clearing a box is not an error to report' );
hti_games_check( 20 === Settings::normalize( array( 'board_size' => '20' ) )['value']['board_size'], 'a numeric string from the form is read as its number' );

echo "\nTaking the whole section down is allowed, and said out loud\n";
$off = Settings::normalize( array( 'leaderboard_enabled' => '1' ) );
hti_games_check( false === $off['value']['stc_enabled'] && false === $off['value']['reveal_enabled'], 'both games can be switched off' );
hti_games_check( array() !== $off['errors'], 'and the screen says what that means rather than letting it happen silently' );
$one_on = Settings::normalize( array( 'stc_enabled' => '1' ) );
hti_games_check( array() === $one_on['errors'], 'one game on is an ordinary state with nothing to report' );

echo "\nNormalizing is idempotent — settings survive a round trip\n";
$once  = Settings::normalize( array( 'stc_enabled' => '1', 'board_size' => 37, 'retention_days' => 90 ) )['value'];
$twice = Settings::normalize( $once )['value'];
hti_games_check( $once === $twice, 'a stored value re-normalizes to itself, so re-saving never drifts' );
hti_games_check( 37 === $twice['board_size'], 'including the numbers' );
hti_games_check( true === $twice['stc_enabled'] && false === $twice['reveal_enabled'], 'and the flags, in both directions' );

echo "\nA game asks one question to know whether it may run\n";
hti_games_check( Settings::game_enabled( Config::GAME_STC, $once ), 'the enabled game is enabled' );
hti_games_check( ! Settings::game_enabled( Config::GAME_REVEAL, $once ), 'the disabled one is not' );
hti_games_check( ! Settings::game_enabled( 'roulette', $defaults ), 'and a game we do not serve is never enabled, whatever the settings say' );

echo "\nThe option and page names are the ones the rest of the plugin expects\n";
hti_games_check( 'hti_games_settings' === Settings::OPTION, 'the option row is hti_games_settings' );
hti_games_check( 'hti-games' === Settings::PAGE, 'the screen is at options-general.php?page=hti-games, which the seeder redirects to' );
hti_games_check( str_starts_with( Settings::OPTION, 'hti_games' ), 'and everything is prefixed, so nothing collides with another plugin' );

echo "\nNothing here can put a partner on a game page\n";
// The section is sealed from the monetised half of the site by design. A
// settings table with no field for a link, a payment or a prize is what makes
// that structural instead of a rule somebody has to remember.
$commercial = array();
foreach ( array_keys( $defaults ) as $key ) {
	// 'link' is not on the list: email_link_enabled is the cross-device magic
	// link, which is a login mechanism and not a destination.
	foreach ( array( 'url', 'href', 'partner', 'affiliate', 'sponsor', 'offer', 'ad_code', 'prize', 'reward', 'payment', 'price' ) as $needle ) {
		if ( str_contains( $key, $needle ) ) {
			$commercial[] = $key;
		}
	}
}
hti_games_check( array() === $commercial, 'no setting names a destination, a payment or a prize (' . ( $commercial ? implode( ', ', $commercial ) : 'clean' ) . ')' );
$typed = array_filter( $defaults, fn( $v ) => ! is_bool( $v ) && ! is_int( $v ) );
hti_games_check( array() === $typed, 'and every value is a boolean or an integer, so no free text can reach a page (' . ( $typed ? implode( ', ', array_keys( $typed ) ) : 'clean' ) . ')' );

hti_games_done();
