<?php
/**
 * Title: Short disclaimer banner
 * Slug: howtoinvest/disclaimer-short
 * Categories: howtoinvest
 * Description: One-line educational disclaimer for the homepage / questionnaire (Textos Finais §1.2).
 * Inserter: yes
 *
 * @package HowToInvest
 */

?>
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}},"border":{"radius":"8px"}},"backgroundColor":"info-surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-info-surface-background-color has-background" style="border-radius:8px;padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30)"><!-- wp:paragraph {"align":"center","fontSize":"small","textColor":"info"} -->
<p class="has-text-align-center has-info-color has-text-color has-small-font-size"><?php
echo esc_html__( 'Educational tool · not financial advice · examples by asset class only', 'howtoinvest' );
?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
