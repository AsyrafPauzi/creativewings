<?php
/**
 * Certificate name comes from the participant name typed per entry (child), not the billing name.
 * Run: php tests/test-cw-participant-name.php
 */

$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-cw-shop.php' );

if ( ! preg_match( '/public static function participant_name_from_fields\(.*?\n    \}\n/s', $src, $m ) ) {
    fwrite( STDERR, "FAIL: participant_name_from_fields() missing\n" );
    exit( 1 );
}
eval( 'class CW_Shop_Name_Test { ' . $m[0] . ' }' );

$cases = [
    'child name typed'         => [ [ [ 'label' => 'Name', 'value' => 'Aisyah binti Ali' ], [ 'label' => 'Age', 'value' => '8' ] ], 'Aisyah binti Ali' ],
    'trims whitespace'         => [ [ [ 'label' => 'Name', 'value' => '  Adam  ' ] ], 'Adam' ],
    'legacy Self placeholder'  => [ [ [ 'label' => 'Name', 'value' => 'Self' ] ], '' ],
    'no name field'            => [ [ [ 'label' => 'School', 'value' => 'SK Taman' ] ], '' ],
    'empty name'               => [ [ [ 'label' => 'Name', 'value' => '' ] ], '' ],
    'not an array'             => [ null, '' ],
];

$failed = 0;
foreach ( $cases as $label => [ $fields, $expected ] ) {
    $got = CW_Shop_Name_Test::participant_name_from_fields( $fields );
    if ( $got !== $expected ) {
        fwrite( STDERR, "FAIL: {$label} — expected '{$expected}', got '{$got}'\n" );
        $failed++;
    }
}

if ( strpos( $src, "if(\$is_activity) {\n                        foreach(\$fields as \$f)" ) !== false ) {
    fwrite( STDERR, "FAIL: checkout still limits typed names to activities\n" );
    $failed++;
}

if ( $failed ) {
    exit( 1 );
}
echo "PASS: participant name helper (" . count( $cases ) . " cases)\n";
