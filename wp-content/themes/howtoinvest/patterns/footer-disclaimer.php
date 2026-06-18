<?php
/**
 * Title: Footer disclaimer
 * Slug: howtoinvest/footer-disclaimer
 * Categories: howtoinvest
 * Description: Full educational disclaimer shown on every page (Textos Finais §1.3).
 * Inserter: no
 *
 * @package HowToInvest
 */

?>
<!-- wp:paragraph {"align":"wide","fontSize":"small","textColor":"muted","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|20"}}}} -->
<p class="has-text-align-wide has-muted-color has-text-color has-small-font-size" style="margin-bottom:var(--wp--preset--spacing--20)"><?php
echo wp_kses_post(
	__( 'HowToInvest is an educational platform about investing literacy. Nothing here is financial, investment, tax or legal advice, or a recommendation to buy or sell any asset. Investing carries risk, including loss of capital. Examples are illustrative and by asset class only. Always do your own research and consider professional advice.', 'howtoinvest' )
);
?></p>
<!-- /wp:paragraph -->
