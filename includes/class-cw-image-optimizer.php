<?php
/**
 * Visually-lossless image optimization helper.
 *
 * Single source of truth for the plugin's upload pipeline:
 *  - resize oversized originals to a sensible context-aware max,
 *  - re-encode JPEG at quality 82 (visually lossless),
 *  - tighten PNG compression (level 9) while preserving alpha,
 *  - emit a .webp sibling at q80 for modern browsers.
 *
 * Server capability is detected via wp_get_image_editor() so the code is safe
 * on GD-only and Imagick hosts alike. If WebP is unsupported, the optimizer
 * still resizes + recompresses and simply skips the .webp twin.
 *
 * @package CreativeWings
 * @since   11.0.59
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Image_Optimizer {

    /** Marker meta added to attachments we've already processed. */
    const ATTACH_META_OPTIMIZED = '_cw_optimized';

    /** Marker meta storing the webp sibling URL when produced. */
    const ATTACH_META_WEBP_URL = '_cw_webp_url';

    /** Default JPEG quality (perceptually lossless for photographs). */
    const JPEG_QUALITY = 82;

    /** WebP quality. */
    const WEBP_QUALITY = 80;

    /** Skip optimizer entirely for files smaller than this. */
    const MIN_SIZE_BYTES = 30 * 1024; // 30 KB

    /**
     * Context => max long-edge pixels.
     */
    public static function dimensions_for_context( $context ) {
        $map = [
            'logo'           => 800,
            'avatar'         => 800,
            'profile_photo'  => 800,
            'cover'          => 1920,
            'hero'           => 1920,
            'header'         => 1920,
            'campaign_thumb' => 1600,
            'portfolio'      => 1600,
            'gallery'        => 1600,
            'attachment'     => 2048,
            'general'        => 2048,
        ];
        $key = is_string( $context ) ? strtolower( $context ) : 'general';
        $max = $map[ $key ] ?? 2048;

        return (int) apply_filters( 'cw_image_optimizer_max_dimension', $max, $key );
    }

    /**
     * Optimize a file in place.
     *
     * Resizes (if larger than the context cap), re-encodes JPEG/PNG with stricter
     * quality settings, strips EXIF, and emits a sibling .webp. Returns a result
     * array; on failure returns WP_Error but never throws.
     *
     * @param string $path    Absolute filesystem path.
     * @param string $context Context tag (e.g. 'logo', 'cover', 'campaign_thumb').
     * @return array|WP_Error {
     *     'optimized'    => bool,    // true if any byte saved
     *     'webp_path'    => string,  // sibling .webp absolute path ('' if skipped)
     *     'bytes_before' => int,
     *     'bytes_after'  => int,
     *     'dims_before'  => [int,int]|null,
     *     'dims_after'   => [int,int]|null,
     * }
     */
    public static function optimize_path( $path, $context = 'general' ) {
        if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
            return new WP_Error( 'cw_optimizer_no_file', __( 'File not found.', 'creativewings-core' ) );
        }

        $bytes_before = (int) filesize( $path );
        if ( $bytes_before < self::MIN_SIZE_BYTES ) {
            // Already tiny — skip to avoid wasted CPU.
            return [
                'optimized'    => false,
                'webp_path'    => '',
                'bytes_before' => $bytes_before,
                'bytes_after'  => $bytes_before,
                'dims_before'  => null,
                'dims_after'   => null,
                'skipped'      => 'too_small',
            ];
        }

        $mime = function_exists( 'mime_content_type' ) ? @mime_content_type( $path ) : '';
        if ( ! $mime ) {
            // Fallback to extension sniff so PNG/JPG/WEBP all work on hosts without fileinfo.
            $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            $map = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'webp' => 'image/webp',
                'gif'  => 'image/gif',
            ];
            $mime = $map[ $ext ] ?? '';
        }

        // Only optimize raster image types we know how to re-encode.
        $supported = [ 'image/jpeg', 'image/png', 'image/webp' ];
        if ( ! in_array( $mime, $supported, true ) ) {
            return [
                'optimized'    => false,
                'webp_path'    => '',
                'bytes_before' => $bytes_before,
                'bytes_after'  => $bytes_before,
                'dims_before'  => null,
                'dims_after'   => null,
                'skipped'      => 'unsupported_mime',
            ];
        }

        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return $editor;
        }

        $editor->set_quality( self::JPEG_QUALITY );

        $size = $editor->get_size();
        $dims_before = [ (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) ];

        // Resize if larger than the context cap on its long edge.
        $max = self::dimensions_for_context( $context );
        $resized = false;
        if ( $dims_before[0] > 0 && $dims_before[1] > 0 ) {
            $long = max( $dims_before[0], $dims_before[1] );
            if ( $long > $max ) {
                $result = ( $dims_before[0] >= $dims_before[1] )
                    ? $editor->resize( $max, null, false )
                    : $editor->resize( null, $max, false );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
                $resized = true;
            }
        }

        // Save back to the same path (overwriting). For PNG with alpha we keep PNG;
        // otherwise transparent JPEGs would lose alpha.
        $saved = $editor->save( $path );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        clearstatcache( true, $path );
        $bytes_after = (int) filesize( $path );
        $size_after  = $editor->get_size();
        $dims_after  = [ (int) ( $size_after['width'] ?? 0 ), (int) ( $size_after['height'] ?? 0 ) ];

        // Emit WebP sibling (best-effort, capability-gated).
        $webp_path = '';
        if ( self::webp_supported() ) {
            $webp_path = preg_replace( '/\.(jpe?g|png|webp)$/i', '.webp', $path );
            if ( ! is_string( $webp_path ) || $webp_path === $path ) {
                // Couldn't derive a sibling path (no extension to swap).
                $webp_path = '';
            } else {
                $webp_editor = wp_get_image_editor( $path );
                if ( ! is_wp_error( $webp_editor ) ) {
                    $webp_editor->set_quality( self::WEBP_QUALITY );
                    $webp_saved = $webp_editor->save( $webp_path, 'image/webp' );
                    if ( is_wp_error( $webp_saved ) || empty( $webp_saved['path'] ) ) {
                        $webp_path = '';
                    } else {
                        // If WebP came out bigger than the source, drop it.
                        clearstatcache( true, $webp_path );
                        if ( file_exists( $webp_path ) && filesize( $webp_path ) >= $bytes_after ) {
                            @unlink( $webp_path );
                            $webp_path = '';
                        }
                    }
                } else {
                    $webp_path = '';
                }
            }
        }

        $optimized = ( $bytes_after < $bytes_before ) || $resized || ( $webp_path !== '' );

        return [
            'optimized'    => $optimized,
            'webp_path'    => $webp_path,
            'bytes_before' => $bytes_before,
            'bytes_after'  => $bytes_after,
            'dims_before'  => $dims_before,
            'dims_after'   => $dims_after,
            'skipped'      => false,
        ];
    }

    /**
     * Optimize an attachment and update its post meta so we don't reprocess it.
     *
     * Also regenerates WordPress's sub-sizes if dimensions shrank, so all the
     * 'medium', 'large', etc. thumbnails stay in lockstep with the optimized
     * original.
     *
     * @param int    $attach_id Attachment post ID.
     * @param string $context   Context tag (e.g. 'logo', 'cover').
     * @return array|WP_Error Same shape as optimize_path() plus 'attach_id'.
     */
    public static function optimize_attachment( $attach_id, $context = 'general' ) {
        $attach_id = (int) $attach_id;
        if ( $attach_id <= 0 ) {
            return new WP_Error( 'cw_optimizer_bad_id', __( 'Invalid attachment ID.', 'creativewings-core' ) );
        }

        if ( get_post_meta( $attach_id, self::ATTACH_META_OPTIMIZED, true ) === '1' ) {
            // Already processed.
            $existing_webp = (string) get_post_meta( $attach_id, self::ATTACH_META_WEBP_URL, true );
            return [
                'optimized'    => false,
                'webp_path'    => $existing_webp ? self::url_to_path( $existing_webp ) : '',
                'bytes_before' => 0,
                'bytes_after'  => 0,
                'dims_before'  => null,
                'dims_after'   => null,
                'skipped'      => 'already_optimized',
                'attach_id'    => $attach_id,
            ];
        }

        $path = get_attached_file( $attach_id );
        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error( 'cw_optimizer_no_file', __( 'Attachment file missing.', 'creativewings-core' ) );
        }

        $result = self::optimize_path( $path, $context );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Regenerate sub-sizes if we shrank the original.
        if ( ! empty( $result['dims_after'] ) && is_array( $result['dims_after'] )
            && ! empty( $result['dims_before'] ) && $result['dims_after'] !== $result['dims_before'] ) {
            if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            wp_create_image_subsizes( $path, $attach_id );
        }

        // Track WebP sibling URL on the attachment, if we made one.
        if ( ! empty( $result['webp_path'] ) ) {
            $webp_url = self::path_to_url( $result['webp_path'] );
            if ( $webp_url ) {
                update_post_meta( $attach_id, self::ATTACH_META_WEBP_URL, $webp_url );
            }
        }

        update_post_meta( $attach_id, self::ATTACH_META_OPTIMIZED, '1' );
        $result['attach_id'] = $attach_id;
        return $result;
    }

    /**
     * Return a <picture> tag preferring a .webp sibling, falling back to the
     * original. Always emits loading="lazy" decoding="async" so callers don't
     * have to remember.
     *
     * @param string $url   Image URL (the JPEG/PNG original).
     * @param string $alt   Alt text. Will be esc_attr'd.
     * @param array  $attrs Extra HTML attributes for the <img> ('class', 'width', 'height', 'sizes', etc.).
     * @return string HTML.
     */
    public static function picture_tag( $url, $alt = '', $attrs = [] ) {
        $url = (string) $url;
        if ( $url === '' ) {
            return '';
        }

        $alt   = (string) $alt;
        $attrs = is_array( $attrs ) ? $attrs : [];

        // Loading attributes default to lazy + async (callers can override via $attrs).
        $img_attrs = wp_parse_args( $attrs, [
            'loading'  => 'lazy',
            'decoding' => 'async',
        ] );

        // Build the <img>.
        $img_html = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"';
        foreach ( $img_attrs as $k => $v ) {
            if ( $v === '' || $v === null ) {
                continue;
            }
            $img_html .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
        }
        $img_html .= '>';

        // Try to surface a .webp sibling if one exists on disk.
        $webp_url = self::webp_sibling_url( $url );
        if ( $webp_url === '' ) {
            return $img_html;
        }

        return '<picture>'
            . '<source type="image/webp" srcset="' . esc_url( $webp_url ) . '">'
            . $img_html
            . '</picture>';
    }

    /**
     * Determine the .webp sibling URL for a given image URL — returns '' when
     * the sibling does not exist on disk.
     *
     * @param string $url
     * @return string
     */
    public static function webp_sibling_url( $url ) {
        if ( ! is_string( $url ) || $url === '' ) {
            return '';
        }

        $webp_url = preg_replace( '/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $url );
        if ( ! is_string( $webp_url ) || $webp_url === $url ) {
            return '';
        }

        // Only return it if the actual file exists on disk.
        $webp_path = self::url_to_path( $webp_url );
        if ( ! $webp_path || ! file_exists( $webp_path ) ) {
            return '';
        }
        return $webp_url;
    }

    /**
     * Best-effort URL -> filesystem path resolver.
     */
    public static function url_to_path( $url ) {
        if ( ! is_string( $url ) || $url === '' ) {
            return '';
        }
        $upload_dir = wp_get_upload_dir();
        if ( strpos( $url, $upload_dir['baseurl'] ) === 0 ) {
            return $upload_dir['basedir'] . substr( $url, strlen( $upload_dir['baseurl'] ) );
        }
        $site_url = site_url();
        if ( strpos( $url, $site_url ) === 0 ) {
            return ABSPATH . ltrim( substr( $url, strlen( $site_url ) ), '/' );
        }
        return '';
    }

    /**
     * Best-effort filesystem path -> URL resolver (used after writing a sibling).
     */
    public static function path_to_url( $path ) {
        if ( ! is_string( $path ) || $path === '' ) {
            return '';
        }
        $upload_dir = wp_get_upload_dir();
        if ( strpos( $path, $upload_dir['basedir'] ) === 0 ) {
            return $upload_dir['baseurl'] . substr( $path, strlen( $upload_dir['basedir'] ) );
        }
        if ( strpos( $path, ABSPATH ) === 0 ) {
            return site_url( '/' . ltrim( substr( $path, strlen( ABSPATH ) ), '/' ) );
        }
        return '';
    }

    /**
     * Whether the active image editor backend (GD or Imagick) supports WebP.
     */
    public static function webp_supported() {
        static $cached = null;
        if ( $cached !== null ) {
            return $cached;
        }
        // wp_image_editor_supports() — true if any editor can write image/webp.
        if ( function_exists( 'wp_image_editor_supports' ) ) {
            $cached = (bool) wp_image_editor_supports( [ 'mime_type' => 'image/webp', 'methods' => [ 'save' ] ] );
            return $cached;
        }
        // Fallback sniff.
        $cached = ( function_exists( 'imagewebp' ) ) || ( class_exists( 'Imagick' ) && in_array( 'WEBP', Imagick::queryFormats( 'WEBP' ), true ) );
        return $cached;
    }
}
