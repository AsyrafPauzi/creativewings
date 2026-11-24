<?php
/**
 * Category intro HTML for the three discovery terms.
 * Run: php tests/test-cw-discovery-category-intro.php
 */

$root = dirname( __DIR__ );
require $root . '/themes/astra-child-creativewings/inc/discovery-category-intro.php';

function assert_same( $expected, $actual, $label ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$label}\n expected: {$expected}\n actual:   {$actual}\n" );
        exit( 1 );
    }
}

$competitions = 'https://creativewings.asia/competitions/';
$osow         = 'https://creativewings.asia/osow/';

assert_same(
    '<p class="cw-discovery-intro">The Merdeka Month drawing competition has closed. <a href="https://creativewings.asia/competitions/">See what is open.</a></p>',
    cw_child_discovery_category_intro_html( 'drawing', $competitions, $osow ),
    'drawing'
);
assert_same(
    '<p class="cw-discovery-intro">The ORBI design competition has closed. <a href="https://creativewings.asia/competitions/">See what is open.</a></p>',
    cw_child_discovery_category_intro_html( 'competitions-design', $competitions, $osow ),
    'design'
);
assert_same(
    '<p class="cw-discovery-intro"><a href="https://creativewings.asia/osow/">One Smile, One World</a> is open for children until 31 December 2026.</p>',
    cw_child_discovery_category_intro_html( 'art-activities', $competitions, $osow ),
    'art'
);
assert_same( '', cw_child_discovery_category_intro_html( 'esports', $competitions, $osow ), 'empty category' );
assert_same( '', cw_child_discovery_category_intro_html( 'poetry', $competitions, $osow ), 'poetry' );

$evil = cw_child_discovery_category_intro_html( 'drawing', 'https://creativewings.asia/competitions/?q="><script>', $osow );
if ( false !== strpos( $evil, '<script>' ) ) {
    fwrite( STDERR, "FAIL: competitions URL was not escaped\n" );
    exit( 1 );
}

echo "PASS\n";
