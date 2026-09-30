<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-campaign Supporting Partners + Mentors shown on [cw_event_detail].
 */
class CW_Campaign_Showcase {

    const META_PARTNERS = 'cw_supporting_partners';
    const META_MENTORS  = 'cw_mentors';

    /** Ordered sponsor tiers (deck package order). */
    const TIER_ORDER = [ 'champion', 'hero', 'community', 'local', 'inkind' ];

    /**
     * @return array<string,string> tier key => public label
     */
    public static function tier_labels() {
        return [
            'champion'  => __( 'Presented by', 'creativewings-core' ),
            'hero'      => __( 'Hero Sponsors', 'creativewings-core' ),
            'community' => __( 'Community Partners', 'creativewings-core' ),
            'local'     => __( 'Local Brands', 'creativewings-core' ),
            'inkind'    => __( 'In-kind & Supporting Partners', 'creativewings-core' ),
        ];
    }

    /**
     * @param string $tier Raw tier.
     * @return string Normalized tier key.
     */
    public static function normalize_tier( $tier ) {
        $tier = sanitize_key( (string) $tier );
        if ( ! in_array( $tier, self::TIER_ORDER, true ) ) {
            return 'local';
        }
        return $tier;
    }

    /**
     * @param string $segment Raw segment.
     * @return string media|nonmedia
     */
    public static function normalize_segment( $segment ) {
        $segment = sanitize_key( (string) $segment );
        if ( $segment === 'media' ) {
            return 'media';
        }
        return 'nonmedia';
    }

    /**
     * @return array<string,string>
     */
    public static function segment_labels() {
        return [
            'media'    => __( 'Media', 'creativewings-core' ),
            'nonmedia' => __( 'Non-media', 'creativewings-core' ),
        ];
    }

