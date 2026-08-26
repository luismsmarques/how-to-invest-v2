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

rssai_done( 'fetcher' );
