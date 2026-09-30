<?php
/**
 * Directory listing must not fan out WP_User_Query across many usermeta joins.
 * That cartesian product scanned 4.8M rows on Creative Wings and helped OOM the server.
 *
 * Run: php tests/test-cw-directory-query.php
 */

if ( php_sapi_name() !== 'cli' ) {
    exit( 1 );
}

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}

if ( ! function_exists( 'add_shortcode' ) ) {
    function add_shortcode() {}
}

require_once $root . '/includes/class-cw-directory.php';

$fail  = 0;
$check = static function ( $label, $ok ) use ( &$fail ) {
    echo ( $ok ? 'OK  ' : 'FAIL' ) . ' ' . $label . PHP_EOL;
    if ( ! $ok ) {
        $fail++;
    }
};

$org = CW_Directory::role_query_args( 'organizer' );
$cr  = CW_Directory::role_query_args( 'creator' );

$check( 'organizer query uses business + admin roles', $org['role__in'] === [ 'business_role', 'administrator' ] );
$check( 'creator query uses creator_role', $cr['role__in'] === [ 'creator_role' ] );

$check( 'organizer query does not request SQL_CALC_FOUND_ROWS', ( $org['count_total'] ?? true ) === false );
$check( 'creator query does not request SQL_CALC_FOUND_ROWS', ( $cr['count_total'] ?? true ) === false );

$check( 'organizer query has no usermeta completeness joins', empty( $org['meta_query'] ) );
$check( 'creator query has no usermeta completeness joins', empty( $cr['meta_query'] ) );

$check( 'organizer query does not SQL-paginate (no LIMIT/offset in WP_User_Query)', empty( $org['number'] ) && empty( $org['paged'] ) );
$check( 'creator query does not SQL-paginate', empty( $cr['number'] ) && empty( $cr['paged'] ) );

$check( 'organizer query hydrates IDs only, not full WP_User rows', ( $org['fields'] ?? '' ) === 'ID' );
$check( 'creator query hydrates IDs only', ( $cr['fields'] ?? '' ) === 'ID' );

$page = CW_Directory::paginate_list( range( 1, 25 ), 2, 12 );
$check( 'page 2 of 25 items at 12/page has 12 items', $page['items'] === range( 13, 24 ) );
$check( 'paginate reports full total before slicing', $page['total'] === 25 );

$last = CW_Directory::paginate_list( range( 1, 25 ), 3, 12 );
$check( 'last page keeps remainder', $last['items'] === [ 25 ] );

$src = (string) file_get_contents( $root . '/includes/class-cw-directory.php' );
$check(
    'directory PHP no longer SQL-gates business_name completeness',
    strpos( $src, "[ 'key' => 'business_name',     'value' => '', 'compare' => '!=' ]" ) === false
);
$check(
    'directory PHP no longer SQL-gates creator_tagline completeness',
    strpos( $src, "[ 'key' => 'creator_tagline', 'value' => '', 'compare' => '!=' ]" ) === false
);
$check(
    'directory PHP no longer sets count_total true',
    strpos( $src, "'count_total' => true" ) === false && strpos( $src, "'count_total'  => true" ) === false
);

exit( $fail > 0 ? 1 : 0 );
