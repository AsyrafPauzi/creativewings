<?php
/**
 * Template for page slug: refund_returns.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_template_part(
	'template-parts/phase5/legal',
	'page',
	[
		'kicker' => __( 'Legal', 'cw-astra-child' ),
		'intro'  => __( 'Returns and refunds for Creative Wings campaign fees and purchases.', 'cw-astra-child' ),
	]
);
