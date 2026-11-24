<?php
/**
 * Template for page slug: privacy-policy.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_template_part(
	'template-parts/phase5/legal',
	'page',
	[
		'kicker' => __( 'Legal', 'cw-astra-child' ),
		'intro'  => __( 'How Creative Wings collects, uses, and protects your information.', 'cw-astra-child' ),
	]
);
