<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce product_cat helpers for the campaign wizard.
 */
class CW_Product_Categories {

    const OPTION_VERSION = 'cw_product_cats_version';
    const TARGET_VERSION = '1.1.1';

    /**
     * Parent slug => default child category names (slug derived via sanitize_title).
     *
     * @return array<string, string[]>
     */
    public static function default_child_names() {
        return [
            'competitions' => [ 'Art', 'Design', 'Drawing', 'Esports', 'Marathon', 'Photography', 'Poetry', 'Writing' ],
            'activities'     => [ 'Art', 'Community', 'Running', 'Volunteer Work', 'Workshop' ],
            'talk-seminar'   => [ 'Seminar', 'Talk' ],
        ];
    }

    /**
     * Ensure default sub-categories exist and prune empty duplicate names.
     */
    public static function maybe_sync() {
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            return;
        }

        $current = (string) get_option( self::OPTION_VERSION, '0' );
        if ( version_compare( $current, self::TARGET_VERSION, '>=' ) ) {
            return;
        }

        foreach ( self::default_child_names() as $parent_slug => $names ) {
            $parent = get_term_by( 'slug', $parent_slug, 'product_cat' );
            if ( ! $parent || is_wp_error( $parent ) ) {
                continue;
            }

            self::dedupe_children_by_name( (int) $parent->term_id );

            foreach ( $names as $name ) {
                self::ensure_child_term( (int) $parent->term_id, $name );
            }

            self::dedupe_children_by_name( (int) $parent->term_id );
        }

        update_option( self::OPTION_VERSION, self::TARGET_VERSION );
    }

    /**
     * Force a re-sync (e.g. after fixing category helpers).
     */
    public static function force_sync() {
        delete_option( self::OPTION_VERSION );
        self::maybe_sync();
    }

    /**
     * Wizard category tree keyed by parent slug.
     *
     * @return array<string, array<int, array{id:int,name:string,slug:string}>>
     */
    public static function get_wizard_tree() {
        self::maybe_sync();

        $tree = [];
        $parents = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'parent'     => 0,
                'hide_empty' => false,
            ]
        );

        if ( is_wp_error( $parents ) || empty( $parents ) ) {
            return $tree;
        }

        foreach ( $parents as $parent ) {
            $subs = get_terms(
                [
                    'taxonomy'   => 'product_cat',
                    'parent'     => (int) $parent->term_id,
                    'hide_empty' => false,
                ]
            );
            if ( is_wp_error( $subs ) || empty( $subs ) ) {
                $tree[ $parent->slug ] = [];
                continue;
            }

            $deduped = self::dedupe_term_list_by_name( $subs );
            usort(
                $deduped,
                static function ( $a, $b ) {
                    return strcasecmp( (string) $a->name, (string) $b->name );
                }
            );

            $tree[ $parent->slug ] = array_map(
                static function ( $term ) {
                    return [
                        'id'   => (int) $term->term_id,
                        'name' => (string) $term->name,
                        'slug' => (string) $term->slug,
                    ];
                },
                $deduped
            );
        }

        $tree['talks'] = $tree['talk-seminar'] ?? ( $tree['activities'] ?? [] );

        return $tree;
    }

    /**
     * @param int    $parent_id
     * @param string $name
     */
    private static function ensure_child_term( $parent_id, $name ) {
        $parent_id = (int) $parent_id;
        $name      = trim( (string) $name );
        if ( $parent_id <= 0 || $name === '' ) {
            return;
        }

        $existing_children = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'parent'     => $parent_id,
                'hide_empty' => false,
                'name'       => $name,
            ]
        );
        if ( ! is_wp_error( $existing_children ) && ! empty( $existing_children ) ) {
            return;
        }

        $slug   = sanitize_title( $name );
        $by_slug = get_term_by( 'slug', $slug, 'product_cat' );
        if ( $by_slug && ! is_wp_error( $by_slug ) && (int) $by_slug->parent !== $parent_id ) {
            $parent = get_term( $parent_id, 'product_cat' );
            $slug   = sanitize_title( $name . '-' . ( $parent && ! is_wp_error( $parent ) ? $parent->slug : 'cat' ) );
        }

        if ( term_exists( $slug, 'product_cat' ) ) {
            return;
        }

        wp_insert_term(
            $name,
            'product_cat',
            [
                'slug'   => $slug,
                'parent' => $parent_id,
            ]
        );
    }

    /**
     * Delete empty duplicate child terms that share the same display name.
     *
     * @param int $parent_id
     */
    private static function dedupe_children_by_name( $parent_id ) {
        $parent_id = (int) $parent_id;
        if ( $parent_id <= 0 ) {
            return;
        }

        $subs = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'parent'     => $parent_id,
                'hide_empty' => false,
            ]
        );
        if ( is_wp_error( $subs ) || count( $subs ) < 2 ) {
            return;
        }

        $groups = [];
        foreach ( $subs as $term ) {
            $key = strtolower( trim( (string) $term->name ) );
            $groups[ $key ][] = $term;
        }

        foreach ( $groups as $terms ) {
            if ( count( $terms ) < 2 ) {
                continue;
            }

            usort(
                $terms,
                static function ( $a, $b ) {
                    if ( (int) $a->count !== (int) $b->count ) {
                        return (int) $b->count <=> (int) $a->count;
                    }
                    return (int) $a->term_id <=> (int) $b->term_id;
                }
            );

            $keep = array_shift( $terms );
            foreach ( $terms as $duplicate ) {
                if ( (int) $duplicate->count > 0 ) {
                    continue;
                }
                wp_delete_term( (int) $duplicate->term_id, 'product_cat' );
            }
        }
    }

    /**
     * @param WP_Term[] $terms
     * @return WP_Term[]
     */
    private static function dedupe_term_list_by_name( array $terms ) {
        $best = [];
        foreach ( $terms as $term ) {
            $key = strtolower( trim( (string) $term->name ) );
            if ( ! isset( $best[ $key ] ) ) {
                $best[ $key ] = $term;
                continue;
            }
            $current = $best[ $key ];
            if ( (int) $term->count > (int) $current->count
                || ( (int) $term->count === (int) $current->count && (int) $term->term_id < (int) $current->term_id ) ) {
                $best[ $key ] = $term;
            }
        }

        return array_values( $best );
    }
}
