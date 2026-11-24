<?php
/**
 * Discovery headings and intros on the three browse templates.
 * Run: php tests/test-cw-discovery-seo-copy.php
 */

$root = dirname( __DIR__ );

$expect = [
    'themes/astra-child-creativewings/page-homepage.php' => [
        'Together, We Help Young Talents Soar',
        'Competitions and Activities in Malaysia',
        'Open to students and adults. Join a competition or an activity and register online.',
    ],
    'themes/astra-child-creativewings/page-competitions.php' => [
        'Competitions in Malaysia',
        'Art, drawing, and design competitions. Open to students and adults. Pick one and register online.',
    ],
    'themes/astra-child-creativewings/page-activities.php' => [
        'Activities and Events in Malaysia',
        'Runs, art sessions, and talks. Open to families and adults. Pick one and register online.',
    ],
];

$banned = [
    'themes/astra-child-creativewings/page-homepage.php' => [
        'Creative Competitions & Activities for Students in Malaysia',
    ],
    'themes/astra-child-creativewings/page-competitions.php' => [
        'Creative Competitions in Malaysia',
        'young artists',
    ],
    'themes/astra-child-creativewings/page-activities.php' => [
        'Community Activities & Events in Malaysia',
        'for children and families',
    ],
];

$failed = false;
foreach ( $expect as $rel => $needles ) {
    $src = file_get_contents( $root . '/' . $rel );
    foreach ( $needles as $needle ) {
        if ( false === strpos( $src, $needle ) ) {
            fwrite( STDERR, "FAIL: {$rel} missing {$needle}\n" );
            $failed = true;
        }
    }
}
foreach ( $banned as $rel => $needles ) {
    $src = file_get_contents( $root . '/' . $rel );
    foreach ( $needles as $needle ) {
        if ( false !== strpos( $src, $needle ) ) {
            fwrite( STDERR, "FAIL: {$rel} still contains {$needle}\n" );
            $failed = true;
        }
    }
}

if ( $failed ) {
    exit( 1 );
}
echo "PASS\n";
