<?php
/**
 * Creative Wings Astra child theme.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

define( 'CW_CHILD_VERSION', '0.12.2' );
define( 'CW_CHILD_DIR', get_stylesheet_directory() );
define( 'CW_CHILD_URI', get_stylesheet_directory_uri() );

/**
 * Attachment IDs used by chrome (live Media Library).
 */
define( 'CW_CHILD_LOGO_ID', 3151 );
define( 'CW_CHILD_SDG_ID', 2216 );
define( 'CW_CHILD_FOOTER_BADGE_ID', 2262 );
define( 'CW_CHILD_MOBILE_LOGO_ID', 3378 );
define( 'CW_CHILD_HOME_HERO_ID', 2225 );

/**
 * Slugs that use Phase 1 browse templates.
 *
 * @return string[]
 */
function cw_child_browse_slugs() {
	return [ 'homepage', 'activities', 'competitions' ];
}

/**
 * Slugs that use Phase 2 shells.
 *
 * @return string[]
 */
function cw_child_phase2_slugs() {
	return [
		'login',
		'registration',
		'forgot-password',
		'reset-password',
		'get-started',
		'complete-profile',
		'complete-guest-registration',
		'creators-directory',
		'organizers-directory',
		'sponsors',
		'rewards',
		'contact',
		'about',
		'privacy-policy',
		'terms-conditions',
		'service-delivery-policy',
		'pdpa',
		'refund_returns',
	];
}

/**
 * Slugs that use Phase 3 story templates.
 *
 * @return string[]
 */
function cw_child_phase3_slugs() {
	return [
		'brand-story',
		'organization-leadership',
		'sdgs',
	];
}

/**
 * Whether current request is a child-owned marketing/auth page.
 */
function cw_child_is_owned_page() {
	return is_front_page()
		|| is_page( cw_child_browse_slugs() )
		|| is_page( cw_child_phase2_slugs() )
		|| is_page( cw_child_phase3_slugs() )
		|| is_search()
		|| ( function_exists( 'is_product' ) && is_product() )
		|| cw_child_is_regflow_page();
}

/**
 * Cart / checkout registration flow (Phase 4 Pass 2).
 */
function cw_child_is_regflow_page() {
	if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
		return false;
	}
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		return false;
	}
	return is_cart() || is_checkout();
}

