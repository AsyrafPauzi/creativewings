<?php
/**
 * Template for page slug: reset-password.
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
		'title'    => __( 'Set a new password', 'cw-astra-child' ),
		'intro'    => __( 'Choose a strong password to finish resetting your Creative Wings account.', 'cw-astra-child' ),
		'modifier' => 'auth',
	]
);
?>
	<div class="cw-phase2__panel">
		<?php echo do_shortcode( '[cw_reset_password]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
