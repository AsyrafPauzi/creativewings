<?php
/**
 * Template for page slug: registration.
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
		'title'    => __( 'Create an account', 'cw-astra-child' ),
		'intro'    => __( 'Register as a creator or organiser and start joining or hosting campaigns.', 'cw-astra-child' ),
		'modifier' => 'auth',
	]
);
?>
	<div class="cw-phase2__panel">
		<?php echo do_shortcode( '[custom_creator_registration_form]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
