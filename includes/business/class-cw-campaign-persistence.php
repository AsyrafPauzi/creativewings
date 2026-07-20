<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared campaign save logic for wizard POST and JSON import.
 */
class CW_Campaign_Persistence {

    public static function get_sdg_map() {
        return class_exists( 'CW_Business' ) ? CW_Business::get_sdg_map() : [];
    }

    /**
     * @param array $data Campaign fields.
     * @param int   $product_id 0 = create draft.
     * @param int   $author_id Post author / organizer.
     * @return int|WP_Error Product ID.
     */
    public static function save_from_array( array $data, $product_id = 0, $author_id = 0 ) {
        if ( ! $author_id ) {
            $author_id = get_current_user_id();
        }

        $title   = sanitize_text_field( $data['post_title'] ?? '' );
        $content = isset( $data['post_content'] ) ? wp_kses_post( $data['post_content'] ) : '';

        if ( ! $title ) {
            return new WP_Error( 'cw_missing_title', 'Campaign title is required.' );
        }

        $is_new          = ( (int) $product_id === 0 );
        $can_publish     = current_user_can( 'manage_woocommerce' );
        $incoming_status = isset( $data['post_status'] ) ? sanitize_text_field( $data['post_status'] ) : '';

        $args = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_type'    => 'product',
        ];

        if ( $is_new ) {
            // New campaign — the creator becomes the owner.
            $args['post_author'] = (int) $author_id;
            // New campaigns: business users always go to pending review; admins keep whatever
            // they pass (or fall back to pending so nothing accidentally publishes silently).
            $args['post_status'] = $can_publish ? ( $incoming_status ?: 'pending' ) : 'pending';
            $product_id          = wp_insert_post( $args, true );
        } else {
            // EXISTING campaign — preserve whoever currently owns it. If an
            // admin previously reassigned ownership to another business via
            // the WP admin "Campaign Owner" metabox, that transfer must NOT
            // be silently undone just because the new owner (or even the
            // original admin) re-saves the campaign through the front-end
            // business form. The only way to change post_author once a
            // campaign exists is the admin metabox.
            $args['ID'] = (int) $product_id;
            $existing_author = (int) get_post_field( 'post_author', (int) $product_id );
            if ( $existing_author > 0 ) {
                $args['post_author'] = $existing_author;
            } else {
                $args['post_author'] = (int) $author_id;
            }
            // Existing campaign: if a non-admin edits a live ("publish") campaign, kick it back
            // to pending for re-review. Otherwise (admin, or already-non-publish) leave the
            // status alone so admins can keep editing publish posts.
            if ( ! $can_publish && get_post_status( (int) $product_id ) === 'publish' ) {
                $args['post_status'] = 'pending';
            } elseif ( $can_publish && $incoming_status ) {
                $args['post_status'] = $incoming_status;
            }
            $product_id = wp_update_post( $args, true );
        }

        if ( is_wp_error( $product_id ) ) {
            return $product_id;
        }

        $product_id = (int) $product_id;

        if ( ! isset( $data['cw_enable_addons'] ) && ( ! empty( $data['addons'] ) || ! empty( $data['cw_addons'] ) ) ) {
            $data['cw_enable_addons'] = 'yes';
        }
        if ( ! isset( $data['cw_enable_age_brackets'] ) && ( ! empty( $data['age_brackets'] ) || ! empty( $data['cw_age_brackets'] ) ) ) {
            $data['cw_enable_age_brackets'] = 'yes';
        }
        if ( ! isset( $data['cw_enable_school_sponsors'] ) && ( ! empty( $data['schools'] ) || ! empty( $data['cw_school_sponsors'] ) || ! empty( $data['cw_campaign_serial'] ) ) ) {
            $data['cw_enable_school_sponsors'] = 'yes';
        }
        if ( ! isset( $data['cw_allow_multiple_participants'] ) ) {
            $max_p = (int) ( $data['cw_max_participants'] ?? 1 );
            $data['cw_allow_multiple_participants'] = $max_p > 1 ? 'yes' : 'no';
        }
        if ( ! isset( $data['cw_use_account_fullname'] ) ) {
            $data['cw_use_account_fullname'] = 'yes';
        }

