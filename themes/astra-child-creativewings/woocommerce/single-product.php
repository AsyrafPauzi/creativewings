<?php
/**
 * WooCommerce single product — Creative Wings campaign detail.
 *
 * Replaces Elementor Theme Builder product templates (122 / 1692)
 * with the plugin shortcode UI `[cw_event_detail]`.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );
?>
<main id="primary" class="site-main cw-phase4 cw-phase4--product" data-cw-phase4-product>
	<div class="cw-phase4__sky" aria-hidden="true">
		<span class="cw-phase4__cloud cw-phase4__cloud--a" data-cw-phase4-cloud></span>
		<span class="cw-phase4__cloud cw-phase4__cloud--b" data-cw-phase4-cloud></span>
	</div>

	<div class="cw-phase4__stage">
		<?php
		while ( have_posts() ) {
			the_post();
			echo do_shortcode( '[cw_event_detail]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
	</div>
</main>
<?php
get_footer( 'shop' );
