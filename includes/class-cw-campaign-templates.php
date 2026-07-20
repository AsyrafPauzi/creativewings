<?php
/**
 * Campaign participant templates (downloadable resources).
 *
 * Each campaign can have any number of template files (PSD / AI / EPS /
 * PDF / SVG / PNG / JPG / ZIP) attached. Participants see them on the
 * public campaign page as a list of download buttons inside the
 * "Resources" card; organisers manage the list from the campaign
 * builder's Step 3 (Prizes & Rewards section).
 *
 * Storage model:
 *   - Primary meta: `cw_template_files` → array of [ 'id' => int, 'label' => string ]
 *   - Legacy meta : `cw_template_file_id` + `cw_template_label`
 *
 * The legacy single-file meta is auto-migrated on read so campaigns
 * created before this feature was multi-file aware keep working
 * without a one-shot DB migration. The save path also mirrors the
 * first entry of the new array back to the legacy keys, so any
 * external consumer that still reads them continues to work.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Campaign_Templates {

    const META_FILES        = 'cw_template_files';
    const LEGACY_META_ID    = 'cw_template_file_id';
    const LEGACY_META_LABEL = 'cw_template_label';

    /**
     * Return the unified list of template attachments for a campaign.
     * Falls back to the legacy single-file meta if `cw_template_files`
     * hasn't been populated yet.
     *
     * @param int $campaign_id
     * @return array<int, array{id:int,label:string}>
     */
    public static function get_files( $campaign_id ) {
        $campaign_id = (int) $campaign_id;
        if ( $campaign_id <= 0 ) {
            return [];
        }

        $raw = get_post_meta( $campaign_id, self::META_FILES, true );
        $out = [];
        if ( is_array( $raw ) ) {
            foreach ( $raw as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $id = isset( $row['id'] ) ? (int) $row['id'] : 0;
                if ( $id <= 0 ) {
                    continue;
                }
                $out[] = [
                    'id'    => $id,
                    'label' => (string) ( $row['label'] ?? '' ),
                ];
            }
        }

        // ── Legacy fallback ──────────────────────────────────────────
        // Campaigns saved before the multi-file rollout stored the file
        // under `cw_template_file_id`. Expose it as a one-element list
        // so old campaigns render correctly without a manual migration.
        if ( empty( $out ) ) {
            $legacy_id = (int) get_post_meta( $campaign_id, self::LEGACY_META_ID, true );
            if ( $legacy_id > 0 ) {
                $out[] = [
                    'id'    => $legacy_id,
                    'label' => (string) get_post_meta( $campaign_id, self::LEGACY_META_LABEL, true ),
                ];
            }
        }

        return $out;
    }

    /**
     * Hydrate each row with download metadata (URL, filename, size, ext).
     * Convenience for the public Resources card. Rows whose attachment
     * has been deleted are silently dropped — this keeps the front end
     * resilient to media library cleanups.
     *
     * @param int $campaign_id
     * @return array<int, array{id:int,label:string,url:string,name:string,ext:string,size:string}>
     */
    public static function get_files_with_meta( $campaign_id ) {
        $rows = self::get_files( $campaign_id );
        $out  = [];
        foreach ( $rows as $row ) {
            $id  = (int) $row['id'];
            $url = (string) wp_get_attachment_url( $id );
            if ( $url === '' ) {
                continue; // Attachment missing — skip silently.
            }
            $path  = (string) get_attached_file( $id );
            $name  = $path ? basename( $path ) : '';
            $ext   = $name ? strtoupper( pathinfo( $name, PATHINFO_EXTENSION ) ) : '';
            $size  = ( $path && file_exists( $path ) ) ? size_format( (int) filesize( $path ), 1 ) : '';
            $label = trim( (string) $row['label'] );
            if ( $label === '' ) {
                $label = __( 'Download Template', 'creativewings-core' );
            }
            $out[] = [
                'id'    => $id,
                'label' => $label,
                'url'   => $url,
                'name'  => $name,
                'ext'   => $ext,
                'size'  => $size,
            ];
        }
        return $out;
    }

    /**
     * Persist the canonical file list and mirror the first entry to the
     * legacy keys for any code paths still reading the singular meta.
     *
     * @param int   $campaign_id
     * @param array $rows  Array of [ 'id' => int, 'label' => string ]
     */
    public static function save_files( $campaign_id, $rows ) {
        $campaign_id = (int) $campaign_id;
        if ( $campaign_id <= 0 ) {
            return;
        }

        $clean = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id = isset( $row['id'] ) ? (int) $row['id'] : 0;
            if ( $id <= 0 ) {
                continue;
            }
            $clean[] = [
                'id'    => $id,
                'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
            ];
        }

        if ( ! empty( $clean ) ) {
            update_post_meta( $campaign_id, self::META_FILES, $clean );
            // Mirror first entry to legacy keys for backwards compatibility.
            update_post_meta( $campaign_id, self::LEGACY_META_ID, $clean[0]['id'] );
            update_post_meta( $campaign_id, self::LEGACY_META_LABEL, $clean[0]['label'] );
        } else {
            delete_post_meta( $campaign_id, self::META_FILES );
            delete_post_meta( $campaign_id, self::LEGACY_META_ID );
            delete_post_meta( $campaign_id, self::LEGACY_META_LABEL );
        }
    }
}