        // Category: slug or term id.
        if ( ! empty( $data['product_cat'] ) ) {
            wp_set_object_terms( $product_id, (int) $data['product_cat'], 'product_cat' );
        } elseif ( ! empty( $data['product_cat_slug'] ) ) {
            $term = get_term_by( 'slug', sanitize_title( $data['product_cat_slug'] ), 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                wp_set_object_terms( $product_id, (int) $term->term_id, 'product_cat' );
            }
        }

        $visibility = $data['cw_visibility'] ?? 'visible';
        if ( $visibility === 'hidden' ) {
            wp_set_object_terms( $product_id, [ 'exclude-from-catalog', 'exclude-from-search' ], 'product_visibility' );
        } else {
            wp_set_object_terms( $product_id, [], 'product_visibility' );
        }

        $price = isset( $data['regular_price'] ) ? sanitize_text_field( $data['regular_price'] ) : '0';
        update_post_meta( $product_id, '_regular_price', $price );
        update_post_meta( $product_id, '_price', $price );
        update_post_meta( $product_id, '_virtual', 'yes' );

        // ── Organiser ownership ─────────────────────────────────────
        // The `organizer_id` meta is the canonical pointer used by the
        // organiser sidebar card, dashboard credits, e-mail signatures,
        // wallet payouts, etc. It must stay locked to the campaign's
        // creator (or whoever the admin transferred it to via the WP
        // admin metabox) even when somebody else — including an admin
        // saving on the organiser's behalf — re-saves the campaign
        // through the front-end business form.
        //
        // Resolution order (existing campaigns):
        //   1. Use the existing `organizer_id` meta if it's set.
        //   2. Fall back to `post_author` (which the persistence
        //      layer above already preserved correctly).
        //   3. Honour an explicit `$data['organizer_id']` only when
        //      it actually matches one of the two above — i.e. the
        //      caller is re-asserting the same owner. We never let a
        //      stale `$data['organizer_id']` from $_POST silently
        //      reassign ownership.
        // For brand-new campaigns we honour the supplied id (or fall
        // back to `$author_id`).
        if ( $is_new ) {
            $organizer_to_save = (int) ( $data['organizer_id'] ?? $author_id );
        } else {
            $existing_org    = (int) get_post_meta( $product_id, 'organizer_id', true );
            $existing_author = (int) get_post_field( 'post_author', $product_id );

            // Self-heal: if `organizer_id` got clobbered to an admin id by an
            // older version of this code (before the bugfix that stopped
            // overwriting it on every save), drag it back to whatever the
            // current post_author says — that's the canonical owner because
            // the admin metabox is the ONLY path that ever changes
            // post_author. If a campaign was transferred to a new business
            // owner, post_author already reflects that, and this branch
            // restores `organizer_id` to match.
            $org_belongs_to_business = false;
            if ( $existing_org > 0 && class_exists( 'CW_Roles' ) ) {
                $org_user = get_userdata( $existing_org );
                if ( $org_user && in_array( 'business_role', (array) $org_user->roles, true ) ) {
                    $org_belongs_to_business = true;
                }
            }

            if ( $existing_org > 0 && ( $org_belongs_to_business || $existing_org === $existing_author ) ) {
                $organizer_to_save = $existing_org;
            } elseif ( $existing_author > 0 ) {
                $organizer_to_save = $existing_author;
            } else {
                $organizer_to_save = (int) ( $data['organizer_id'] ?? $author_id );
            }
        }
        update_post_meta( $product_id, 'organizer_id', $organizer_to_save );

