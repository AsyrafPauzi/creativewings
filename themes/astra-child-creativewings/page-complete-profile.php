<?php
/**
 * Template for page slug: complete-profile.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_header();
get_template_part(
	'template-parts/phase2/shell',
	'start',
	[
		'kicker'   => __( 'Account', 'cw-astra-child' ),
		'title'    => __( 'One more step', 'cw-astra-child' ),
		'intro'    => __( 'Finish a couple of details so we can place you in the right campaign category.', 'cw-astra-child' ),
		'modifier' => 'auth',
	]
);
?>
	<div class="cw-phase2__panel">
		<?php echo do_shortcode( '[cw_complete_profile]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
