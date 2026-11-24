<?php
/**
 * Template for page slug: login.
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
		'title'    => __( 'Log in', 'cw-astra-child' ),
		'intro'    => __( 'Access your Creative Wings dashboard to join campaigns and manage entries.', 'cw-astra-child' ),
		'modifier' => 'auth',
	]
);
?>
	<div class="cw-phase2__panel">
		<?php echo do_shortcode( '[custom_login_form]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
