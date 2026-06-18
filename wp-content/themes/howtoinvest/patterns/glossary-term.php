<?php
/**
 * Title: Glossary term layout
 * Slug: howtoinvest/glossary-term
 * Categories: howtoinvest
 * Description: Heading + definition layout for a glossary term, used by the glossary CPT template.
 * Block Types: core/post-content
 * Template Types: single-glossary
 * Inserter: yes
 *
 * @package HowToInvest
 */

?>
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:paragraph {"fontSize":"small","textColor":"primary","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.08em"}}} -->
<p class="has-primary-color has-text-color has-small-font-size" style="letter-spacing:0.08em;text-transform:uppercase"><?php echo esc_html__( 'Glossary', 'howtoinvest' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:post-title {"level":1} /-->

<!-- wp:post-content {"layout":{"type":"constrained"}} /--></div>
<!-- /wp:group -->
