<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO structured data: FAQ JSON-LD, Organization enrichment, campaign OG images.
 */
class CW_Structured_Data {

    public static function register_hooks() {
        add_action( 'wp_head', [ __CLASS__, 'output_faq_json_ld' ], 20 );
        add_action( 'wp_head', [ __CLASS__, 'output_local_business_json_ld' ], 21 );
        add_filter( 'rank_math/json_ld', [ __CLASS__, 'enhance_json_ld' ], 99, 2 );
        add_action( 'save_post_product', [ __CLASS__, 'sync_product_og_image' ], 20, 2 );
        add_filter( 'rank_math/opengraph/facebook/image', [ __CLASS__, 'fallback_og_image' ] );
        add_filter( 'rank_math/opengraph/twitter/image', [ __CLASS__, 'fallback_og_image' ] );
        add_filter( 'rank_math/sitemap/http_headers', [ __CLASS__, 'fix_sitemap_headers' ], 10, 2 );
        add_filter( 'rank_math/sitemap/urlimages', [ __CLASS__, 'filter_sitemap_images' ], 10, 2 );
    }

    /**
     * Drop page images from sitemaps — Elementor pages can exhaust PHP memory.
     *
     * @param array<int, array<string, string>> $images
     * @param int                               $post_id
     * @return array<int, array<string, string>>
     */
    public static function filter_sitemap_images( $images, $post_id ) {
        if ( get_post_type( (int) $post_id ) === 'page' ) {
            return [];
        }

        return $images;
    }

    /**
     * GSC sometimes rejects sitemaps when Rank Math sends X-Robots-Tag: noindex.
     *
     * @param array<string,string|int> $headers
     * @param bool                     $is_xsl
     * @return array<string,string|int>
     */
    public static function fix_sitemap_headers( $headers, $is_xsl ) {
        unset( $headers['X-Robots-Tag'] );

        if ( ! $is_xsl ) {
            $headers['Content-Type'] = 'application/xml; charset=UTF-8';
        }

        return $headers;
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    public static function get_faq_items( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return [];
        }

        $raw = get_post_meta( $post_id, 'faq', true );
        if ( is_array( $raw ) && isset( $raw[0] ) && is_array( $raw[0] ) ) {
            $raw = $raw[0];
        }
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $items = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $question = trim( (string) ( $row['question'] ?? '' ) );
            $answer   = trim( wp_strip_all_tags( (string) ( $row['answer'] ?? '' ) ) );
            if ( $question === '' || $answer === '' ) {
                continue;
            }
            $items[] = [
                'question' => $question,
                'answer'   => $answer,
            ];
        }

