<?php
/**
 * The Reveal's case library can be installed without a shell.
 *
 * The Reveal reached production with an empty pool because the only way in was
 * `wp hti-games seed-cases` over SSH — and class-seed-cases.php was required
 * only from inside the WP-CLI branch, so on an ordinary cPanel site the
 * library was not even loaded. Worse, the case-queue panel reported "0 of 0"
 * followed by "Nothing is waiting on anybody", which is true of an empty table
 * and reads exactly like everything is fine.
 *
 * This file holds the three things that must not come back: the shipped count
 * the panel quotes has to be the real one, the three library states have to be
 * distinguishable, and the button has to be wired the way every other admin
 * action in this plugin is.
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-cpt.php';
require_once __DIR__ . '/../includes/class-case-admin.php';
require_once __DIR__ . '/../includes/class-reveal-lessons.php';
require_once __DIR__ . '/../includes/class-seed-cases.php';
require_once __DIR__ . '/../includes/class-case-installer.php';

use HTI\Games\Case_Installer;
use HTI\Games\Config;
use HTI\Games\Seed_Cases;

/* ------------------------------------------------ the count is the truth */

echo "\nThe count the panel quotes is the library's real size\n";

$shipped = Config::case_library()['count'];
$actual  = count( Seed_Cases::cases() );

hti_games_check(
	$shipped === $actual,
	"Config::CASE_LIBRARY_COUNT ({$shipped}) matches Seed_Cases::cases() ({$actual})"
);
hti_games_check( $shipped > 0, 'the shipped library is not empty' );
hti_games_check(
	Config::case_library()['version'] >= 1,
	'the library declares a version, so a stale install is tellable from a complete one'
);
hti_games_check(
	Case_Installer::shipped() === $actual,
	'Case_Installer::shipped() reports the same number without loading the library'
);

/* ---------------------------------------------------------- the three states */

echo "\nAn empty library, a partial one and a complete one are three different things\n";

$empty = Case_Installer::state( 34, 0 );
hti_games_check( 'empty' === $empty['state'], 'nothing installed reads as empty' );
hti_games_check( 34 === $empty['missing'], 'an empty library is missing all of them' );

$partial = Case_Installer::state( 34, 20 );
hti_games_check( 'partial' === $partial['state'], 'a half-finished run reads as partial' );
hti_games_check( 14 === $partial['missing'], 'a partial library counts what is left' );

$complete = Case_Installer::state( 34, 34 );
hti_games_check( 'complete' === $complete['state'], 'a finished library reads as complete' );
hti_games_check( 0 === $complete['missing'], 'a complete library is missing nothing' );

// An editor who authored cases of their own puts the count above the shipped
// size. That is not a broken state and must not report a negative shortfall.
$extra = Case_Installer::state( 34, 40 );
hti_games_check( 'complete' === $extra['state'], 'more cases than shipped still reads as complete' );
hti_games_check( 0 === $extra['missing'], 'a surplus never reports a negative shortfall' );

// Defensive: nonsense in must not produce nonsense out.
$weird = Case_Installer::state( -5, -5 );
hti_games_check( 'empty' === $weird['state'], 'negative inputs are clamped, not propagated' );
hti_games_check( 0 === $weird['missing'], 'negative inputs never yield a negative shortfall' );

$none_shipped = Case_Installer::state( 0, 0 );
hti_games_check( 'empty' === $none_shipped['state'], 'a library that ships nothing reads as empty' );

/* --------------------------------------------------------- the wiring holds */

$installer = file_get_contents( __DIR__ . '/../includes/class-case-installer.php' );
$admin     = file_get_contents( __DIR__ . '/../includes/class-case-admin.php' );
$bootstrap = file_get_contents( __DIR__ . '/../hti-games.php' );
$uninstall = file_get_contents( __DIR__ . '/../uninstall.php' );
$seed      = file_get_contents( __DIR__ . '/../includes/class-seed-cases.php' );

