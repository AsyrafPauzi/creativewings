<?php
/**
 * Bulk SEO map keeps discovery titles and the product skip.
 * Run: php tests/test-cw-discovery-seo-bulk.php
 */

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) {
        return $text;
    }
}

if ( ! class_exists( 'WP_Term' ) ) {
    class WP_Term {}
}

$root = dirname( __DIR__ );
require $root . '/scripts/bulk-rank-math-seo.php';

function assert_same( $expected, $actual, $label ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$label}\n expected: {$expected}\n actual:   {$actual}\n" );
        exit( 1 );
    }
}

function assert_len( $value, $max, $label ) {
    $len = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
    if ( $len > $max ) {
        fwrite( STDERR, "FAIL: {$label} is {$len} characters\n" );
        exit( 1 );
    }
}

$pages = cw_bulk_seo_page_map();
assert_same( 'Competitions in Malaysia | Creative Wings', $pages['homepage']['seo_title'], 'home title' );
assert_same( 'Join art, drawing, and design competitions in Malaysia. Open to students and adults. Browse what is open and register online.', $pages['homepage']['meta_description'], 'home description' );
assert_same( [ 'competitions in Malaysia' ], $pages['homepage']['focus_keywords'], 'home focus' );
assert_same( 'Competitions in Malaysia | Creative Wings', $pages['competitions']['seo_title'], 'competitions title' );
assert_same( 'Art, drawing, and design competitions in Malaysia. Open to students and adults. Pick one and register online.', $pages['competitions']['meta_description'], 'competitions description' );
assert_same( [ 'art drawing design competitions' ], $pages['competitions']['focus_keywords'], 'competitions focus' );
assert_same( 'Activities and Events in Malaysia | Creative Wings', $pages['activities']['seo_title'], 'activities title' );
assert_same( 'Runs, art sessions, and talks in Malaysia. Open to families and adults. Pick one and register online.', $pages['activities']['meta_description'], 'activities description' );
assert_same( [ 'activities and events in Malaysia' ], $pages['activities']['focus_keywords'], 'activities focus' );

$terms = [
    'drawing' => [
        'title' => 'Drawing Competitions in Malaysia | Creative Wings',
        'desc'  => 'Drawing competitions in Malaysia for students and adults. The Merdeka Month drawing competition has closed. See what is open.',
        'focus' => [ 'drawing competitions in Malaysia' ],
    ],
    'competitions-design' => [
        'title' => 'Design Competitions in Malaysia | Creative Wings',
        'desc'  => 'Design competitions in Malaysia for students and adults. The ORBI design competition has closed. See what is open.',
        'focus' => [ 'design competitions in Malaysia' ],
    ],
    'art-activities' => [
        'title' => 'Art Competitions in Malaysia | Creative Wings',
        'desc'  => 'Art competitions in Malaysia. One Smile, One World is open for children until 31 December 2026. Register online.',
        'focus' => [ 'art competitions in Malaysia' ],
    ],
];

foreach ( $terms as $slug => $row ) {
    $term       = new WP_Term();
    $term->slug = $slug;
    $term->name = 'Should Not Appear';
    $payload    = cw_bulk_seo_term_payload( $term, null );
    assert_same( $row['title'], $payload['seo_title'], $slug . ' title' );
    assert_same( $row['desc'], $payload['meta_description'], $slug . ' description' );
    assert_same( $row['focus'], $payload['focus_keywords'], $slug . ' focus' );
}

$other       = new WP_Term();
$other->slug = 'esports';
$other->name = 'Esports';
$generic     = cw_bulk_seo_term_payload( $other, null );
assert_same( 'Esports | Creative Wings', $generic['seo_title'], 'empty category stays generic' );

foreach ( [ 'homepage', 'competitions', 'activities' ] as $slug ) {
    assert_len( $pages[ $slug ]['seo_title'], 60, $slug . ' title' );
    assert_len( $pages[ $slug ]['meta_description'], 160, $slug . ' description' );
}
foreach ( $terms as $slug => $row ) {
    assert_len( $row['title'], 60, $slug . ' title length' );
    assert_len( $row['desc'], 160, $slug . ' description length' );
}

$src = file_get_contents( $root . '/scripts/bulk-rank-math-seo.php' );
if ( false === strpos( $src, "get_post_meta( \$post->ID, 'rank_math_title', true )" ) ) {
    fwrite( STDERR, "FAIL: product title skip was removed\n" );
    exit( 1 );
}

echo "PASS\n";