        $scalar_keys = [
            'submission_deadline', 'cw_total_prize_value', 'cw_min_participants', 'cw_max_participants',
            'cw_submission_start', 'cw_review_start', 'cw_final_event_date', 'cw_location_details',
            'cw_enable_certificate', 'cw_talk_speaker', 'cw_talk_type',
            'cw_cert_x', 'cw_cert_y', 'cw_cert_font_size', 'cw_cert_font_color', 'cw_cert_max_width', 'cw_cert_align',
            'cw_event_mode', 'cw_online_link', 'cw_multi_min', 'cw_multi_max',
            'cw_campaign_serial', 'cw_checkout_message_label',
            'cw_submissions_gallery_layout',
            'cw_enable_addons', 'cw_enable_age_brackets', 'cw_enable_school_sponsors',
            'cw_allow_multiple_participants', 'cw_use_account_fullname',
            'cw_design_picker_label', 'cw_design_artwork_w', 'cw_design_artwork_h',
            // Print-area window on the casing (visible front face). Saved as
            // integers via sanitize_text_field — JS treats any non-positive
            // value as "no crop" so blank entries are safe.
            'cw_design_print_x', 'cw_design_print_y', 'cw_design_print_w', 'cw_design_print_h',
            // Note: `cw_template_label` was a scalar legacy meta when templates
            // were single-file. With multi-template support it's now mirrored
            // from the first row of `cw_template_files` by CW_Campaign_Templates,
            // so we deliberately do NOT round-trip it through scalar persistence.
        ];
        foreach ( $scalar_keys as $k ) {
            if ( array_key_exists( $k, $data ) ) {
                if ( 'cw_submissions_gallery_layout' === $k ) {
                    $layout = sanitize_key( (string) $data[ $k ] );
                    update_post_meta( $product_id, $k, in_array( $layout, [ 'grid', 'map' ], true ) ? $layout : 'grid' );
                } else {
                    update_post_meta( $product_id, $k, sanitize_text_field( $data[ $k ] ) );
                }
            }
        }

        // Rich-text fields. `sanitize_text_field()` would strip every tag —
        // including the `<ul>/<ol>/<li>` an organiser pastes in for bullet
        // lists — so these go through `wp_kses_post()` which keeps the post-
        // content whitelist (lists, paragraphs, links, headings, emphasis).
        // We `wp_unslash` first because $_POST is magic-quote-slashed by
        // WordPress, otherwise saved HTML would contain literal `\"` etc.
        $rich_keys = [
            'cw_judging_criteria',   // "Judges & Criteria / Who Can Join" box
        ];
        foreach ( $rich_keys as $k ) {
            if ( array_key_exists( $k, $data ) ) {
                update_post_meta( $product_id, $k, wp_kses_post( wp_unslash( (string) $data[ $k ] ) ) );
            }
        }

        // Numeric prize amount — drives the site-wide [total_prize_money] sum.
        // Stored as a clean float (no symbols / commas) so SUM() works reliably.
        if ( array_key_exists( 'cw_total_prize_amount', $data ) ) {
            $amt_raw = (string) $data['cw_total_prize_amount'];
            // Strip everything except digits, decimal point, and leading minus.
            $amt_clean = preg_replace( '/[^0-9.\-]/', '', $amt_raw );
            $amt_val   = ( $amt_clean === '' || ! is_numeric( $amt_clean ) ) ? '' : (string) (float) $amt_clean;
            update_post_meta( $product_id, 'cw_total_prize_amount', $amt_val );
            // Invalidate the cached site-wide total so the header reflects the change.
            delete_transient( 'cw_total_prize_money_v4' );
        }

        if ( ! get_post_meta( $product_id, 'cw_campaign_serial', true ) ) {
            update_post_meta( $product_id, 'cw_campaign_serial', str_pad( (string) $product_id, 3, '0', STR_PAD_LEFT ) );
        } elseif ( ! empty( $data['cw_campaign_serial'] ) ) {
            update_post_meta( $product_id, 'cw_campaign_serial', sanitize_text_field( $data['cw_campaign_serial'] ) );
        }

        update_post_meta( $product_id, 'cw_enable_voting', ( ! empty( $data['cw_enable_voting'] ) && $data['cw_enable_voting'] !== 'no' ) ? 'yes' : 'no' );
        update_post_meta( $product_id, 'multiple_submissions', ! empty( $data['multiple_submissions'] ) && $data['multiple_submissions'] !== 'false' ? 'true' : 'false' );

        $toggle_keys = [
            'cw_enable_addons',
            'cw_enable_age_brackets',
            'cw_enable_school_sponsors',
            'cw_allow_multiple_participants',
            'cw_use_account_fullname',
            'cw_enable_certificate',
            'cw_show_submissions_gallery',
        ];
        foreach ( $toggle_keys as $tk ) {
            if ( array_key_exists( $tk, $data ) ) {
                update_post_meta( $product_id, $tk, self::is_yes( $data[ $tk ] ) ? 'yes' : 'no' );
            }
        }

