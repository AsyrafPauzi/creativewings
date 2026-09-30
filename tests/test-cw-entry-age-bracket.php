<?php
/**
 * Manage Entries groups entries by age category from the participant's DOB on the day they joined.
 * Run: php tests/test-cw-entry-age-bracket.php
 */

date_default_timezone_set( 'Asia/Kuala_Lumpur' );

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
    }
}

$src = file_get_contents( dirname( __DIR__ ) . '/includes/dashboard/class-cw-dashboard-business.php' );
if ( ! preg_match( '/public static function age_bracket_for_dob\(.*?\n    \}\n/s', $src, $m ) ) {
    fwrite( STDERR, "FAIL: age_bracket_for_dob() missing\n" );
    exit( 1 );
}
eval( 'class CW_Age_Bracket_Test { ' . $m[0] . ' }' );

$brackets = [
    [ 'label' => 'Primary', 'min_age' => 7, 'max_age' => 12, 'key' => 'primary' ],
    [ 'label' => 'Secondary', 'min_age' => 13, 'max_age' => 17, 'key' => 'secondary' ],
];

$cases = [
    'primary, age 10'                => [ '18/04/2016', '2026-09-27', 'primary' ],
    'secondary, age 13'              => [ '14/08/2013', '2026-09-27', 'secondary' ],
    'turns 13 the day after joining' => [ '28/09/2013', '2026-09-27', 'primary' ],
    'turns 13 on joining day'        => [ '27/09/2013', '2026-09-27', 'secondary' ],
    'single-digit day and month'     => [ '8/4/2016', '2026-09-25', 'primary' ],
    'too young'                      => [ '01/01/2021', '2026-09-27', '' ],
    'too old'                        => [ '01/01/2005', '2026-09-27', '' ],
    'empty dob'                      => [ '', '2026-09-27', '' ],
    'garbage dob'                    => [ 'not a date', '2026-09-27', '' ],
    'born after joining'             => [ '01/01/2030', '2026-09-27', '' ],
];

$failed = 0;
foreach ( $cases as $label => [ $dob, $on, $expected ] ) {
    $got = CW_Age_Bracket_Test::age_bracket_for_dob( $dob, $on, $brackets );
    if ( $got !== $expected ) {
        fwrite( STDERR, "FAIL: {$label} — expected '{$expected}', got '{$got}'\n" );
        $failed++;
    }
}

$no_key = CW_Age_Bracket_Test::age_bracket_for_dob( '18/04/2016', '2026-09-27', [ [ 'label' => 'Primary School', 'min_age' => 7, 'max_age' => 12 ] ] );
if ( 'primaryschool' !== $no_key ) {
    fwrite( STDERR, "FAIL: bracket without key should fall back to sanitized label, got '{$no_key}'\n" );
    $failed++;
}

if ( $failed ) {
    exit( 1 );
}
echo 'PASS: entry age bracket (' . ( count( $cases ) + 1 ) . " cases)\n";
