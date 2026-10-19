<?php
/**
 * Blank full-bleed shell for the Creative Wings dashboard (My Account).
 *
 * No theme chrome and no Elementor canvas — portal markup + CW CSS only.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="cw-dashboard-blank-html">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'cw-dashboard-blank' ); ?>>
<?php wp_body_open(); ?>
<main id="cw-dashboard-root" class="cw-dashboard-root">
<?php
while ( have_posts() ) {
	the_post();
	$raw = (string) get_post()->post_content;
	if ( has_shortcode( $raw, 'cw_dashboard' ) ) {
		// Call the shortcode directly so Elementor content filters cannot wrap it.
		echo do_shortcode( '[cw_dashboard]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode returns trusted HTML
	} else {
		the_content();
	}
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