echo "\nThe button is wired like every other admin action here\n";

hti_games_check(
	str_contains( $installer, "add_action( 'admin_post_hti_games_install_cases'" ),
	'the handler is registered on admin_post'
);
hti_games_check(
	str_contains( $installer, 'check_admin_referer( self::NONCE )' ),
	'the handler checks the nonce'
);
hti_games_check(
	str_contains( $installer, "current_user_can( 'manage_options' )" ),
	'the handler checks the capability'
);
hti_games_check(
	str_contains( $installer, 'wp_nonce_field( self::NONCE )' ),
	'the form carries the nonce the handler checks'
);
hti_games_check(
	str_contains( $bootstrap, "'class-case-installer' => 'Case_Installer'" ),
	'the class is in the plugin map, so its handler is registered on every admin request'
);
hti_games_check(
	! str_contains( $bootstrap, "'class-seed-cases'" ),
	'the two-thousand-line dossier file is still NOT in the map — it loads lazily'
);
hti_games_check(
	str_contains( $installer, "require_once HTI_GAMES_PATH . 'includes/class-seed-cases.php'" ),
	'the installer loads the library itself when the button is pressed'
);
hti_games_check(
	str_contains( $uninstall, "'hti_games_last_cases'" ),
	'the option the installer writes is deleted on uninstall'
);

echo "\nThe panel no longer says everything is fine when the library is empty\n";

hti_games_check(
	str_contains( $admin, 'if ( array() === $rows ) {' ),
	'the panel distinguishes an empty table from a finished queue'
);
hti_games_check(
	str_contains( $admin, 'Case_Installer::render_form();' ),
	'the panel that reports the state also offers the cure'
);
// The empty branch must come BEFORE the "nothing is waiting" branch, or an
// empty library falls into the reassuring message again.
$empty_at   = strpos( $admin, 'if ( array() === $rows ) {' );
$waiting_at = strpos( $admin, "if ( array() === \$waiting ) {" );
hti_games_check(
	false !== $empty_at && false !== $waiting_at && $empty_at < $waiting_at,
	'the empty-library check runs before the finished-queue message'
);

echo "\nBoth doors run one implementation\n";

hti_games_check(
	str_contains( $seed, 'public static function install( ?callable $log = null ): array' ),
	'Seed_Cases::install() is the shared implementation'
);
hti_games_check(
	str_contains( $seed, '$report = self::install(' ),
	'the CLI goes through it rather than keeping a second copy'
);
hti_games_check(
	1 === substr_count( $seed, 'if ( self::create( $case ) ) {' ),
	'there is exactly one place a case is created'
);
hti_games_check(
	str_contains( $installer, 'Seed_Cases::install()' ),
	'the button goes through it too'
);

echo "\nThe panel does not call a reconstruction a verified case\n";

// This is not a copy nit. CLAUDE.md invariant 2 lets the games name real
// companies ONLY because each case declares where its numbers came from, and
// the gate deliberately passes both declarations. A panel that folds them into
// one sentence reported thirty-four illustrative cases as "verified" — the
// opposite of the one property the arrangement rests on.
hti_games_check(
	! str_contains( $admin, 'cases are published, verified and in the rotation' ),
	'the headline no longer claims every served case is verified'
);
hti_games_check(
	str_contains( $admin, 'cases are published and in the rotation' ),
	'the headline counts what is served without claiming a provenance'
);
hti_games_check(
	str_contains( $admin, "'provenance'    => self::provenance( \$meta )" ),
	'the queue row carries the provenance the panel needs to tell them apart'
);
hti_games_check(
	str_contains( $admin, "if ( 'verified' === ( \$row['provenance'] ?? '' ) )" ),
	'verified cases are counted separately from illustrative ones'
);
hti_games_check(
	str_contains( $admin, 'are verified against a published document' )
		&& str_contains( $admin, 'figures reconstructed to show the pattern' ),
	'the split is reported in words an owner can act on'
);

hti_games_done( 'case-installer' );
