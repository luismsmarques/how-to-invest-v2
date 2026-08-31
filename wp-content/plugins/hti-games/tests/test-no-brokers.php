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
 * A rule in a document is a wish. This file is the control, and it runs in two
 * passes because the invariant is about two different things.
 *
 * The first pass reads every source file the plugin ships and fails if an
 * affiliate surface appears in one. That catches the mistake at the commit
 * that makes it, which is the cheapest place to catch it.
 *
 * The second pass RENDERS, because the invariant is about what a visitor sees
 * and a source sweep cannot tell a mention in a comment from a link in a page.
 * Both halves of a games page can be produced without WordPress: Seeder::plan()
 * is pure, and the five shortcode shells need four small shims. So every page
 * of the section is built in both languages — title, meta description, body,
 * FAQs and the game shell that mounts inside it — and the whole of it is swept
 * for a redirector, a sponsored rel, a broker name and a partner module. It
 * also asserts the stronger property the section actually promises: not one
 * link on any games page leaves the site.
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
//
// Matched on word boundaries, not as bare substrings: "xtb" is three letters
// and lives inside "nextButtons", which is how this check first failed on
// perfectly innocent event-handler code. A control that cries wolf gets
// switched off, so it has to be right about what it is looking at.
$brokers = array( 'xtb', 'etoro', 'degiro', 'trading212', 'interactive brokers', 'plus500', 'avatrade', 'exness', 'octafx', 'xm.com' );
$named   = array();
foreach ( $sources as $file ) {
	$body = strtolower( (string) file_get_contents( $file ) );
	foreach ( $brokers as $broker ) {
		if ( preg_match( '/\b' . preg_quote( $broker, '/' ) . '\b/', $body ) ) {
			$named[] = basename( $file ) . ': ' . $broker;
		}
	}
}
hti_games_check( array() === $named, 'no broker name appears (' . ( $named ? implode( '; ', $named ) : 'clean' ) . ')' );

// And the check is actually capable of finding one — a boundary-matched
// pattern that never fires is indistinguishable from a pattern that is wrong.
hti_games_check(
	1 === preg_match( '/\betoro\b/', strtolower( 'a review of eToro published today' ) )
		&& 0 === preg_match( '/\bxtb\b/', 'var nextbuttons = root.queryselectorall();' ),
	'the matcher finds a real mention and ignores a substring collision'
);

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

/* -------------------------------------------------------------------------
 * Pass two: the pages, as a visitor gets them
 * ---------------------------------------------------------------------- */

