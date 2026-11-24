<?php
/**
 * One discovery sentence on the drawing, design, and art category archives.
 *
 * @package CW_Astra_Child
 */

if ( ! function_exists( 'cw_child_discovery_category_intro_html' ) ) {
	/**
	 * @param string $slug              Product category slug.
	 * @param string $competitions_url  Competitions page URL.
	 * @param string $osow_url          One Smile, One World product URL.
	 */
	function cw_child_discovery_category_intro_html( $slug, $competitions_url, $osow_url ) {
		$competitions_url = esc_url( $competitions_url );
		$osow_url         = esc_url( $osow_url );
		if ( 'drawing' === $slug ) {
			return '<p class="cw-discovery-intro">The Merdeka Month drawing competition has closed. <a href="' . $competitions_url . '">See what is open.</a></p>';
		}
		if ( 'competitions-design' === $slug ) {
			return '<p class="cw-discovery-intro">The ORBI design competition has closed. <a href="' . $competitions_url . '">See what is open.</a></p>';
		}
		if ( 'art-activities' === $slug ) {
			return '<p class="cw-discovery-intro"><a href="' . $osow_url . '">One Smile, One World</a> is open for children until 31 December 2026.</p>';
		}
		return '';
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action(
		'woocommerce_archive_description',
		static function () {
			if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
				return;
			}
			$term = get_queried_object();
			if ( ! $term || empty( $term->slug ) ) {
				return;
			}
			$html = cw_child_discovery_category_intro_html(
				(string) $term->slug,
				home_url( '/competitions/' ),
				get_permalink( 3361 )
			);
			if ( '' !== $html ) {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the builder.
			}
		}
	);
}
