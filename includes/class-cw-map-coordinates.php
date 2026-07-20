<?php
/**
 * Stable decorative country coordinates for the public world-map gallery.
 *
 * Coordinates are country centroids in longitude/latitude. They do not represent
 * a participant's real location.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Map_Coordinates {

    const MAX_POINTS = 10000;

    /**
     * Country name => [longitude, latitude].
     *
     * Kept to populated land areas and spread across all continents. Tiny island
     * states are omitted because even small visual jitter can move a dot offshore.
     *
     * @return array<string, array{0:float,1:float}>
     */
    private static function countries() {
        return [
            'Argentina' => [ -64.0, -34.0 ], 'Australia' => [ 134.0, -25.0 ],
            'Austria' => [ 14.1, 47.6 ], 'Bangladesh' => [ 90.3, 23.7 ],
            'Belgium' => [ 4.7, 50.8 ], 'Bolivia' => [ -64.7, -16.7 ],
            'Botswana' => [ 24.7, -22.3 ], 'Brazil' => [ -51.9, -14.2 ],
            'Bulgaria' => [ 25.5, 42.7 ], 'Cambodia' => [ 104.9, 12.6 ],
            'Cameroon' => [ 12.4, 7.4 ], 'Canada' => [ -106.3, 56.1 ],
            'Chile' => [ -71.5, -35.7 ], 'China' => [ 104.2, 35.9 ],
            'Colombia' => [ -74.3, 4.6 ], 'Costa Rica' => [ -84.0, 9.7 ],
            'Croatia' => [ 15.2, 45.1 ], 'Czechia' => [ 15.5, 49.8 ],
            'Denmark' => [ 9.5, 56.3 ], 'Ecuador' => [ -78.2, -1.8 ],
            'Egypt' => [ 30.8, 26.8 ], 'Ethiopia' => [ 40.5, 9.1 ],
            'Finland' => [ 25.7, 61.9 ], 'France' => [ 2.2, 46.2 ],
            'Germany' => [ 10.5, 51.2 ], 'Ghana' => [ -1.0, 7.9 ],
            'Greece' => [ 21.8, 39.1 ], 'Guatemala' => [ -90.2, 15.8 ],
            'Hungary' => [ 19.5, 47.2 ], 'India' => [ 78.9, 20.6 ],
            'Indonesia' => [ 113.9, -0.8 ], 'Iran' => [ 53.7, 32.4 ],
            'Iraq' => [ 43.7, 33.2 ], 'Ireland' => [ -8.0, 53.4 ],
            'Italy' => [ 12.6, 42.8 ], 'Japan' => [ 138.3, 36.2 ],
            'Jordan' => [ 36.2, 30.6 ], 'Kazakhstan' => [ 66.9, 48.0 ],
            'Kenya' => [ 37.9, 0.0 ], 'Laos' => [ 102.5, 19.9 ],
            'Madagascar' => [ 46.9, -18.8 ], 'Malaysia' => [ 102.0, 4.2 ],
            'Mexico' => [ -102.6, 23.6 ], 'Mongolia' => [ 103.8, 46.9 ],
            'Morocco' => [ -7.1, 31.8 ], 'Mozambique' => [ 35.5, -18.7 ],
            'Myanmar' => [ 96.0, 21.9 ], 'Namibia' => [ 18.5, -22.6 ],
            'Nepal' => [ 84.1, 28.4 ], 'Netherlands' => [ 5.3, 52.1 ],
            'New Zealand' => [ 174.9, -40.9 ], 'Nigeria' => [ 8.7, 9.1 ],
            'Norway' => [ 8.5, 60.5 ], 'Pakistan' => [ 69.3, 30.4 ],
            'Panama' => [ -80.8, 8.5 ], 'Papua New Guinea' => [ 143.9, -6.3 ],
            'Paraguay' => [ -58.4, -23.4 ], 'Peru' => [ -75.0, -9.2 ],
            'Philippines' => [ 121.8, 12.9 ], 'Poland' => [ 19.1, 51.9 ],
            'Portugal' => [ -8.2, 39.4 ], 'Romania' => [ 24.9, 45.9 ],
            'Saudi Arabia' => [ 45.1, 23.9 ], 'Senegal' => [ -14.5, 14.5 ],
            'Serbia' => [ 21.0, 44.0 ], 'South Africa' => [ 22.9, -30.6 ],
            'South Korea' => [ 127.8, 35.9 ], 'Spain' => [ -3.7, 40.5 ],
            'Sri Lanka' => [ 80.8, 7.9 ], 'Sudan' => [ 30.2, 12.9 ],
            'Sweden' => [ 18.6, 60.1 ], 'Switzerland' => [ 8.2, 46.8 ],
            'Tanzania' => [ 34.9, -6.4 ], 'Thailand' => [ 100.9, 15.9 ],
            'Tunisia' => [ 9.5, 33.9 ], 'Turkey' => [ 35.2, 39.0 ],
            'Uganda' => [ 32.3, 1.4 ], 'Ukraine' => [ 31.2, 48.4 ],
            'United Kingdom' => [ -3.4, 55.4 ], 'United States' => [ -99.0, 39.5 ],
            'Uruguay' => [ -55.8, -32.5 ], 'Uzbekistan' => [ 64.6, 41.4 ],
            'Venezuela' => [ -66.6, 6.4 ], 'Vietnam' => [ 108.3, 14.1 ],
            'Zambia' => [ 27.8, -13.1 ], 'Zimbabwe' => [ 29.2, -19.0 ],
        ];
    }

    /**
     * @param int $entry_id
     * @return array{x:float,y:float,country:string}
     */
    public static function point_for_entry( $entry_id ) {
        $entry_id = max( 1, (int) $entry_id );
        $countries = self::countries();
        $names     = array_keys( $countries );
        $hash      = (int) sprintf( '%u', crc32( 'cw-country-' . $entry_id ) );
        $country   = $names[ $hash % count( $names ) ];
        list( $longitude, $latitude ) = $countries[ $country ];

        // Tiny deterministic jitter makes repeated-country dots visible while
        // keeping them visually anchored to the selected country.
        $jitter_hash = (int) sprintf( '%u', crc32( 'cw-jitter-' . $entry_id ) );
        $jx = ( ( $jitter_hash % 101 ) - 50 ) / 100;
        $jy = ( ( (int) ( $jitter_hash / 101 ) % 101 ) - 50 ) / 100;

        $x = ( ( $longitude + 180 ) / 360 ) * 100 + ( $jx * 0.7 );
        $y = ( ( 90 - $latitude ) / 180 ) * 100 + ( $jy * 0.5 );

        return [
            'x'       => round( max( 0, min( 100, $x ) ), 2 ),
            'y'       => round( max( 0, min( 100, $y ) ), 2 ),
            'country' => $country,
        ];
    }

    /**
     * Compact points for the canvas layer.
     *
     * @param int[] $entry_ids
     * @param int   $limit
     * @return array<int, array{0:float,1:float}>
     */
    public static function points_for_entries( $entry_ids, $limit = self::MAX_POINTS ) {
        if ( ! is_array( $entry_ids ) ) {
            return [];
        }
        $limit = max( 0, min( self::MAX_POINTS, (int) $limit ) );
        $out   = [];
        foreach ( array_slice( $entry_ids, 0, $limit ) as $entry_id ) {
            $point = self::point_for_entry( (int) $entry_id );
            $out[] = [ $point['x'], $point['y'] ];
        }
        return $out;
    }
}
