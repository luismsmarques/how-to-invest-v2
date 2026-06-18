<?php
/**
 * Title: News card
 * Slug: howtoinvest/news-card
 * Categories: howtoinvest
 * Description: Compact card (image, date, title, excerpt) for a news item inside a query loop.
 * Block Types: core/post-template
 * Inserter: yes
 *
 * @package HowToInvest
 */

?>
<!-- wp:group {"className":"hti-news-card","style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group hti-news-card"><!-- wp:group {"style":{"spacing":{"blockGap":"10px"}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:post-terms {"term":"news_category","className":"hti-eyebrow"} /-->

<!-- wp:post-date {"fontSize":"small","textColor":"muted-light"} /--></div>
<!-- /wp:group -->

<!-- wp:post-title {"isLink":true} /-->

<!-- wp:post-excerpt {"excerptLength":24,"textColor":"muted","showMoreOnNewLine":false} /--></div>
<!-- /wp:group -->