        update_post_meta( $product_id, 'cw_enable_checkout_message', ( ! empty( $data['cw_enable_checkout_message'] ) && $data['cw_enable_checkout_message'] !== 'no' ) ? 'yes' : 'no' );
        update_post_meta( $product_id, 'cw_checkout_message_required', ! empty( $data['cw_checkout_message_required'] ) ? 'yes' : 'no' );
        $sco = $data['cw_school_coupons_optional'] ?? 'yes';
        update_post_meta( $product_id, 'cw_school_coupons_optional', ( $sco === 'yes' || $sco === true || $sco === '1' ) ? 'yes' : 'no' );

        if ( array_key_exists( 'guest_checkout_fields', $data ) || array_key_exists( 'cw_guest_checkout_fields', $data ) || ! empty( $data['_save_feature_blocks'] ) ) {
            $raw_modes = $data['guest_checkout_fields'] ?? $data['cw_guest_checkout_fields'] ?? [];
            $sanitized = class_exists( 'CW_Guest_Join' )
                ? CW_Guest_Join::sanitize_checkout_field_modes( $raw_modes )
                : [];
            update_post_meta( $product_id, 'cw_guest_checkout_fields', $sanitized );
        }

        if ( ! self::is_yes( $data['cw_allow_multiple_participants'] ?? 'no' ) ) {
            update_post_meta( $product_id, 'cw_min_participants', '1' );
            update_post_meta( $product_id, 'cw_max_participants', '1' );
        }

        if ( isset( $data['faq'] ) ) {
            self::save_repeater_meta( $product_id, 'faq', $data['faq'], 'question' );
        }
        if ( isset( $data['prizes'] ) || isset( $data['cw_prizes'] ) ) {
            self::save_repeater_meta( $product_id, 'prizes', $data['prizes'] ?? $data['cw_prizes'], 'prize_title' );
        }

        if ( array_key_exists( 'addons', $data ) || array_key_exists( 'cw_addons', $data ) || ! empty( $data['_save_feature_blocks'] ) ) {
            if ( self::is_yes( $data['cw_enable_addons'] ?? 'no' ) ) {
                self::save_addons( $product_id, $data['addons'] ?? $data['cw_addons'] ?? [] );
            } else {
                update_post_meta( $product_id, 'addon_products', [] );
            }
        }

        if ( isset( $data['sdg_goals'] ) ) {
            self::save_sdg_goals( $product_id, $data['sdg_goals'] );
        }
        if ( array_key_exists( 'custom_fields', $data ) || ! empty( $data['_save_feature_blocks'] ) ) {
            self::save_custom_fields( $product_id, $data['custom_fields'] ?? [] );
        }

        if ( array_key_exists( 'age_brackets', $data ) || array_key_exists( 'cw_age_brackets', $data ) || ! empty( $data['_save_feature_blocks'] ) ) {
            if ( self::is_yes( $data['cw_enable_age_brackets'] ?? 'no' ) ) {
                $brackets = $data['age_brackets'] ?? $data['cw_age_brackets'] ?? [];
                update_post_meta( $product_id, 'cw_age_brackets', self::sanitize_age_brackets( $brackets ) );
            } else {
                update_post_meta( $product_id, 'cw_age_brackets', [] );
            }
        }

        if ( array_key_exists( 'schools', $data ) || array_key_exists( 'cw_school_sponsors', $data ) || ! empty( $data['_save_feature_blocks'] ) ) {
            if ( self::is_yes( $data['cw_enable_school_sponsors'] ?? 'no' ) ) {
                $schools = $data['schools'] ?? $data['cw_school_sponsors'] ?? [];
                update_post_meta( $product_id, 'cw_school_sponsors', self::sanitize_schools( $schools ) );
                if ( class_exists( 'CW_Sponsor_Coupons' ) ) {
                    CW_Sponsor_Coupons::sync_campaign_coupons( $product_id );
                }
                if ( class_exists( 'CW_Staged_Submissions' ) ) {
                    CW_Staged_Submissions::sync_school_upload_tokens( $product_id );
                }
            } else {
                update_post_meta( $product_id, 'cw_school_sponsors', [] );
                delete_post_meta( $product_id, 'cw_school_upload_links' );
            }
        }

