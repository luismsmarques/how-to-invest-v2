<?php
/**
 * Title: CTA — Discover your investor profile
 * Slug: howtoinvest/cta-questionnaire
 * Categories: howtoinvest
 * Keywords: cta, questionnaire, profile, quiz
 * Description: Insertable call-to-action that sends readers to the educational questionnaire. Never links to execution/brokerage.
 * Inserter: yes
 *
 * @package HowToInvest
 */

?>
<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40","right":"var:preset|spacing|40"},"blockGap":"var:preset|spacing|20"},"border":{"radius":"16px","color":"#E7DCFB","width":"1px"}},"backgroundColor":"primary-soft","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide has-primary-soft-background-color has-background has-border-color" style="border-color:#E7DCFB;border-width:1px;border-radius:16px;padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)"><!-- wp:heading {"textAlign":"center","level":3,"textColor":"contrast"} -->
<h3 class="wp-block-heading has-text-align-center has-contrast-color has-text-color"><?php echo esc_html__( 'Curious where you fit?', 'howtoinvest' ); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","textColor":"muted"} -->
<p class="has-text-align-center has-muted-color has-text-color"><?php echo esc_html__( 'Answer a few questions and discover your investor archetype — with an illustrative example portfolio by asset class. Educational, not advice.', 'howtoinvest' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/investor-profile-quiz/' ) ); ?>"><?php echo esc_html__( 'Discover your profile', 'howtoinvest' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
