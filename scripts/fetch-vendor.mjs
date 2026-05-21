// Copies pinned vendor libraries from node_modules into assets/vendor/
// so the plugin can self-host them (no CDN dependency at runtime).
//
// Run: `npm install && npm run vendor:fetch`

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const root      = path.resolve( __dirname, '..' );

function copy( src, dest ) {
    const dir = path.dirname( dest );
    fs.mkdirSync( dir, { recursive: true } );
    fs.copyFileSync( src, dest );
    console.log( `  ${path.relative( root, src )}  ->  ${path.relative( root, dest )}` );
}

console.log( 'Copying self-hosted vendor libraries…' );

// Chart.js UMD bundle.
copy(
    path.join( root, 'node_modules/chart.js/dist/chart.umd.js' ),
    path.join( root, 'assets/vendor/chart.js/chart.umd.min.js' )
);

// SweetAlert2.
copy(
    path.join( root, 'node_modules/sweetalert2/dist/sweetalert2.min.js' ),
    path.join( root, 'assets/vendor/sweetalert2/sweetalert2.min.js' )
);
copy(
    path.join( root, 'node_modules/sweetalert2/dist/sweetalert2.min.css' ),
    path.join( root, 'assets/vendor/sweetalert2/sweetalert2.min.css' )
);

console.log( 'Done.' );
