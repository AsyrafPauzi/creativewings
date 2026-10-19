<?php
/**
 * Guard: contestant shell + overview markup conventions.
 * Run: php -n tests/test-cw-contestant-overview-markup.php
 */
if ( php_sapi_name() !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );
$fail = 0;

function cw_assert( $cond, $msg ) {
	global $fail;
	if ( $cond ) {
		echo "OK  $msg\n";
		return;
	}
	echo "FAIL $msg\n";
	$fail++;
}

$manager = file_get_contents( $root . '/includes/dashboard/class-cw-dashboard-manager.php' );
cw_assert(
	strpos( $manager, 'cw-layout--contestant' ) !== false,
	'manager references cw-layout--contestant'
);

$contestant = file_get_contents( $root . '/includes/dashboard/class-cw-dashboard-contestant.php' );
// Overview-specific classes (will fail until Task 3).
cw_assert(
	strpos( $contestant, 'cw-ov-welcome' ) !== false,
	'overview has cw-ov-welcome'
);
cw_assert(
	strpos( $contestant, 'cw-ov-metrics' ) !== false,
	'overview has cw-ov-metrics'
);
cw_assert(
	strpos( $contestant, 'cw-ov-recent' ) !== false,
	'overview has cw-ov-recent'
);

// Stricter: between render_overview and the next public function, no .stat-icon
if ( preg_match( '/function render_overview\(\)\s*\{([\s\S]*?)\n[ \t]*public function /', $contestant, $m ) ) {
	$body = $m[1];
	cw_assert( strpos( $body, 'stat-icon' ) === false, 'render_overview body has no stat-icon' );
	cw_assert( strpos( $body, 'cw-ov-welcome' ) !== false, 'render_overview body has cw-ov-welcome' );
	cw_assert( strpos( $body, '<table' ) === false, 'render_overview body has no table' );
}

$css = file_get_contents( $root . '/assets/css/cw-style-contestant.css' );
cw_assert( strpos( $css, '.cw-ov-welcome' ) !== false, 'contestant CSS defines .cw-ov-welcome' );
if ( preg_match( '/Overview revamp \(cw-ov-\*\)[\s\S]*?(?=\/\* ---)/', $css, $ov_css ) ) {
	cw_assert( strpos( $ov_css[0], 'backdrop-filter' ) === false, 'cw-ov CSS block has no backdrop-filter' );
} else {
	cw_assert( false, 'cw-ov CSS block marker present' );
}

$gen = file_get_contents( $root . '/assets/css/cw-style-general.css' );
cw_assert(
	strpos( $gen, '.cw-layout--contestant' ) !== false,
	'general CSS scopes contestant shell'
);

exit( $fail > 0 ? 1 : 0 );