// The four shims the render path needs on top of bootstrap.php. Guarded, so
// this file stays runnable both on its own and inside the runner.
if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * URL escaping.
	 *
	 * @param string $url URL.
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Slash removal.
	 *
	 * @param mixed $value Value.
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'determine_locale' ) ) {
	/**
	 * Site locale.
	 */
	function determine_locale() {
		return 'en_US';
	}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	/**
	 * Merge shortcode attributes over defaults.
	 *
	 * @param array  $pairs     Defaults.
	 * @param array  $atts      Supplied attributes.
	 * @param string $shortcode Tag.
	 */
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		unset( $shortcode );
		$out = $pairs;
		foreach ( (array) $atts as $key => $value ) {
			if ( array_key_exists( $key, $pairs ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}

foreach ( array( 'class-config', 'class-strings', 'class-day', 'class-stc-engine', 'class-settings', 'class-player', 'class-seeder', 'class-schema', 'class-frontend' ) as $hti_class ) {
	require_once __DIR__ . '/../includes/' . $hti_class . '.php';
}

/**
 * Every page of the section, rendered, keyed by something a failure can name.
 *
 * Both the editorial half (Seeder::plan(), which is pure) and the interactive
 * half (the shortcode shells), in both languages, with the SEO fields folded
 * in — a broker in a meta description is a broker on a search results page.
 *
 * @return array<string,string>
 */
function hti_games_rendered(): array {
	$out = array();

	// Both landing claims, since one of them is a different paragraph.
	foreach ( array( false, true ) as $stc_real ) {
		foreach ( \HTI\Games\Seeder::plan( $stc_real ) as $key => $def ) {
			foreach ( \HTI\Games\Strings::LANGS as $lang ) {
				$faqs = '';
				foreach ( (array) ( $def['faqs'][ $lang ] ?? array() ) as $faq ) {
					$faqs .= ' ' . $faq['q'] . ' ' . $faq['a'];
				}
				$out[ 'page:' . $key . ':' . $lang . ':' . ( $stc_real ? 'real' : 'generated' ) ] =
					$def['title'][ $lang ] . ' ' . $def['seo_title'][ $lang ] . ' '
					. $def['seo_desc'][ $lang ] . ' ' . $def['content'][ $lang ] . $faqs;
			}
		}
	}

	// The shells. Frontend::lang() reads the URL first, exactly as it does in
	// production, so the Portuguese pass is a Portuguese request.
	$was = $_SERVER['REQUEST_URI'] ?? null;
	foreach ( array( 'en' => '/games/', 'pt' => '/pt/jogos/' ) as $lang => $uri ) {
		$_SERVER['REQUEST_URI'] = $uri;
		$out[ 'shell:stc:' . $lang ]         = \HTI\Games\Frontend::render_game( array( 'name' => 'stc' ) );
		$out[ 'shell:reveal:' . $lang ]      = \HTI\Games\Frontend::render_game( array( 'name' => 'reveal' ) );
		$out[ 'shell:hub:' . $lang ]         = \HTI\Games\Frontend::render_hub();
		$out[ 'shell:leaderboard:' . $lang ] = \HTI\Games\Frontend::render_board();
		$out[ 'shell:profile:' . $lang ]     = \HTI\Games\Frontend::render_profile();
	}
	if ( null === $was ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $was;
	}

	return $out;
}

/**
 * Everything an affiliate surface would leave behind in rendered markup.
 *
 * Word boundaries on the broker slugs for the same reason as above, and the
 * two words the broker section is legally required to label itself with —
 * "Parceria · Publicidade" — because a partner module landing on a game page
 * would arrive carrying them.
 *
 * @param string $html Rendered markup.
 * @return array<int,string> What was found, empty when clean.
 */
function hti_games_surfaces( string $html ): array {
	$found = array();
	$body  = strtolower( $html );

	foreach ( array( '/go/', 'rel="sponsored', "rel='sponsored", 'utm_campaign', 'hti_brokers', 'hti-partner', 'parceria', 'publicidade', 'afiliad', 'affiliate' ) as $needle ) {
		if ( str_contains( $body, $needle ) ) {
			$found[] = $needle;
		}
	}

	foreach ( array( 'xtb', 'etoro', 'degiro', 'trading212', 'interactive brokers', 'plus500', 'avatrade', 'exness', 'octafx', 'xm.com' ) as $broker ) {
		if ( preg_match( '/\b' . preg_quote( $broker, '/' ) . '\b/', $body ) ) {
			$found[] = $broker;
		}
	}

	// Every link on a games page is internal. Not a proxy for the rule — it
	// IS the rule at its widest: an affiliate link nobody thought to name is
	// still a link that leaves the site, and there is no reason for one here.
	if ( preg_match_all( '/href="([^"]*)"/i', $html, $links ) ) {
		foreach ( $links[1] as $href ) {
			if ( ! str_starts_with( $href, '/' ) ) {
				$found[] = 'outbound href: ' . $href;
			}
		}
	}

	return $found;
}

echo "\nNothing a visitor is served carries a broker\n";

$rendered = hti_games_rendered();
hti_games_check( count( $rendered ) >= 20, sprintf( 'rendered %d page bodies and shells, in both languages', count( $rendered ) ) );
hti_games_check(
	array_sum( array_map( 'strlen', $rendered ) ) > 40000,
	sprintf( 'and they are real pages (%d characters swept)', array_sum( array_map( 'strlen', $rendered ) ) )
);

$dirty = array();
foreach ( $rendered as $where => $html ) {
	foreach ( hti_games_surfaces( $html ) as $hit ) {
		$dirty[] = $where . ' → ' . $hit;
	}
}
hti_games_check( array() === $dirty, 'no redirector, sponsored rel, broker name or partner module in any rendered page (' . ( $dirty ? implode( '; ', $dirty ) : 'clean' ) . ')' );

// The sweep is capable of finding one. A render-time control that has never
// fired is a control nobody has any reason to believe.
hti_games_check(
	array() !== hti_games_surfaces( '<p>Open an account with <a href="/go/xtb">XTB</a>.</p>' )
		&& array() !== hti_games_surfaces( '<a href="https://example.com/partner" rel="sponsored nofollow">Compare</a>' )
		&& array() === hti_games_surfaces( '<p>The <a href="/games/leaderboard/">next buttons</a> are ordinary.</p>' ),
	'the render sweep catches a redirector and an outbound sponsored link, and passes an ordinary internal page'
);

hti_games_done();
