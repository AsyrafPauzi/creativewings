<?php
/**
 * Template for page slug: get-started.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	$dest = home_url( '/get-started/' );
	$login = get_page_by_path( 'login' )
		? add_query_arg( 'redirect_to', $dest, home_url( '/login/' ) )
		: wp_login_url( $dest );
	wp_safe_redirect( $login );
	exit;
}

get_header();
get_template_part(
	'template-parts/phase2/shell',
	'start',
	[
		'kicker'   => __( 'Onboarding', 'cw-astra-child' ),
		'title'    => __( 'Get started', 'cw-astra-child' ),
		'intro'    => __( 'Choose how you want to use Creative Wings — then finish your profile.', 'cw-astra-child' ),
		'modifier' => 'auth',
	]
);
?>
	<div class="cw-phase2__panel">
		<?php echo do_shortcode( '[cw_role_selection]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
