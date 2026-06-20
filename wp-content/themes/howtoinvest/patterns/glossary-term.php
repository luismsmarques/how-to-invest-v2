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
<!-- wp:group {"className":"hti-term","layout":{"type":"constrained"}} -->
<div class="wp-block-group hti-term"><!-- wp:paragraph {"className":"hti-back-wrap"} -->
<p class="hti-back-wrap"><a class="hti-back" href="<?php echo esc_url( get_post_type_archive_link( 'glossary' ) ); ?>"><?php echo esc_html( \HowToInvest\Theme\t( 'back_glossary' ) ); ?></a></p>
<!-- /wp:paragraph -->

<!-- wp:post-title {"level":1} /-->

<!-- wp:post-content {"className":"hti-prose","layout":{"type":"constrained"}} /--></div>
<!-- /wp:group -->