    /**
     * @param mixed $rows
     * @return array<int,array{name:string,attachment_id:int,url:string,tier:string,segment:string}>
     */
    public static function sanitize_partners( $rows ) {
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $out = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
            $aid  = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
            $url  = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
            $tier = self::normalize_tier( $row['tier'] ?? 'local' );
            $seg  = self::normalize_segment( $row['segment'] ?? 'nonmedia' );
            if ( $name === '' || $aid <= 0 ) {
                continue;
            }
            $out[] = [
                'name'          => $name,
                'attachment_id' => $aid,
                'url'           => $url,
                'tier'          => $tier,
                'segment'       => $seg,
            ];
        }
        return $out;
    }

    /**
     * @param mixed $rows
     * @return array<int,array{name:string,title:string,attachment_id:int,bio:string,url:string}>
     */
    public static function sanitize_mentors( $rows ) {
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $out = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name  = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
            $title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
            $aid   = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
            $bio   = isset( $row['bio'] ) ? sanitize_textarea_field( (string) $row['bio'] ) : '';
            $url   = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
            if ( $name === '' || $aid <= 0 ) {
                continue;
            }
            $out[] = [
                'name'          => $name,
                'title'         => $title,
                'attachment_id' => $aid,
                'bio'           => $bio,
                'url'           => $url,
            ];
        }
        return $out;
    }

    /**
     * @param int $product_id
     * @return array<int,array{name:string,attachment_id:int,url:string,tier:string}>
     */
    public static function get_partners( $product_id ) {
        $raw = get_post_meta( (int) $product_id, self::META_PARTNERS, true );
        return self::sanitize_partners( is_array( $raw ) ? $raw : [] );
    }

    /**
     * Partners grouped by tier in deck order. Empty tiers omitted.
     *
     * @param int $product_id
     * @return array<string,array<int,array{name:string,attachment_id:int,url:string,tier:string}>>
     */
    public static function partners_by_tier( $product_id ) {
        $grouped = [];
        foreach ( self::TIER_ORDER as $tier ) {
            $grouped[ $tier ] = [];
        }
        foreach ( self::get_partners( $product_id ) as $row ) {
            $tier = self::normalize_tier( $row['tier'] ?? 'local' );
            $grouped[ $tier ][] = $row;
        }
        return array_filter(
            $grouped,
            static function ( $rows ) {
                return ! empty( $rows );
            }
        );
    }

    /**
     * Demo / empty-tier placeholder partner row (no attachment).
     *
     * @param string $tier
     * @param int    $index
     * @return array{name:string,attachment_id:int,url:string,tier:string,is_placeholder:bool,placeholder_i:int}
     */
    public static function placeholder_partner( $tier, $index = 0 ) {
        $tier   = self::normalize_tier( $tier );
        $labels = [
            'champion'  => __( 'Champion Sponsor', 'creativewings-core' ),
            'hero'      => __( 'Hero Sponsor', 'creativewings-core' ),
            'community' => __( 'Community Partner', 'creativewings-core' ),
            'local'     => __( 'Local Brand', 'creativewings-core' ),
            'inkind'    => __( 'Supporting Partner', 'creativewings-core' ),
        ];
        $segment = 'nonmedia';
        if ( $tier === 'inkind' && (int) $index === 0 ) {
            $segment            = 'media';
            $labels['inkind'] = __( 'Media Partner', 'creativewings-core' );
        }
        return [
            'name'           => $labels[ $tier ] ?? __( 'Partner', 'creativewings-core' ),
            'attachment_id'  => 0,
            'url'            => '',
            'tier'           => $tier,
            'segment'        => $segment,
            'is_placeholder' => true,
            'placeholder_i'  => (int) $index,
        ];
    }

    /**
     * Split partner rows into media / non-media (for In-kind section).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{media:array,nonmedia:array}
     */
    public static function split_by_segment( array $rows ) {
        $out = [ 'media' => [], 'nonmedia' => [] ];
        foreach ( $rows as $row ) {
            $seg = self::normalize_segment( $row['segment'] ?? 'nonmedia' );
            $out[ $seg ][] = $row;
        }
        return $out;
    }

    /**
     * Render a partners grid (optionally with segment subgroups for inkind).
     *
     * @param string                         $tier
     * @param array<int,array<string,mixed>> $rows
     * @param string                         $size
     * @return string
     */
    public static function render_partners_grid_html( $tier, array $rows, $size = 'medium' ) {
        if ( empty( $rows ) ) {
            return '';
        }
        $tier = self::normalize_tier( $tier );
        ob_start();
        if ( $tier === 'inkind' ) {
            $split  = self::split_by_segment( $rows );
            $labels = self::segment_labels();
            foreach ( [ 'media', 'nonmedia' ] as $seg ) {
                $seg_rows = $split[ $seg ] ?? [];
                if ( empty( $seg_rows ) ) {
                    continue;
                }
                ?>
                <div class="cwd-partners-subgroup cwd-partners-subgroup--<?php echo esc_attr( $seg ); ?>">
                    <h3 class="cwd-partners-subgroup__title"><?php echo esc_html( $labels[ $seg ] ); ?></h3>
                    <div class="cwd-partners-grid cwd-partners-grid--inkind cwd-partners-grid--<?php echo esc_attr( $seg ); ?> cwd-partners-grid--count-<?php echo (int) count( $seg_rows ); ?>">
                        <?php foreach ( $seg_rows as $partner ) :
                            $cell = self::render_partner_cell_html( $partner, $size );
                            if ( $cell === '' ) {
                                continue;
                            }
                            ?>
                            <div class="cwd-partner-item"><?php echo $cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
            }
            return (string) ob_get_clean();
        }
        ?>
        <div class="cwd-partners-grid cwd-partners-grid--<?php echo esc_attr( $tier ); ?> cwd-partners-grid--count-<?php echo (int) count( $rows ); ?>">
            <?php foreach ( $rows as $partner ) :
                $cell = self::render_partner_cell_html( $partner, $size );
                if ( $cell === '' ) {
                    continue;
                }
                ?>
                <div class="cwd-partner-item"><?php echo $cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Muted “Your brand here” tile for empty sponsor tiers.
     *
     * @param string $tier
     * @return string
     */
    public static function render_placeholder_html( $tier ) {
        $tier    = self::normalize_tier( $tier );
        $partner = self::placeholder_partner( $tier );
        $eyebrow = __( 'Your brand here', 'creativewings-core' );
        return sprintf(
            '<div class="cw-partner-placeholder cw-partner-placeholder--%1$s" role="img" aria-label="%2$s"><span class="cw-partner-placeholder__eyebrow">%3$s</span><span class="cw-partner-placeholder__name">%4$s</span></div>',
            esc_attr( $tier ),
            esc_attr( $partner['name'] ),
            esc_html( $eyebrow ),
            esc_html( $partner['name'] )
        );
    }

    /**
     * Partners by tier, optionally filling empty Champion/Hero (and empty band-3) with placeholders.
     *
     * @param int  $product_id
     * @param bool $include_placeholders
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function partners_by_tier_with_placeholders( $product_id, $include_placeholders = false ) {
        $grouped = [];
        foreach ( self::TIER_ORDER as $tier ) {
            $grouped[ $tier ] = [];
        }
        foreach ( self::get_partners( $product_id ) as $row ) {
            $grouped[ self::normalize_tier( $row['tier'] ?? 'local' ) ][] = $row;
        }
        if ( ! $include_placeholders ) {
            return array_filter(
                $grouped,
                static function ( $rows ) {
                    return ! empty( $rows );
                }
            );
        }
        if ( empty( $grouped['champion'] ) ) {
            $grouped['champion'] = [
                self::placeholder_partner( 'champion', 0 ),
                self::placeholder_partner( 'champion', 1 ),
            ];
        }
        if ( empty( $grouped['hero'] ) ) {
            $grouped['hero'] = [
                self::placeholder_partner( 'hero', 0 ),
                self::placeholder_partner( 'hero', 1 ),
            ];
        }
        if ( empty( $grouped['community'] ) ) {
            $grouped['community'] = [
                self::placeholder_partner( 'community', 0 ),
            ];
        }
        if ( empty( $grouped['local'] ) ) {
            $grouped['local'] = [
                self::placeholder_partner( 'local', 0 ),
                self::placeholder_partner( 'local', 1 ),
                self::placeholder_partner( 'local', 2 ),
            ];
        }
        if ( empty( $grouped['inkind'] ) ) {
            $grouped['inkind'] = [
                self::placeholder_partner( 'inkind', 0 ),
                self::placeholder_partner( 'inkind', 1 ),
            ];
        }
        return array_filter(
            $grouped,
            static function ( $rows ) {
                return ! empty( $rows );
            }
        );
    }

    /**
     * Logo cell or placeholder tile for a partner row.
     *
     * @param array<string,mixed> $partner
     * @param string              $size
     * @return string
     */
    public static function render_partner_cell_html( array $partner, $size = 'medium' ) {
        if ( ! empty( $partner['is_placeholder'] ) ) {
            return self::render_placeholder_html( (string) ( $partner['tier'] ?? 'local' ) );
        }
        return self::render_partner_logo_html( $partner, $size );
    }

    /**
     * Render a single partner logo cell (linked when URL present).
     *
     * @param array{name:string,attachment_id:int,url:string} $partner
     * @param string $size Attachment size.
     * @return string
     */
    public static function render_partner_logo_html( array $partner, $size = 'medium' ) {
        if ( ! empty( $partner['is_placeholder'] ) ) {
            return self::render_placeholder_html( (string) ( $partner['tier'] ?? 'local' ) );
        }
        $aid = (int) ( $partner['attachment_id'] ?? 0 );
        $logo_url = $aid ? wp_get_attachment_image_url( $aid, $size ) : '';
        if ( ! $logo_url ) {
            return '';
        }
        $name = (string) ( $partner['name'] ?? '' );
        $url  = ! empty( $partner['url'] ) ? (string) $partner['url'] : '';
        $img  = sprintf(
            '<img src="%s" alt="%s" loading="lazy" decoding="async">',
            esc_url( $logo_url ),
            esc_attr( $name )
        );
        if ( $url !== '' ) {
            return sprintf(
                '<a href="%s" class="cwd-partner-logo" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
                esc_url( $url ),
                esc_attr( $name ),
                $img
            );
        }
        return sprintf(
            '<div class="cwd-partner-logo" title="%s">%s</div>',
            esc_attr( $name ),
            $img
        );
    }

    /**
     * Markup for Champion “Presented by” strip.
     *
     * @param int  $product_id
     * @param bool $placeholders Fill empty Champion with demo tiles.
     * @return string
     */
    public static function render_champion_strip_html( $product_id, $placeholders = false ) {
        $by_tier = self::partners_by_tier_with_placeholders( $product_id, (bool) $placeholders );
        $champs  = $by_tier['champion'] ?? [];
        if ( empty( $champs ) ) {
            return '';
        }
        $labels = self::tier_labels();
        ob_start();
        ?>
        <div class="cwd-champion-strip" aria-label="<?php echo esc_attr( $labels['champion'] ); ?>">
            <p class="cwd-champion-strip__label"><?php echo esc_html( $labels['champion'] ); ?></p>
            <div class="cwd-champion-strip__logos">
                <?php foreach ( $champs as $partner ) :
                    $cell = self::render_partner_cell_html( $partner, 'large' );
                    if ( $cell === '' ) {
                        continue;
                    }
                    ?>
                    <div class="cwd-champion-strip__item"><?php echo $cell; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Tiered partners in ONE section (Champion excluded — hero banner only).
     * Packages stay visually separate via size rows, not multiple H2 sections.
     *
     * @param int  $product_id
     * @param bool $include_champion Include champion row (sponsors page; not campaign body).
     * @param bool $placeholders     Fill empty top tiers with demo tiles.
     * @return string
     */
    public static function render_tiered_partners_html( $product_id, $include_champion = false, $placeholders = false ) {
        $by_tier = self::partners_by_tier_with_placeholders( $product_id, (bool) $placeholders );
        if ( empty( $by_tier ) ) {
            return '';
        }

        $labels = self::tier_labels();
        $rows_out = [];
        foreach ( self::TIER_ORDER as $tier ) {
            if ( $tier === 'champion' && ! $include_champion ) {
                continue;
            }
            $rows = $by_tier[ $tier ] ?? [];
            if ( empty( $rows ) ) {
                continue;
            }
            $rows_out[ $tier ] = $rows;
        }
        if ( empty( $rows_out ) ) {
            return '';
        }

        $section_title = __( 'Our Partners', 'creativewings-core' );
        ob_start();
        ?>
        <section class="cwd-section cwd-partners-section cwd-partners-combined">
            <h2 class="cwd-section-title">
                <i class="fas fa-handshake" aria-hidden="true"></i>
                <?php echo esc_html( $section_title ); ?>
            </h2>
            <?php foreach ( $rows_out as $tier => $rows ) :
                $pkg_label = $labels[ $tier ] ?? $tier;
                if ( $tier === 'champion' ) {
                    $pkg_label = __( 'Champion', 'creativewings-core' );
                }
                $size = ( $tier === 'champion' || $tier === 'hero' || $tier === 'community' ) ? 'large' : 'medium';
                ?>
                <div class="cwd-partners-package cwd-partners-package--<?php echo esc_attr( $tier ); ?>">
                    <p class="cwd-partners-package__label"><?php echo esc_html( $pkg_label ); ?></p>
                    <?php
                    echo self::render_partners_grid_html( $tier, $rows, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param int $product_id
     * @return array<int,array{name:string,title:string,attachment_id:int,bio:string,url:string}>
     */
    public static function get_mentors( $product_id ) {
        $raw = get_post_meta( (int) $product_id, self::META_MENTORS, true );
        return self::sanitize_mentors( is_array( $raw ) ? $raw : [] );
    }

    /**
     * @param array $raw
     * @param array $existing
     * @param string $name_key
     * @return array
     */
    private static function preserve_existing_attachments( array $raw, array $existing, $name_key = 'name' ) {
        if ( empty( $existing ) ) {
            return $raw;
        }
        $by_name = [];
        foreach ( $existing as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $aid = (int) ( $row['attachment_id'] ?? 0 );
            $key = strtolower( sanitize_text_field( (string) ( $row[ $name_key ] ?? '' ) ) );
            if ( $aid > 0 && $key !== '' ) {
                $by_name[ $key ] = $aid;
            }
        }
        foreach ( $raw as $idx => $row ) {
            if ( ! is_array( $row ) || (int) ( $row['attachment_id'] ?? 0 ) > 0 ) {
                continue;
            }
            $key = strtolower( sanitize_text_field( (string) ( $row[ $name_key ] ?? '' ) ) );
            if ( $key !== '' && isset( $by_name[ $key ] ) ) {
                $raw[ $idx ]['attachment_id'] = $by_name[ $key ];
            }
        }
        return $raw;
    }

    /**
     * @param int    $campaign_id
     * @param int    $user_id
     * @param array  $raw
     * @param string $files_key e.g. cw_supporting_partner_file
     * @return array
     */
    private static function merge_uploaded_files( $campaign_id, $user_id, array $raw, $files_key ) {
        if (
            empty( $_FILES[ $files_key ]['name'] )
            || ! is_array( $_FILES[ $files_key ]['name'] )
        ) {
            return $raw;
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        foreach ( array_keys( $_FILES[ $files_key ]['name'] ) as $idx ) {
            if ( empty( $_FILES[ $files_key ]['name'][ $idx ] ) ) {
                continue;
            }
            if ( (int) ( $_FILES[ $files_key ]['error'][ $idx ] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
                continue;
            }

            $single = [
                'name'     => $_FILES[ $files_key ]['name'][ $idx ],
                'type'     => $_FILES[ $files_key ]['type'][ $idx ],
                'tmp_name' => $_FILES[ $files_key ]['tmp_name'][ $idx ],
                'error'    => $_FILES[ $files_key ]['error'][ $idx ],
                'size'     => $_FILES[ $files_key ]['size'][ $idx ],
            ];
            $_FILES['cw_showcase_one'] = $single;
            $aid = media_handle_upload( 'cw_showcase_one', (int) $campaign_id );
            unset( $_FILES['cw_showcase_one'] );
            if ( is_wp_error( $aid ) ) {
                continue;
            }

            $aid = (int) $aid;
            if ( class_exists( 'CW' ) ) {
                CW::tag_plugin_media( $aid );
            }
            wp_update_post(
                [
                    'ID'          => $aid,
                    'post_author' => (int) $user_id,
                ]
            );
            if ( class_exists( 'CW_Image_Optimizer' ) ) {
                CW_Image_Optimizer::optimize_attachment( $aid, 'campaign_thumb' );
            }

            if ( isset( $raw[ $idx ] ) && is_array( $raw[ $idx ] ) ) {
                $raw[ $idx ]['attachment_id'] = $aid;
            } elseif ( isset( $raw[ (string) $idx ] ) && is_array( $raw[ (string) $idx ] ) ) {
                $raw[ (string) $idx ]['attachment_id'] = $aid;
            }
        }

        return $raw;
    }

    /**
     * @param array $raw
     * @param int   $user_id
     * @param int   $campaign_id
     * @return array
     */
    private static function validate_attachment_rows( array $raw, $user_id, $campaign_id ) {
        foreach ( $raw as $idx => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $aid = (int) ( $row['attachment_id'] ?? 0 );
            if ( $aid <= 0 ) {
                continue;
            }
            $ok = class_exists( 'CW_Design_Submission' )
                ? CW_Design_Submission::user_can_use_attachment( $aid, $user_id, $campaign_id )
                : ( (int) get_post_field( 'post_author', $aid ) === (int) $user_id );
            if ( ! $ok ) {
                $raw[ $idx ]['attachment_id'] = 0;
            }
        }
        return $raw;
    }

    /**
     * @param int $campaign_id
     * @param int $user_id
     */
    public static function persist_from_wizard( $campaign_id, $user_id ) {
        $campaign_id = (int) $campaign_id;
        $user_id     = (int) $user_id;
        if ( $campaign_id <= 0 ) {
            return;
        }

        $existing_partners = self::get_partners( $campaign_id );
        $existing_mentors  = self::get_mentors( $campaign_id );

        $raw_partners = isset( $_POST['cw_supporting_partners'] ) && is_array( $_POST['cw_supporting_partners'] )
            ? wp_unslash( $_POST['cw_supporting_partners'] )
            : [];
        $raw_mentors = isset( $_POST['cw_mentors'] ) && is_array( $_POST['cw_mentors'] )
            ? wp_unslash( $_POST['cw_mentors'] )
            : [];

        $raw_partners = self::preserve_existing_attachments( $raw_partners, $existing_partners );
        $raw_mentors  = self::preserve_existing_attachments( $raw_mentors, $existing_mentors );

        $raw_partners = self::merge_uploaded_files( $campaign_id, $user_id, $raw_partners, 'cw_supporting_partner_file' );
        $raw_mentors  = self::merge_uploaded_files( $campaign_id, $user_id, $raw_mentors, 'cw_mentor_photo_file' );

        $raw_partners = self::validate_attachment_rows( $raw_partners, $user_id, $campaign_id );
        $raw_mentors  = self::validate_attachment_rows( $raw_mentors, $user_id, $campaign_id );

        update_post_meta( $campaign_id, self::META_PARTNERS, self::sanitize_partners( $raw_partners ) );
        update_post_meta( $campaign_id, self::META_MENTORS, self::sanitize_mentors( $raw_mentors ) );
    }
}