        if ( ! empty( $data['banner_attachment_id'] ) ) {
            set_post_thumbnail( $product_id, (int) $data['banner_attachment_id'] );
        }
        if ( ! empty( $data['cw_cert_template'] ) ) {
            update_post_meta( $product_id, 'cw_cert_template', esc_url_raw( $data['cw_cert_template'] ) );
        }

        if ( ! empty( $data['cw_enable_moderation'] ) && 'no' !== $data['cw_enable_moderation'] ) {
            update_post_meta( $product_id, 'cw_enable_moderation', 'yes' );
        } elseif ( array_key_exists( 'cw_enable_moderation', $data ) ) {
            delete_post_meta( $product_id, 'cw_enable_moderation' );
        }

        // ── KPI / Target progress bar ──
        // Mirror the WP admin metabox's "set when non-empty, delete otherwise"
        // semantics so the meta table looks identical regardless of which save
        // path (metabox, wizard POST, JSON import) wrote the row. The public
        // renderer in class-cw-shortcodes.php only paints the bar when
        // cw_kpi_show_progress === 'yes' AND cw_kpi_target > 0.
        if (
            array_key_exists( 'cw_kpi_show_progress', $data )
            || array_key_exists( 'cw_kpi_target', $data )
            || array_key_exists( 'cw_kpi_label', $data )
            || array_key_exists( 'cw_kpi_display_boost', $data )
            || ! empty( $data['_save_feature_blocks'] )
        ) {
            if ( self::is_yes( $data['cw_kpi_show_progress'] ?? 'no' ) ) {
                update_post_meta( $product_id, 'cw_kpi_show_progress', 'yes' );
            } else {
                delete_post_meta( $product_id, 'cw_kpi_show_progress' );
            }

            if ( array_key_exists( 'cw_kpi_target', $data ) ) {
                $kpi_target = max( 0, (int) $data['cw_kpi_target'] );
                if ( $kpi_target > 0 ) {
                    update_post_meta( $product_id, 'cw_kpi_target', $kpi_target );
                } else {
                    delete_post_meta( $product_id, 'cw_kpi_target' );
                }
            }

            if ( array_key_exists( 'cw_kpi_label', $data ) ) {
                $kpi_label = sanitize_text_field( (string) $data['cw_kpi_label'] );
                if ( $kpi_label !== '' ) {
                    update_post_meta( $product_id, 'cw_kpi_label', $kpi_label );
                } else {
                    delete_post_meta( $product_id, 'cw_kpi_label' );
                }
            }

            if ( array_key_exists( 'cw_kpi_display_boost', $data ) ) {
                $kpi_boost = max( 0, (int) $data['cw_kpi_display_boost'] );
                if ( $kpi_boost > 0 ) {
                    update_post_meta( $product_id, 'cw_kpi_display_boost', $kpi_boost );
                } else {
                    delete_post_meta( $product_id, 'cw_kpi_display_boost' );
                }
            }
        }

        // ── Design Submission ──
        // Toggle + variants repeater + chosen-default. Sanitisation lives on
        // CW_Design_Submission so the admin metabox and wizard share the same
        // canonical shape (unique slugs, empty rows dropped, etc.).
        if ( array_key_exists( 'cw_enable_design', $data ) || array_key_exists( 'cw_design_variants', $data ) || ! empty( $data['_save_feature_blocks'] ) ) {
            $design_on = self::is_yes( $data['cw_enable_design'] ?? 'no' );
            update_post_meta( $product_id, 'cw_enable_design', $design_on ? 'yes' : 'no' );

            if ( $design_on && class_exists( 'CW_Design_Submission' ) ) {
                $raw_variants = isset( $data['cw_design_variants'] ) && is_array( $data['cw_design_variants'] )
                    ? $data['cw_design_variants']
                    : [];
                $variants = CW_Design_Submission::sanitize_variants( $raw_variants );
                update_post_meta( $product_id, 'cw_design_variants', $variants );

                $default = isset( $data['cw_design_default_variant'] )
                    ? sanitize_title( (string) $data['cw_design_default_variant'] )
                    : '';
                $valid_default = '';
                foreach ( $variants as $v ) {
                    if ( ( $v['slug'] ?? '' ) === $default ) {
                        $valid_default = $default;
                        break;
                    }
                }
                if ( $valid_default === '' && ! empty( $variants ) ) {
                    $valid_default = (string) $variants[0]['slug'];
                }
                update_post_meta( $product_id, 'cw_design_default_variant', $valid_default );
            } else {
                update_post_meta( $product_id, 'cw_design_variants', [] );
                update_post_meta( $product_id, 'cw_design_default_variant', '' );
            }
        }

