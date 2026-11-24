<?php
/**
 * Shared legal / policy page body (Phase 5).
 *
 * Expected $args: kicker, intro (optional). Title comes from the page.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

$args = wp_parse_args(
	$args ?? [],
	[
		'kicker' => __( 'Legal', 'cw-astra-child' ),
		'intro'  => '',
	]
);

get_header();
get_template_part(
	'template-parts/phase2/shell',
	'start',
	[
		'kicker'   => $args['kicker'],
		'title'    => get_the_title(),
		'intro'    => $args['intro'],
		'modifier' => 'legal',
	]
);
?>
	<div class="cw-phase2__panel cw-legal-prose">
		<?php
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
