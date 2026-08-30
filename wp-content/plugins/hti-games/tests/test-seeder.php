<?php
/**
 * The page plan, asserted without a database.
 *
 * Seeder::plan() is pure on purpose, and this file is why: the whole of what
 * the seeder is about to write to five live pages — paths, titles, meta
 * descriptions, block markup, hashes — can be checked at commit time instead
 * of being discovered on staging.
 *
 * Three things here are guarding real mistakes rather than style.
 *
 * First, the SEO limits. A title over 60 characters and a description over
 * 155 are both silently truncated in a result page, and the half that gets
 * cut is always the end, which is where the qualifier lives. Portuguese runs
 * longer than English almost every time, so the PT half of the table is where
 * this breaks.
 *
 * Second, the editorial half. A page that is only a shortcode is a thin page,
 * and the section would deserve the noindex it would eventually earn. The
 * assertions below insist on prose either side of the game mount — the
 * lesson, the four steps, the rules, the FAQ and the disclaimer — so that
 * stripping it as "just marketing" turns this file red.
 *
 * Third, the landing claim. Which of the two sentences appears on the
 * Survive the Charts page has to be a function of the data, not of a
 * checkbox: plan(true) and plan(false) must produce genuinely different
 * pages, and the generated variant must never call the charts historical.
 *
 *   php wp-content/plugins/hti-games/tests/test-seeder.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escape a URL for output. The plan only ever builds root-relative paths
	 * from validated slugs, so an attribute escape is the whole job.
	 *
	 * @param string $url URL.
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-strings.php';
require_once __DIR__ . '/../includes/class-seeder.php';

use HTI\Games\Config;
use HTI\Games\Seeder;
use HTI\Games\Strings;

$plan  = Seeder::plan( false );
$real  = Seeder::plan( true );
$pages = Config::pages();

echo "The plan covers the whole section\n";
hti_games_check( count( $plan ) === count( $pages ), sprintf( 'every page in the table is planned (%d)', count( $plan ) ) );
hti_games_check( array_keys( $plan ) === array_keys( $pages ), 'in the order the table declares, so the hub is upserted before its children' );
hti_games_check( 'hub' === array_key_first( $plan ), 'and the hub is first — a child inserted before its parent gets the wrong permalink and keeps it' );

echo "\nPaths come from the page table, never from a literal\n";
hti_games_check( 'games' === $plan['hub']['path']['en'], 'the hub sits at /games/' );
hti_games_check( 'jogos' === $plan['hub']['path']['pt'], 'and at /jogos/ in Portuguese' );
hti_games_check( 'games/survive-the-charts' === $plan['stc']['path']['en'], 'Survive the Charts hangs off the hub' );
hti_games_check( 'jogos/sobreviver-aos-graficos' === $plan['stc']['path']['pt'], 'and so does its Portuguese translation' );
hti_games_check( 'games/the-reveal' === $plan['reveal']['path']['en'] && 'jogos/a-revelacao' === $plan['reveal']['path']['pt'], 'The Reveal is a child in both languages' );

$derived = true;
foreach ( $plan as $key => $def ) {
	foreach ( Strings::LANGS as $lang ) {
		$expected = null === $pages[ $key ]['parent']
			? $pages[ $key ][ $lang ]
			: $pages[ $pages[ $key ]['parent'] ][ $lang ] . '/' . $pages[ $key ][ $lang ];
		if ( $expected !== $def['path'][ $lang ] ) {
			$derived = false;
		}
	}
}
hti_games_check( $derived, 'every path is assembled from Config::pages(), so changing a slug there changes the plan' );
hti_games_check( '' === Seeder::path( 'not-a-page', 'en' ), 'an unknown page key has no path rather than a broken one' );

echo "\nInternal links resolve to those same paths\n";
hti_games_check( '/games/survive-the-charts/' === Seeder::url( 'stc', 'en' ), 'the English URL is root-relative' );
hti_games_check( '/pt/jogos/sobreviver-aos-graficos/' === Seeder::url( 'stc', 'pt' ), 'the Portuguese one carries the language prefix' );
$bad_links = array();
foreach ( $plan as $key => $def ) {
	foreach ( Strings::LANGS as $lang ) {
		preg_match_all( '/href="([^"]+)"/', $def['content'][ $lang ], $m );
		foreach ( $m[1] as $href ) {
			$prefix = 'pt' === $lang ? '/pt/' : '/';
			if ( ! str_starts_with( $href, $prefix ) ) {
				$bad_links[] = "{$key}.{$lang}: {$href}";
			}
		}
	}
}
hti_games_check( array() === $bad_links, 'no page links outside its own language tree (' . ( $bad_links ? implode( '; ', $bad_links ) : 'clean' ) . ')' );

echo "\nEvery page is complete in both languages\n";
$gaps = array();
foreach ( $plan as $key => $def ) {
	foreach ( Strings::LANGS as $lang ) {
		foreach ( array( 'title', 'seo_title', 'seo_desc', 'content' ) as $field ) {
			if ( '' === trim( (string) $def[ $field ][ $lang ] ) ) {
				$gaps[] = "{$key}.{$field}.{$lang}";
			}
		}
	}
}
hti_games_check( array() === $gaps, 'no title, meta description or body is missing (' . ( $gaps ? implode( ', ', $gaps ) : 'all present' ) . ')' );

$untranslated = array();
foreach ( $plan as $key => $def ) {
	foreach ( array( 'title', 'seo_title', 'seo_desc', 'content' ) as $field ) {
		if ( $def[ $field ]['en'] === $def[ $field ]['pt'] ) {
			$untranslated[] = "{$key}.{$field}";
		}
	}
}
hti_games_check( array() === $untranslated, 'nothing was left in English on the Portuguese page (' . ( $untranslated ? implode( ', ', $untranslated ) : 'none' ) . ')' );

echo "\nSEO copy fits in a result page\n";
$long_titles = array();
$long_descs  = array();
foreach ( $plan as $key => $def ) {
	foreach ( Strings::LANGS as $lang ) {
		foreach ( array( 'title', 'seo_title' ) as $field ) {
			$len = mb_strlen( (string) $def[ $field ][ $lang ] );
			if ( $len > 60 ) {
				$long_titles[] = "{$key}.{$field}.{$lang} ({$len})";
			}
		}
		$len = mb_strlen( (string) $def['seo_desc'][ $lang ] );
		if ( $len > 155 ) {
			$long_descs[] = "{$key}.{$lang} ({$len})";
		}
	}
}
hti_games_check( array() === $long_titles, 'every title is 60 characters or fewer (' . ( $long_titles ? implode( ', ', $long_titles ) : 'all fit' ) . ')' );
hti_games_check( array() === $long_descs, 'every meta description is 155 characters or fewer (' . ( $long_descs ? implode( ', ', $long_descs ) : 'all fit' ) . ')' );

$short_descs = array();
foreach ( $plan as $key => $def ) {
	foreach ( Strings::LANGS as $lang ) {
		if ( mb_strlen( (string) $def['seo_desc'][ $lang ] ) < 80 ) {
			$short_descs[] = "{$key}.{$lang}";
		}
	}
}
hti_games_check( array() === $short_descs, 'and none is so short that Google writes its own instead (' . ( $short_descs ? implode( ', ', $short_descs ) : 'all substantial' ) . ')' );

echo "\nEach page mounts the shortcode its front end owns\n";
hti_games_check( str_contains( $plan['hub']['content']['en'], '[hti_games_hub]' ), 'the hub embeds [hti_games_hub]' );
hti_games_check( str_contains( $plan['stc']['content']['pt'], '[hti_game name="stc"]' ), 'the Survive the Charts page embeds the game, in both languages' );
hti_games_check( str_contains( $plan['reveal']['content']['en'], '[hti_game name="reveal"]' ), 'The Reveal page embeds the game' );
hti_games_check( str_contains( $plan['leaderboard']['content']['en'], '[hti_games_leaderboard]' ), 'the leaderboard page embeds the board' );
hti_games_check( str_contains( $plan['profile']['content']['pt'], '[hti_games_profile]' ), 'the profile page embeds the profile' );

echo "\nThe editorial half is there — it is what makes these pages indexable\n";
foreach ( array( 'stc', 'reveal' ) as $game ) {
	foreach ( Strings::LANGS as $lang ) {
		$body = $plan[ $game ]['content'][ $lang ];
		$text = trim( strip_tags( preg_replace( '/<!--.*?-->/s', ' ', $body ) ) );
		$words = str_word_count( $text, 0, 'áàâãéêíóôõúçÁÀÂÃÉÊÍÓÔÕÚÇ' );

		hti_games_check( $words >= 400, "{$game}.{$lang}: the page carries {$words} words of prose, not just a canvas" );
		hti_games_check( substr_count( $body, '<h2' ) >= 4, "{$game}.{$lang}: at least four H2 sections structure it" );
		hti_games_check( str_contains( $body, '<ol' ), "{$game}.{$lang}: the four steps of a day are an ordered list" );
		hti_games_check( 4 === count( Seeder::steps( $game, $lang ) ), "{$game}.{$lang}: and there are exactly four of them" );
		hti_games_check( str_contains( $body, 'hti-games-tiles' ), "{$game}.{$lang}: the stat tiles render above the game" );
		hti_games_check( substr_count( $body, '<h3' ) >= 3, "{$game}.{$lang}: the FAQ has at least three questions" );
	}
}

echo "\nEditorial content sits on both sides of the game mount\n";
foreach ( array( 'hub', 'stc', 'reveal', 'leaderboard' ) as $key ) {
	$body  = $plan[ $key ]['content']['en'];
	$mount = strpos( $body, '<!-- wp:shortcode -->' );
	hti_games_check( $mount > 200, "{$key}: something is read before the game loads" );
	hti_games_check( strlen( substr( $body, (int) $mount ) ) > 1500, "{$key}: and considerably more after it" );
}

echo "\nThe canonical disclaimer is on every page\n";
foreach ( $plan as $key => $def ) {
	foreach ( Strings::LANGS as $lang ) {
		hti_games_check(
			str_contains( $def['content'][ $lang ], esc_html( Strings::get( 'disclaimer_full', $lang ) ) ),
			"{$key}.{$lang} carries the full disclaimer verbatim from Strings"
		);
	}
}

echo "\nThe landing claim is a function of the data, not of a setting\n";
$gen_en  = Strings::get( 'stc_claim_generated', 'en' );
$real_en = Strings::get( 'stc_claim_real', 'en' );
hti_games_check( str_contains( $plan['stc']['content']['en'], esc_html( $gen_en ) ), 'with a pool that is not all real, the generated claim is on the page' );
hti_games_check( ! str_contains( $plan['stc']['content']['en'], esc_html( $real_en ) ), 'and the real-data claim is not' );
hti_games_check( str_contains( $real['stc']['content']['en'], esc_html( $real_en ) ), 'with an all-real pool, the real-data claim is on the page' );
hti_games_check( ! str_contains( $real['stc']['content']['en'], esc_html( $gen_en ) ), 'and the generated one is gone' );
hti_games_check( $plan['stc']['content']['pt'] !== $real['stc']['content']['pt'], 'the same swap happens in Portuguese' );
hti_games_check( $plan['stc']['hash']['en'] !== $real['stc']['hash']['en'], 'so the page hash changes and the sync rewrites the page' );
hti_games_check( $plan['reveal']['hash']['en'] === $real['reveal']['hash']['en'], 'while The Reveal, which makes no such claim, is untouched by it' );

$faq_gen  = Seeder::faqs( 'stc', 'en', false );
$faq_real = Seeder::faqs( 'stc', 'en', true );
hti_games_check( end( $faq_gen )['a'] !== end( $faq_real )['a'], 'the "are the charts real?" answer tracks the same flag' );
hti_games_check(
	false !== stripos( end( $faq_gen )['a'], 'generated' ) && false === stripos( end( $faq_real )['a'], 'generated' ),
	'so the FAQ cannot say the charts are real while the landing copy says they are generated'
);

echo "\nThe FAQ the page renders is the array the schema will read\n";
foreach ( array( 'hub', 'stc', 'reveal', 'leaderboard' ) as $key ) {
	$en = Seeder::faqs( $key, 'en' );
	$pt = Seeder::faqs( $key, 'pt' );
	hti_games_check( count( $en ) >= 3, "{$key}: at least three questions are answered" );
	hti_games_check( count( $en ) === count( $pt ), "{$key}: the Portuguese FAQ has the same questions" );

	$flaws = array();
	foreach ( array( 'en' => $en, 'pt' => $pt ) as $lang => $faqs ) {
		foreach ( $faqs as $i => $faq ) {
			if ( '' === trim( $faq['q'] ) || '' === trim( $faq['a'] ) ) {
				$flaws[] = "{$lang}[{$i}]";
			}
			if ( ! str_contains( $plan[ $key ]['content'][ $lang ], esc_html( $faq['q'] ) ) ) {
				$flaws[] = "{$lang}[{$i}] not on the page";
			}
		}
	}
	hti_games_check( array() === $flaws, "{$key}: every question is answered and actually rendered (" . ( $flaws ? implode( ', ', $flaws ) : 'all good' ) . ')' );
}
hti_games_check( array() === Seeder::faqs( 'profile', 'en' ), 'the noindexed profile page has no FAQ to publish' );

echo "\nThe editorial copy table is complete in both languages\n";
$copy    = Seeder::copy();
$missing = array();
$same    = array();
foreach ( $copy as $key => $pair ) {
	foreach ( Strings::LANGS as $lang ) {
		if ( ! isset( $pair[ $lang ] ) || '' === trim( (string) $pair[ $lang ] ) ) {
			$missing[] = "{$key}.{$lang}";
		}
	}
	if ( isset( $pair['en'], $pair['pt'] ) && $pair['en'] === $pair['pt'] ) {
		$same[] = $key;
	}
}
hti_games_check( count( $copy ) > 40, sprintf( 'there are %d editorial strings', count( $copy ) ) );
hti_games_check( array() === $missing, 'every one has both languages (' . ( $missing ? implode( ', ', $missing ) : 'all present' ) . ')' );
hti_games_check( array() === $same, 'and none was left untranslated (' . ( $same ? implode( ', ', $same ) : 'none' ) . ')' );
hti_games_check( '' === Seeder::c( 'no_such_copy_key', 'en' ), 'an unknown copy key returns an empty string rather than a warning' );
hti_games_check( Seeder::c( 'h_rules', 'de' ) === Seeder::c( 'h_rules', 'en' ), 'an unsupported language falls back to English' );

echo "\nThe hash is what stops a deploy from rewriting five unchanged pages\n";
hti_games_check( Seeder::plan( false )['hub']['hash']['en'] === $plan['hub']['hash']['en'], 'the same plan hashes the same twice' );
hti_games_check( $plan['hub']['hash']['en'] !== $plan['hub']['hash']['pt'], 'the two languages of one page hash differently' );
$hashes = array();
foreach ( $plan as $def ) {
	$hashes[] = $def['hash']['en'];
	$hashes[] = $def['hash']['pt'];
}
hti_games_check( count( $hashes ) === count( array_unique( $hashes ) ), 'and no two pages share a hash, so none is skipped by mistake' );
hti_games_check(
	Seeder::sync_hash( array( 'key' => 'x', 'title' => array( 'en' => 'a' ), 'content' => array( 'en' => 'b' ), 'seo_title' => array( 'en' => '' ), 'seo_desc' => array( 'en' => '' ) ), 'en' )
		!== Seeder::sync_hash( array( 'key' => 'x', 'title' => array( 'en' => 'a' ), 'content' => array( 'en' => 'c' ), 'seo_title' => array( 'en' => '' ), 'seo_desc' => array( 'en' => '' ) ), 'en' ),
	'a changed body changes the hash'
);

echo "\nThe profile page is the deliberate exception\n";
hti_games_check( false === $plan['profile']['index'], 'it is planned as not indexable' );
hti_games_check( strlen( $plan['profile']['content']['en'] ) < strlen( $plan['stc']['content']['en'] ), 'and it is deliberately thinner — nothing there is written for a crawler' );

echo "\nNothing commercial reaches a game page\n";
$leaks = array();
foreach ( $plan as $key => $def ) {
	foreach ( Strings::LANGS as $lang ) {
		$body = strtolower( $def['content'][ $lang ] );
		foreach ( array( 'href="http', 'sponsored', 'affiliate', 'afiliad', 'utm_', 'target="_blank"' ) as $needle ) {
			if ( str_contains( $body, $needle ) ) {
				$leaks[] = "{$key}.{$lang}: {$needle}";
			}
		}
	}
}
hti_games_check( array() === $leaks, 'no outbound link, tracking parameter or partner rel in any page body (' . ( $leaks ? implode( '; ', $leaks ) : 'clean' ) . ')' );

hti_games_done();
