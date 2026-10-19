<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Dashboard_Contestant {

    public function __construct() {
        // 1. Tab Content Injection
        add_action( 'woocommerce_account_cw-activities_endpoint', [ $this, 'render_activities' ] );
        add_action( 'woocommerce_account_cw-upgrade_endpoint', [ $this, 'render_upgrade' ] );
        add_action( 'woocommerce_account_cw-settings_endpoint', [ $this, 'render_settings' ] );

        // 2. Form Handlers
        add_action( 'admin_post_cw_save_contestant_settings', [ $this, 'handle_save_settings' ] ); // Corrected handler name
        add_action( 'admin_post_cw_redeem_points_reward', [ $this, 'handle_redeem_reward' ] );
        add_action( 'wp_ajax_cw_points_merch_redeem', [ $this, 'ajax_merch_redeem' ] );
    }

    /* ==========================================================================
       1. OVERVIEW TAB (Fourth Screenshot - Nova UI)
       ========================================================================== */
    public function render_overview() {
        $uid = get_current_user_id();
        $u   = get_userdata( $uid );

        // Fetch entries with IDs only — avoids loading full WP_Post objects we don't use.
        $entries = get_posts([
            'post_type'      => ['cw_competition_entry', 'cw_activity_entry'],
            'meta_key'       => 'customer_id',
            'meta_value'     => $uid,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $entries_count = count( $entries );

        // Prime postmeta cache once so the foreach below resolves from memory, not DB.
        if ( ! empty( $entries ) ) {
            update_meta_cache( 'post', $entries );
        }

        // Count entries by status for Pending Review — competition campaigns only.
        $pending_review_count = 0;
        foreach($entries as $eid) {
            $entry_pid = (int) get_post_meta( $eid, 'product_id', true );
            if ( class_exists( 'CW_Shop' ) && ! CW_Shop::campaign_is_judged( $entry_pid ) ) {
                continue;
            }
            if (get_post_meta($eid, 'judge_score', true) === '' && get_post_meta($eid, 'upload_document', true) !== '') {
                $pending_review_count++;
            }
        }

        // CRITICAL FIX: Base URL for View All Activities link
        $base_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $activities_url = add_query_arg( 'tab', 'activities', $base_url );
        $upgrade_url    = add_query_arg( 'tab', 'upgrade', $base_url );
        $explore_url    = add_query_arg( 'tab', 'explore', $base_url );
        $points_url     = add_query_arg( 'tab', 'points', $base_url );

        $points_balance = class_exists( 'CW_Points' ) ? CW_Points::get_balance( $uid ) : 0;
        $points_expiry  = class_exists( 'CW_Points' ) ? CW_Points::get_expires_at( $uid ) : '';
        $points_rank    = class_exists( 'CW_Points' ) ? CW_Points::get_user_rank( $uid ) : 0;

        $recent_entries = get_posts([
            'post_type'      => ['cw_competition_entry', 'cw_activity_entry'],
            'meta_key'       => 'customer_id',
            'meta_value'     => $uid,
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        // Prime caches once for the recent batch (meta + referenced products).
        if ( ! empty( $recent_entries ) ) {
            $recent_ids = array_map( static fn( $e ) => (int) $e->ID, $recent_entries );
            update_meta_cache( 'post', $recent_ids );
            $referenced_pids = [];
            foreach ( $recent_ids as $rid ) {
                $rpid = (int) get_post_meta( $rid, 'product_id', true );
                if ( $rpid ) $referenced_pids[ $rpid ] = true;
            }
            $referenced_pids = array_keys( $referenced_pids );
            if ( ! empty( $referenced_pids ) ) {
                update_meta_cache( 'post', $referenced_pids );
                update_object_term_cache( $referenced_pids, 'product' );
            }
        }

        // Build a usable list (skipping entries whose product was deleted)
        $renderable_recent = [];
        foreach ( $recent_entries as $e ) {
            $pid     = get_post_meta( $e->ID, 'product_id', true );
            $product = wc_get_product( $pid );
            if ( ! $product ) {
                continue;
            }

            $is_activity = $this->entry_is_activity( $e, (int) $pid );
            $score       = get_post_meta( $e->ID, 'judge_score', true );
            $comment     = get_post_meta( $e->ID, 'judge_comment', true );
            $details     = get_post_meta( $e->ID, 'participant_details', true );
            $is_judged   = class_exists( 'CW_Shop' ) ? CW_Shop::campaign_is_judged( $pid ) : true;

            if ( ! $is_judged ) {
                $status_label = __( 'Completed', 'creativewings-core' );
                $status_class = 'cw-status-completed';
            } elseif ( $score !== '' && $score > 0 ) {
                $status_label = __( 'Completed', 'creativewings-core' );
                $status_class = 'cw-status-completed';
            } else {
                $status_label = __( 'Pending', 'creativewings-core' );
                $status_class = 'cw-status-pending';
            }

            $date = get_the_date( 'Y-m-d', $e->ID );
            $cert_enabled  = get_post_meta( $pid, 'cw_enable_certificate', true ) === 'yes';
            $cert_available = $cert_enabled && class_exists( 'CW_Certificate' ) && CW_Certificate::entry_cert_available( $e->ID );
            $cert_eta_raw   = get_post_meta( $pid, 'submission_deadline', true );
            $cert_eta_label = $cert_eta_raw ? date_i18n( get_option( 'date_format', 'd M Y' ), strtotime( $cert_eta_raw ) ) : '';
            $cert_url       = ( $cert_enabled && class_exists( 'CW_Certificate' ) ) ? CW_Certificate::download_url( $e->ID ) : '';

            $modal_data = [
                'id'             => $e->ID,
                'title'          => $product->get_name(),
                'date'           => $date,
                'status'         => $status_label,
                'show_score'     => (bool) $is_judged,
                'score'          => $score !== '' ? $score : 'N/A',
                'comment'        => $comment ?: __( 'No feedback yet.', 'creativewings-core' ),
                'details'        => $this->filter_entry_details_for_modal( $details ),
                'cert_enabled'   => $cert_enabled,
                'cert_available' => $cert_available,
                'cert_eta'       => $cert_eta_label,
                'cert_url'       => $cert_url,
            ];

            $renderable_recent[] = [
                'entry'        => $e,
                'pid'          => $pid,
                'product'      => $product,
                'type_tag'     => $is_activity ? __( 'Activity', 'creativewings-core' ) : __( 'Competition', 'creativewings-core' ),
                'status_label' => $status_label,
                'status_class' => $status_class,
                'date'         => $date,
                'modal_json'   => wp_json_encode( $modal_data ),
            ];
        }

        ?>
        <div class="cw-content-wrapper cw-ov">
            <header class="cw-ov-welcome">
                <div class="cw-ov-welcome-copy">
                    <h2 class="cw-ov-welcome-title">
                        <?php
                        printf(
                            /* translators: %s: first name or display name */
                            esc_html__( 'Welcome back, %s', 'creativewings-core' ),
                            esc_html( $u->first_name ?: $u->display_name )
                        );
                        ?>
                    </h2>
                    <p class="cw-ov-welcome-sub">
                        <?php esc_html_e( 'Track your submissions, points, and next opportunities in one place.', 'creativewings-core' ); ?>
                    </p>
                </div>
                <div class="cw-ov-welcome-actions">
                    <a href="<?php echo esc_url( $explore_url ); ?>" class="cw-btn-primary cw-ov-btn-primary">
                        <?php esc_html_e( 'Explore Opportunities', 'creativewings-core' ); ?>
                    </a>
                    <button type="button" class="cw-ov-btn-secondary" id="cw-ov-link-code-open" aria-haspopup="dialog" aria-controls="cw-ov-link-modal">
                        <?php esc_html_e( 'Link a code', 'creativewings-core' ); ?>
                    </button>
                    <a href="<?php echo esc_url( $activities_url ); ?>" class="cw-ov-btn-secondary">
                        <?php esc_html_e( 'View activities', 'creativewings-core' ); ?>
                    </a>
                </div>
            </header>

            <div class="cw-ov-metrics" role="list">
                <div class="cw-ov-metric" role="listitem">
                    <p class="cw-ov-metric-value"><?php echo esc_html( number_format_i18n( $entries_count ) ); ?></p>
                    <p class="cw-ov-metric-label"><?php esc_html_e( 'Total submissions', 'creativewings-core' ); ?></p>
                </div>
                <div class="cw-ov-metric" role="listitem">
                    <p class="cw-ov-metric-value"><?php echo esc_html( number_format_i18n( $pending_review_count ) ); ?></p>
                    <p class="cw-ov-metric-label"><?php esc_html_e( 'Pending review', 'creativewings-core' ); ?></p>
                </div>
                <div class="cw-ov-metric" role="listitem">
                    <p class="cw-ov-metric-value">
                        <a href="<?php echo esc_url( $points_url ); ?>" class="cw-ov-metric-link">
                            <?php echo esc_html( number_format_i18n( $points_balance ) ); ?>
                        </a>
                    </p>
                    <p class="cw-ov-metric-label">
                        <?php esc_html_e( 'Points', 'creativewings-core' ); ?>
                        <?php if ( $points_rank > 0 ) : ?>
                            <span class="cw-ov-metric-meta">· #<?php echo (int) $points_rank; ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if ( $points_expiry && $points_balance > 0 ) : ?>
                        <p class="cw-ov-metric-hint">
                            <?php
                            printf(
                                /* translators: %s: expiry date */
                                esc_html__( 'Expire %s unless you join again', 'creativewings-core' ),
                                esc_html( date_i18n( get_option( 'date_format' ), strtotime( $points_expiry ) ) )
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="cw-ov-upgrade" role="listitem">
                    <p class="cw-ov-upgrade-title"><?php esc_html_e( 'Want to showcase your portfolio?', 'creativewings-core' ); ?></p>
                    <p class="cw-ov-upgrade-copy"><?php esc_html_e( 'Upgrade to Creator to build your public profile and share your work.', 'creativewings-core' ); ?></p>
                    <a href="<?php echo esc_url( $upgrade_url ); ?>" class="cw-btn-primary cw-ov-upgrade-btn">
                        <?php esc_html_e( 'Become a Creator', 'creativewings-core' ); ?>
                    </a>
                </div>
            </div>

            <section class="cw-ov-recent" aria-labelledby="cw-ov-recent-heading">
                <h3 id="cw-ov-recent-heading" class="cw-ov-recent-heading">
                    <?php esc_html_e( 'Recent submissions', 'creativewings-core' ); ?>
                </h3>

                <?php if ( ! empty( $renderable_recent ) ) : ?>
                    <div class="cw-ov-recent-table-wrap">
                        <table class="cw-ov-recent-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Submission', 'creativewings-core' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Type', 'creativewings-core' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Date', 'creativewings-core' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Status', 'creativewings-core' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $renderable_recent as $row ) : ?>
                                    <tr
                                        class="cw-ov-recent-row"
                                        tabindex="0"
                                        role="button"
                                        aria-label="<?php echo esc_attr( sprintf( __( 'View submission: %s', 'creativewings-core' ), $row['product']->get_name() ) ); ?>"
                                        data-cw-modal="<?php echo esc_attr( $row['modal_json'] ); ?>"
                                    >
                                        <td class="cw-ov-recent-col-name" data-label="<?php esc_attr_e( 'Submission', 'creativewings-core' ); ?>">
                                            <?php echo esc_html( $row['product']->get_name() ); ?>
                                        </td>
                                        <td class="cw-ov-recent-col-type" data-label="<?php esc_attr_e( 'Type', 'creativewings-core' ); ?>">
                                            <span class="cw-ov-type-pill"><?php echo esc_html( $row['type_tag'] ); ?></span>
                                        </td>
                                        <td class="cw-ov-recent-col-date" data-label="<?php esc_attr_e( 'Date', 'creativewings-core' ); ?>">
                                            <time datetime="<?php echo esc_attr( get_the_date( 'c', $row['entry']->ID ) ); ?>">
                                                <?php echo esc_html( $row['date'] ); ?>
                                            </time>
                                        </td>
                                        <td class="cw-ov-recent-col-status" data-label="<?php esc_attr_e( 'Status', 'creativewings-core' ); ?>">
                                            <span class="cw-status-badge <?php echo esc_attr( $row['status_class'] ); ?>">
                                                <?php echo esc_html( $row['status_label'] ); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="cw-ov-empty">
                        <p class="cw-ov-empty-title"><?php esc_html_e( 'No activities yet', 'creativewings-core' ); ?></p>
                        <p class="cw-ov-empty-desc"><?php esc_html_e( 'Join a competition or activity to see your submissions here.', 'creativewings-core' ); ?></p>
                        <a href="<?php echo esc_url( $explore_url ); ?>" class="cw-btn-primary">
                            <?php esc_html_e( 'Explore Opportunities', 'creativewings-core' ); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
        $this->render_overview_link_code_modal();
        $this->render_activity_detail_modal();
    }

    /**
     * Overview quick-enter modal for school submission codes.
     */
    private function render_overview_link_code_modal() {
        ?>
        <div id="cw-ov-link-modal" class="cw-ov-link-modal" role="dialog" aria-modal="true" aria-labelledby="cw-ov-link-modal-title" hidden>
            <div class="cw-ov-link-modal-backdrop" data-cw-ov-link-close tabindex="-1"></div>
            <div class="cw-ov-link-modal-panel">
                <button type="button" class="cw-ov-link-modal-close" data-cw-ov-link-close aria-label="<?php esc_attr_e( 'Close', 'creativewings-core' ); ?>">&times;</button>
                <h2 id="cw-ov-link-modal-title" class="cw-ov-link-modal-title"><?php esc_html_e( 'Link a submission code', 'creativewings-core' ); ?></h2>
                <p class="cw-ov-link-modal-sub"><?php esc_html_e( 'Enter the code from your school. One code per campaign.', 'creativewings-core' ); ?></p>
                <?php
                if ( function_exists( 'cw_core' ) && cw_core()->claim_flow ) {
                    cw_core()->claim_flow->render_quick_link_form( 'cw-ov-link-code-input' );
                }
                ?>
            </div>
        </div>
        <script>
        (function () {
            var modal = document.getElementById('cw-ov-link-modal');
            var openBtn = document.getElementById('cw-ov-link-code-open');
            if (!modal || !openBtn) return;
            var input = document.getElementById('cw-ov-link-code-input');
            var lastFocus = null;

            function openModal() {
                lastFocus = document.activeElement;
                modal.hidden = false;
                modal.classList.add('is-open');
                document.body.classList.add('cw-ov-link-modal-open');
                if (input) {
                    setTimeout(function () { input.focus(); }, 20);
                }
            }
            function closeModal() {
                modal.classList.remove('is-open');
                modal.hidden = true;
                document.body.classList.remove('cw-ov-link-modal-open');
                if (lastFocus && lastFocus.focus) lastFocus.focus();
            }
            openBtn.addEventListener('click', openModal);
            modal.querySelectorAll('[data-cw-ov-link-close]').forEach(function (el) {
                el.addEventListener('click', closeModal);
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Whether an entry belongs to an activity campaign (vs competition).
     *
     * @param WP_Post|int $entry Entry post or ID.
     * @param int         $product_id Campaign product ID.
     */
    private function entry_is_activity( $entry, $product_id ) {
        $post = is_object( $entry ) ? $entry : get_post( $entry );
        if ( $post && 'cw_activity_entry' === $post->post_type ) {
            return true;
        }
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return false;
        }
        if ( has_term( 'activities', 'product_cat', $product_id ) ) {
            return true;
        }
        $slugs = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
        if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
            return false;
        }
        foreach ( $slugs as $slug ) {
            if ( false !== strpos( (string) $slug, 'activit' ) ) {
                return true;
            }
        }
        return false;
    }

    public function render_link_submission() {
        if ( function_exists( 'cw_core' ) && cw_core()->claim_flow ) {
            cw_core()->claim_flow->render_endpoint();
            return;
        }
        echo '<p>' . esc_html__( 'Link submission is unavailable.', 'creativewings-core' ) . '</p>';
    }

    /* ==========================================================================
       2. ACTIVITIES TAB (Third Screenshot - Nova UI)
       ========================================================================== */
    public function render_activities() {
        $uid         = get_current_user_id();
        $base_url    = get_permalink( wc_get_page_id( 'myaccount' ) );
        $act_url     = add_query_arg( 'tab', 'activities', $base_url );
        $explore_url = add_query_arg( 'tab', 'explore', $base_url );
        $paged       = isset( $_GET['cw_page'] ) ? max( 1, intval( $_GET['cw_page'] ) ) : 1;
        $per_page    = 9;
        $scope       = sanitize_key( $_GET['scope'] ?? 'active' );
        if ( ! in_array( $scope, [ 'active', 'past' ], true ) ) {
            $scope = 'active';
        }

        // Load all of this user's entries, then split Active / Past before paging.
        $all_q = new WP_Query([
            'post_type'      => [ 'cw_competition_entry', 'cw_activity_entry' ],
            'meta_key'       => 'customer_id',
            'meta_value'     => $uid,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
        $all_entries = $all_q->posts;

        if ( ! empty( $all_entries ) ) {
            $entry_ids = array_map( static fn( $e ) => (int) $e->ID, $all_entries );
            update_meta_cache( 'post', $entry_ids );
            $referenced_pids = [];
            foreach ( $entry_ids as $eid ) {
                $rpid = (int) get_post_meta( $eid, 'product_id', true );
                if ( $rpid ) {
                    $referenced_pids[ $rpid ] = true;
                }
            }
            $referenced_pids = array_keys( $referenced_pids );
            if ( ! empty( $referenced_pids ) ) {
                update_meta_cache( 'post', $referenced_pids );
                update_object_term_cache( $referenced_pids, 'product' );
            }
        }

        $active_entries = [];
        $past_entries   = [];
        foreach ( $all_entries as $e ) {
            $pid = (int) get_post_meta( $e->ID, 'product_id', true );
            if ( ! $pid || ! wc_get_product( $pid ) ) {
                continue;
            }
            $score     = get_post_meta( $e->ID, 'judge_score', true );
            $is_judged = class_exists( 'CW_Shop' ) ? CW_Shop::campaign_is_judged( $pid ) : true;
            if ( $this->entry_is_past( $pid, $is_judged, $score ) ) {
                $past_entries[] = $e;
            } else {
                $active_entries[] = $e;
            }
        }

        $scoped       = ( 'past' === $scope ) ? $past_entries : $active_entries;
        $total_items  = count( $scoped );
        $total_pages  = max( 1, (int) ceil( $total_items / $per_page ) );
        $paged        = min( $paged, $total_pages );
        $entries      = array_slice( $scoped, ( $paged - 1 ) * $per_page, $per_page );
        $scope_base   = add_query_arg( 'scope', $scope, $act_url );
        $has_any      = ! empty( $all_entries );
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-portfolio-header cw-activities-header">
                <h2><?php esc_html_e( 'My Activities', 'creativewings-core' ); ?></h2>
                <a href="<?php echo esc_url( $explore_url ); ?>" class="cw-btn-primary cw-browse-new-btn"><i class="fas fa-bolt"></i> <?php esc_html_e( 'Browse Campaigns', 'creativewings-core' ); ?></a>
            </div>

            <?php if ( $has_any ) : ?>
            <div class="cw-scope-pills" role="tablist" aria-label="<?php esc_attr_e( 'Activity time filter', 'creativewings-core' ); ?>">
                <a href="<?php echo esc_url( add_query_arg( [ 'scope' => 'active', 'cw_page' => 1 ], $act_url ) ); ?>"
                   class="cw-scope-pill <?php echo 'active' === $scope ? 'is-active' : ''; ?>"
                   role="tab" aria-selected="<?php echo 'active' === $scope ? 'true' : 'false'; ?>">
                    <?php esc_html_e( 'Active', 'creativewings-core' ); ?>
                    <span class="cw-scope-count"><?php echo (int) count( $active_entries ); ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( [ 'scope' => 'past', 'cw_page' => 1 ], $act_url ) ); ?>"
                   class="cw-scope-pill <?php echo 'past' === $scope ? 'is-active' : ''; ?>"
                   role="tab" aria-selected="<?php echo 'past' === $scope ? 'true' : 'false'; ?>">
                    <?php esc_html_e( 'Past', 'creativewings-core' ); ?>
                    <span class="cw-scope-count"><?php echo (int) count( $past_entries ); ?></span>
                </a>
            </div>
            <?php endif; ?>

            <?php if ( $entries ) : ?>
            <div class="cw-activities-grid">
                <?php foreach ( $entries as $e ) :
                    $pid = get_post_meta( $e->ID, 'product_id', true );
                    $product = wc_get_product( $pid );
                    if ( ! $product ) {
                        continue;
                    }

                    $title         = $product->get_name();
                    $img           = get_the_post_thumbnail_url( $pid, 'medium' ) ?: CW_URL . 'assets/img/placeholder.jpg';
                    $date          = get_the_date( 'Y-m-d', $e->ID );
                    $score         = get_post_meta( $e->ID, 'judge_score', true );
                    $comment       = get_post_meta( $e->ID, 'judge_comment', true );
                    $entry_details = get_post_meta( $e->ID, 'participant_details', true );
                    $cert_enabled  = get_post_meta( $pid, 'cw_enable_certificate', true ) === 'yes';
                    $is_activity   = $this->entry_is_activity( $e, (int) $pid );
                    $type_tag      = $is_activity ? __( 'Activity', 'creativewings-core' ) : __( 'Competition', 'creativewings-core' );
                    $is_judged     = class_exists( 'CW_Shop' ) ? CW_Shop::campaign_is_judged( $pid ) : true;

                    if ( ! $is_judged ) {
                        $status_label = 'Completed'; $status_cls = 'completed';
                    } elseif ( $score !== '' && $score > 0 ) {
                        $status_label = 'Completed'; $status_cls = 'completed';
                    } else {
                        $status_label = 'Registered'; $status_cls = 'registered';
                    }

                    $cert_available = $cert_enabled && class_exists( 'CW_Certificate' ) && CW_Certificate::entry_cert_available( $e->ID );
                    $cert_eta_raw   = get_post_meta( $pid, 'submission_deadline', true );
                    $cert_eta_label = $cert_eta_raw ? date_i18n( get_option( 'date_format', 'd M Y' ), strtotime( $cert_eta_raw ) ) : '';
                    $cert_url       = ( $cert_enabled && class_exists( 'CW_Certificate' ) ) ? CW_Certificate::download_url( $e->ID ) : '';

                    $modal_json = wp_json_encode( [
                        'id'             => $e->ID,
                        'title'          => $title,
                        'date'           => $date,
                        'status'         => $status_label,
                        'show_score'     => (bool) $is_judged,
                        'score'          => $score ?: 'N/A',
                        'comment'        => $comment ?: 'No feedback yet.',
                        'details'        => $this->filter_entry_details_for_modal( $entry_details ),
                        'cert_enabled'   => $cert_enabled,
                        'cert_available' => $cert_available,
                        'cert_eta'       => $cert_eta_label,
                        'cert_url'       => $cert_url,
                    ] );
                ?>
                <div class="cw-activity-card">
                    <div class="cw-activity-image-wrap">
                        <img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" decoding="async">
                        <span class="cw-activity-type-badge"><?php echo esc_html( $type_tag ); ?></span>
                    </div>
                    <div class="cw-activity-info">
                        <h4><?php echo esc_html( $title ); ?></h4>
                        <div class="cw-activity-meta">
                            <span><i class="fas fa-calendar-alt"></i> <?php echo esc_html( $date ); ?></span>
                        </div>
                        <div class="cw-activity-footer">
                            <span class="cw-status-badge <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $status_label ); ?></span>
                            <button type="button" class="cw-btn-details cw-ov-btn-secondary" data-cw-modal="<?php echo esc_attr( $modal_json ); ?>"><?php esc_html_e( 'View Details', 'creativewings-core' ); ?></button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php $this->render_pagination( $paged, $total_pages, $scope_base ); ?>

            <?php elseif ( $has_any ) : ?>
                <div class="cw-recent-empty">
                    <h4 class="cw-recent-empty-title">
                        <?php
                        echo 'past' === $scope
                            ? esc_html__( 'No past activities yet', 'creativewings-core' )
                            : esc_html__( 'No active activities', 'creativewings-core' );
                        ?>
                    </h4>
                    <p class="cw-recent-empty-desc">
                        <?php
                        echo 'past' === $scope
                            ? esc_html__( 'Finished competitions and activities will show up here.', 'creativewings-core' )
                            : esc_html__( 'Join something new, or check Past for settled entries.', 'creativewings-core' );
                        ?>
                    </p>
                    <?php if ( 'active' === $scope ) : ?>
                    <a href="<?php echo esc_url( $explore_url ); ?>" class="cw-recent-empty-btn">
                        <i class="fas fa-bolt"></i> <?php esc_html_e( 'Explore Opportunities', 'creativewings-core' ); ?>
                    </a>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div class="cw-recent-empty">
                    <div class="cw-recent-empty-icon"><i class="fas fa-rocket"></i></div>
                    <h4 class="cw-recent-empty-title"><?php esc_html_e( "You haven't joined any activities yet", 'creativewings-core' ); ?></h4>
                    <p class="cw-recent-empty-desc"><?php esc_html_e( 'Browse the latest competitions and activities to get started.', 'creativewings-core' ); ?></p>
                    <a href="<?php echo esc_url( $explore_url ); ?>" class="cw-recent-empty-btn">
                        <i class="fas fa-bolt"></i> <?php esc_html_e( 'Explore Opportunities', 'creativewings-core' ); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $this->render_activity_detail_modal();
    }

    /**
     * Whether an entry belongs in the Past scope.
     * Past = campaign deadline passed, or judged campaign already scored.
     *
     * @param int        $product_id Campaign product ID.
     * @param bool       $is_judged  Whether campaign uses scoring.
     * @param string|int $score      Judge score meta.
     */
    private function entry_is_past( $product_id, $is_judged, $score ) {
        $deadline = (string) get_post_meta( (int) $product_id, 'submission_deadline', true );
        if ( $deadline !== '' && strtotime( $deadline ) < current_time( 'timestamp' ) ) {
            return true;
        }
        if ( $is_judged && $score !== '' && is_numeric( $score ) && (float) $score > 0 ) {
            return true;
        }
        return false;
    }  
    /**
     * Drop Artwork / file rows that have no uploaded file URL.
     *
     * @param mixed $details participant_details meta.
     * @return array
     */
    private function filter_entry_details_for_modal( $details ) {
        if ( ! is_array( $details ) ) {
            return [];
        }
        $out = [];
        foreach ( $details as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['label'] ) ) {
                continue;
            }
            $label = trim( (string) $item['label'] );
            $value = isset( $item['value'] ) ? trim( (string) $item['value'] ) : '';
            $is_file_row = (bool) preg_match( '/artwork|upload|file|media|image/i', $label );
            if ( $is_file_row && ! $this->entry_detail_has_file( $value ) ) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param string $value Detail cell value.
     */
    private function entry_detail_has_file( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value || '-' === $value || 0 === strcasecmp( $value, 'n/a' ) ) {
            return false;
        }
        if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
            return true;
        }
        return (bool) preg_match( '/\.(jpe?g|png|gif|webp|pdf|docx?)(\?|$)/i', $value );
    }

    // Helper to format the entry details into a clean HTML table
    private function format_entry_details_html_contestant($details) {
        $html = '<table class="cw-detail-table">';
        $details = $this->filter_entry_details_for_modal( $details );
        if (is_array($details)) {
            foreach($details as $item) {
                if (isset($item['label']) && isset($item['value'])) {
                    $value = esc_html($item['value']);
                    // Check if the value is a file URL and replace with link
                    if (filter_var($item['value'], FILTER_VALIDATE_URL) && preg_match('/\.(jpg|jpeg|png|pdf|doc|docx)$/i', $item['value'])) {
                        $value = '<a href="'.esc_url($item['value']).'" target="_blank" style="color:var(--cw-primary); text-decoration:underline;">View File</a>';
                    }
                    $html .= '<tr><td>'.esc_html($item['label']).'</td><td>'.$value.'</td></tr>';
                }
            }
        }
        $html .= '</table>';
        return $html;
    }
    
    // Method to Render the Modal HTML and JS Logic
    private function render_activity_detail_modal() {
        ?>
        <div id="cw-activity-detail-modal" class="cw-modal cw-adm" hidden>
            <div class="cw-adm-backdrop" data-cw-adm-close tabindex="-1"></div>
            <div class="cw-detail-box cw-adm-panel" role="dialog" aria-modal="true" aria-labelledby="modal-title">
                <button type="button" class="cw-adm-close" data-cw-adm-close aria-label="<?php esc_attr_e( 'Close', 'creativewings-core' ); ?>">&times;</button>

                <header class="cw-adm-header">
                    <p class="cw-adm-date" id="modal-date"></p>
                    <h3 class="cw-adm-title" id="modal-title"></h3>
                </header>

                <div class="cw-adm-metrics">
                    <div class="cw-adm-metric" id="modal-status-metric">
                        <span class="cw-adm-metric-label"><?php esc_html_e( 'Status', 'creativewings-core' ); ?></span>
                        <span class="cw-adm-metric-value" id="modal-status-text"></span>
                    </div>
                    <div class="cw-adm-metric" id="modal-score-metric">
                        <span class="cw-adm-metric-label"><?php esc_html_e( 'Score', 'creativewings-core' ); ?></span>
                        <span class="cw-adm-metric-value"><span id="modal-score"></span> / 100</span>
                    </div>
                </div>

                <div id="modal-certificate-bar" class="cw-adm-cert"></div>

                <section class="cw-adm-section" aria-labelledby="cw-adm-details-heading">
                    <h4 id="cw-adm-details-heading" class="cw-adm-section-title"><?php esc_html_e( 'Submission details', 'creativewings-core' ); ?></h4>
                    <div id="modal-details-table"></div>
                </section>

                <div id="modal-host-feedback"></div>

                <div class="cw-adm-footer">
                    <button type="button" class="cw-btn-primary" data-cw-adm-close><?php esc_html_e( 'Close', 'creativewings-core' ); ?></button>
                </div>
            </div>
        </div>

        <script>
        function formatDetailsTable(details) {
            let tableHtml = '<table class="cw-detail-table">';
            if (Array.isArray(details)) {
                details.forEach(item => {
                    const label = (item.label || '').toString();
                    let value = item.value == null ? '' : String(item.value).trim();
                    const isFileRow = /artwork|upload|file|media|image/i.test(label);
                    const hasFile = value !== '' && value !== '-' && value.toLowerCase() !== 'n/a'
                        && (/^https?:\/\//i.test(value) || /\.(jpe?g|png|gif|webp|pdf|docx?)(\?|$)/i.test(value));
                    if (isFileRow && !hasFile) {
                        return;
                    }
                    if (value && /\.(jpe?g|png|gif|webp|pdf|docx?)(\?|$)/i.test(value)) {
                        value = '<a href="' + value + '" target="_blank" rel="noopener">View file</a>';
                    }
                    tableHtml += '<tr><td>' + label + '</td><td>' + value + '</td></tr>';
                });
            }
            tableHtml += '</table>';
            return tableHtml;
        }

        function openContestantModal(modalDataJson) {
            const data = JSON.parse(modalDataJson);
            const $modal = jQuery('#cw-activity-detail-modal');

            jQuery('#modal-title').text(data.title);
            jQuery('#modal-date').text(data.date);

            const showScore = data.show_score === true || data.show_score === 1 || data.show_score === '1';
            const score = (data.score === 'N/A' || data.score === '') ? 'N/A' : data.score;
            jQuery('#modal-status-text').text(data.status);
            jQuery('#modal-score').text(score);

            const metrics = jQuery('.cw-adm-metrics');
            const statusMetric = jQuery('#modal-status-metric');
            const scoreMetric = jQuery('#modal-score-metric');
            statusMetric.removeClass('is-complete is-pending');
            scoreMetric.removeClass('is-complete is-pending');
            if (showScore) {
                scoreMetric.show();
                metrics.removeClass('cw-adm-metrics--status-only');
            } else {
                scoreMetric.hide();
                metrics.addClass('cw-adm-metrics--status-only');
            }
            if (data.status === 'Completed') {
                statusMetric.addClass('is-complete');
                scoreMetric.addClass('is-complete');
            } else {
                statusMetric.addClass('is-pending');
                scoreMetric.addClass('is-pending');
            }

            const certBar = jQuery('#modal-certificate-bar');
            if (data.cert_enabled && data.cert_available && data.cert_url) {
                certBar.html(
                    '<div class="cw-adm-cert-card">' +
                        '<div><strong>Certificate available</strong>' +
                        '<p>Download your certificate of completion.</p></div>' +
                        '<a href="' + data.cert_url + '" class="cw-btn-primary cw-adm-cert-btn" target="_blank" rel="noopener">Download</a>' +
                    '</div>'
                );
            } else if (data.cert_enabled && data.cert_eta) {
                certBar.html(
                    '<div class="cw-adm-cert-card is-soon">' +
                        '<div><strong>Certificate coming soon</strong>' +
                        '<p>Available after the event ends on <strong>' + data.cert_eta + '</strong>.</p></div>' +
                        '<button type="button" class="cw-btn-disabled cw-adm-cert-btn" disabled>Not yet available</button>' +
                    '</div>'
                );
            } else {
                certBar.empty();
            }

            jQuery('#modal-details-table').html(formatDetailsTable(data.details));

            const feedbackContainer = jQuery('#modal-host-feedback');
            if (data.comment && data.comment !== 'No feedback yet.') {
                feedbackContainer.html(
                    '<div class="cw-adm-feedback">' +
                        '<h4>Feedback from host</h4>' +
                        '<p>' + jQuery('<div>').text(data.comment).html() + '</p>' +
                    '</div>'
                );
            } else {
                feedbackContainer.empty();
            }

            $modal.prop('hidden', false).css('display', 'flex').hide().fadeIn(180);
            document.body.classList.add('cw-adm-open');
        }

        function closeContestantModal() {
            const $modal = jQuery('#cw-activity-detail-modal');
            $modal.fadeOut(160, function () {
                $modal.prop('hidden', true).css('display', 'none');
                document.body.classList.remove('cw-adm-open');
            });
        }

        jQuery(document).ready(function ($) {
            function openFromEl(el) {
                const raw = el && el.getAttribute('data-cw-modal');
                if (!raw) return;
                try { openContestantModal(raw); } catch (err) {}
            }
            $(document).on('click', '[data-cw-adm-close]', function () {
                closeContestantModal();
            });
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' && !$('#cw-activity-detail-modal').prop('hidden')) {
                    closeContestantModal();
                }
            });
            $(document).on('click', '[data-cw-modal]', function () {
                openFromEl(this);
            });
            $(document).on('keydown', '[data-cw-modal]', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openFromEl(this);
                }
            });
        });
        </script>
        <?php
    }
    /* ==========================================================================
       3. UPGRADE TAB — mirrors the public Get Started page
       ========================================================================== */
    public function render_upgrade() {
        $uid          = get_current_user_id();
        $user         = wp_get_current_user();
        $base_url     = get_permalink( wc_get_page_id( 'myaccount' ) );
        $overview_url = add_query_arg( 'tab', 'overview', $base_url );
        $admin_post   = esc_url( admin_url( 'admin-post.php' ) );

        // Already a Creator -> show a success card with a link back to the dashboard.
        if ( in_array( 'creator_role', (array) $user->roles, true ) ) {
            ?>
            <div class="cw-content-wrapper">
                <div class="cw-onboard-card" style="text-align:center;padding:60px 20px;max-width:600px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 5px 20px rgba(0,0,0,0.05);">
                    <i class="fa-solid fa-circle-check" style="font-size:60px;color:#22c55e;margin-bottom:25px;"></i>
                    <h2 style="margin:0 0 10px;"><?php esc_html_e( 'You are already a Creator', 'creativewings-core' ); ?></h2>
                    <p style="color:#555555;margin:0 0 25px;"><?php esc_html_e( 'Your account already has Creator privileges.', 'creativewings-core' ); ?></p>
                    <a href="<?php echo esc_url( $overview_url ); ?>" class="cw-btn-primary" style="padding:12px 28px;font-size:15px;"><?php esc_html_e( 'Go to Dashboard', 'creativewings-core' ); ?></a>
                </div>
            </div>
            <?php
            return;
        }

        // Pending Business Partner application -> show the same pending card markup the onboarding page uses.
        $biz_status = get_user_meta( $uid, 'cw_business_application_status', true );
        if ( $biz_status === 'pending' ) {
            ?>
            <div class="cw-content-wrapper">
                <div class="cw-onboard-card" style="text-align:center;padding:60px 20px;max-width:600px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 5px 20px rgba(0,0,0,0.05);">
                    <i class="fa-solid fa-clock" style="font-size:60px;color:#f39c12;margin-bottom:25px;"></i>
                    <h2 style="margin:0 0 10px;"><?php esc_html_e( 'Application Pending', 'creativewings-core' ); ?></h2>
                    <p style="color:#555555;margin:0;"><?php esc_html_e( 'Your request to become a Business Partner is under review. Please wait for approval.', 'creativewings-core' ); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        if ( isset( $_GET['upgrade_error'] ) && $_GET['upgrade_error'] === 'security' ) {
            echo '<div class="cw-alert error" style="max-width:820px;margin:0 auto 20px;">' . esc_html__( 'Security check failed. Please try again.', 'creativewings-core' ) . '</div>';
        }
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-onboarding-wrapper">
                <h2><?php esc_html_e( 'Upgrade your account', 'creativewings-core' ); ?></h2>
                <p class="cw-subtext"><?php esc_html_e( 'Select an account type to unlock more features.', 'creativewings-core' ); ?></p>

                <div class="cw-role-cards">

                    <!-- CREATOR CARD -->
                    <div class="cw-role-card">
                        <div class="icon"><i class="fas fa-palette"></i></div>
                        <h3><?php esc_html_e( 'Creator', 'creativewings-core' ); ?></h3>
                        <p><?php esc_html_e( 'Build your portfolio, showcase your art, and join creative contests.', 'creativewings-core' ); ?></p>
                        <ul class="cw-features">
                            <li><i class="fas fa-check"></i> <?php esc_html_e( 'Upload Portfolio', 'creativewings-core' ); ?></li>
                            <li><i class="fas fa-check"></i> <?php esc_html_e( 'Public Profile', 'creativewings-core' ); ?></li>
                            <li><i class="fas fa-bolt"></i> <?php esc_html_e( 'Instant Approval', 'creativewings-core' ); ?></li>
                        </ul>
                        <form action="<?php echo $admin_post; ?>" method="POST" style="width:100%;margin-top:auto;">
                            <?php wp_nonce_field( 'cw_upgrade_creator_action', 'cw_nonce' ); ?>
                            <input type="hidden" name="action" value="cw_become_creator">
                            <button type="submit" class="btn-creator"><?php esc_html_e( 'Select Creator', 'creativewings-core' ); ?></button>
                        </form>
                    </div>

                    <!-- BUSINESS CARD -->
                    <div class="cw-role-card">
                        <div class="icon"><i class="fas fa-briefcase"></i></div>
                        <h3><?php esc_html_e( 'Business Partner', 'creativewings-core' ); ?></h3>
                        <p><?php esc_html_e( 'Organize tournaments, create campaigns, and manage participants.', 'creativewings-core' ); ?></p>
                        <ul class="cw-features">
                            <li><i class="fas fa-check"></i> <?php esc_html_e( 'Create Campaigns', 'creativewings-core' ); ?></li>
                            <li><i class="fas fa-check"></i> <?php esc_html_e( 'Manage Participants', 'creativewings-core' ); ?></li>
                            <li><i class="fas fa-clock"></i> <?php esc_html_e( 'Admin Approval Required', 'creativewings-core' ); ?></li>
                        </ul>
                        <form action="<?php echo $admin_post; ?>" method="POST" style="width:100%;margin-top:auto;">
                            <?php wp_nonce_field( 'cw_upgrade_business_action', 'cw_nonce' ); ?>
                            <input type="hidden" name="action" value="cw_become_business">
                            <button type="submit" class="btn-business"><?php esc_html_e( 'Apply as Business', 'creativewings-core' ); ?></button>
                        </form>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }

    /* ==========================================================================
       4. SETTINGS TAB
       ========================================================================== */
    public function render_settings() {
        $u   = wp_get_current_user();
        $uid = $u->ID;
        $dob = get_user_meta( $uid, 'birthdate', true );
        $addr = class_exists( 'CW_Points_Rewards' )
            ? CW_Points_Rewards::get_user_shipping_address( $uid )
            : [
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
                'phone'      => '',
                'address_1'  => '',
                'address_2'  => '',
                'city'       => '',
                'state'      => '',
                'postcode'   => '',
                'country'    => 'MY',
            ];
        if ( empty( $addr['first_name'] ) ) {
            $addr['first_name'] = $u->first_name;
        }
        if ( empty( $addr['last_name'] ) ) {
            $addr['last_name'] = $u->last_name;
        }

        $mono_src = trim( (string) ( $u->display_name ?: $u->first_name ?: $u->user_login ) );
        $mono     = function_exists( 'mb_substr' )
            ? mb_strtoupper( mb_substr( $mono_src, 0, 1 ) )
            : strtoupper( substr( $mono_src, 0, 1 ) );

        $error_messages = [
            'mismatch'    => __( 'The new passwords you entered do not match. Please try again.', 'creativewings-core' ),
            'invalid_dob' => __( 'That date of birth doesn\'t look right. Please use the format dd/mm/yyyy.', 'creativewings-core' ),
        ];
        $err_key = isset( $_GET['err'] ) ? sanitize_key( wp_unslash( $_GET['err'] ) ) : '';

        $upgrade_url = add_query_arg( 'tab', 'upgrade', get_permalink( wc_get_page_id( 'myaccount' ) ) );
        $is_creator  = in_array( 'creator_role', (array) $u->roles, true );
        $biz_status  = (string) get_user_meta( $uid, 'cw_business_application_status', true );
        ?>
        <div class="cw-content-wrapper cw-settings-atelier">
            <?php
            $popup_payload = null;
            if ( $err_key && isset( $error_messages[ $err_key ] ) ) {
                $popup_payload = [
                    'icon'  => 'error',
                    'title' => __( 'Please review your details', 'creativewings-core' ),
                    'text'  => (string) $error_messages[ $err_key ],
                ];
            }
            if ( $popup_payload ) :
            ?>
                <script>
                (function(){
                    function fire(){
                        if (typeof Swal === 'undefined') { return; }
                        Swal.fire({
                            icon: <?php echo wp_json_encode( $popup_payload['icon'] ); ?>,
                            title: <?php echo wp_json_encode( $popup_payload['title'] ); ?>,
                            text: <?php echo wp_json_encode( $popup_payload['text'] ); ?>,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#dc2626',
                            showCloseButton: true
                        });
                        try {
                            var url = new URL(window.location.href);
                            if (url.searchParams.has('err')) {
                                url.searchParams.delete('err');
                                window.history.replaceState({}, document.title, url.pathname + (url.search || '') + url.hash);
                            }
                        } catch(e){}
                    }
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', fire);
                    } else { fire(); }
                })();
                </script>
            <?php endif; ?>

            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" class="cw-settings-atelier-form" autocomplete="off">
                <input type="hidden" name="action" value="cw_save_contestant_settings">
                <?php wp_nonce_field( 'cw_settings_nonce' ); ?>

                <div class="cw-settings-atelier-layout">
                    <aside class="cw-settings-rail" aria-label="<?php esc_attr_e( 'Your profile', 'creativewings-core' ); ?>">
                        <div class="cw-settings-rail__mono" aria-hidden="true"><?php echo esc_html( $mono ); ?></div>
                        <h2 class="cw-settings-rail__name"><?php echo esc_html( $u->display_name ?: $u->user_login ); ?></h2>
                        <p class="cw-settings-rail__email"><?php echo esc_html( $u->user_email ); ?></p>
                        <p class="cw-settings-rail__note"><?php esc_html_e( 'Name and address power certificates, leaderboards, merch delivery, and checkout.', 'creativewings-core' ); ?></p>
                    </aside>

                    <div class="cw-settings-atelier-main">
                        <header class="cw-settings-atelier-head">
                            <h1><?php esc_html_e( 'Account Settings', 'creativewings-core' ); ?></h1>
                            <p><?php esc_html_e( 'Keep your identity and delivery details current.', 'creativewings-core' ); ?></p>
                        </header>

                        <section class="cw-settings-panel">
                            <header class="cw-settings-panel__head">
                                <h3><?php esc_html_e( 'Personal', 'creativewings-core' ); ?></h3>
                                <p><?php esc_html_e( 'How you appear across Creative Wings.', 'creativewings-core' ); ?></p>
                            </header>
                            <div class="cw-settings-name-grid">
                                <div class="cw-field">
                                    <label for="cw-settings-first-name"><?php esc_html_e( 'First Name', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-settings-first-name" name="first_name" class="cw-input" value="<?php echo esc_attr( $u->first_name ); ?>" required>
                                </div>
                                <div class="cw-field">
                                    <label for="cw-settings-last-name"><?php esc_html_e( 'Last Name', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-settings-last-name" name="last_name" class="cw-input" value="<?php echo esc_attr( $u->last_name ); ?>" required>
                                </div>
                            </div>
                            <div class="cw-field">
                                <label for="cw-settings-display-name"><?php esc_html_e( 'Display Name', 'creativewings-core' ); ?></label>
                                <input type="text" id="cw-settings-display-name" name="display_name" class="cw-input" value="<?php echo esc_attr( $u->display_name ); ?>">
                                <small><?php esc_html_e( 'Shown on public certificates and leaderboards.', 'creativewings-core' ); ?></small>
                            </div>
                            <div class="cw-field">
                                <label for="birthdate"><?php esc_html_e( 'Date of Birth', 'creativewings-core' ); ?></label>
                                <input type="text" id="birthdate" name="birthdate" class="cw-input cw-datepicker" value="<?php echo esc_attr( $dob ); ?>" placeholder="dd/mm/yyyy" readonly autocomplete="bday">
                                <small><?php esc_html_e( 'Format: dd/mm/yyyy. Tap the field to pick a date.', 'creativewings-core' ); ?></small>
                            </div>
                        </section>

                        <section class="cw-settings-panel">
                            <header class="cw-settings-panel__head">
                                <h3><?php esc_html_e( 'Address', 'creativewings-core' ); ?></h3>
                                <p><?php esc_html_e( 'Prefills checkout and physical merchandise delivery.', 'creativewings-core' ); ?></p>
                            </header>
                            <div class="cw-settings-name-grid">
                                <div class="cw-field">
                                    <label for="cw-addr-first"><?php esc_html_e( 'Recipient first name', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-first" name="addr_first_name" class="cw-input" value="<?php echo esc_attr( $addr['first_name'] ); ?>" autocomplete="shipping given-name">
                                </div>
                                <div class="cw-field">
                                    <label for="cw-addr-last"><?php esc_html_e( 'Recipient last name', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-last" name="addr_last_name" class="cw-input" value="<?php echo esc_attr( $addr['last_name'] ); ?>" autocomplete="shipping family-name">
                                </div>
                            </div>
                            <div class="cw-field">
                                <label for="cw-addr-phone"><?php esc_html_e( 'Phone', 'creativewings-core' ); ?></label>
                                <input type="tel" id="cw-addr-phone" name="addr_phone" class="cw-input" value="<?php echo esc_attr( $addr['phone'] ); ?>" autocomplete="tel">
                            </div>
                            <div class="cw-field">
                                <label for="cw-addr-1"><?php esc_html_e( 'Address', 'creativewings-core' ); ?></label>
                                <input type="text" id="cw-addr-1" name="addr_address_1" class="cw-input" value="<?php echo esc_attr( $addr['address_1'] ); ?>" autocomplete="shipping address-line1">
                            </div>
                            <div class="cw-field">
                                <label for="cw-addr-2"><?php esc_html_e( 'Address line 2', 'creativewings-core' ); ?></label>
                                <input type="text" id="cw-addr-2" name="addr_address_2" class="cw-input" value="<?php echo esc_attr( $addr['address_2'] ); ?>" autocomplete="shipping address-line2">
                            </div>
                            <div class="cw-settings-addr-grid">
                                <div class="cw-field">
                                    <label for="cw-addr-city"><?php esc_html_e( 'City', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-city" name="addr_city" class="cw-input" value="<?php echo esc_attr( $addr['city'] ); ?>" autocomplete="shipping address-level2">
                                </div>
                                <div class="cw-field">
                                    <label for="cw-addr-state"><?php esc_html_e( 'State', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-state" name="addr_state" class="cw-input" value="<?php echo esc_attr( $addr['state'] ); ?>" autocomplete="shipping address-level1">
                                </div>
                                <div class="cw-field">
                                    <label for="cw-addr-postcode"><?php esc_html_e( 'Postcode', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-postcode" name="addr_postcode" class="cw-input" value="<?php echo esc_attr( $addr['postcode'] ); ?>" autocomplete="shipping postal-code">
                                </div>
                                <div class="cw-field">
                                    <label for="cw-addr-country"><?php esc_html_e( 'Country', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-country" name="addr_country" class="cw-input" value="<?php echo esc_attr( $addr['country'] ?: 'MY' ); ?>" autocomplete="shipping country" maxlength="2">
                                    <small><?php esc_html_e( 'ISO country code, e.g. MY.', 'creativewings-core' ); ?></small>
                                </div>
                            </div>
                        </section>

                        <section class="cw-settings-panel">
                            <header class="cw-settings-panel__head">
                                <h3><?php esc_html_e( 'Security', 'creativewings-core' ); ?></h3>
                                <p><?php esc_html_e( 'Leave blank to keep your current password.', 'creativewings-core' ); ?></p>
                            </header>
                            <div class="cw-field">
                                <label for="cw-settings-pass1"><?php esc_html_e( 'New Password', 'creativewings-core' ); ?></label>
                                <input type="password" id="cw-settings-pass1" name="pass1" class="cw-input" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to keep current password', 'creativewings-core' ); ?>">
                                <small><?php esc_html_e( 'Use at least 8 characters with a mix of letters, numbers, and symbols.', 'creativewings-core' ); ?></small>
                            </div>
                            <div class="cw-field">
                                <label for="cw-settings-pass2"><?php esc_html_e( 'Confirm New Password', 'creativewings-core' ); ?></label>
                                <input type="password" id="cw-settings-pass2" name="pass2" class="cw-input" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Re-enter the new password', 'creativewings-core' ); ?>">
                            </div>
                        </section>

                        <div class="cw-settings-atelier-footer">
                            <button type="submit" class="cw-btn-primary cw-settings-save-btn"><?php esc_html_e( 'Save Changes', 'creativewings-core' ); ?></button>
                        </div>
                    </div>
                </div>
            </form>

            <section class="cw-settings-upgrade-strip" aria-labelledby="cw-settings-upgrade-heading">
                <div class="cw-settings-upgrade-strip__copy">
                    <h3 id="cw-settings-upgrade-heading"><?php esc_html_e( 'Upgrade Account', 'creativewings-core' ); ?></h3>
                    <?php if ( $is_creator ) : ?>
                        <p><?php esc_html_e( 'You already have Creator privileges. You can still apply as a Business Partner to host campaigns.', 'creativewings-core' ); ?></p>
                    <?php elseif ( 'pending' === $biz_status ) : ?>
                        <p><?php esc_html_e( 'Your Business Partner application is pending review.', 'creativewings-core' ); ?></p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'Become a Creator for your portfolio, or a Business Partner to host campaigns.', 'creativewings-core' ); ?></p>
                    <?php endif; ?>
                </div>
                <a href="<?php echo esc_url( $upgrade_url ); ?>" class="cw-btn-primary cw-settings-upgrade-strip__cta">
                    <?php esc_html_e( 'View upgrade options', 'creativewings-core' ); ?>
                </a>
            </section>
        </div>

        <script>
        // Safety-net datepicker init: the global script in assets/js/cw-script.js
        // already binds jQuery UI datepicker to #birthdate with dd/mm/yy format.
        // This guarded fallback runs only if the global init hasn't bound yet
        // (e.g. if script order ever changes), to keep the picker working.
        (function () {
            if ( typeof jQuery === 'undefined' ) { return; }
            jQuery(function ($) {
                if ( ! $.fn || ! $.fn.datepicker ) { return; }
                var $field = $('input.cw-datepicker#birthdate');
                if ( ! $field.length || $field.hasClass('hasDatepicker') ) { return; }
                $field.datepicker({
                    changeMonth: true,
                    changeYear: true,
                    yearRange: 'c-90:c-13',
                    dateFormat: 'dd/mm/yy',
                    maxDate: 0
                });
            });
        })();
        </script>
        <?php
    }

    /* ==========================================================================
       5. HANDLER
       ========================================================================== */
    private function render_pagination( $current_page, $total_pages, $base_url ) {
        if ( $total_pages <= 1 ) return;
        ?>
        <nav class="cw-pagination-nav" aria-label="Pagination">
            <?php if ( $current_page > 1 ): ?>
                <a href="<?php echo esc_url( add_query_arg( 'cw_page', $current_page - 1, $base_url ) ); ?>" class="cw-page-btn prev"><i class="fas fa-chevron-left"></i></a>
            <?php else: ?>
                <span class="cw-page-btn prev disabled"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>
            <?php
            $range = 2; $start = max(1, $current_page - $range); $end = min($total_pages, $current_page + $range);
            if ($start > 1) { echo '<a href="'.esc_url(add_query_arg('cw_page', 1, $base_url)).'" class="cw-page-btn">1</a>'; if ($start > 2) echo '<span class="cw-page-ellipsis">…</span>'; }
            for ($i = $start; $i <= $end; $i++): $active = ($i === $current_page) ? 'active' : ''; ?>
                <a href="<?php echo esc_url(add_query_arg('cw_page', $i, $base_url)); ?>" class="cw-page-btn <?php echo $active; ?>" <?php echo $active ? 'aria-current="page"' : ''; ?>><?php echo $i; ?></a>
            <?php endfor;
            if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<span class="cw-page-ellipsis">…</span>'; echo '<a href="'.esc_url(add_query_arg('cw_page', $total_pages, $base_url)).'" class="cw-page-btn">'.$total_pages.'</a>'; }
            ?>
            <?php if ( $current_page < $total_pages ): ?>
                <a href="<?php echo esc_url( add_query_arg( 'cw_page', $current_page + 1, $base_url ) ); ?>" class="cw-page-btn next"><i class="fas fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="cw-page-btn next disabled"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </nav>
        <?php
    }

    public function handle_save_settings() {
        if ( ! is_user_logged_in() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cw_settings_nonce' ) ) {
            wp_die( 'Security Error' );
        }

        $uid          = get_current_user_id();
        $base_url     = get_permalink( wc_get_page_id( 'myaccount' ) );
        $settings_url = add_query_arg( 'tab', 'settings', $base_url );

        // --- Validate Date of Birth (optional, but if provided must match dd/mm/yyyy
        //     to stay consistent with how 'birthdate' is stored elsewhere in the plugin
        //     — see CW_Auth::process_complete_profile() and the registration flow.)
        $dob_raw = isset( $_POST['birthdate'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['birthdate'] ) ) ) : '';
        if ( $dob_raw !== '' && ! preg_match( '#^\d{2}/\d{2}/\d{4}$#', $dob_raw ) ) {
            wp_safe_redirect( add_query_arg( 'err', 'invalid_dob', $settings_url ) );
            exit;
        }

        $userdata = [
            'ID'           => $uid,
            'first_name'   => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
            'last_name'    => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
            'display_name' => sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) ),
        ];

        wp_update_user( $userdata );

        if ( isset( $_POST['birthdate'] ) ) {
            update_user_meta( $uid, 'birthdate', $dob_raw );
        }

        if ( class_exists( 'CW_Points_Rewards' ) ) {
            CW_Points_Rewards::save_user_shipping_address(
                $uid,
                [
                    'first_name' => sanitize_text_field( wp_unslash( $_POST['addr_first_name'] ?? '' ) ),
                    'last_name'  => sanitize_text_field( wp_unslash( $_POST['addr_last_name'] ?? '' ) ),
                    'phone'      => sanitize_text_field( wp_unslash( $_POST['addr_phone'] ?? '' ) ),
                    'address_1'  => sanitize_text_field( wp_unslash( $_POST['addr_address_1'] ?? '' ) ),
                    'address_2'  => sanitize_text_field( wp_unslash( $_POST['addr_address_2'] ?? '' ) ),
                    'city'       => sanitize_text_field( wp_unslash( $_POST['addr_city'] ?? '' ) ),
                    'state'      => sanitize_text_field( wp_unslash( $_POST['addr_state'] ?? '' ) ),
                    'postcode'   => sanitize_text_field( wp_unslash( $_POST['addr_postcode'] ?? '' ) ),
                    'country'    => strtoupper( sanitize_text_field( wp_unslash( $_POST['addr_country'] ?? 'MY' ) ) ),
                ]
            );
        }

        if ( ! empty( $_POST['pass1'] ) ) {
            if ( $_POST['pass1'] === ( $_POST['pass2'] ?? '' ) ) {
                wp_update_user([ 'ID' => $uid, 'user_pass' => $_POST['pass1'] ]);

                // Re-authenticate user so they aren't logged out
                $user = get_user_by( 'id', $uid );
                wp_signon([
                    'user_login'    => $user->user_login,
                    'user_password' => $_POST['pass1'],
                    'remember'      => true,
                ]);
            } else {
                wp_safe_redirect( add_query_arg( 'err', 'mismatch', $settings_url ) );
                exit;
            }
        }

        wp_safe_redirect( add_query_arg( 'updated', '1', $settings_url ) );
        exit;
    }

    /* ==========================================================================
       POINTS + LEADERBOARD
       ========================================================================== */

    public function render_points() {
        $uid      = get_current_user_id();
        $base_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $board_url = add_query_arg( 'tab', 'leaderboard', $base_url );

        if ( ! class_exists( 'CW_Points' ) ) {
            echo '<p>' . esc_html__( 'Points are unavailable.', 'creativewings-core' ) . '</p>';
            return;
        }

        $balance  = CW_Points::get_balance( $uid );
        $lifetime = CW_Points::get_lifetime_earned( $uid );
        $expires  = CW_Points::get_expires_at( $uid );
        $rank     = CW_Points::get_user_rank( $uid );
        $rm_value = CW_Points::points_to_rm( $balance );

        $rewards     = class_exists( 'CW_Points_Rewards' ) ? CW_Points_Rewards::get_rewards( true ) : [];
        $redemptions = class_exists( 'CW_Points_Rewards' ) ? CW_Points_Rewards::get_user_redemptions( $uid, 10 ) : [];

        $flash = '';
        if ( ! empty( $_GET['cw_points_msg'] ) ) {
            $flash = sanitize_text_field( wp_unslash( $_GET['cw_points_msg'] ) );
        }

        global $wpdb;
        $table = CW_Points::table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 20",
                $uid
            ),
            ARRAY_A
        );
        $shop_url    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
        $explore_url = add_query_arg( 'tab', 'explore', $base_url );
        ?>
        <div class="cw-content-wrapper cw-pts">
            <header class="cw-pts-hero">
                <h1 class="cw-pts-title"><?php esc_html_e( 'Your Points', 'creativewings-core' ); ?></h1>
                <p class="cw-pts-sub"><?php esc_html_e( 'Earn when you pay to join. Spend at checkout or redeem merchandise.', 'creativewings-core' ); ?></p>
            </header>

            <?php if ( $flash !== '' ) : ?>
                <div class="cw-pts-flash" role="status"><?php echo esc_html( $flash ); ?></div>
            <?php endif; ?>

            <ol class="cw-pts-steps">
                <li><?php esc_html_e( 'Earn 1 point per RM1 cash paid (free / 100% coupon joins earn 0).', 'creativewings-core' ); ?></li>
                <li><?php esc_html_e( '100 points = RM1.00 checkout credit on your next campaign join.', 'creativewings-core' ); ?></li>
                <li><?php esc_html_e( 'Points expire 12 months after your last join unless you join again.', 'creativewings-core' ); ?></li>
            </ol>

            <div class="cw-pts-metrics" role="list">
                <div class="cw-pts-metric" role="listitem">
                    <p class="cw-pts-metric-value"><?php echo esc_html( number_format_i18n( $balance ) ); ?></p>
                    <p class="cw-pts-metric-label"><?php esc_html_e( 'Current balance', 'creativewings-core' ); ?></p>
                    <p class="cw-pts-metric-hint">≈ RM<?php echo esc_html( number_format_i18n( $rm_value, 2 ) ); ?></p>
                </div>
                <div class="cw-pts-metric" role="listitem">
                    <p class="cw-pts-metric-value"><?php echo esc_html( number_format_i18n( $lifetime ) ); ?></p>
                    <p class="cw-pts-metric-label"><?php esc_html_e( 'Lifetime earned', 'creativewings-core' ); ?></p>
                </div>
                <div class="cw-pts-metric" role="listitem">
                    <p class="cw-pts-metric-value"><?php echo $rank > 0 ? '#' . (int) $rank : '—'; ?></p>
                    <p class="cw-pts-metric-label">
                        <a href="<?php echo esc_url( $board_url ); ?>" class="cw-pts-metric-link"><?php esc_html_e( 'Leaderboard rank', 'creativewings-core' ); ?></a>
                    </p>
                </div>
            </div>

            <?php if ( $expires && $balance > 0 ) : ?>
                <p class="cw-pts-expiry">
                    <?php
                    printf(
                        /* translators: %s: date */
                        esc_html__( 'Points expire on %s unless you join another campaign.', 'creativewings-core' ),
                        esc_html( date_i18n( get_option( 'date_format' ), strtotime( $expires ) ) )
                    );
                    ?>
                </p>
            <?php endif; ?>

            <section class="cw-pts-panel" aria-labelledby="cw-pts-checkout-heading">
                <div class="cw-pts-panel-copy">
                    <h2 id="cw-pts-checkout-heading" class="cw-pts-panel-title"><?php esc_html_e( 'Use points at checkout', 'creativewings-core' ); ?></h2>
                    <p class="cw-pts-panel-text"><?php esc_html_e( 'Apply points when joining your next campaign for entry-fee credit.', 'creativewings-core' ); ?></p>
                </div>
                <a href="<?php echo esc_url( $explore_url ?: $shop_url ); ?>" class="cw-btn-primary cw-pts-btn"><?php esc_html_e( 'Explore Opportunities', 'creativewings-core' ); ?></a>
            </section>

            <section class="cw-pts-section" aria-labelledby="cw-pts-merch-heading">
                <h2 id="cw-pts-merch-heading" class="cw-pts-section-title"><?php esc_html_e( 'Redeem merchandise', 'creativewings-core' ); ?></h2>
                <?php if ( empty( $rewards ) ) : ?>
                    <p class="cw-pts-empty"><?php esc_html_e( 'No merchandise available right now. Check back soon.', 'creativewings-core' ); ?></p>
                <?php else :
                    $has_address = class_exists( 'CW_Points_Rewards' ) && CW_Points_Rewards::user_has_shipping_address( $uid );
                    $addr_pref   = class_exists( 'CW_Points_Rewards' ) ? CW_Points_Rewards::get_user_shipping_address( $uid ) : [];
                    ?>
                    <div class="cw-points-merch-grid">
                        <?php foreach ( $rewards as $reward ) :
                            $cost        = (int) $reward['points_cost'];
                            $stock       = $reward['stock'];
                            $oos         = ( $stock !== null && (int) $stock <= 0 );
                            $can         = ! $oos && $balance >= $cost;
                            $img         = ! empty( $reward['image_id'] ) ? wp_get_attachment_image_url( (int) $reward['image_id'], 'medium' ) : '';
                            $img_large   = ! empty( $reward['image_id'] ) ? wp_get_attachment_image_url( (int) $reward['image_id'], 'large' ) : $img;
                            $fulfillment = class_exists( 'CW_Points_Rewards' )
                                ? CW_Points_Rewards::normalize_fulfillment( $reward['fulfillment'] ?? 'physical' )
                                : 'physical';
                            $stock_label = $oos
                                ? __( 'Out of stock', 'creativewings-core' )
                                : ( $stock === null
                                    ? __( 'In stock', 'creativewings-core' )
                                    : sprintf( __( '%d left', 'creativewings-core' ), (int) $stock ) );
                            $payload = [
                                'id'           => (int) $reward['id'],
                                'title'        => (string) $reward['title'],
                                'description'  => (string) ( $reward['description'] ?? '' ),
                                'cost'         => $cost,
                                'stock_label'  => $stock_label,
                                'fulfillment'  => $fulfillment,
                                'image'        => $img_large ?: '',
                                'can'          => (bool) $can,
                                'need_address' => ( 'physical' === $fulfillment && ! $has_address ),
                            ];
                            ?>
                            <button type="button"
                                class="cw-points-merch-card cw-points-merch-card--btn"
                                data-cw-merch="<?php echo esc_attr( wp_json_encode( $payload ) ); ?>">
                                <div class="cw-points-merch-card__media">
                                    <?php if ( $img ) : ?>
                                        <img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy">
                                    <?php else : ?>
                                        <span class="cw-points-merch-card__placeholder" aria-hidden="true"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="cw-points-merch-card__body">
                                    <span class="cw-pts-fulfill-pill cw-pts-fulfill-pill--<?php echo esc_attr( $fulfillment ); ?>">
                                        <?php echo 'digital' === $fulfillment ? esc_html__( 'Digital', 'creativewings-core' ) : esc_html__( 'Physical', 'creativewings-core' ); ?>
                                    </span>
                                    <h3 class="cw-points-merch-card__title"><?php echo esc_html( $reward['title'] ); ?></h3>
                                    <?php if ( ! empty( $reward['description'] ) ) : ?>
                                        <p class="cw-points-merch-card__desc"><?php echo esc_html( wp_trim_words( (string) $reward['description'], 18 ) ); ?></p>
                                    <?php endif; ?>
                                    <div class="cw-points-merch-card__meta">
                                        <strong><?php echo esc_html( number_format_i18n( $cost ) ); ?> pts</strong>
                                        <span><?php echo esc_html( $stock_label ); ?></span>
                                    </div>
                                    <span class="cw-btn-primary cw-pts-btn cw-pts-merch-view"><?php esc_html_e( 'View details', 'creativewings-core' ); ?></span>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php $this->render_merch_redeem_modal( $addr_pref ); ?>
                <?php endif; ?>
            </section>

            <?php if ( ! empty( $redemptions ) ) : ?>
                <section class="cw-pts-section" aria-labelledby="cw-pts-redemptions-heading">
                    <h2 id="cw-pts-redemptions-heading" class="cw-pts-section-title"><?php esc_html_e( 'Merchandise redemptions', 'creativewings-core' ); ?></h2>
                    <div class="cw-ov-recent-table-wrap">
                        <table class="cw-ov-recent-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Date', 'creativewings-core' ); ?></th>
                                    <th><?php esc_html_e( 'Item', 'creativewings-core' ); ?></th>
                                    <th><?php esc_html_e( 'Points', 'creativewings-core' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'creativewings-core' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $redemptions as $red ) : ?>
                                <tr>
                                    <td data-label="<?php esc_attr_e( 'Date', 'creativewings-core' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $red['created_at'] ) ) ); ?></td>
                                    <td data-label="<?php esc_attr_e( 'Item', 'creativewings-core' ); ?>"><?php echo esc_html( $red['reward_title'] ?? ( '#' . $red['reward_id'] ) ); ?></td>
                                    <td data-label="<?php esc_attr_e( 'Points', 'creativewings-core' ); ?>"><?php echo esc_html( number_format_i18n( (int) $red['points_spent'] ) ); ?></td>
                                    <td data-label="<?php esc_attr_e( 'Status', 'creativewings-core' ); ?>"><?php echo esc_html( ucfirst( (string) $red['status'] ) ); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <section class="cw-pts-section" aria-labelledby="cw-pts-activity-heading">
                <h2 id="cw-pts-activity-heading" class="cw-pts-section-title"><?php esc_html_e( 'Recent activity', 'creativewings-core' ); ?></h2>
                <?php if ( empty( $rows ) ) : ?>
                    <div class="cw-ov-empty">
                        <p class="cw-ov-empty-title"><?php esc_html_e( 'No point transactions yet', 'creativewings-core' ); ?></p>
                        <p class="cw-ov-empty-desc"><?php esc_html_e( 'Join a campaign to start earning.', 'creativewings-core' ); ?></p>
                        <a href="<?php echo esc_url( $explore_url ); ?>" class="cw-btn-primary"><?php esc_html_e( 'Explore Opportunities', 'creativewings-core' ); ?></a>
                    </div>
                <?php else : ?>
                    <div class="cw-ov-recent-table-wrap">
                        <table class="cw-ov-recent-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Date', 'creativewings-core' ); ?></th>
                                    <th><?php esc_html_e( 'Type', 'creativewings-core' ); ?></th>
                                    <th><?php esc_html_e( 'Points', 'creativewings-core' ); ?></th>
                                    <th><?php esc_html_e( 'Note', 'creativewings-core' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $rows as $row ) : ?>
                                <tr>
                                    <td data-label="<?php esc_attr_e( 'Date', 'creativewings-core' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['created_at'] ) ) ); ?></td>
                                    <td data-label="<?php esc_attr_e( 'Type', 'creativewings-core' ); ?>"><?php echo esc_html( $this->points_ledger_type_label( (string) $row['type'] ) ); ?></td>
                                    <td data-label="<?php esc_attr_e( 'Points', 'creativewings-core' ); ?>"><?php echo esc_html( ( (int) $row['points'] >= 0 ? '+' : '' ) . number_format_i18n( (int) $row['points'] ) ); ?></td>
                                    <td data-label="<?php esc_attr_e( 'Note', 'creativewings-core' ); ?>"><?php echo esc_html( (string) ( $row['note'] ?? '' ) ); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    /**
     * Human label for points ledger type.
     *
     * @param string $type Ledger type key.
     */
    private function points_ledger_type_label( $type ) {
        $map = [
            'earn'            => __( 'Earn', 'creativewings-core' ),
            'redeem_merch'    => __( 'Merchandise', 'creativewings-core' ),
            'spend_checkout'  => __( 'Checkout', 'creativewings-core' ),
            'wipe'            => __( 'Expired', 'creativewings-core' ),
            'adjust'          => __( 'Adjustment', 'creativewings-core' ),
        ];
        $type = sanitize_key( $type );
        return $map[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) );
    }

    /**
     * @param array<string,string> $addr Prefill address.
     */
    private function render_merch_redeem_modal( $addr ) {
        $ajax = admin_url( 'admin-ajax.php' );
        $nonce = wp_create_nonce( 'cw_points_merch_redeem' );
        ?>
        <div id="cw-pts-merch-modal" class="cw-pts-merch-modal" hidden>
            <div class="cw-pts-merch-modal-backdrop" data-cw-merch-close tabindex="-1"></div>
            <div class="cw-pts-merch-modal-panel" role="dialog" aria-modal="true" aria-labelledby="cw-pts-merch-modal-title">
                <button type="button" class="cw-pts-merch-modal-close" data-cw-merch-close aria-label="<?php esc_attr_e( 'Close', 'creativewings-core' ); ?>">&times;</button>

                <div class="cw-pts-merch-step" data-step="detail">
                    <div class="cw-pts-merch-detail-media">
                        <img id="cw-pts-merch-img" src="" alt="" hidden>
                        <div id="cw-pts-merch-img-fallback" class="cw-points-merch-card__placeholder" aria-hidden="true"></div>
                    </div>
                    <p id="cw-pts-merch-type" class="cw-pts-fulfill-pill"></p>
                    <h2 id="cw-pts-merch-modal-title" class="cw-pts-merch-modal-title"></h2>
                    <p id="cw-pts-merch-desc" class="cw-pts-merch-modal-desc"></p>
                    <p class="cw-pts-merch-modal-meta"><strong id="cw-pts-merch-cost"></strong> · <span id="cw-pts-merch-stock"></span></p>
                    <div class="cw-pts-merch-modal-actions">
                        <button type="button" class="cw-ov-btn-secondary" data-cw-merch-close><?php esc_html_e( 'Close', 'creativewings-core' ); ?></button>
                        <button type="button" class="cw-btn-primary" id="cw-pts-merch-to-confirm"><?php esc_html_e( 'Redeem', 'creativewings-core' ); ?></button>
                    </div>
                </div>

                <div class="cw-pts-merch-step" data-step="confirm" hidden>
                    <h2 class="cw-pts-merch-modal-title"><?php esc_html_e( 'Confirm redemption', 'creativewings-core' ); ?></h2>
                    <p id="cw-pts-merch-confirm-copy" class="cw-pts-merch-modal-desc"></p>
                    <div class="cw-pts-merch-modal-actions">
                        <button type="button" class="cw-ov-btn-secondary" id="cw-pts-merch-back-detail"><?php esc_html_e( 'Back', 'creativewings-core' ); ?></button>
                        <button type="button" class="cw-btn-primary" id="cw-pts-merch-confirm"><?php esc_html_e( 'Confirm redeem', 'creativewings-core' ); ?></button>
                    </div>
                </div>

                <div class="cw-pts-merch-step" data-step="address" hidden>
                    <h2 class="cw-pts-merch-modal-title"><?php esc_html_e( 'Shipping address', 'creativewings-core' ); ?></h2>
                    <p class="cw-pts-merch-modal-desc"><?php esc_html_e( 'This item is physical. Enter where we should send it.', 'creativewings-core' ); ?></p>
                    <form id="cw-pts-merch-address-form" class="cw-pts-merch-address-form">
                        <div class="cw-pts-merch-addr-grid">
                            <label><?php esc_html_e( 'First name', 'creativewings-core' ); ?>
                                <input type="text" name="first_name" required value="<?php echo esc_attr( $addr['first_name'] ?? '' ); ?>">
                            </label>
                            <label><?php esc_html_e( 'Last name', 'creativewings-core' ); ?>
                                <input type="text" name="last_name" value="<?php echo esc_attr( $addr['last_name'] ?? '' ); ?>">
                            </label>
                            <label class="cw-pts-merch-addr-span"><?php esc_html_e( 'Phone', 'creativewings-core' ); ?>
                                <input type="tel" name="phone" required value="<?php echo esc_attr( $addr['phone'] ?? '' ); ?>">
                            </label>
                            <label class="cw-pts-merch-addr-span"><?php esc_html_e( 'Address', 'creativewings-core' ); ?>
                                <input type="text" name="address_1" required value="<?php echo esc_attr( $addr['address_1'] ?? '' ); ?>">
                            </label>
                            <label class="cw-pts-merch-addr-span"><?php esc_html_e( 'Address line 2', 'creativewings-core' ); ?>
                                <input type="text" name="address_2" value="<?php echo esc_attr( $addr['address_2'] ?? '' ); ?>">
                            </label>
                            <label><?php esc_html_e( 'City', 'creativewings-core' ); ?>
                                <input type="text" name="city" required value="<?php echo esc_attr( $addr['city'] ?? '' ); ?>">
                            </label>
                            <label><?php esc_html_e( 'Postcode', 'creativewings-core' ); ?>
                                <input type="text" name="postcode" required value="<?php echo esc_attr( $addr['postcode'] ?? '' ); ?>">
                            </label>
                            <label><?php esc_html_e( 'State', 'creativewings-core' ); ?>
                                <input type="text" name="state" value="<?php echo esc_attr( $addr['state'] ?? '' ); ?>">
                            </label>
                            <label><?php esc_html_e( 'Country', 'creativewings-core' ); ?>
                                <input type="text" name="country" required value="<?php echo esc_attr( $addr['country'] ?? 'MY' ); ?>">
                            </label>
                        </div>
                        <p id="cw-pts-merch-addr-error" class="cw-pts-merch-error" hidden></p>
                        <div class="cw-pts-merch-modal-actions">
                            <button type="button" class="cw-ov-btn-secondary" id="cw-pts-merch-back-confirm"><?php esc_html_e( 'Back', 'creativewings-core' ); ?></button>
                            <button type="submit" class="cw-btn-primary"><?php esc_html_e( 'Save & redeem', 'creativewings-core' ); ?></button>
                        </div>
                    </form>
                </div>

                <div class="cw-pts-merch-step" data-step="done" hidden>
                    <h2 class="cw-pts-merch-modal-title"><?php esc_html_e( 'Redeemed!', 'creativewings-core' ); ?></h2>
                    <p id="cw-pts-merch-done-copy" class="cw-pts-merch-modal-desc"></p>
                    <div class="cw-pts-merch-modal-actions">
                        <button type="button" class="cw-btn-primary" data-cw-merch-reload><?php esc_html_e( 'Done', 'creativewings-core' ); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var modal = document.getElementById('cw-pts-merch-modal');
            if (!modal) return;
            var current = null;
            var ajaxUrl = <?php echo wp_json_encode( $ajax ); ?>;
            var nonce = <?php echo wp_json_encode( $nonce ); ?>;

            function showStep(name) {
                modal.querySelectorAll('.cw-pts-merch-step').forEach(function (el) {
                    el.hidden = el.getAttribute('data-step') !== name;
                });
            }
            function openModal(data) {
                current = data;
                document.getElementById('cw-pts-merch-modal-title').textContent = data.title || '';
                document.getElementById('cw-pts-merch-desc').textContent = data.description || '';
                document.getElementById('cw-pts-merch-cost').textContent = (data.cost || 0).toLocaleString() + ' pts';
                document.getElementById('cw-pts-merch-stock').textContent = data.stock_label || '';
                var typeEl = document.getElementById('cw-pts-merch-type');
                typeEl.textContent = data.fulfillment === 'digital' ? 'Digital' : 'Physical';
                typeEl.className = 'cw-pts-fulfill-pill cw-pts-fulfill-pill--' + (data.fulfillment || 'physical');
                var img = document.getElementById('cw-pts-merch-img');
                var fb = document.getElementById('cw-pts-merch-img-fallback');
                if (data.image) {
                    img.src = data.image;
                    img.hidden = false;
                    fb.hidden = true;
                } else {
                    img.hidden = true;
                    img.removeAttribute('src');
                    fb.hidden = false;
                }
                var redeemBtn = document.getElementById('cw-pts-merch-to-confirm');
                redeemBtn.disabled = !data.can;
                document.getElementById('cw-pts-merch-confirm-copy').textContent =
                    'Spend ' + (data.cost || 0).toLocaleString() + ' points on “' + (data.title || '') + '”?';
                document.getElementById('cw-pts-merch-addr-error').hidden = true;
                modal.hidden = false;
                modal.classList.add('is-open');
                document.body.classList.add('cw-pts-merch-open');
                showStep('detail');
            }
            function closeModal() {
                modal.classList.remove('is-open');
                modal.hidden = true;
                document.body.classList.remove('cw-pts-merch-open');
                current = null;
            }
            function redeem(address) {
                var body = new FormData();
                body.append('action', 'cw_points_merch_redeem');
                body.append('nonce', nonce);
                body.append('reward_id', String(current.id));
                if (address) {
                    Object.keys(address).forEach(function (k) { body.append('address[' + k + ']', address[k]); });
                }
                var confirmBtn = document.getElementById('cw-pts-merch-confirm');
                confirmBtn.disabled = true;
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        confirmBtn.disabled = false;
                        if (!res || !res.success) {
                            var err = (res && res.data && res.data.message) ? res.data.message : 'Could not redeem.';
                            if (res && res.data && res.data.need_address) {
                                document.getElementById('cw-pts-merch-addr-error').textContent = err;
                                document.getElementById('cw-pts-merch-addr-error').hidden = false;
                                showStep('address');
                                return;
                            }
                            alert(err);
                            return;
                        }
                        document.getElementById('cw-pts-merch-done-copy').textContent =
                            res.data && res.data.message ? res.data.message : 'Your redemption is pending fulfillment.';
                        showStep('done');
                    })
                    .catch(function () {
                        confirmBtn.disabled = false;
                        alert('Network error. Please try again.');
                    });
            }

            document.querySelectorAll('[data-cw-merch]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    try { openModal(JSON.parse(btn.getAttribute('data-cw-merch'))); } catch (e) {}
                });
            });
            modal.querySelectorAll('[data-cw-merch-close]').forEach(function (el) {
                el.addEventListener('click', closeModal);
            });
            modal.querySelectorAll('[data-cw-merch-reload]').forEach(function (el) {
                el.addEventListener('click', function () { window.location.reload(); });
            });
            document.getElementById('cw-pts-merch-to-confirm').addEventListener('click', function () {
                if (!current || !current.can) return;
                showStep('confirm');
            });
            document.getElementById('cw-pts-merch-back-detail').addEventListener('click', function () { showStep('detail'); });
            document.getElementById('cw-pts-merch-back-confirm').addEventListener('click', function () { showStep('confirm'); });
            document.getElementById('cw-pts-merch-confirm').addEventListener('click', function () {
                if (!current) return;
                if (current.need_address) {
                    showStep('address');
                    return;
                }
                redeem(null);
            });
            document.getElementById('cw-pts-merch-address-form').addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(e.target);
                var address = {};
                fd.forEach(function (v, k) { address[k] = v; });
                redeem(address);
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
            });
        })();
        </script>
        <?php
    }

    public function ajax_merch_redeem() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Please log in.', 'creativewings-core' ) ], 401 );
        }
        check_ajax_referer( 'cw_points_merch_redeem', 'nonce' );
        $uid = get_current_user_id();

        // Contestant / creator only (points merch is My Account contestant surface).
        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        $allowed = array_intersect( $roles, [ 'contestant', 'creator_role', 'administrator' ] );
        if ( empty( $allowed ) ) {
            wp_send_json_error( [ 'message' => __( 'Not allowed.', 'creativewings-core' ) ], 403 );
        }

        $rate_key = 'cw_merch_redeem_rl_' . $uid;
        $hits     = (int) get_transient( $rate_key );
        if ( $hits >= 12 ) {
            wp_send_json_error( [ 'message' => __( 'Too many attempts. Please wait a moment.', 'creativewings-core' ) ], 429 );
        }
        set_transient( $rate_key, $hits + 1, MINUTE_IN_SECONDS );

        $reward_id = absint( $_POST['reward_id'] ?? 0 );
        if ( $reward_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid reward.', 'creativewings-core' ) ] );
        }
        $address   = null;
        if ( ! empty( $_POST['address'] ) && is_array( $_POST['address'] ) ) {
            $raw = wp_unslash( $_POST['address'] );
            $address = [];
            foreach ( [ 'first_name', 'last_name', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ] as $key ) {
                $val = isset( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
                if ( 'country' === $key ) {
                    $val = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $val ), 0, 2 ) );
                }
                $address[ $key ] = $val;
            }
        }

        if ( ! class_exists( 'CW_Points_Rewards' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rewards unavailable.', 'creativewings-core' ) ] );
        }

        $result = CW_Points_Rewards::redeem( $uid, $reward_id, $address );
        if ( is_wp_error( $result ) ) {
            $data = [ 'message' => $result->get_error_message() ];
            $err_data = $result->get_error_data();
            if ( is_array( $err_data ) && ! empty( $err_data['need_address'] ) ) {
                $data['need_address'] = true;
            }
            wp_send_json_error( $data );
        }

        wp_send_json_success(
            [
                'message' => __( 'Redeemed! We will fulfill your merchandise soon.', 'creativewings-core' ),
                'balance' => class_exists( 'CW_Points' ) ? CW_Points::get_balance( $uid ) : 0,
            ]
        );
    }

    public function handle_redeem_reward() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }
        $uid       = get_current_user_id();
        $reward_id = absint( $_POST['reward_id'] ?? 0 );
        check_admin_referer( 'cw_redeem_points_reward_' . $reward_id );

        $base = get_permalink( wc_get_page_id( 'myaccount' ) );
        $url  = add_query_arg( 'tab', 'points', $base );

        if ( ! class_exists( 'CW_Points_Rewards' ) ) {
            wp_safe_redirect( add_query_arg( 'cw_points_msg', rawurlencode( __( 'Rewards unavailable.', 'creativewings-core' ) ), $url ) );
            exit;
        }

        $result = CW_Points_Rewards::redeem( $uid, $reward_id );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'cw_points_msg', rawurlencode( $result->get_error_message() ), $url ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( 'cw_points_msg', rawurlencode( __( 'Redeemed! We will fulfill your merchandise soon.', 'creativewings-core' ) ), $url ) );
        exit;
    }

    public function render_leaderboard() {
        if ( ! class_exists( 'CW_Points' ) ) {
            echo '<p>' . esc_html__( 'Leaderboard is unavailable.', 'creativewings-core' ) . '</p>';
            return;
        }

        $uid   = get_current_user_id();
        $limit = CW_Points::PANEL_SIZE;

        $week_rows = CW_Points::get_leaderboard_earned( 7, $limit );
        $all_raw   = CW_Points::get_leaderboard( $limit );
        $all_rows  = [];
        foreach ( $all_raw as $row ) {
            $all_rows[] = [
                'user_id'      => (int) $row['user_id'],
                'points'       => (int) $row['balance'],
                'display_name' => (string) $row['display_name'],
                'rank'         => (int) $row['rank'],
            ];
        }
        $month_rows = CW_Points::get_leaderboard_earned( 30, $limit );

        $panels = [
            [
                'key'         => 'week',
                'title'       => __( 'This week', 'creativewings-core' ),
                'eyebrow'     => __( 'Last 7 days', 'creativewings-core' ),
                'featured'    => false,
                'rows'        => $week_rows,
                'you_rank'    => CW_Points::get_user_earned_rank( $uid, 7 ),
                'you_points'  => CW_Points::get_user_earned( $uid, 7 ),
                'empty'       => __( 'No earns this week yet.', 'creativewings-core' ),
            ],
            [
                'key'         => 'alltime',
                'title'       => __( 'All-time', 'creativewings-core' ),
                'eyebrow'     => __( 'Current balance', 'creativewings-core' ),
                'featured'    => true,
                'rows'        => $all_rows,
                'you_rank'    => CW_Points::get_user_rank( $uid ),
                'you_points'  => CW_Points::get_balance( $uid ),
                'empty'       => __( 'No ranked contestants yet.', 'creativewings-core' ),
            ],
            [
                'key'         => 'month',
                'title'       => __( 'This month', 'creativewings-core' ),
                'eyebrow'     => __( 'Last 30 days', 'creativewings-core' ),
                'featured'    => false,
                'rows'        => $month_rows,
                'you_rank'    => CW_Points::get_user_earned_rank( $uid, 30 ),
                'you_points'  => CW_Points::get_user_earned( $uid, 30 ),
                'empty'       => __( 'No earns this month yet.', 'creativewings-core' ),
            ],
        ];
        ?>
        <div class="cw-content-wrapper cw-lb">
            <div class="cw-dash-header">
                <h2><?php esc_html_e( 'Points Leaderboard', 'creativewings-core' ); ?></h2>
                <p><?php esc_html_e( 'Week and month rank by points earned. All-time ranks by current balance.', 'creativewings-core' ); ?></p>
            </div>

            <div class="cw-lb-trio" role="list">
                <?php foreach ( $panels as $panel ) :
                    $this->render_leaderboard_panel( $panel, $uid );
                endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $panel
     * @param int                 $uid
     */
    private function render_leaderboard_panel( array $panel, $uid ) {
        $featured = ! empty( $panel['featured'] );
        $rows     = is_array( $panel['rows'] ?? null ) ? $panel['rows'] : [];
        $you_rank = (int) ( $panel['you_rank'] ?? 0 );
        $you_pts  = (int) ( $panel['you_points'] ?? 0 );
        $classes  = 'cw-lb-card cw-lb-card--' . sanitize_html_class( (string) ( $panel['key'] ?? 'panel' ) );
        if ( $featured ) {
            $classes .= ' cw-lb-card--featured';
        }
        ?>
        <article class="<?php echo esc_attr( $classes ); ?>" role="listitem">
            <header class="cw-lb-card__head">
                <p class="cw-lb-card__eyebrow"><?php echo esc_html( (string) $panel['eyebrow'] ); ?></p>
                <h3 class="cw-lb-card__title"><?php echo esc_html( (string) $panel['title'] ); ?></h3>
                <?php if ( $featured ) : ?>
                    <span class="cw-lb-card__badge"><?php esc_html_e( 'Featured', 'creativewings-core' ); ?></span>
                <?php endif; ?>
            </header>

            <div class="cw-lb-card__you">
                <?php if ( $you_rank > 0 ) : ?>
                    <span class="cw-lb-card__you-rank">#<?php echo (int) $you_rank; ?></span>
                    <span class="cw-lb-card__you-copy">
                        <?php
                        printf(
                            /* translators: %s: formatted points */
                            esc_html__( 'You · %s pts', 'creativewings-core' ),
                            esc_html( number_format_i18n( $you_pts ) )
                        );
                        ?>
                    </span>
                <?php else : ?>
                    <span class="cw-lb-card__you-copy cw-lb-card__you-copy--muted">
                        <?php esc_html_e( 'You are unranked here', 'creativewings-core' ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ( empty( $rows ) ) : ?>
                <p class="cw-lb-card__empty"><?php echo esc_html( (string) ( $panel['empty'] ?? '' ) ); ?></p>
            <?php else : ?>
                <ol class="cw-lb-list">
                    <?php foreach ( $rows as $row ) :
                        $is_me = ( (int) $row['user_id'] === (int) $uid );
                        $rank  = (int) $row['rank'];
                        $medal = $rank <= 3 ? ' cw-lb-row--medal-' . $rank : '';
                        ?>
                        <li class="cw-lb-row<?php echo $is_me ? ' is-me' : ''; ?><?php echo esc_attr( $medal ); ?>">
                            <span class="cw-lb-row__rank" aria-hidden="true">
                                <?php if ( $rank <= 3 ) : ?>
                                    <span class="cw-lb-medal"><?php echo (int) $rank; ?></span>
                                <?php else : ?>
                                    <?php echo (int) $rank; ?>
                                <?php endif; ?>
                            </span>
                            <span class="cw-lb-row__name">
                                <?php echo esc_html( (string) $row['display_name'] ); ?>
                                <?php if ( $is_me ) : ?>
                                    <span class="cw-lb-you-pill"><?php esc_html_e( 'You', 'creativewings-core' ); ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="cw-lb-row__pts">
                                <?php echo esc_html( number_format_i18n( (int) $row['points'] ) ); ?>
                                <span class="cw-lb-row__pts-unit"><?php esc_html_e( 'pts', 'creativewings-core' ); ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </article>
        <?php
    }
}