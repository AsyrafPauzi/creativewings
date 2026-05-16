<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Campaign_Resolver {

    public static function get_id_by_serial( $serial ) {
        $serial = str_pad( preg_replace( '/\D/', '', (string) $serial ), 3, '0', STR_PAD_LEFT );
        if ( ! $serial ) {
            return 0;
        }

        $cache_key = 'cw_camp_serial_' . $serial;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return (int) $cached;
        }

        $posts = get_posts(
            [
                'post_type'      => 'product',
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'   => 'cw_campaign_serial',
                        'value' => $serial,
                    ],
                ],
            ]
        );

        $id = $posts ? (int) $posts[0] : 0;
        set_transient( $cache_key, $id, DAY_IN_SECONDS );
        return $id;
    }

    public static function flush_serial_cache( $product_id ) {
        $serial = get_post_meta( (int) $product_id, 'cw_campaign_serial', true );
        if ( $serial ) {
            $serial = str_pad( preg_replace( '/\D/', '', (string) $serial ), 3, '0', STR_PAD_LEFT );
            delete_transient( 'cw_camp_serial_' . $serial );
        }
    }
}
