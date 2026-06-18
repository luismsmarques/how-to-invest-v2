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
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30","left":"var:preset|spacing|30","right":"var:preset|spacing|30"},"blockGap":"var:preset|spacing|20"},"border":{"color":"var:preset|color|border","width":"1px","radius":"12px"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group has-border-color" style="border-color:var(--wp--preset--color--border);border-width:1px;border-radius:12px;padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30);padding-left:var(--wp--preset--spacing--30)"><!-- wp:post-featured-image {"isLink":true,"aspectRatio":"16/9","style":{"border":{"radius":"8px"}}} /-->

<!-- wp:post-date {"fontSize":"small","textColor":"muted"} /-->

<!-- wp:post-title {"isLink":true,"fontSize":"large"} /-->

<!-- wp:post-excerpt {"excerptLength":24,"textColor":"muted"} /--></div>
<!-- /wp:group -->
