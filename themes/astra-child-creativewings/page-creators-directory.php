<?php
/**
 * Template for page slug: creators-directory.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_header();
get_template_part(
	'template-parts/phase2/shell',
	'start',
	[
		'kicker'   => __( 'Directory', 'cw-astra-child' ),
		'title'    => __( 'Creators Directory', 'cw-astra-child' ),
		'intro'    => __( 'Discover young creators and portfolios across Malaysia on Creative Wings.', 'cw-astra-child' ),
		'modifier' => 'directory',
	]
);
?>
	<div class="cw-phase2__panel">
		<?php echo do_shortcode( '[cw_creators_directory]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
