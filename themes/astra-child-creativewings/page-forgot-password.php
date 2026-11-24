<?php
/**
 * Template for page slug: forgot-password.
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
		'title'    => __( 'Forgot password', 'cw-astra-child' ),
		'intro'    => __( 'Enter your email and we’ll send a link to choose a new password.', 'cw-astra-child' ),
		'modifier' => 'auth',
	]
);
?>
	<div class="cw-phase2__panel">
		<?php echo do_shortcode( '[custom_forgot_password]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