        return $items;
    }

    public static function sync_product_og_image( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! $post instanceof WP_Post || $post->post_type !== 'product' ) {
            return;
        }
        self::set_product_og_image( $post_id, true );
    }

    /**
     * Sync Rank Math social images from the WooCommerce featured image.
     */
    public static function set_product_og_image( $post_id, $force = false ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return false;
        }

        $thumb_id = (int) get_post_thumbnail_id( $post_id );
        if ( $thumb_id <= 0 ) {
            return false;
        }

        $existing = (int) get_post_meta( $post_id, 'rank_math_facebook_image_id', true );
        if ( ! $force && $existing > 0 ) {
            return false;
        }

        $image_url = wp_get_attachment_image_url( $thumb_id, 'full' );
        if ( ! $image_url ) {
            return false;
        }

        update_post_meta( $post_id, 'rank_math_facebook_image_id', $thumb_id );
        update_post_meta( $post_id, 'rank_math_facebook_image', $image_url );
        update_post_meta( $post_id, 'rank_math_twitter_use_facebook', 'on' );
        update_post_meta( $post_id, 'rank_math_twitter_image_id', $thumb_id );
        update_post_meta( $post_id, 'rank_math_twitter_image', $image_url );

        return true;
    }

    public static function fallback_og_image( $image ) {
        if ( $image || ! is_singular( 'product' ) ) {
            return $image;
        }

        $thumb_id = (int) get_post_thumbnail_id( get_queried_object_id() );
        if ( $thumb_id <= 0 ) {
            return $image;
        }

        $url = wp_get_attachment_image_url( $thumb_id, 'full' );
        return $url ?: $image;
    }

    public static function output_faq_json_ld() {
        if ( ! is_singular( 'product' ) ) {
            return;
        }

        $items = self::get_faq_items( get_queried_object_id() );
        if ( empty( $items ) ) {
            return;
        }

        $main_entity = [];
        foreach ( $items as $item ) {
            $main_entity[] = [
                '@type'          => 'Question',
                'name'           => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $item['answer'],
                ],
            ];
        }

        $payload = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $main_entity,
        ];

        echo '<script type="application/ld+json" class="cw-faq-schema">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    }

    /**
     * Official NAP for Creative Wings (Malaysia service-area business).
     *
     * @return array<string,mixed>
     */
    public static function get_nap() {
        $nap = [
            'legal_name'     => 'Creative Wings Sdn Bhd',
            'brand_name'     => 'Creative Wings',
            'street_address' => 'NO. 12A, Jalan Setia Impian U13/5E, Setia Alam Seksyen U13',
            'locality'       => 'Shah Alam',
            'region'         => 'Selangor',
            'postal_code'     => '40170',
            'country'        => 'MY',
            'country_name'   => 'Malaysia',
            'telephone'      => '+60126618338',
            'email'          => 'hello@creativewings.asia',
            'service_area'   => 'Malaysia',
            'same_as'        => [
                'https://www.facebook.com/creativewings.asia',
                'https://www.instagram.com/creativewings.asia',
            ],
            'description'    => 'Creative Wings connects students, creators, and communities through competitions, activities, and meaningful campaigns across Malaysia.',
        ];

        return apply_filters( 'cw_nap', $nap );
    }

    /**
     * Enrich Rank Math Organization node for brand trust / local / AIO.
     *
     * @param array<string,mixed> $data
     * @param mixed               $jsonld
     * @return array<string,mixed>
     */
    public static function enhance_json_ld( $data, $jsonld ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }

        $nap = self::get_nap();
        $org_defaults = [
            'name'          => $nap['legal_name'],
            'legalName'     => $nap['legal_name'],
            'alternateName' => $nap['brand_name'],
            'description'   => $nap['description'],
            'telephone'     => $nap['telephone'],
            'email'         => $nap['email'],
            'sameAs'        => array_values( array_filter( (array) $nap['same_as'] ) ),
            'address'       => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $nap['street_address'],
                'addressLocality' => $nap['locality'],
                'addressRegion'   => $nap['region'],
                'postalCode'       => $nap['postal_code'],
                'addressCountry'  => $nap['country'],
            ],
            'areaServed'    => [
                '@type' => 'Country',
                'name'  => $nap['country_name'],
            ],
            'contactPoint'  => [
                '@type'             => 'ContactPoint',
                'telephone'         => $nap['telephone'],
                'email'             => $nap['email'],
                'contactType'       => 'customer support',
                'areaServed'        => $nap['country'],
                'availableLanguage' => [ 'English', 'Malay' ],
            ],
        ];
        $org_defaults = apply_filters( 'cw_organization_schema', $org_defaults );

        foreach ( $data as $key => $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $type = $node['@type'] ?? '';
            if ( is_array( $type ) ) {
                $type = $type[0] ?? '';
            }

            if ( $type !== 'Organization' && ! in_array( $key, [ 'organization', 'Organization', 'publisher' ], true ) ) {
                continue;
            }

            foreach ( [ 'name', 'legalName', 'alternateName', 'description', 'telephone', 'email', 'sameAs', 'address', 'areaServed', 'contactPoint' ] as $field ) {
                if ( empty( $node[ $field ] ) && ! empty( $org_defaults[ $field ] ) ) {
                    $data[ $key ][ $field ] = $org_defaults[ $field ];
                }
            }

            if ( ! empty( $org_defaults['legalName'] ) ) {
                $data[ $key ]['legalName'] = $org_defaults['legalName'];
            }
            if ( ! empty( $org_defaults['alternateName'] ) ) {
                $data[ $key ]['alternateName'] = $org_defaults['alternateName'];
            }
            // Always refresh NAP contact fields so Rank Math stubs stay accurate.
            $data[ $key ]['telephone'] = $org_defaults['telephone'];
            $data[ $key ]['email']     = $org_defaults['email'];
            $data[ $key ]['address']   = $org_defaults['address'];
            $data[ $key ]['areaServed']= $org_defaults['areaServed'];
            $data[ $key ]['contactPoint'] = $org_defaults['contactPoint'];
        }

        return $data;
    }

    /**
     * Service-area ProfessionalService JSON-LD (NAP + Malaysia) for local/AEO.
     */
    public static function output_local_business_json_ld() {
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }
        if ( ! is_front_page() && ! is_page( [ 'contact', 'about' ] ) ) {
            return;
        }

        $nap  = self::get_nap();
        $home = home_url( '/' );
        $payload = [
            '@context'      => 'https://schema.org',
            '@type'         => [ 'Organization', 'ProfessionalService' ],
            '@id'           => trailingslashit( $home ) . '#organization',
            'name'          => $nap['legal_name'],
            'legalName'     => $nap['legal_name'],
            'alternateName' => $nap['brand_name'],
            'url'           => $home,
            'description'   => $nap['description'],
            'telephone'     => $nap['telephone'],
            'email'         => $nap['email'],
            'image'         => 'https://creativewings.asia/wp-content/uploads/2024/12/cropped-CW-removebg-preview-e1758776467771.png',
            'logo'          => 'https://creativewings.asia/wp-content/uploads/2024/12/cropped-CW-removebg-preview-e1758776467771.png',
            'address'       => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $nap['street_address'],
                'addressLocality' => $nap['locality'],
                'addressRegion'   => $nap['region'],
                'postalCode'       => $nap['postal_code'],
                'addressCountry'  => $nap['country'],
            ],
            'areaServed'    => [
                '@type' => 'Country',
                'name'  => $nap['country_name'],
            ],
            'sameAs'        => array_values( array_filter( (array) $nap['same_as'] ) ),
            'contactPoint'  => [
                '@type'             => 'ContactPoint',
                'telephone'         => $nap['telephone'],
                'email'             => $nap['email'],
                'contactType'       => 'customer support',
                'areaServed'        => 'MY',
                'availableLanguage' => [ 'English', 'Malay' ],
            ],
        ];

        echo '<script type="application/ld+json" class="cw-nap-schema">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    }

    /**
     * Bulk sync OG images for all products (CLI / Novamira one-shot).
     *
     * @return array{updated:int, skipped:int, items:array<int,array<string,mixed>>}
     */
    public static function bulk_sync_product_og_images() {
        $products = get_posts( [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $updated = 0;
        $skipped = 0;
        $items   = [];

        foreach ( $products as $product_id ) {
            $product_id = (int) $product_id;
            $ok         = self::set_product_og_image( $product_id, true );
            $items[]    = [
                'id'    => $product_id,
                'title' => html_entity_decode( get_the_title( $product_id ), ENT_QUOTES, 'UTF-8' ),
                'ok'    => $ok,
            ];
            if ( $ok ) {
                ++$updated;
            } else {
                ++$skipped;
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'items'   => $items,
        ];
    }
}
