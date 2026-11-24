<?php
/**
 * Template for page slug: terms-conditions.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_template_part(
	'template-parts/phase5/legal',
	'page',
	[
		'kicker' => __( 'Legal', 'cw-astra-child' ),
		'intro'  => __( 'The terms that apply when you use Creative Wings websites and services.', 'cw-astra-child' ),
	]
);
