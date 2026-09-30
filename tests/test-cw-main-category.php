<?php
/**
 * Campaign cards show the top-level category, never the subcategory.
 * Run: php tests/test-cw-main-category.php
 */

$root = dirname( __DIR__ );
$src  = file_get_contents( $root . '/includes/class-cw-shop.php' );

if ( ! preg_match( '/public static function main_category\(.*?\n    \}\n/s', $src, $m ) ) {
    fwrite( STDERR, "FAIL: main_category() missing\n" );
    exit( 1 );
}

function __( $s ) { return $s; }
function is_wp_error( $v ) { return false; }

$cw_terms = [
    58 => (object) [ 'term_id' => 58, 'slug' => 'competitions', 'name' => 'Competitions', 'parent' => 0 ],
    59 => (object) [ 'term_id' => 59, 'slug' => 'activities', 'name' => 'Activities', 'parent' => 0 ],
    71 => (object) [ 'term_id' => 71, 'slug' => 'talk-seminar', 'name' => 'Talk & Seminar', 'parent' => 0 ],
    75 => (object) [ 'term_id' => 75, 'slug' => 'drawing', 'name' => 'Drawing', 'parent' => 58 ],
    74 => (object) [ 'term_id' => 74, 'slug' => 'competitions-design', 'name' => 'Design', 'parent' => 58 ],
    73 => (object) [ 'term_id' => 73, 'slug' => 'art-activities', 'name' => 'Art', 'parent' => 59 ],
    55 => (object) [ 'term_id' => 55, 'slug' => 'run', 'name' => 'Run', 'parent' => 59 ],
    66 => (object) [ 'term_id' => 66, 'slug' => 'talk', 'name' => 'Talk', 'parent' => 71 ],
    16 => (object) [ 'term_id' => 16, 'slug' => 'uncategorized', 'name' => 'Uncategorized', 'parent' => 0 ],
    90 => (object) [ 'term_id' => 90, 'slug' => 'festival', 'name' => 'Festival', 'parent' => 0 ],
];
$cw_product_terms = [];

function get_term( $id ) { global $cw_terms; return $cw_terms[ $id ] ?? null; }
function get_the_terms( $pid ) {
    global $cw_terms, $cw_product_terms;
    $ids = $cw_product_terms[ $pid ] ?? [];
    return $ids ? array_map( function ( $id ) use ( $cw_terms ) { return $cw_terms[ $id ]; }, $ids ) : false;
}

eval( 'class CW_Shop_Main_Cat_Test { ' . $m[0] . ' }' );

$cases = [
    'drawing → Competition'       => [ [ 75 ], 'competition', 'Competition' ],
    'design → Competition'        => [ [ 74 ], 'competition', 'Competition' ],
    'art activity → Activity'     => [ [ 73 ], 'activity', 'Activity' ],
    'run → Activity'              => [ [ 55 ], 'activity', 'Activity' ],
    'talk → Talk / Seminar'       => [ [ 66 ], 'seminar', 'Talk / Seminar' ],
    'parent + child assigned'     => [ [ 59, 73 ], 'activity', 'Activity' ],
    'uncategorized skipped'       => [ [ 16, 75 ], 'competition', 'Competition' ],
    'unknown top-level kept'      => [ [ 90 ], 'festival', 'Festival' ],
    'only uncategorized'          => [ [ 16 ], '', '' ],
    'no terms'                    => [ [], '', '' ],
];

$fail = 0;
foreach ( $cases as $name => $c ) {
    $cw_product_terms[1] = $c[0];
    $got = CW_Shop_Main_Cat_Test::main_category( 1 );
    if ( $got['key'] !== $c[1] || $got['label'] !== $c[2] ) {
        $fail++;
        fwrite( STDERR, "FAIL: $name → " . json_encode( $got ) . "\n" );
    }
}

if ( $fail ) {
    exit( 1 );
}
echo 'PASS: main category (' . count( $cases ) . " cases)\n";
