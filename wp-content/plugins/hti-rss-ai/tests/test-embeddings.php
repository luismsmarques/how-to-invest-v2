<?php
/**
 * Tests for Embeddings::chunk_timeout (the budget one embedding batch gets).
 *
 * A batch is one blocking HTTP call. Under the grouping run's wall-clock
 * budget, a batch must not start without enough runway and its cURL timeout
 * must never promise more time than the budget still has — otherwise a
 * "Group now" click can outlive the host's execution limit and die on the
 * fatal-error page.
 *
 * @package HTI_RSS_AI
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/includes/class-embeddings.php';

use HTI\RssAI\Embeddings;

rssai_ok( 60 === Embeddings::chunk_timeout( null, 100.0 ), 'no deadline → the full 60s HTTP timeout' );
rssai_ok( 60 === Embeddings::chunk_timeout( 500.0, 100.0 ), 'plenty of budget → capped at 60s' );
rssai_ok( 18 === Embeddings::chunk_timeout( 120.0, 100.0 ), '20s left → 18s timeout (2s margin for the rest)' );
rssai_ok( 8 === Embeddings::chunk_timeout( 110.0, 100.0 ), 'exactly the minimum runway (10s) → a short 8s timeout' );
rssai_ok( null === Embeddings::chunk_timeout( 109.0, 100.0 ), 'under the minimum runway → do not start a batch' );
rssai_ok( null === Embeddings::chunk_timeout( 100.0, 100.0 ), 'no time left → do not start a batch' );
rssai_ok( null === Embeddings::chunk_timeout( 90.0, 100.0 ), 'deadline already passed → do not start a batch' );

// Whatever the inputs, a granted timeout never promises more than the budget.
foreach ( array( 111.0, 115.0, 130.0, 160.0, 400.0 ) as $deadline ) {
	$t = Embeddings::chunk_timeout( $deadline, 100.0 );
	rssai_ok( null !== $t && $t <= ( $deadline - 100.0 ), sprintf( 'timeout %ds fits inside a %.0fs budget', (int) $t, $deadline - 100.0 ) );
}

rssai_done( 'embeddings' );
