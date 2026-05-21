// Build pipeline for the CreativeWings plugin assets.
//
// What we do:
//  - Take the hand-authored stylesheets in assets/css/* and produce minified,
//    fingerprinted outputs in assets/dist/.
//  - Bundle the small amount of plugin JS through Terser for compression.
//  - Emit a manifest.json so PHP can resolve fingerprinted filenames at runtime.
//
// Vite is invoked with `npm run build`. No JS framework — we just use Vite as
// a thin asset bundler.

import { defineConfig } from 'vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

export default defineConfig( {
    root: __dirname,
    publicDir: false,

    build: {
        outDir: 'assets/dist',
        emptyOutDir: true,
        manifest: true,
        cssCodeSplit: true,
        minify: 'terser',
        terserOptions: {
            compress: { drop_console: false, drop_debugger: true },
            format: { comments: false },
        },
        rollupOptions: {
            // One entry per stylesheet/JS file so PHP can decide per-page what to enqueue.
            input: {
                'cw-core':       path.resolve( __dirname, 'assets/css/cw-style-general.css' ),
                'cw-business':   path.resolve( __dirname, 'assets/css/cw-style-business.css' ),
                'cw-contestant': path.resolve( __dirname, 'assets/css/cw-style-contestant.css' ),
                'cw-creator':    path.resolve( __dirname, 'assets/css/cw-style-creator.css' ),
                'cw-organizer':  path.resolve( __dirname, 'assets/css/cw-style-organizer.css' ),
                'cw-directory':  path.resolve( __dirname, 'assets/css/cw-style-directory.css' ),
                'cw-checkout':   path.resolve( __dirname, 'assets/css/cw-style-checkout.css' ),
                'cw-wizard':     path.resolve( __dirname, 'assets/css/cw-style-wizard.css' ),
                'cw-app':        path.resolve( __dirname, 'assets/js/cw-script.js' ),
            },
            output: {
                entryFileNames: 'js/[name].[hash].js',
                chunkFileNames: 'js/[name].[hash].js',
                assetFileNames: ( assetInfo ) => {
                    if ( assetInfo.name && assetInfo.name.endsWith( '.css' ) ) {
                        return 'css/[name].[hash][extname]';
                    }
                    return '[ext]/[name].[hash][extname]';
                },
            },
        },
    },
} );
