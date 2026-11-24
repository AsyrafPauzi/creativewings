<?php
/**
 * Template for page slug: pdpa.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_template_part(
	'template-parts/phase5/legal',
	'page',
	[
		'kicker' => __( 'Legal', 'cw-astra-child' ),
		'intro'  => __( 'Personal Data Protection Notice for Creative Wings applicants and users.', 'cw-astra-child' ),
	]
);
