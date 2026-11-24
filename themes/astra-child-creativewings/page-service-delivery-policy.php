<?php
/**
 * Template for page slug: service-delivery-policy.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_template_part(
	'template-parts/phase5/legal',
	'page',
	[
		'kicker' => __( 'Legal', 'cw-astra-child' ),
		'intro'  => __( 'How we deliver competitions, activities, and related services.', 'cw-astra-child' ),
	]
);
