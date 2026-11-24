<?php
/**
 * Template for page slug: organizers-directory.
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
		'title'    => __( 'Organizers Directory', 'cw-astra-child' ),
		'intro'    => __( 'Find schools, NGOs, and organisations running campaigns on Creative Wings.', 'cw-astra-child' ),
		'modifier' => 'directory',
	]
);
?>
	<div class="cw-phase2__panel">
		<?php echo do_shortcode( '[cw_organizers_directory]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
