<?php
/**
 * One-time bulk Rank Math SEO sync for Creative Wings.
 * Run via Novamira execute-php (require this file after deploy) or WP-CLI eval-file.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'cw_bulk_seo_clean' ) ) {
function cw_bulk_seo_clean( $text, $limit = 160 ) {
    $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
    $text = preg_replace( '/\s+/u', ' ', trim( $text ) );
    if ( $limit > 0 && mb_strlen( $text ) > $limit ) {
        $text = mb_substr( $text, 0, $limit - 1 ) . '…';
    }
    return $text;
}

function cw_bulk_seo_apply_post( $post_id, array $data ) {
    $post_id = (int) $post_id;
    if ( $post_id <= 0 ) {
        return false;
    }

    if ( ! empty( $data['seo_title'] ) ) {
        update_post_meta( $post_id, 'rank_math_title', $data['seo_title'] );
    }
    if ( ! empty( $data['meta_description'] ) ) {
        update_post_meta( $post_id, 'rank_math_description', $data['meta_description'] );
    }
    if ( ! empty( $data['focus_keywords'] ) ) {
        $kw = is_array( $data['focus_keywords'] ) ? implode( ', ', $data['focus_keywords'] ) : $data['focus_keywords'];
        update_post_meta( $post_id, 'rank_math_focus_keyword', $kw );
    }
    if ( ! empty( $data['robots'] ) && is_array( $data['robots'] ) ) {
        $robots = [];
        if ( ( $data['robots']['index'] ?? 'index' ) === 'noindex' ) {
            $robots[] = 'noindex';
        } else {
            $robots[] = 'index';
        }
        if ( ( $data['robots']['follow'] ?? 'follow' ) === 'nofollow' ) {
            $robots[] = 'nofollow';
        }
        update_post_meta( $post_id, 'rank_math_robots', $robots );
    }
    $fb_title = $data['facebook']['title'] ?? $data['seo_title'] ?? '';
    $fb_desc  = $data['facebook']['description'] ?? $data['meta_description'] ?? '';
    $tw_title = $data['twitter']['title'] ?? $data['seo_title'] ?? '';
    $tw_desc  = $data['twitter']['description'] ?? $data['meta_description'] ?? '';
    if ( $fb_title ) {
        update_post_meta( $post_id, 'rank_math_facebook_title', $fb_title );
    }
    if ( $fb_desc ) {
        update_post_meta( $post_id, 'rank_math_facebook_description', $fb_desc );
    }
    if ( $tw_title ) {
        update_post_meta( $post_id, 'rank_math_twitter_title', $tw_title );
    }
    if ( $tw_desc ) {
        update_post_meta( $post_id, 'rank_math_twitter_description', $tw_desc );
    }

    return true;
}

function cw_bulk_seo_apply_term( $term_id, array $data ) {
    $term_id = (int) $term_id;
    if ( $term_id <= 0 ) {
        return false;
    }
    if ( ! empty( $data['seo_title'] ) ) {
        update_term_meta( $term_id, 'rank_math_title', $data['seo_title'] );
    }
    if ( ! empty( $data['meta_description'] ) ) {
        update_term_meta( $term_id, 'rank_math_description', $data['meta_description'] );
    }
    if ( ! empty( $data['focus_keywords'] ) ) {
        $kw = is_array( $data['focus_keywords'] ) ? implode( ', ', $data['focus_keywords'] ) : $data['focus_keywords'];
        update_term_meta( $term_id, 'rank_math_focus_keyword', $kw );
    }
    if ( ! empty( $data['robots'] ) && is_array( $data['robots'] ) ) {
        $robots = ( $data['robots']['index'] ?? 'index' ) === 'noindex' ? [ 'noindex' ] : [ 'index' ];
        update_term_meta( $term_id, 'rank_math_robots', $robots );
    }
    return true;
}

function cw_bulk_seo_product_payload( WP_Post $post ) {
    $title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
    $excerpt = get_the_excerpt( $post );
    if ( ! $excerpt ) {
        $excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '' );
    }
    $deadline = (string) get_post_meta( $post->ID, 'submission_deadline', true );
    $fee      = (string) get_post_meta( $post->ID, '_regular_price', true );
    $terms    = get_the_terms( $post->ID, 'product_cat' );
    $cats     = [];
    if ( $terms && ! is_wp_error( $terms ) ) {
        foreach ( $terms as $t ) {
            $cats[] = $t->name;
        }
    }

    $desc = cw_bulk_seo_clean( $excerpt, 0 );
    if ( $fee !== '' && $fee !== '0' ) {
        $desc .= ' Entry from RM' . $fee . '.';
    }
    if ( $deadline ) {
        $ts = strtotime( $deadline );
        if ( $ts ) {
            $desc .= ' Closes ' . gmdate( 'j M Y', $ts ) . '.';
        }
    }
    $desc = cw_bulk_seo_clean( $desc, 160 );

    $seo_title = cw_bulk_seo_clean( $title, 0 );
    if ( mb_strlen( $seo_title ) > 52 ) {
        $seo_title = cw_bulk_seo_clean( $seo_title, 52 ) . ' | Creative Wings';
    } else {
        $seo_title .= ' | Creative Wings';
    }

    $keywords = array_filter( array_unique( array_merge(
        [ 'Creative Wings', 'Malaysia' ],
        $cats,
        array_slice( preg_split( '/\s+/u', preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $title ) ), 0, 4 )
    ) ) );

    return [
        'seo_title'       => $seo_title,
        'meta_description'=> $desc,
        'focus_keywords'  => array_slice( $keywords, 0, 5 ),
        'facebook'        => [
            'title'       => $title,
            'description' => $desc,
        ],
        'twitter'         => [
            'title'       => cw_bulk_seo_clean( $title, 70 ),
            'description' => $desc,
        ],
        'robots'          => [
            'index'  => 'publish' === $post->post_status ? 'index' : 'noindex',
            'follow' => 'follow',
        ],
        'schema_type'     => 'Event',
    ];
}

function cw_bulk_seo_page_map() {
    return [
        'homepage'                  => [
            'seo_title'        => 'Creative Wings | Competitions, Activities & Campaigns in Malaysia',
            'meta_description' => 'Creative Wings connects students, creators, and communities through competitions, activities, and meaningful campaigns across Malaysia. Join, create, and make an impact.',
            'focus_keywords'   => [ 'Creative Wings', 'Malaysia competitions', 'creative campaigns Malaysia' ],
        ],
        'competitions'              => [
            'seo_title'        => 'Creative Competitions in Malaysia | Creative Wings',
            'meta_description' => 'Browse art, design, drawing, writing, and photography competitions on Creative Wings. Register online and submit your creative work.',
            'focus_keywords'   => [ 'Malaysia competitions', 'art competition', 'Creative Wings' ],
        ],
        'activities'                => [
            'seo_title'        => 'Community Activities & Events | Creative Wings',
            'meta_description' => 'Discover runs, workshops, volunteer activities, and community events on Creative Wings. Join activities that create positive impact in Malaysia.',
            'focus_keywords'   => [ 'Malaysia activities', 'community events', 'Creative Wings' ],
        ],
        'list-competitions'         => [
            'seo_title'        => 'All Competitions | Creative Wings',
            'meta_description' => 'See every open and upcoming competition on Creative Wings. Filter by category and register online.',
            'focus_keywords'   => [ 'competition list', 'Creative Wings', 'Malaysia' ],
        ],
        'brand-story'               => [
            'seo_title'        => 'Our Brand Story | Creative Wings',
            'meta_description' => 'Learn how Creative Wings empowers creators, students, and organizers to run competitions and campaigns that inspire smiles and social impact.',
            'focus_keywords'   => [ 'Creative Wings story', 'about Creative Wings' ],
        ],
        'sdgs'                      => [
            'seo_title'        => 'Sustainable Development Goals | Creative Wings',
            'meta_description' => 'Creative Wings aligns campaigns with UN Sustainable Development Goals to drive education, community, and environmental impact across Malaysia.',
            'focus_keywords'   => [ 'SDG Malaysia', 'Creative Wings SDG' ],
        ],
        'directory'                 => [
            'seo_title'        => 'Directory | Creative Wings',
            'meta_description' => 'Explore organizers and creators on Creative Wings. Discover people and organizations behind competitions and community campaigns.',
            'focus_keywords'   => [ 'Creative Wings directory', 'creators Malaysia' ],
        ],
        'creators-directory'        => [
            'seo_title'        => 'Creators Directory | Creative Wings',
            'meta_description' => 'Meet creators and artists on Creative Wings. Browse profiles and discover talent from competitions and campaigns across Malaysia.',
            'focus_keywords'   => [ 'creators directory', 'Creative Wings' ],
        ],
        'organizers-directory'      => [
            'seo_title'        => 'Organizers Directory | Creative Wings',
            'meta_description' => 'Find schools, NGOs, and organizations running campaigns on Creative Wings. Connect with event and competition organizers in Malaysia.',
            'focus_keywords'   => [ 'organizers directory', 'Creative Wings' ],
        ],
        'organization-leadership'   => [
            'seo_title'        => 'Organization & Leadership | Creative Wings',
            'meta_description' => 'Meet the leadership team behind Creative Wings and learn how we support competitions, activities, and community impact initiatives.',
            'focus_keywords'   => [ 'Creative Wings leadership', 'organization' ],
        ],
        'get-started'               => [
            'seo_title'        => 'Get Started | Creative Wings',
            'meta_description' => 'Create an account on Creative Wings to join competitions, submit artwork, and participate in community activities across Malaysia.',
            'focus_keywords'   => [ 'join Creative Wings', 'register Creative Wings' ],
        ],
        'registration'              => [
            'seo_title'        => 'Register | Creative Wings',
            'meta_description' => 'Sign up for a free Creative Wings account to join competitions and activities, track submissions, and manage your profile.',
            'focus_keywords'   => [ 'Creative Wings registration', 'sign up' ],
        ],
        'privacy-policy'            => [
            'seo_title'        => 'Privacy Policy | Creative Wings',
            'meta_description' => 'Read the Creative Wings privacy policy to understand how we collect, use, and protect your personal information.',
            'focus_keywords'   => [ 'Creative Wings privacy policy' ],
        ],
        'pdpa'                      => [
            'seo_title'        => 'PDPA | Creative Wings',
            'meta_description' => 'Creative Wings PDPA information for personal data protection compliance in Malaysia.',
            'focus_keywords'   => [ 'PDPA Creative Wings', 'personal data protection' ],
        ],
        'terms-conditions'          => [
            'seo_title'        => 'Terms & Conditions | Creative Wings',
            'meta_description' => 'Terms and conditions for using Creative Wings competitions, activities, and platform services.',
            'focus_keywords'   => [ 'Creative Wings terms' ],
        ],
        'refund_returns'            => [
            'seo_title'        => 'Refund & Returns Policy | Creative Wings',
            'meta_description' => 'Refund and returns policy for Creative Wings paid entries and campaign registrations.',
            'focus_keywords'   => [ 'Creative Wings refund policy' ],
        ],
        'service-delivery-policy'   => [
            'seo_title'        => 'Service Delivery Policy | Creative Wings',
            'meta_description' => 'Service delivery policy for Creative Wings online competitions, registrations, and digital campaign services.',
            'focus_keywords'   => [ 'Creative Wings service delivery' ],
        ],
    ];
}

function cw_bulk_seo_noindex_slugs() {
    return [
        'cart',
        'checkout',
        'my-account',
        'login',
        'forgot-password',
        'reset-password',
        'complete-profile',
        'complete-guest-registration',
        'shop',
    ];
}

function cw_bulk_seo_term_payload( WP_Term $term, WP_Term $parent = null ) {
    $name = $term->name;
    $parent_name = $parent ? $parent->name : '';
    $ctx = $parent_name ? "$name $parent_name" : $name;

    return [
        'seo_title'        => "$ctx | Creative Wings",
        'meta_description' => cw_bulk_seo_clean(
            "Browse $ctx on Creative Wings. Discover campaigns, register online, and join creative opportunities across Malaysia.",
            160
        ),
        'focus_keywords'   => array_filter( [ $name, 'Creative Wings', 'Malaysia', $parent_name ] ),
        'robots'           => [ 'index' => 'index', 'follow' => 'follow' ],
    ];
}

function cw_bulk_seo_run() {
    $report = [
        'products' => [],
        'pages'    => [],
        'terms'    => [],
    ];

    $products = get_posts(
        [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'private' ],
            'posts_per_page' => -1,
        ]
    );
    foreach ( $products as $post ) {
        if ( trim( (string) get_post_meta( $post->ID, 'rank_math_title', true ) ) !== '' ) {
            $report['products'][] = [
                'id'      => (int) $post->ID,
                'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
                'status'  => $post->post_status,
                'skipped' => true,
            ];
            continue;
        }
        $payload = cw_bulk_seo_product_payload( $post );
        $schema  = $payload['schema_type'];
        unset( $payload['schema_type'] );
        cw_bulk_seo_apply_post( $post->ID, $payload );
        if ( 'publish' === $post->post_status && $schema ) {
            // Best-effort schema via Rank Math REST ability pattern — set rich snippet hint.
            update_post_meta( $post->ID, 'rank_math_rich_snippet', strtolower( $schema ) );
        }
        $report['products'][] = [
            'id'    => (int) $post->ID,
            'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
            'status'=> $post->post_status,
        ];
    }

    $page_map    = cw_bulk_seo_page_map();
    $noindex     = cw_bulk_seo_noindex_slugs();
    $pages       = get_posts(
        [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ]
    );
    foreach ( $pages as $page ) {
        $slug = $page->post_name;
        if ( isset( $page_map[ $slug ] ) ) {
            $payload = $page_map[ $slug ];
            $payload['robots'] = [ 'index' => 'index', 'follow' => 'follow' ];
        } elseif ( in_array( $slug, $noindex, true ) ) {
            $payload = [
                'seo_title'        => html_entity_decode( get_the_title( $page ), ENT_QUOTES, 'UTF-8' ) . ' | Creative Wings',
                'meta_description' => cw_bulk_seo_clean( get_the_excerpt( $page ) ?: wp_trim_words( $page->post_content, 25 ), 160 ),
                'robots'           => [ 'index' => 'noindex', 'follow' => 'nofollow' ],
            ];
        } else {
            $payload = [
                'seo_title'        => html_entity_decode( get_the_title( $page ), ENT_QUOTES, 'UTF-8' ) . ' | Creative Wings',
                'meta_description' => cw_bulk_seo_clean( get_the_excerpt( $page ) ?: wp_trim_words( $page->post_content, 30 ), 160 ),
                'focus_keywords'   => [ 'Creative Wings' ],
                'robots'           => [ 'index' => 'index', 'follow' => 'follow' ],
            ];
        }
        cw_bulk_seo_apply_post( $page->ID, $payload );
        $report['pages'][] = [
            'id'    => (int) $page->ID,
            'slug'  => $slug,
            'title' => html_entity_decode( get_the_title( $page ), ENT_QUOTES, 'UTF-8' ),
        ];
    }

    $terms = get_terms(
        [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]
    );
    if ( ! is_wp_error( $terms ) ) {
        $parents = [];
        foreach ( $terms as $term ) {
            if ( 0 === (int) $term->parent ) {
                $parents[ $term->term_id ] = $term;
            }
        }
        foreach ( $terms as $term ) {
            if ( 'uncategorized' === $term->slug ) {
                continue;
            }
            $parent = (int) $term->parent && isset( $parents[ (int) $term->parent ] )
                ? $parents[ (int) $term->parent ]
                : null;
            cw_bulk_seo_apply_term( (int) $term->term_id, cw_bulk_seo_term_payload( $term, $parent ) );
            $report['terms'][] = [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }
    }

    return [
        'ok'     => true,
        'counts' => [
            'products' => count( $report['products'] ),
            'pages'    => count( $report['pages'] ),
            'terms'    => count( $report['terms'] ),
        ],
        'report' => $report,
    ];
}