add_action(
	'wp_enqueue_scripts',
	function () {
		$astra = wp_get_theme( 'astra' );
		wp_enqueue_style(
			'astra-parent-style',
			get_template_directory_uri() . '/style.css',
			[],
			$astra->get( 'Version' )
		);
		wp_enqueue_style(
			'cw-child-tokens',
			CW_CHILD_URI . '/assets/css/tokens.css',
			[ 'astra-parent-style' ],
			CW_CHILD_VERSION
		);
		wp_enqueue_style(
			'cw-child-style',
			CW_CHILD_URI . '/style.css',
			[ 'cw-child-tokens' ],
			CW_CHILD_VERSION
		);
		wp_enqueue_style(
			'cw-child-chrome',
			CW_CHILD_URI . '/assets/css/chrome.css',
			[ 'cw-child-style' ],
			CW_CHILD_VERSION
		);

		if ( is_front_page() || is_page( cw_child_browse_slugs() ) ) {
			$pages_deps = [ 'cw-child-chrome' ];
			if ( wp_style_is( 'cw-style-general', 'registered' ) || wp_style_is( 'cw-style-general', 'enqueued' ) ) {
				$pages_deps[] = 'cw-style-general';
			}
			wp_enqueue_style(
				'cw-child-pages',
				CW_CHILD_URI . '/assets/css/pages.css',
				$pages_deps,
				CW_CHILD_VERSION
			);
		}

		if ( is_page( cw_child_phase2_slugs() ) || is_page( cw_child_phase3_slugs() ) || is_search() ) {
			$phase2_deps = [ 'cw-child-chrome' ];
			if ( wp_style_is( 'cw-style-directory', 'registered' ) || wp_style_is( 'cw-style-directory', 'enqueued' ) ) {
				$phase2_deps[] = 'cw-style-directory';
			}
			if ( wp_style_is( 'cw-style-general', 'registered' ) || wp_style_is( 'cw-style-general', 'enqueued' ) ) {
				$phase2_deps[] = 'cw-style-general';
			}
			wp_enqueue_style(
				'cw-child-phase2',
				CW_CHILD_URI . '/assets/css/phase2.css',
				$phase2_deps,
				CW_CHILD_VERSION
			);
		}

		if ( is_page( cw_child_phase3_slugs() ) ) {
			wp_enqueue_style(
				'cw-child-phase3',
				CW_CHILD_URI . '/assets/css/phase3.css',
				[ 'cw-child-phase2' ],
				CW_CHILD_VERSION
			);
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$p4_deps = [ 'cw-child-chrome' ];
			if ( wp_style_is( 'cw-style-general', 'registered' ) || wp_style_is( 'cw-style-general', 'enqueued' ) ) {
				$p4_deps[] = 'cw-style-general';
			}
			if ( wp_style_is( 'cw-style-checkout', 'registered' ) || wp_style_is( 'cw-style-checkout', 'enqueued' ) ) {
				$p4_deps[] = 'cw-style-checkout';
			}
			wp_enqueue_style(
				'cw-child-phase4-product',
				CW_CHILD_URI . '/assets/css/phase4-product.css',
				$p4_deps,
				CW_CHILD_VERSION
			);
		}

		if ( cw_child_is_regflow_page() ) {
			$p4c_deps = [ 'cw-child-chrome' ];
			if ( wp_style_is( 'cw-style-general', 'registered' ) || wp_style_is( 'cw-style-general', 'enqueued' ) ) {
				$p4c_deps[] = 'cw-style-general';
			}
			if ( wp_style_is( 'cw-style-checkout', 'registered' ) || wp_style_is( 'cw-style-checkout', 'enqueued' ) ) {
				$p4c_deps[] = 'cw-style-checkout';
			}
			wp_enqueue_style(
				'cw-child-phase4-commerce',
				CW_CHILD_URI . '/assets/css/phase4-commerce.css',
				$p4c_deps,
				CW_CHILD_VERSION
			);
		}

		wp_enqueue_script(
			'cw-child-chrome',
			CW_CHILD_URI . '/assets/js/chrome.js',
			[],
			CW_CHILD_VERSION,
			true
		);

		$needs_gsap = is_front_page()
			|| is_page( cw_child_browse_slugs() )
			|| is_page( cw_child_phase2_slugs() )
			|| is_page( cw_child_phase3_slugs() )
			|| is_search()
			|| ( function_exists( 'is_product' ) && is_product() )
			|| cw_child_is_regflow_page();

		if ( $needs_gsap ) {
			wp_enqueue_script(
				'cw-gsap',
				'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js',
				[],
				'3.12.5',
				true
			);
			wp_enqueue_script(
				'cw-gsap-scrolltrigger',
				'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js',
				[ 'cw-gsap' ],
				'3.12.5',
				true
			);
		}

		if ( is_front_page() || is_page( 'homepage' ) ) {
			wp_enqueue_script(
				'cw-child-home',
				CW_CHILD_URI . '/assets/js/home.js',
				[ 'cw-gsap-scrolltrigger' ],
				CW_CHILD_VERSION,
				true
			);
		}

		if ( is_page( [ 'activities', 'competitions' ] ) ) {
			wp_enqueue_script(
				'cw-child-browse',
				CW_CHILD_URI . '/assets/js/browse.js',
				[ 'cw-gsap-scrolltrigger' ],
				CW_CHILD_VERSION,
				true
			);
		}

		if ( is_page( cw_child_phase2_slugs() ) || is_page( cw_child_phase3_slugs() ) || is_search() ) {
			wp_enqueue_script(
				'cw-child-phase2',
				CW_CHILD_URI . '/assets/js/phase2.js',
				[ 'cw-gsap-scrolltrigger' ],
				CW_CHILD_VERSION,
				true
			);
		}

		if ( is_page( cw_child_phase3_slugs() ) ) {
			wp_enqueue_script(
				'cw-child-phase3',
				CW_CHILD_URI . '/assets/js/phase3.js',
				[ 'cw-gsap-scrolltrigger', 'cw-child-phase2' ],
				CW_CHILD_VERSION,
				true
			);
		}

		if ( is_page( 'sdgs' ) ) {
			wp_enqueue_script(
				'cw-child-phase3-sdgs',
				CW_CHILD_URI . '/assets/js/phase3-sdgs.js',
				[ 'cw-gsap-scrolltrigger', 'cw-child-phase2' ],
				CW_CHILD_VERSION,
				true
			);
		}

		if ( is_page( 'sponsors' ) ) {
			wp_enqueue_script(
				'cw-child-sponsors',
				CW_CHILD_URI . '/assets/js/sponsors.js',
				[ 'cw-gsap-scrolltrigger', 'cw-child-phase2' ],
				CW_CHILD_VERSION,
				true
			);
		}

		if ( is_page( [ 'contact', 'about' ] ) ) {
			wp_enqueue_script(
				'cw-child-content',
				CW_CHILD_URI . '/assets/js/content.js',
				[ 'cw-gsap-scrolltrigger', 'cw-child-phase2' ],
				CW_CHILD_VERSION,
				true
			);
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_script(
				'cw-child-phase4-product',
				CW_CHILD_URI . '/assets/js/phase4-product.js',
				[ 'cw-gsap-scrolltrigger' ],
				CW_CHILD_VERSION,
				true
			);
		}

		if ( cw_child_is_regflow_page() ) {
			wp_enqueue_script(
				'cw-child-phase4-commerce',
				CW_CHILD_URI . '/assets/js/phase4-commerce.js',
				[ 'cw-gsap' ],
				CW_CHILD_VERSION,
				true
			);
		}
	},
	20
);

