<?php
/**
 * Malaysia smiley-mosaic gallery — fixed land slot positions for the public map.
 *
 * Slots are precomputed in assets/data/malaysia-smiley-slots.json from
 * assets/data/malaysia-boundary.geojson (Natural Earth 1:50m).
 * Submissions fill slots in join order (oldest first). Decorative only — not
 * real participant locations.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Map_Coordinates {

    const MAX_SLOTS = 900;

    /** @var array{viewBox:array,count:int,slots:array}|null */
    private static $slot_cache = null;

    /**
     * @return array{viewBox:array<int>,count:int,slots:array<int,array{0:float,1:float}>}
     */
    public static function get_slot_data() {
        if ( null !== self::$slot_cache ) {
            return self::$slot_cache;
        }

        $path = defined( 'CW_PATH' )
            ? CW_PATH . 'assets/data/malaysia-smiley-slots.json'
            : dirname( __DIR__ ) . '/assets/data/malaysia-smiley-slots.json';

        $default = [
            'viewBox' => [ 1000, 750 ],
            'count'   => 0,
            'slots'   => [],
        ];

        if ( ! is_readable( $path ) ) {
            self::$slot_cache = $default;
            return self::$slot_cache;
        }

        $raw = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $raw ) || empty( $raw['slots'] ) || ! is_array( $raw['slots'] ) ) {
            self::$slot_cache = $default;
            return self::$slot_cache;
        }

        $slots = [];
        foreach ( $raw['slots'] as $point ) {
            if ( ! is_array( $point ) || count( $point ) < 2 ) {
                continue;
            }
            $x = (float) $point[0];
            $y = (float) $point[1];
            if ( $x < 0 || $x > 100 || $y < 0 || $y > 100 ) {
                continue;
            }
            $slots[] = [ round( $x, 2 ), round( $y, 2 ) ];
        }

        self::$slot_cache = [
            'viewBox'    => array_map( 'intval', (array) ( $raw['viewBox'] ?? [ 1000, 750 ] ) ),
            'gridStep'   => (float) ( $raw['gridStep'] ?? 6.2 ),
            'radiusRatio'=> (float) ( $raw['radiusRatio'] ?? 0.4 ),
            'count'      => count( $slots ),
            'slots'      => $slots,
        ];

        return self::$slot_cache;
    }

    /**
     * @return array<int, array{0:float,1:float}>
     */
    public static function get_all_slots() {
        $data = self::get_slot_data();
        return is_array( $data['slots'] ?? null ) ? $data['slots'] : [];
    }

    /**
     * How many mosaic slots to render.
     *
     * KPI progress is shown in the toolbar only — it does not inflate the
     * on-map grid (dense grids make smileys unreadable).
     *
     * @param int $kpi_target Unused; kept for call-site compatibility.
     * @param int $filled     Real successful submission count.
     */
    public static function total_slots_for_display( $kpi_target, $filled ) {
        unset( $kpi_target );
        $max    = self::max_slots();
        $filled = max( 0, (int) $filled );

        return $max;
    }

    /**
     * Deterministic random slot indices for submissions (stable per campaign).
     *
     * @param int[] $entry_ids
     * @param int   $display_slots
     * @param int   $seed Campaign product ID.
     * @return array<int,int> entry_id => slot_index
     */
    public static function random_slot_assignments( $entry_ids, $display_slots, $seed ) {
        $display_slots = max( 0, (int) $display_slots );
        $entry_ids     = array_values( array_map( 'intval', (array) $entry_ids ) );
        if ( $display_slots <= 0 || empty( $entry_ids ) ) {
            return [];
        }

        $pool = range( 0, $display_slots - 1 );
        $pool = self::seeded_shuffle_array( $pool, (int) $seed );

        $assignments = [];
        $take          = min( count( $entry_ids ), count( $pool ) );
        for ( $i = 0; $i < $take; $i++ ) {
            $assignments[ (int) $entry_ids[ $i ] ] = (int) $pool[ $i ];
        }

        return $assignments;
    }

    /**
     * @param array<int,int|string> $items
     * @return array<int,int|string>
     */
    private static function seeded_shuffle_array( array $items, $seed ) {
        $n     = count( $items );
        $state = (int) sprintf( '%u', crc32( 'cw-map:' . (string) $seed ) );
        for ( $i = $n - 1; $i > 0; $i-- ) {
            $state = self::prng_next( $state );
            $j     = $state % ( $i + 1 );
            $tmp   = $items[ $i ];
            $items[ $i ] = $items[ $j ];
            $items[ $j ] = $tmp;
        }
        return $items;
    }

    private static function prng_next( $state ) {
        return (int) ( ( $state * 1103515245 + 12345 ) & 0x7fffffff );
    }

    public static function max_slots() {
        $data = self::get_slot_data();
        $count = (int) ( $data['count'] ?? 0 );
        if ( $count > 0 ) {
            return min( self::MAX_SLOTS, $count );
        }
        return self::MAX_SLOTS;
    }

    /**
     * @return array{viewBox:array<int>,gridStep:float,radiusRatio:float,count:int,slots:array}
     */
    public static function get_mosaic_meta() {
        $data = self::get_slot_data();
        return [
            'viewBox'     => $data['viewBox'] ?? [ 1000, 750 ],
            'gridStep'    => (float) ( $data['gridStep'] ?? 6.2 ),
            'radiusRatio' => (float) ( $data['radiusRatio'] ?? 0.4 ),
            'count'       => (int) ( $data['count'] ?? 0 ),
        ];
    }

    /**
     * Slots used for the mosaic (trimmed to display count).
     *
     * @param int $display_count
     * @return array<int, array{0:float,1:float}>
     */
    public static function slots_for_display( $display_count ) {
        $display_count = max( 0, min( self::max_slots(), (int) $display_count ) );
        return array_slice( self::get_all_slots(), 0, $display_count );
    }

    /**
     * @deprecated World-map country points removed — kept for test compatibility.
     */
    public static function point_for_entry( $entry_id ) {
        $slots = self::get_all_slots();
        if ( empty( $slots ) ) {
            return [ 'x' => 50.0, 'y' => 50.0, 'country' => 'Malaysia' ];
        }
        $index = max( 0, (int) $entry_id - 1 ) % count( $slots );
        return [
            'x'       => (float) $slots[ $index ][0],
            'y'       => (float) $slots[ $index ][1],
            'country' => 'Malaysia',
        ];
    }

    /**
     * @deprecated
     * @param int[] $entry_ids
     */
    public static function points_for_entries( $entry_ids, $limit = self::MAX_SLOTS ) {
        unset( $limit );
        if ( ! is_array( $entry_ids ) ) {
            return [];
        }
        $slots = self::get_all_slots();
        $out   = [];
        foreach ( array_values( $entry_ids ) as $i => $entry_id ) {
            if ( ! isset( $slots[ $i ] ) ) {
                break;
            }
            $out[] = [ (float) $slots[ $i ][0], (float) $slots[ $i ][1] ];
        }
        return $out;
    }
}