        if ( class_exists( 'CW_Campaign_Resolver' ) ) {
            CW_Campaign_Resolver::flush_serial_cache( $product_id );
        }

        return $product_id;
    }

    /**
     * Build array from wizard $_POST.
     */
    public static function array_from_post() {
        $data = [
            'post_title'                    => $_POST['post_title'] ?? '',
            'post_content'                  => $_POST['post_content'] ?? '',
            'product_cat'                   => $_POST['product_cat'] ?? '',
            'cw_visibility'                 => $_POST['cw_visibility'] ?? 'visible',
            'regular_price'                 => $_POST['regular_price'] ?? '0',
            // Note: do NOT seed `organizer_id` from get_current_user_id() here.
            // For existing campaigns the persistence layer reads the stored
            // owner from meta / post_author so an admin (or anyone else with
            // edit access) saving on the organiser's behalf doesn't silently
            // become the new organiser. For brand-new campaigns save_from_array()
            // falls back to the `$author_id` argument, which the caller passes
            // in (and which is the actual creator's user id).
            'submission_deadline'           => $_POST['submission_deadline'] ?? '',
            'cw_total_prize_value'          => $_POST['cw_total_prize_value'] ?? '',
            'cw_total_prize_amount'         => $_POST['cw_total_prize_amount'] ?? '',
            'cw_min_participants'           => $_POST['cw_min_participants'] ?? '',
            'cw_max_participants'           => $_POST['cw_max_participants'] ?? '',
            'cw_submission_start'           => $_POST['cw_submission_start'] ?? '',
            'cw_review_start'               => $_POST['cw_review_start'] ?? '',
            'cw_final_event_date'           => $_POST['cw_final_event_date'] ?? '',
            'cw_location_details'           => $_POST['cw_location_details'] ?? '',
            'cw_enable_certificate'         => isset( $_POST['cw_enable_certificate'] ) ? 'yes' : 'no',
            'cw_judging_criteria'           => $_POST['cw_judging_criteria'] ?? '',
            'cw_talk_speaker'               => $_POST['cw_talk_speaker'] ?? '',
            'cw_event_mode'                 => $_POST['cw_event_mode'] ?? '',
            'cw_online_link'                => $_POST['cw_online_link'] ?? '',
            'cw_multi_min'                  => $_POST['cw_multi_min'] ?? '',
            'cw_multi_max'                  => $_POST['cw_multi_max'] ?? '',
            'cw_enable_voting'              => isset( $_POST['cw_enable_voting'] ) ? 'yes' : 'no',
            'multiple_submissions'          => isset( $_POST['multiple_submissions'] ) ? 'true' : 'false',
            'cw_enable_checkout_message'    => isset( $_POST['cw_enable_checkout_message'] ) ? 'yes' : 'no',
            'cw_checkout_message_label'     => $_POST['cw_checkout_message_label'] ?? '',
            'cw_checkout_message_required'  => isset( $_POST['cw_checkout_message_required'] ) ? 'yes' : 'no',
            'cw_school_coupons_optional'    => isset( $_POST['cw_school_coupons_optional'] ) ? 'yes' : 'no',
            'cw_campaign_serial'            => $_POST['cw_campaign_serial'] ?? '',
            'cw_enable_addons'              => isset( $_POST['cw_enable_addons'] ) ? 'yes' : 'no',
            'cw_enable_age_brackets'        => isset( $_POST['cw_enable_age_brackets'] ) ? 'yes' : 'no',
            'cw_enable_school_sponsors'     => isset( $_POST['cw_enable_school_sponsors'] ) ? 'yes' : 'no',
            'cw_allow_multiple_participants' => isset( $_POST['cw_allow_multiple_participants'] ) ? 'yes' : 'no',
            'cw_use_account_fullname'       => isset( $_POST['cw_use_account_fullname'] ) ? 'yes' : 'no',
            'cw_show_submissions_gallery'   => isset( $_POST['cw_show_submissions_gallery'] ) ? 'yes' : 'no',
            'cw_submissions_gallery_layout' => ( isset( $_POST['cw_submissions_gallery_layout'] ) && 'map' === $_POST['cw_submissions_gallery_layout'] ) ? 'map' : 'grid',
            'cw_enable_design'              => isset( $_POST['cw_enable_design'] ) ? 'yes' : 'no',
            'cw_design_picker_label'        => $_POST['cw_design_picker_label'] ?? '',
            'cw_design_artwork_w'           => $_POST['cw_design_artwork_w'] ?? '',
            'cw_design_artwork_h'           => $_POST['cw_design_artwork_h'] ?? '',
            'cw_design_print_x'             => $_POST['cw_design_print_x'] ?? '',
            'cw_design_print_y'             => $_POST['cw_design_print_y'] ?? '',
            'cw_design_print_w'             => $_POST['cw_design_print_w'] ?? '',
            'cw_design_print_h'             => $_POST['cw_design_print_h'] ?? '',
            'cw_design_default_variant'     => $_POST['cw_design_default_variant'] ?? '',
            // Participant templates are persisted by CW_Business_Save directly
            // (multi-file repeater under `cw_templates[idx]` + flat
            // `cw_template_file_<idx>` $_FILES keys). Nothing to pipe through here.
            'cw_kpi_show_progress'          => isset( $_POST['cw_kpi_show_progress'] ) ? 'yes' : 'no',
            'cw_kpi_target'                 => $_POST['cw_kpi_target'] ?? '',
            'cw_kpi_label'                  => $_POST['cw_kpi_label'] ?? '',
            'cw_kpi_display_boost'          => $_POST['cw_kpi_display_boost'] ?? '',
            '_save_feature_blocks'          => true,
        ];

        if ( isset( $_POST['cw_design_variants'] ) && is_array( $_POST['cw_design_variants'] ) ) {
            $data['cw_design_variants'] = $_POST['cw_design_variants'];
        }

        if ( ! self::is_yes( $data['cw_allow_multiple_participants'] ) ) {
            $data['cw_min_participants'] = '1';
            $data['cw_max_participants'] = '1';
        }

        if ( isset( $_POST['cw_faq'] ) ) {
            $data['faq'] = $_POST['cw_faq'];
        }
        if ( isset( $_POST['cw_prizes'] ) ) {
            $data['prizes'] = $_POST['cw_prizes'];
        }
        $data['addons']        = isset( $_POST['cw_addons'] ) && is_array( $_POST['cw_addons'] ) ? $_POST['cw_addons'] : [];
        if ( isset( $_POST['sdg_goals'] ) ) {
            $data['sdg_goals'] = $_POST['sdg_goals'];
        }
        $data['custom_fields'] = isset( $_POST['custom_fields'] ) && is_array( $_POST['custom_fields'] ) ? $_POST['custom_fields'] : [];
        $data['age_brackets']  = isset( $_POST['cw_age_brackets'] ) && is_array( $_POST['cw_age_brackets'] ) ? $_POST['cw_age_brackets'] : [];
        $data['schools']       = isset( $_POST['cw_school_sponsors'] ) && is_array( $_POST['cw_school_sponsors'] ) ? $_POST['cw_school_sponsors'] : [];
        $data['cw_guest_checkout_fields'] = isset( $_POST['cw_guest_checkout_fields'] ) && is_array( $_POST['cw_guest_checkout_fields'] )
            ? $_POST['cw_guest_checkout_fields']
            : [];
        $data['guest_checkout_fields'] = $data['cw_guest_checkout_fields'];

        return $data;
    }

    public static function is_yes( $v ) {
        return $v === 'yes' || $v === true || $v === '1' || $v === 1;
    }

    private static function save_repeater_meta( $pid, $meta_key, $rows, $required_field ) {
        $out = [];
        $i   = 0;
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            if ( ! empty( $row[ $required_field ] ) ) {
                $out[ 'item-' . $i ] = array_map( 'sanitize_text_field', $row );
                $i++;
            }
        }
        update_post_meta( $pid, $meta_key, $out );
    }

    private static function save_addons( $pid, $rows ) {
        $allowed_at = [ 'checkbox', 'text', 'textarea', 'number', 'email', 'phone', 'file', 'media', 'select' ];
        $a          = [];
        $i          = 0;
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['addon_title'] ) ) {
                continue;
            }
            $at = strtolower( trim( sanitize_text_field( $row['addon_type'] ?? 'checkbox' ) ) );
            if ( ! in_array( $at, $allowed_at, true ) ) {
                $at = 'checkbox';
            }
            $row['addon_type']  = $at;
            $row['addon_label'] = sanitize_text_field( $row['addon_label'] ?? '' );
            $row['addon_opts']  = sanitize_text_field( $row['addon_opts'] ?? '' );
            $a[ 'item-' . $i ]  = $row;
            $i++;
        }
        update_post_meta( $pid, 'addon_products', $a );
    }

    private static function save_sdg_goals( $pid, $selected ) {
        $map = self::get_sdg_map();
        if ( is_array( $selected ) && isset( $selected[0] ) && is_string( $selected[0] ) && ! is_numeric( $selected[0] ) ) {
            $bool = [];
            foreach ( $map as $id => $name ) {
                $bool[ $name ] = in_array( $name, $selected, true ) ? 'true' : 'false';
            }
            update_post_meta( $pid, 'sdg_goals', $bool );
            return;
        }
        $selected_ids = array_map( 'intval', (array) $selected );
        $bool         = [];
        foreach ( $map as $id => $name ) {
            $bool[ $name ] = in_array( (int) $id, $selected_ids, true ) ? 'true' : 'false';
        }
        update_post_meta( $pid, 'sdg_goals', $bool );
    }

    private static function save_custom_fields( $pid, $rows ) {
        $allowed_cf = [ 'text', 'textarea', 'number', 'email', 'phone', 'file', 'media', 'select', 'wysiwyg' ];
        // Only these field types support a word-count constraint. Numbers,
        // files, dropdowns, etc. don't have a meaningful word count so we
        // silently drop min/max for them at save time.
        $word_count_types = [ 'text', 'textarea', 'wysiwyg' ];
        $fields     = [];
        foreach ( (array) $rows as $f ) {
            if ( empty( $f['label'] ) ) {
                continue;
            }
            $t = strtolower( trim( sanitize_text_field( $f['type'] ?? 'text' ) ) );
            if ( ! in_array( $t, $allowed_cf, true ) ) {
                $t = 'text';
            }

            // Word-count limits. Stored as non-negative integers, 0 = no
            // limit. Max is clamped to be ≥ min so a misconfiguration
            // like min=50 max=10 can never block a participant.
            $min_w = 0;
            $max_w = 0;
            if ( in_array( $t, $word_count_types, true ) ) {
                $min_w = isset( $f['min_words'] ) ? max( 0, (int) $f['min_words'] ) : 0;
                $max_w = isset( $f['max_words'] ) ? max( 0, (int) $f['max_words'] ) : 0;
                if ( $max_w > 0 && $max_w < $min_w ) {
                    $max_w = $min_w;
                }
            }

            $fields[] = [
                'label'     => sanitize_text_field( $f['label'] ),
                'type'      => $t,
                'opts'      => isset( $f['opts'] ) ? sanitize_text_field( $f['opts'] ) : '',
                'required'  => ! empty( $f['required'] ) ? 1 : 0,
                'min_words' => $min_w,
                'max_words' => $max_w,
            ];
        }
        update_post_meta( $pid, 'cw_custom_fields', array_values( $fields ) );
    }

    public static function sanitize_age_brackets( $rows ) {
        $out = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['label'] ) ) {
                continue;
            }
            $out[] = [
                'label'            => sanitize_text_field( $row['label'] ),
                'min_age'          => (int) ( $row['min_age'] ?? 0 ),
                'max_age'          => (int) ( $row['max_age'] ?? 99 ),
                'product_cat_slug' => sanitize_title( $row['product_cat_slug'] ?? '' ),
                'key'              => sanitize_key( $row['key'] ?? sanitize_title( $row['label'] ) ),
            ];
        }
        return $out;
    }

    public static function sanitize_schools( $rows ) {
        $out = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['school_code'] ) ) {
                continue;
            }
            $out[] = [
                'school_code'  => preg_replace( '/\D/', '', $row['school_code'] ),
                'school_name'  => sanitize_text_field( $row['school_name'] ?? '' ),
                'coupon_code'  => sanitize_text_field( $row['coupon_code'] ?? '' ),
            ];
        }
        return $out;
    }
}
