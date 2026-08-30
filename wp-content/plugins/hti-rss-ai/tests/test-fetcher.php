<?php
/**
 * Tests for Fetcher::backoff_seconds (pure back-off schedule).
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-fetcher.php';

use HTI\RssAI\Fetcher;

rssai_ok( 300 === Fetcher::backoff_seconds( 0 ), 'backoff base (0 errors) = 300s' );
rssai_ok( 600 === Fetcher::backoff_seconds( 1 ), 'backoff doubles at 1 error = 600s' );
rssai_ok( Fetcher::backoff_seconds( 2 ) > Fetcher::backoff_seconds( 1 ), 'backoff grows with errors' );
rssai_ok( 86400 === Fetcher::backoff_seconds( 100 ), 'backoff saturates at one day' );
rssai_ok( 300 === Fetcher::backoff_seconds( -5 ), 'negative error count clamps to base' );

// --- How much one cron tick is allowed to do ----------------------------
//
// A fetch used to walk all fifteen feeds and then cluster them, in one PHP
// process. On a host with twenty concurrent processes that is enough to start
// refusing visitors — and WordPress, with no real cron, spawns another
// wp-cron.php per visit while the first is still going. The numbers below are
// what keeps a tick short; they are asserted because a later "let's raise it a
// bit" is exactly how this comes back.

$cls    = new ReflectionClass( 'HTI\RssAI\Fetcher' );
$per    = $cls->getConstant( 'FEEDS_PER_RUN' );
$budget = $cls->getConstant( 'BUDGET_SECONDS' );

rssai_ok( is_int( $per ) && $per >= 1 && $per <= 5, 'a tick visits a handful of feeds, not all of them' );
rssai_ok( is_int( $budget ) && $budget > 0, 'a run has a time budget' );

// WP-Cron's lock expires after 60 seconds. A run allowed to exceed that lets a
// second run start on top of it, which is the pile-up the budget exists to
// prevent — so the budget must stay comfortably under the lock.
rssai_ok( $budget < 60, 'the budget stays under WP-Cron\'s 60-second lock' );

// The clustering must not run inside the fetch: it is the CPU-bound half, and
// pairing it with the network-bound half is what made one job hold a process
// for a minute. Asserted on the source, since exercising it needs a database.
$src   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-fetcher.php' );
$start = strpos( $src, 'function run(' );
$run   = substr( $src, $start, strpos( $src, 'function backoff_seconds' ) - $start );

rssai_ok( ! str_contains( $run, 'Grouping::run()' ), 'the fetch no longer clusters in its own process' );
rssai_ok( str_contains( $run, 'GROUP_HOOK' ), 'it queues the clustering onto its own tick instead' );
rssai_ok( str_contains( $run, 'Feeds::due(' ), 'and it asks for the feeds most overdue, not for all of them' );
rssai_ok( ! str_contains( $run, 'Feeds::all()' ), 'never for every feed at once' );


rssai_done( 'fetcher' );
