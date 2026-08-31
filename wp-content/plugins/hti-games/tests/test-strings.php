<?php
/**
 * The bilingual promise, checked rather than trusted.
 *
 * This project runs the pt_PT_ao90 locale with pt_PT translation files, and
 * WordPress does not fall back between them. A missing Portuguese string
 * therefore raises nothing at all — it renders in English, on a Portuguese
 * page, and nobody notices until a reader does. Hence a table with both
 * languages in one place and this file to guard it.
 *
 * The placeholder check is the one that catches real bugs: a `%d` present in
 * English and dropped in Portuguese turns a sentence about position size into
 * a PHP warning the day somebody reads it in Portuguese.
 *
 *   php wp-content/plugins/hti-games/tests/test-strings.php
 *
 * @package HTI_Games
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-strings.php';

use HTI\Games\Strings;

$all = Strings::all();

echo "The table is complete in both languages\n";
hti_games_check( count( $all ) > 100, sprintf( 'there are %d keys to check', count( $all ) ) );

$missing = array();
$empty   = array();
foreach ( $all as $key => $pair ) {
	foreach ( Strings::LANGS as $lang ) {
		if ( ! array_key_exists( $lang, $pair ) ) {
			$missing[] = "{$key}.{$lang}";
		} elseif ( '' === trim( (string) $pair[ $lang ] ) ) {
			$empty[] = "{$key}.{$lang}";
		}
	}
}
hti_games_check( array() === $missing, 'every key has both en and pt (' . ( $missing ? implode( ', ', $missing ) : 'all present' ) . ')' );
hti_games_check( array() === $empty, 'no string is blank (' . ( $empty ? implode( ', ', $empty ) : 'none blank' ) . ')' );

echo "\nNo Portuguese string is just the English one\n";
// A handful of words are the same in both languages and that is not a bug.
// Keep this list short and justified: it is the escape hatch that turns the
// check above from a guarantee into a suggestion, one entry at a time.
$same_ok = array(
	'lbl_capital', // "Capital" is the same word in English and Portuguese.
);
$same    = array();
foreach ( $all as $key => $pair ) {
	if ( in_array( $key, $same_ok, true ) ) {
		continue;
	}
	if ( $pair['en'] === $pair['pt'] ) {
		$same[] = $key;
	}
}
hti_games_check( array() === $same, 'nothing was left untranslated (' . ( $same ? implode( ', ', $same ) : 'none' ) . ')' );

echo "\nPlaceholders agree across the two languages\n";
$mismatched = array();
foreach ( $all as $key => $pair ) {
	// %% is a literal percent sign, not a placeholder — strip it first.
	$en = array();
	$pt = array();
	preg_match_all( '/%(?!%)[0-9]*\$?[bcdeEfFgGosuxX]/', str_replace( '%%', '', $pair['en'] ), $en );
	preg_match_all( '/%(?!%)[0-9]*\$?[bcdeEfFgGosuxX]/', str_replace( '%%', '', $pair['pt'] ), $pt );
	sort( $en[0] );
	sort( $pt[0] );
	if ( $en[0] !== $pt[0] ) {
		$mismatched[] = $key . ' (en: ' . implode( ',', $en[0] ) . ' / pt: ' . implode( ',', $pt[0] ) . ')';
	}
}
hti_games_check( array() === $mismatched, 'the same placeholders appear in both (' . ( $mismatched ? implode( '; ', $mismatched ) : 'all aligned' ) . ')' );

echo "\nThe voice rules hold\n";
// .claude/skills/brand-voice: no urgency, no promises, no imperatives to act,
// nothing that reads as an inducement. A leaderboard pulls hard the other way,
// which is exactly why this is a test and not a note in a document.
$banned = array(
	'en' => array( 'guaranteed', 'beat the market', "don't miss", 'act now', 'hurry', 'limited time', 'you will make', 'best broker', 'top picks', 'turbo' ),
	'pt' => array( 'garantido', 'bater o mercado', 'não percas', 'age já', 'despacha', 'tempo limitado', 'vais ganhar', 'melhor corretora', 'turbo' ),
);
$offenders = array();
foreach ( $all as $key => $pair ) {
	foreach ( $banned as $lang => $words ) {
		foreach ( $words as $word ) {
			if ( false !== stripos( $pair[ $lang ], $word ) ) {
				$offenders[] = "{$key}.{$lang}: \"{$word}\"";
			}
		}
	}
}
hti_games_check( array() === $offenders, 'no urgency, promise or inducement wording (' . ( $offenders ? implode( '; ', $offenders ) : 'clean' ) . ')' );

echo "\nNo broker or affiliate surface leaks into the games\n";
// CLAUDE.md invariant 9: the /forex/ exemption does not extend here by
// analogy, and ESMA prohibits incentives tied to retail CFD trading.
$broker_words = array( '/go/', 'broker', 'corretora', 'affiliate', 'afiliad', 'sponsored', 'bonus', 'bónus', 'deposit now', 'depositar' );
$leaks        = array();
foreach ( $all as $key => $pair ) {
	foreach ( Strings::LANGS as $lang ) {
		foreach ( $broker_words as $word ) {
			if ( false !== stripos( $pair[ $lang ], $word ) ) {
				// "no broker anywhere in this section" is the promise itself.
				if ( in_array( $key, array( 'no_brokers' ), true ) ) {
					continue;
				}
				$leaks[] = "{$key}.{$lang}: \"{$word}\"";
			}
		}
	}
}
hti_games_check( array() === $leaks, 'no broker, affiliate or bonus wording (' . ( $leaks ? implode( '; ', $leaks ) : 'clean' ) . ')' );

echo "\nThe disclaimers say what they have to say\n";
foreach ( Strings::LANGS as $lang ) {
	$full = Strings::get( 'disclaimer_full', $lang );
	hti_games_check( '' !== $full, "the full disclaimer exists in {$lang}" );
	hti_games_check( false !== stripos( $full, 'virtual' ), "it says the money is virtual ({$lang})" );
	// The claim, not one particular phrasing of it: the disclaimer has to
	// deny that any of this is advice, and it has to deny that anything is
	// executed. How it says so is the copywriter's business.
	$advice = 'en' === $lang ? 'financial advice' : 'aconselhamento financeiro';
	hti_games_check( false !== stripos( $full, $advice ), "it denies being financial advice ({$lang})" );
	$executed = 'en' === $lang ? 'executed' : 'executado';
	hti_games_check( false !== stripos( $full, $executed ), "it says nothing is executed anywhere ({$lang})" );
}
hti_games_check( false !== stripos( Strings::get( 'rev_historical', 'en' ), 'not a view on this company today' ), 'The Reveal says it is not a view on the company today — the line that keeps the named-company exemption an exemption' );
hti_games_check( false !== stripos( Strings::get( 'rev_historical', 'pt' ), 'opinião sobre esta empresa hoje' ), 'and says it in Portuguese too' );

echo "\nThe two landing claims are genuinely different claims\n";
// Library::is_real() decides which one renders. The generated variant must
// never call the charts historical, or the flag is decoration.
foreach ( array( 'en' => array( 'real historical', 'historical chart' ), 'pt' => array( 'histórico real', 'gráfico histórico' ) ) as $lang => $phrases ) {
	$generated = Strings::get( 'stc_claim_generated', $lang );
	$hit       = array_filter( $phrases, fn( $p ) => false !== stripos( $generated, $p ) );
	hti_games_check( array() === $hit, "the generated-data claim never says the charts are historical ({$lang})" );
}
hti_games_check( false !== stripos( Strings::get( 'stc_claim_real', 'en' ), 'real historical' ), 'the real-data claim does say it' );

echo "\nLookup behaves\n";
hti_games_check( 'Educational games' === Strings::get( 'section_name', 'en' ), 'a known key returns its English' );
hti_games_check( 'Jogos educacionais' === Strings::get( 'section_name', 'pt' ), 'and its Portuguese' );
hti_games_check( '' === Strings::get( 'no_such_key', 'en' ), 'an unknown key returns an empty string rather than a warning' );
hti_games_check( Strings::get( 'section_name', 'de' ) === Strings::get( 'section_name', 'en' ), 'an unsupported language falls back to English' );
hti_games_check( count( Strings::table( 'pt' ) ) === count( $all ), 'the flattened table has every key' );

echo "\nNothing promises an audited number before the decision\n";
// The library ships illustrative cases: the company, the period and the
// direction are real, the figures are reconstructions. The reveal screen says
// so — but it says so AFTER the player has committed, and a disclosure that
// arrives after the decision is not a disclosure. So the strings shown BEFORE
// it must not promise a number that most cases do not have.
//
// This exists because the onboarding rules card did exactly that: "the outcome
// is what the company actually returned", read by every player before their
// first decision, contradicted three screens later by rev_illustrative.
$pre_decision = array(
	'rev_ob1_body',
	'rev_ob2_r3',
	'rev_ob2_r4',
	'rev_ob3_body',
	'rev_tagline',
	'rev_fundamentals',
	'rev_sector_avg',
	'rev_headlines',
);
$audited = array(
	'en' => array( 'actually returned', 'the real return', 'real figures', 'taken from a filing', 'audited' ),
	'pt' => array( 'realmente rendeu', 'o retorno real', 'números reais', 'retirados de um relatório', 'auditad' ),
);
$promises = array();
foreach ( $pre_decision as $key ) {
	foreach ( $audited as $lang => $phrases ) {
		$text = Strings::get( $key, $lang );
		if ( '' === $text ) {
			continue;
		}
		foreach ( $phrases as $phrase ) {
			if ( false !== stripos( $text, $phrase ) ) {
				$promises[] = "{$key}.{$lang}: \"{$phrase}\"";
			}
		}
	}
}
hti_games_check( array() === $promises, 'no pre-decision string claims the figures are an audited return (' . ( $promises ? implode( '; ', $promises ) : 'clean' ) . ')' );

// And the line that does the disclosing still exists and still says the two
// halves that matter: what is real, and what is not.
foreach ( array( 'en' => array( 'real', 'reconstructed' ), 'pt' => array( 'reais', 'reconstruídos' ) ) as $lang => $halves ) {
	$line = Strings::get( 'rev_illustrative', $lang );
	$has  = array_filter( $halves, fn( $h ) => false !== stripos( $line, $h ) );
	hti_games_check( count( $has ) === count( $halves ), "the illustrative line says both what is real and what is reconstructed ({$lang})" );
}

hti_games_done();
