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
        add_filter( 'rank_math/frontend/description', [ __CLASS__, 'campaign_meta_description' ], 20 );
        add_filter( 'wp_get_attachment_image_attributes', [ __CLASS__, 'fallback_image_alt' ], 10, 2 );
        add_filter( 'wp_content_img_tag', [ __CLASS__, 'fill_content_image_alt' ], 10, 3 );
        add_filter( 'language_attributes', [ __CLASS__, 'localize_lang' ] );
        add_filter( 'rank_math/schema/language', [ __CLASS__, 'localize_lang' ] );
        add_filter( 'rank_math/opengraph/facebook/og_locale', [ __CLASS__, 'localize_og_locale' ] );
        add_filter( 'rank_math/frontend/robots', [ __CLASS__, 'noindex_utility_singles' ] );
        add_filter( 'rank_math/llms_txt/extra_content', [ __CLASS__, 'llms_campaign_facts' ] );
    }

    /** Post types that exist for plumbing or hold participant (often minor) names. */
    const NOINDEX_POST_TYPES = [ 'cw_competition_entry', 'cw_activity_entry', 'slider', 'jet-engine', 'e-floating-buttons', 'ha_library', 'elementor_library' ];

    /** Malaysian English site-wide; a page can override with a `cw_lang` meta such as ms-MY. */
    private static function page_lang() {
        if ( is_singular() ) {
            $lang = (string) get_post_meta( get_queried_object_id(), 'cw_lang', true );
            if ( preg_match( '/^[a-z]{2}-[A-Z]{2}$/', $lang ) ) {
                return $lang;
            }
        }
        return 'en-MY';
    }

    public static function localize_lang( $output ) {
        $lang = self::page_lang();
        return 'en-US' === $output ? $lang : str_replace( 'lang="en-US"', 'lang="' . $lang . '"', (string) $output );
    }

    public static function localize_og_locale( $locale ) {
        $lang = self::page_lang();
        return 'en-MY' === $lang ? $locale : str_replace( '-', '_', $lang );
    }

    public static function noindex_utility_singles( $robots ) {
        if ( is_singular( self::NOINDEX_POST_TYPES ) || isset( $_GET['jet-engine'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $robots          = is_array( $robots ) ? $robots : [];
            $robots['index'] = 'noindex';
            unset( $robots['max-snippet'], $robots['max-video-preview'], $robots['max-image-preview'] );
        }
        return $robots;
    }

    /**
     * Live campaign facts for AI answer engines, appended to Rank Math's llms.txt.
     * Rank Math runs esc_html over this, so the text avoids & ' " < >.
     */
    public static function llms_campaign_facts( $extra ) {
        $nap   = self::get_nap();
        $lines = [
            '## About Creative Wings',
            'Creative Wings (' . $nap['legal_name'] . ') runs creative competitions, community activities and campaigns for students, families and creators across Malaysia. Based in ' . $nap['locality'] . ', ' . $nap['region'] . '. Contact: ' . $nap['email'] . ', ' . $nap['telephone'] . '. All dates and times are Malaysia time (GMT+8). Entry fees are in Malaysian Ringgit (RM).',
            '',
        ];

        $groups = [ 'open' => [], 'upcoming' => [], 'past' => [] ];
        $ids    = get_posts( [
            'post_type'   => 'product',
            'post_status' => 'publish',
            'numberposts' => 30,
            'fields'      => 'ids',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );
        foreach ( $ids as $pid ) {
            $start    = (string) get_post_meta( $pid, 'cw_submission_start', true );
            $deadline = (string) get_post_meta( $pid, 'submission_deadline', true );
            if ( $deadline && CW_Campaign_Dates::is_past( $deadline, true ) ) {
                $groups['past'][] = $pid;
            } elseif ( $start && CW_Campaign_Dates::is_future( $start ) ) {
                $groups['upcoming'][] = $pid;
            } else {
                $groups['open'][] = $pid;
            }
        }

        $labels = [ 'open' => 'Open for entries now', 'upcoming' => 'Opening soon', 'past' => 'Past campaigns' ];
        foreach ( $groups as $group => $pids ) {
            if ( ! $pids ) {
                continue;
            }
            $lines[] = '## ' . $labels[ $group ];
            foreach ( $pids as $pid ) {
                $lines[] = self::llms_campaign_line( $pid, 'past' !== $group );
            }
            $lines[] = '';
        }

        $facts = self::llms_plain( implode( "\n", $lines ) );
        return trim( (string) $extra ) ? trim( (string) $extra ) . "\n\n" . $facts : $facts;
    }

    private static function llms_campaign_line( $pid, $with_faq ) {
        $g = function ( $key ) use ( $pid ) {
            return (string) get_post_meta( $pid, $key, true );
        };
        $when = function ( $key ) use ( $g ) {
            $value = $g( $key );
            return CW_Campaign_Dates::format( '00:00' === CW_Campaign_Dates::time_part( $value ) ? CW_Campaign_Dates::date_part( $value ) : $value );
        };
        $parts = [];
        $cats  = wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'names' ] );
        if ( $cats && ! is_wp_error( $cats ) ) {
            $parts[] = 'Category: ' . implode( ', ', $cats ) . '.';
        }
        if ( $g( 'cw_submission_start' ) && $g( 'submission_deadline' ) ) {
            $parts[] = 'Entries: ' . $when( 'cw_submission_start' ) . ' to ' . $when( 'submission_deadline' ) . '.';
        } elseif ( $g( 'submission_deadline' ) ) {
            $parts[] = 'Closing date: ' . $when( 'submission_deadline' ) . '.';
        }
        if ( $g( 'cw_final_event_date' ) ) {
            $parts[] = 'Results or final event: ' . $when( 'cw_final_event_date' ) . '.';
        }
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
        if ( $product ) {
            $price   = (float) $product->get_price();
            $parts[] = 'Entry fee: ' . ( $price > 0 ? 'RM' . rtrim( rtrim( number_format( $price, 2, '.', '' ), '0' ), '.' ) : 'Free' ) . '.';
        }
        $where = 'online' === $g( 'cw_event_mode' ) ? 'Online' : trim( wp_strip_all_tags( $g( 'cw_location_details' ) ) );
        if ( $where ) {
            $parts[] = 'Where: ' . $where . '.';
        }
        $summary = trim( wp_strip_all_tags( $g( 'rank_math_description' ) ) );
        if ( $summary ) {
            $parts[] = preg_replace( '/\s*Closes \d{1,2} [A-Za-z]{3} \d{4}[^.]*\.?$/', '', $summary );
        }

        $line = '- [' . html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ) . '](' . get_permalink( $pid ) . '): ' . implode( ' ', $parts );
        if ( $with_faq ) {
            foreach ( array_slice( self::get_faq_items( $pid ), 0, 8 ) as $item ) {
                $line .= "\n  - Q: " . $item['question'] . ' A: ' . wp_trim_words( $item['answer'], 40, '…' );
            }
        }
        return $line;
    }

    private static function llms_plain( $text ) {
        $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/\s*&\s*/', ' and ', $text );
        return strtr( $text, [ "'" => '’', '"' => '”', '<' => '', '>' => '' ] );
    }

    /**
     * Content images saved with an empty alt pick up the Media Library alt text.
     */
    public static function fill_content_image_alt( $img, $context, $attachment_id ) {
        if ( ! $attachment_id || preg_match( '/\balt="[^"]+"/', $img ) ) {
            return $img;
        }
        $alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
        if ( '' === $alt ) {
            return $img;
        }
        $alt = 'alt="' . esc_attr( $alt ) . '"';
        return preg_match( '/\balt=""/', $img )
            ? preg_replace( '/\balt=""/', $alt, $img, 1 )
            : preg_replace( '/^<img\b/', '<img ' . $alt, $img, 1 );
    }

    /**
     * Campaign descriptions carry the live deadline, so stored copy never goes stale.
     */
    public static function campaign_meta_description( $description ) {
        if ( ! is_singular( 'product' ) || ! class_exists( 'CW_Campaign_Dates' ) ) {
            return $description;
        }
        $description = trim( preg_replace( '/\s*Closes \d{1,2} [A-Za-z]{3} \d{4}[^.]*\.?\s*$/', '', (string) $description ) );
        $deadline    = (string) get_post_meta( get_queried_object_id(), 'submission_deadline', true );
        if ( '' === $description || '' === $deadline || CW_Campaign_Dates::is_past( $deadline, true ) ) {
            return $description;
        }
        $suffix = ' Closes ' . wp_date( 'j M Y', CW_Campaign_Dates::timestamp( $deadline ) ) . '.';
        return mb_strlen( $description . $suffix ) <= 175 ? $description . $suffix : $description;
    }

    /**
     * Campaign images without alt text fall back to the campaign title.
     */
    public static function fallback_image_alt( $attr, $attachment ) {
        if ( ! empty( $attr['alt'] ) || ! $attachment instanceof WP_Post ) {
            return $attr;
        }
        $parent = (int) $attachment->post_parent;
        if ( $parent && 'product' === get_post_type( $parent ) ) {
            $attr['alt'] = html_entity_decode( get_the_title( $parent ), ENT_QUOTES, 'UTF-8' );
        }
        return $attr;
    }

    /**
     * schema.org Event for a campaign, built from live campaign meta.
     *
     * @return array<string,mixed>
     */
    public static function campaign_event_node( $pid ) {
        $pid      = (int) $pid;
        $url      = get_permalink( $pid );
        $name     = self::schema_text( get_the_title( $pid ) );
        $start    = (string) get_post_meta( $pid, 'cw_submission_start', true );
        $deadline = (string) get_post_meta( $pid, 'submission_deadline', true );
        $final    = (string) get_post_meta( $pid, 'cw_final_event_date', true );
        $location = trim( wp_strip_all_tags( (string) get_post_meta( $pid, 'cw_location_details', true ) ) );
        $mode     = (string) get_post_meta( $pid, 'cw_event_mode', true );

        $desc = (string) get_post_meta( $pid, 'rank_math_description', true );
        if ( '' === trim( $desc ) ) {
            $desc = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) ), 45, '…' );
        }
        $desc = self::schema_text( wp_strip_all_tags( $desc ) );

        $node = [
            '@type'       => 'Event',
            'name'        => $name,
            'description' => $desc,
            'url'         => $url,
            'eventStatus' => 'https://schema.org/EventScheduled',
        ];

        $end_raw = $final ?: $deadline;
        if ( ! $start ) {
            $start = $end_raw;
        }
        $start_iso = CW_Campaign_Dates::schema_date( $start );
        $end_iso   = CW_Campaign_Dates::schema_date( $end_raw );
        if ( $start_iso ) {
            $node['startDate'] = $start_iso;
        }
        if ( $end_iso && CW_Campaign_Dates::timestamp( $end_raw, true ) >= CW_Campaign_Dates::timestamp( $start ) ) {
            $node['endDate'] = $end_iso;
        }

        $is_online = 'online' === $mode || preg_match( '/\bonline\b/i', $location );
        if ( $is_online ) {
            $node['eventAttendanceMode'] = 'https://schema.org/OnlineEventAttendanceMode';
            $node['location']            = [ '@type' => 'VirtualLocation', 'url' => $url ];
        } else {
            $node['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
            $node['location']            = [
                '@type'   => 'Place',
                'name'    => $location ? self::schema_text( $location ) : 'Malaysia',
                'address' => array_filter( [
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => self::schema_text( $location ),
                    'addressCountry'  => 'MY',
                ] ),
            ];
        }

        $thumb = (int) get_post_thumbnail_id( $pid );
        if ( $thumb && ( $img = wp_get_attachment_image_url( $thumb, 'full' ) ) ) {
            $node['image'] = [ $img ];
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
        if ( $product ) {
            $closed = $deadline && CW_Campaign_Dates::is_past( $deadline, true );
            $offer  = [
                '@type'         => 'Offer',
                'url'           => $url,
                'price'         => (string) (float) $product->get_price(),
                'priceCurrency' => 'MYR',
                'availability'  => $closed ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
            ];
            if ( $start_iso ) {
                $offer['validFrom'] = $start_iso;
            }
            $node['offers'] = $offer;
        }

        $org_id   = (int) get_post_meta( $pid, 'organizer_id', true );
        $org_user = $org_id ? get_userdata( $org_id ) : null;
        $org_name = $org_user ? trim( (string) get_user_meta( $org_id, 'business_name', true ) ) : '';
        if ( $org_user && '' !== $org_name ) {
            $org_url = class_exists( 'CW_Organizer_Profile' )
                ? home_url( '/' . CW_Organizer_Profile::ORG_BASE . '/' . rawurlencode( $org_user->user_login ) . '/' )
                : home_url( '/' );
            $node['organizer'] = [ '@type' => 'Organization', 'name' => self::schema_text( $org_name ), 'url' => $org_url ];
        } else {
            $node['organizer'] = [ '@type' => 'Organization', 'name' => 'Creative Wings', 'url' => home_url( '/' ) ];
        }

        return $node;
    }

    /**
     * Replace Rank Math's stub Event (and any WooCommerce Product node) with the full campaign Event.
     */
    private static function apply_campaign_event( array $data, $pid ) {
        $event     = self::campaign_event_node( $pid );
        $event_key = null;
        foreach ( $data as $key => $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            $type = self::node_type( $node );
            if ( 'Event' === $type && null === $event_key ) {
                $event_key = $key;
            } elseif ( 'Product' === $type ) {
                unset( $data[ $key ] );
            }
        }
        if ( null !== $event_key ) {
            $data[ $event_key ] = array_merge( $data[ $event_key ], $event );
        } else {
            $url             = get_permalink( $pid );
            $data['cwEvent'] = array_merge(
                [ '@id' => $url . '#event', 'mainEntityOfPage' => [ '@id' => $url . '#webpage' ] ],
                $event
            );
        }
        return $data;
    }

    /**
     * Listing pages become a CollectionPage whose main entity is the campaign list.
     *
     * @param string[] $parent_cats product_cat slugs (children included).
     */
    private static function add_campaign_list( array $data, array $parent_cats, $name ) {
        $ids = get_posts( [
            'post_type'   => 'product',
            'post_status' => 'publish',
            'numberposts' => 30,
            'fields'      => 'ids',
            'orderby'     => 'date',
            'order'       => 'DESC',
            'tax_query'   => [ [ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $parent_cats, 'include_children' => true ] ],
        ] );
        if ( ! $ids ) {
            return $data;
        }

        $url   = get_permalink();
        $items = [];
        foreach ( array_values( $ids ) as $i => $pid ) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'url'      => get_permalink( $pid ),
                'name'     => self::schema_text( get_the_title( $pid ) ),
            ];
        }
        $data['cwCampaignList'] = [
            '@type'           => 'ItemList',
            '@id'             => $url . '#campaigns',
            'name'            => $name,
            'numberOfItems'   => count( $items ),
            'itemListElement' => $items,
        ];

        foreach ( $data as $key => $node ) {
            if ( is_array( $node ) && 'WebPage' === self::node_type( $node ) ) {
                $data[ $key ]['@type']      = 'CollectionPage';
                $data[ $key ]['mainEntity'] = [ '@id' => $url . '#campaigns' ];
            }
        }
        return $data;
    }

    /**
     * Rank Math passes JSON-LD through wp_kses, which turns a bare "&" into "&amp;".
     */
    private static function schema_text( $text ) {
        $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
        return trim( preg_replace( '/\s*&\s*/', ' and ', $text ) );
    }

    private static function node_type( $node ) {
        $type = $node['@type'] ?? '';
        return is_array( $type ) ? (string) ( $type[0] ?? '' ) : (string) $type;
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

        $thumb_id = (int) get_post_thumbnail_id( $post_id );
        if ( $thumb_id && '' === trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ) ) {
            update_post_meta( $thumb_id, '_wp_attachment_image_alt', sanitize_text_field( html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) ) );
        }
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
        if ( ! is_singular( [ 'product', 'page' ] ) ) {
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

        if ( is_singular( 'product' ) ) {
            $data = self::apply_campaign_event( $data, get_queried_object_id() );
        } elseif ( is_page() || is_front_page() ) {
            foreach ( $data as $key => $node ) {
                if ( is_array( $node ) && in_array( self::node_type( $node ), [ 'Article', 'BlogPosting', 'NewsArticle', 'Person' ], true ) ) {
                    unset( $data[ $key ] );
                }
            }
            if ( is_page( 'competitions' ) ) {
                $data = self::add_campaign_list( $data, [ 'competitions' ], 'Creative competitions in Malaysia' );
            } elseif ( is_page( 'activities' ) ) {
                $data = self::add_campaign_list( $data, [ 'activities', 'talk-seminar' ], 'Community activities and events in Malaysia' );
            }
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
