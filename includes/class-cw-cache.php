<?php
/**
 * Lightweight cache facade.
 *
 * Routes through the persistent object cache (Redis/Memcached) when one is
 * available, falls back to transients otherwise. Adds group-based bust via
 * a "last-changed" key — flushing a whole group is O(1) (just rotate the key).
 *
 * Usage:
 *
 *     $html = CW_Cache::remember( 'org:' . $uid, 'org_profile', 5 * MINUTE_IN_SECONDS, function () use ( $uid ) {
 *         return expensive_render( $uid );
 *     });
 *
 *     // Bust an entire group at once:
 *     CW_Cache::bust_group( 'org_profile' );
 *
 * @package CreativeWings
 * @since   11.0.59
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Cache {

    /**
     * Per-request memo of the last_changed signature for each group.
     * Saves a wp_cache_get per call inside long-running render passes.
     *
     * @var array<string,string>
     */
    private static $group_sigs = [];

    /**
     * Whether to actually cache anything. Off when WP debugging.
     */
    public static function enabled() {
        if ( defined( 'CW_DISABLE_CACHE' ) && CW_DISABLE_CACHE ) {
            return false;
        }
        return ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! ( defined( 'CW_FORCE_CACHE' ) && CW_FORCE_CACHE ) );
    }

    /**
     * Remember a value: try cache first, then call $producer and store the result.
     *
     * @param string   $key      Stable key (will be group-namespaced + signature-suffixed).
     * @param string   $group    Logical group ('org_profile', 'directory', 'reports', ...).
     * @param int      $ttl      Seconds to cache (also used for transient fallback).
     * @param callable $producer Returns the value to cache. Will receive no args.
     * @return mixed
     */
    public static function remember( $key, $group, $ttl, callable $producer ) {
        if ( ! self::enabled() ) {
            return $producer();
        }

        $cache_key = self::cache_key( $key, $group );

        // Persistent object cache path.
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            $hit = wp_cache_get( $cache_key, 'cw' );
            if ( false !== $hit ) {
                return $hit;
            }
            $value = $producer();
            wp_cache_set( $cache_key, $value, 'cw', (int) $ttl );
            return $value;
        }

        // Transient fallback. Use a short, deterministic transient name (hash the cache key
        // so we never blow past the 172-char WP transient option name limit).
        $tname = 'cw_' . md5( $cache_key );
        $hit   = get_transient( $tname );
        if ( false !== $hit ) {
            return $hit;
        }
        $value = $producer();
        set_transient( $tname, $value, (int) $ttl );
        return $value;
    }

    /**
     * Pure cache lookup. Returns null on miss.
     *
     * @param string $key
     * @param string $group
     * @return mixed|null
     */
    public static function get( $key, $group ) {
        if ( ! self::enabled() ) {
            return null;
        }
        $cache_key = self::cache_key( $key, $group );
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            $hit = wp_cache_get( $cache_key, 'cw' );
            return false === $hit ? null : $hit;
        }
        $tname = 'cw_' . md5( $cache_key );
        $hit   = get_transient( $tname );
        return false === $hit ? null : $hit;
    }

    /**
     * Pure cache store.
     */
    public static function set( $key, $group, $value, $ttl ) {
        if ( ! self::enabled() ) {
            return;
        }
        $cache_key = self::cache_key( $key, $group );
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            wp_cache_set( $cache_key, $value, 'cw', (int) $ttl );
            return;
        }
        $tname = 'cw_' . md5( $cache_key );
        set_transient( $tname, $value, (int) $ttl );
    }

    /**
     * Invalidate every key in a group by rotating the group's "last-changed" key.
     *
     * For persistent object cache this is O(1). For the transient fallback we can't
     * enumerate keys, so we also clear the per-request memo and rely on bumped
     * signatures to make subsequent computes miss; old transients expire normally.
     *
     * @param string $group
     */
    public static function bust_group( $group ) {
        unset( self::$group_sigs[ $group ] );
        $sig_key = 'cw_lc_' . $group;
        $new_sig = microtime() . '_' . wp_generate_password( 6, false, false );

        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            wp_cache_set( $sig_key, $new_sig, 'cw' );
            return;
        }

        // Transient fallback: persist the bumped signature for an hour so every
        // new producer compute is forced (the old transients linger until TTL).
        set_transient( 'cw_' . md5( $sig_key ), $new_sig, HOUR_IN_SECONDS );
    }

    /**
     * Compose the namespaced cache key (group + last-changed signature + per-call key).
     */
    private static function cache_key( $key, $group ) {
        $sig = self::group_signature( $group );
        return $group . ':' . $sig . ':' . $key;
    }

    private static function group_signature( $group ) {
        if ( isset( self::$group_sigs[ $group ] ) ) {
            return self::$group_sigs[ $group ];
        }
        $sig_key = 'cw_lc_' . $group;

        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            $sig = wp_cache_get( $sig_key, 'cw' );
            if ( ! $sig ) {
                $sig = microtime();
                wp_cache_set( $sig_key, $sig, 'cw' );
            }
        } else {
            $sig = get_transient( 'cw_' . md5( $sig_key ) );
            if ( ! $sig ) {
                $sig = microtime();
                set_transient( 'cw_' . md5( $sig_key ), $sig, HOUR_IN_SECONDS );
            }
        }
        self::$group_sigs[ $group ] = (string) $sig;
        return (string) $sig;
    }

    /**
     * Wire up centralized invalidation hooks. Called once during plugin bootstrap.
     */
    public static function register_invalidation_hooks() {
        // Campaign product saved/trashed → bust org profile, directory and reports caches.
        add_action( 'save_post_product', [ __CLASS__, 'on_product_changed' ], 20, 1 );
        add_action( 'trashed_post',      [ __CLASS__, 'on_product_changed' ], 20, 1 );

        // Entry created / deleted → bust org + business dashboard + reports.
        add_action( 'cw_entry_created',  [ __CLASS__, 'on_entry_changed' ],   20 );
        add_action( 'cw_entry_deleted',  [ __CLASS__, 'on_entry_changed' ],   20 );
        add_action( 'save_post_cw_activity_entry', [ __CLASS__, 'on_map_entry_changed' ], 20 );
        add_action( 'save_post_cw_competition_entry', [ __CLASS__, 'on_map_entry_changed' ], 20 );
        add_action( 'before_delete_post', [ __CLASS__, 'on_map_entry_deleted' ], 20 );

        // Order status changes → bust revenue/chart caches.
        add_action( 'woocommerce_order_status_completed',  [ __CLASS__, 'on_order_completed' ], 20 );
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'on_order_completed' ], 20 );

        // User meta changes that drive directory/profile content.
        add_action( 'profile_update',     [ __CLASS__, 'on_profile_update' ], 20 );
        add_action( 'updated_user_meta',  [ __CLASS__, 'on_user_meta_update' ], 20, 4 );

        // Badges: any award changes the public profile + directory.
        add_action( 'cw_badge_awarded',   [ __CLASS__, 'on_badge_awarded' ], 20 );

        // Badge catalog edits → drop cached rule catalog.
        add_action( 'save_post_cw_badge', [ __CLASS__, 'on_badge_catalog_changed' ], 20 );
        add_action( 'trashed_post',       [ __CLASS__, 'on_badge_catalog_changed' ], 20 );
    }

    public static function on_badge_catalog_changed( $post_id ) {
        if ( get_post_type( $post_id ) !== 'cw_badge' ) {
            return;
        }
        self::bust_group( 'badges' );
    }

    public static function on_product_changed( $post_id ) {
        if ( get_post_type( $post_id ) !== 'product' ) {
            return;
        }
        self::bust_group( 'org_profile' );
        self::bust_group( 'directory' );
        self::bust_group( 'reports' );
    }

    public static function on_entry_changed() {
        self::bust_group( 'org_profile' );
        self::bust_group( 'reports' );
        self::bust_group( 'biz_dash' );
        self::bust_group( 'map_gallery' );
    }

    public static function on_map_entry_changed() {
        self::bust_group( 'map_gallery' );
    }

    public static function on_map_entry_deleted( $post_id ) {
        if ( in_array( get_post_type( $post_id ), [ 'cw_activity_entry', 'cw_competition_entry' ], true ) ) {
            self::bust_group( 'map_gallery' );
        }
    }

    public static function on_order_completed() {
        self::bust_group( 'reports' );
        self::bust_group( 'biz_dash' );
        self::bust_group( 'wallet' );
    }

    public static function on_profile_update() {
        self::bust_group( 'org_profile' );
        self::bust_group( 'directory' );
    }

    public static function on_badge_awarded() {
        self::bust_group( 'org_profile' );
        self::bust_group( 'directory' );
    }

    public static function on_user_meta_update( $meta_id, $object_id, $meta_key, $_meta_value ) {
        $watched = [
            'business_name', 'business_logo', 'business_cover', 'business_tagline',
            'business_about', 'business_industry', 'business_city', 'business_country',
            'business_website', 'business_phone', 'business_address',
            'creator_display_name', 'creator_profile_image', 'creator_header_image',
            'creator_tagline', 'creator_address', 'creator_skills',
        ];
        if ( in_array( (string) $meta_key, $watched, true ) ) {
            self::bust_group( 'org_profile' );
            self::bust_group( 'directory' );
        }
    }
}