/**
 * Prefer theme Woo single-product over Elementor Theme Builder product location.
 */
add_action(
	'wp',
	function () {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		// Stop Elementor Pro from hijacking the product singular location.
		add_filter(
			'elementor/theme/need_override_location',
			static function ( $need_override, $location ) {
				if ( in_array( $location, [ 'single', 'singleproduct', 'product' ], true ) ) {
					return false;
				}
				return $need_override;
			},
			20,
			2
		);
	},
	5
);

add_action(
	'after_setup_theme',
	function () {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
	}
);

/**
 * Replace Astra header/footer with Creative Wings chrome.
 */
add_action(
	'init',
	function () {
		remove_action( 'astra_header', 'astra_header_markup' );
		remove_action( 'astra_footer', 'astra_footer_markup' );
		add_filter( 'astra_footer_bottom_bar_layout', '__return_empty_string' );
		remove_action( 'astra_footer_content_bottom', 'astra_footer_small_content_markup', 5 );
		remove_action( 'astra_footer_content_top', 'astra_footer_small_content_markup', 5 );
	},
	20
);

add_action(
	'wp_head',
	function () {
		echo '<style id="cw-child-hide-astra-credit">.ast-footer-copyright,.ast-small-footer,.site-below-footer-wrap,.ast-archive-description,.ast-footer-site-title{display:none!important}body.home #page,body.page-id-159 #page,body.page-id-2468 #page,body.page-id-460 #page,body.page-id-281 #page,body.page-id-265 #page,body.page-id-2980 #page,body.page-id-3543 #page,body.page-id-3542 #page,body.page-id-4066 #page,body.page-id-4044 #page,body.page-id-4045 #page,body.page-id-1039 #page,body.page-id-3473 #page,body.page-id-2119 #page,body.page-id-3308 #page,body.page-id-2117 #page,body.page-id-3700 #page,body.page-id-3459 #page,body.page-id-22 #page,body.page-id-23 #page,body.single-product #page,body.woocommerce-cart #page,body.woocommerce-checkout #page{background:transparent!important}</style>';
	},
	99
);

/**
 * Pass 2 / Phase 5 redirects for retired Elementor shells.
 */
add_action(
	'template_redirect',
	function () {
		if ( is_page( 'list-competitions' ) || is_page( 842 ) ) {
			wp_safe_redirect( home_url( '/competitions/' ), 301 );
			exit;
		}
		// Empty parent Directory hub → Creators Directory.
		if ( is_page( 'directory' ) && ! is_page( [ 'creators-directory', 'organizers-directory' ] ) ) {
			wp_safe_redirect( home_url( '/directory/creators-directory/' ), 301 );
			exit;
		}
	},
	5
);

/**
 * Our page templates own the H1 — hide Astra entry titles on those pages.
 */
add_filter(
	'astra_the_title_enabled',
	function ( $enabled ) {
		if ( cw_child_is_owned_page() ) {
			return false;
		}
		return $enabled;
	}
);

add_action(
	'astra_header',
	function () {
		get_template_part( 'template-parts/chrome/header', 'bar' );
	}
);

add_action(
	'astra_footer',
	function () {
		get_template_part( 'template-parts/chrome/footer', 'bar' );
	}
);

/**
 * Admin-only preview of this child theme while parent is still active.
 * Visit any URL with ?cw_child_preview=1 while logged in as admin.
 */
add_filter(
	'template',
	function ( $template ) {
		if ( ! empty( $_GET['cw_child_preview'] ) && current_user_can( 'switch_themes' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'astra';
		}
		return $template;
	},
	1
);

add_filter(
	'stylesheet',
	function ( $stylesheet ) {
		if ( ! empty( $_GET['cw_child_preview'] ) && current_user_can( 'switch_themes' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'astra-child-creativewings';
		}
		return $stylesheet;
	},
	1
);

/**
 * Current nav helper — matches Elementor active color on known paths.
 *
 * @param string $path Path fragment e.g. '/brand-story/'.
 */
function cw_child_nav_is_current( $path ) {
	$request = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ); // phpcs:ignore
	$path    = untrailingslashit( $path );
	$request = untrailingslashit( (string) $request );
	return $request === $path || str_ends_with( $request, $path );
}
