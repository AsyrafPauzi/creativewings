<?php
/**
 * A join must not go through when a required file/media field received no file.
 * Run: php tests/test-cw-required-upload.php
 */

$root = dirname( __DIR__ );
$src  = file_get_contents( $root . '/includes/class-cw-shop.php' );

if ( ! preg_match( '/public static function required_upload_gaps\(.*?\n    \}\n/s', $src, $m ) ) {
    fwrite( STDERR, "FAIL: required_upload_gaps() missing\n" );
    exit( 1 );
}
eval( 'class CW_Shop_Upload_Test { ' . $m[0] . ' }' );

$artwork  = [ 'label' => 'Artwork', 'type' => 'media', 'required' => 1 ];
$optional = [ 'label' => 'Extra', 'type' => 'file', 'required' => 0 ];
$text     = [ 'label' => 'School', 'type' => 'text', 'required' => 1 ];
$none     = function () { return false; };
$all      = function () { return true; };

$cases = [
    'required media missing'      => [ [ $artwork ], [ 1 ], $none, [ [ 'row' => 1, 'label' => 'Artwork' ] ] ],
    'required media present'      => [ [ $artwork ], [ 1 ], $all, [] ],
    'optional file missing'       => [ [ $optional ], [ 1 ], $none, [] ],
    'text fields ignored'         => [ [ $text ], [ 1 ], $none, [] ],
    'only row 2 missing'          => [ [ $artwork ], [ 1, 2 ], function ( $row ) { return 1 === $row; }, [ [ 'row' => 2, 'label' => 'Artwork' ] ] ],
    'field index passed through'  => [ [ $text, $artwork ], [ 1 ], function ( $row, $idx ) { return 1 === $idx; }, [] ],
    'type is case-insensitive'    => [ [ [ 'label' => 'Art', 'type' => 'Media', 'required' => '1' ] ], [ 3 ], $none, [ [ 'row' => 3, 'label' => 'Art' ] ] ],
    'no fields'                   => [ [], [ 1 ], $none, [] ],
];

$failed = 0;
foreach ( $cases as $label => [ $fields, $rows, $has, $expected ] ) {
    $got = CW_Shop_Upload_Test::required_upload_gaps( $fields, $rows, $has );
    if ( $got !== $expected ) {
        fwrite( STDERR, "FAIL: {$label} — expected " . json_encode( $expected ) . ', got ' . json_encode( $got ) . "\n" );
        $failed++;
    }
}

$modal = file_get_contents( $root . '/includes/class-cw-shortcodes.php' );
if ( strpos( $modal, 'cwd-reg-file-media" accept="image/*,application/pdf,.pdf"' ) === false ) {
    fwrite( STDERR, "FAIL: campaign join modal media field does not accept PDF\n" );
    $failed++;
}
if ( strpos( $src, "preg_match('/\\.(jpg|jpeg|png|gif|webp|heic|heif|pdf)\$/i'" ) === false ) {
    fwrite( STDERR, "FAIL: entry creation does not keep all accepted artwork types\n" );
    $failed++;
}

if ( $failed ) {
    exit( 1 );
}
echo "PASS: required upload gaps (" . count( $cases ) . " cases) + PDF accepted\n";
