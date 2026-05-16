<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Claim_Flow {

    public function __construct() {
        add_action( 'init', [ $this, 'register_endpoint' ] );
        add_action( 'template_redirect', [ $this, 'maybe_redirect_to_dashboard_tab' ], 5 );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'account_menu' ], 99 );
        add_action( 'woocommerce_account_cw-link-submission_endpoint', [ $this, 'render_endpoint' ] );

        add_action( 'admin_post_cw_claim_lookup', [ $this, 'handle_lookup' ] );
        add_action( 'admin_post_cw_claim_confirm', [ $this, 'handle_confirm' ] );
        add_action( 'admin_post_cw_claim_continue', [ $this, 'handle_continue' ] );
        add_action( 'admin_post_cw_claim_cancel', [ $this, 'handle_cancel' ] );

        add_filter( 'woocommerce_get_item_data', [ $this, 'display_claim_cart' ], 20, 2 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'maybe_zero_claim_line' ], 25 );

        add_action( 'woocommerce_after_order_notes', [ $this, 'checkout_message_field' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_checkout_message' ] );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_message' ] );
    }

    public function register_endpoint() {
        add_rewrite_endpoint( 'cw-link-submission', EP_ROOT | EP_PAGES );
    }

    /**
     * Map legacy /my-account/cw-link-submission/ URLs to ?tab=link-submission for the custom portal.
     */
    public function maybe_redirect_to_dashboard_tab() {
        if ( ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
            return;
        }
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'cw-link-submission' ) ) {
            return;
        }
        if ( isset( $_GET['tab'] ) && 'link-submission' === $_GET['tab'] ) {
            return;
        }
        $user = wp_get_current_user();
        if ( ! class_exists( 'CW_Roles' ) || 'contestant' !== CW_Roles::get_dashboard_role( $user ) ) {
            return;
        }

        $args = wp_unslash( $_GET );
        $args['tab'] = 'link-submission';
        wp_safe_redirect( add_query_arg( $args, get_permalink( wc_get_page_id( 'myaccount' ) ) ) );
        exit;
    }

    public function account_menu( $items ) {
        $new = [];
        foreach ( $items as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'dashboard' === $key ) {
                $new['cw-link-submission'] = __( 'Link submission code', 'creativewings-core' );
            }
        }
        return $new;
    }

    /**
     * Contestant dashboard uses ?tab=link-submission; WC endpoint URLs alone show Overview.
     *
     * @param array<string, string> $args Query args (step, claim_token, error, linked, …).
     */
    public function get_link_submission_url( $args = [] ) {
        $base = get_permalink( wc_get_page_id( 'myaccount' ) );
        if ( ! $base ) {
            $base = home_url( '/my-account/' );
        }
        $url = add_query_arg( 'tab', 'link-submission', $base );
        if ( ! empty( $args ) ) {
            $url = add_query_arg( $args, $url );
        }
        return $url;
    }

    public function render_endpoint() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $base  = $this->get_link_submission_url();
        $step  = sanitize_text_field( $_GET['step'] ?? 'enter' );
        $token = sanitize_text_field( $_GET['claim_token'] ?? '' );

        if ( 'confirm' === $step && $token && class_exists( 'CW_Security' ) ) {
            $sess = CW_Security::get_claim_session( get_current_user_id() );
            if ( $sess && hash_equals( $sess['token'], $token ) ) {
                $this->render_confirm_step( (int) $sess['staged_id'], $base );
                return;
            }
            $this->render_claim_shell_open(
                __( 'Link your submission code', 'creativewings-core' ),
                __( 'Enter the code from your school to continue.', 'creativewings-core' )
            );
            echo '<div class="cw-alert error">' . esc_html__( 'Session expired. Please enter your code again.', 'creativewings-core' ) . '</div>';
            $this->render_enter_step_form( $base );
            $this->render_claim_shell_close();
            return;
        }

        if ( 'waiting' === $step ) {
            $this->render_waiting_step( $base );
            return;
        }

        $this->render_enter_step( $base );
    }

    private function render_claim_shell_open( $title, $subtitle = '' ) {
        echo '<div class="cw-content-wrapper cw-claim-page">';
        echo '<div class="cw-dash-header">';
        echo '<h2>' . esc_html( $title ) . '</h2>';
        if ( $subtitle ) {
            echo '<p>' . esc_html( $subtitle ) . '</p>';
        }
        echo '</div>';
    }

    private function render_claim_shell_close() {
        echo '</div>';
    }

    private function render_enter_step_form( $base ) {
        ?>
        <div class="cw-claim-card">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cw_claim_lookup', 'cw_claim_nonce' ); ?>
                <input type="hidden" name="action" value="cw_claim_lookup">
                <div class="cw-settings-field">
                    <label for="cw-claim-code-input"><?php esc_html_e( 'Submission code', 'creativewings-core' ); ?></label>
                    <input type="text" id="cw-claim-code-input" name="submission_code" required minlength="13" maxlength="14" inputmode="numeric" class="cw-claim-input" placeholder="0020500100001" autocomplete="off">
                </div>
                <div class="cw-claim-actions">
                    <button type="submit" class="cw-btn-primary"><?php esc_html_e( 'Continue', 'creativewings-core' ); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * @param array<int, array<string, mixed>> $pending_list
     */
    private function render_pending_cards( $pending_list, $base, $compact = false, $section_title = '' ) {
        if ( empty( $pending_list ) ) {
            return;
        }
        if ( $section_title ) {
            echo '<h3 class="cw-claim-section-title">' . esc_html( $section_title ) . '</h3>';
        }
        if ( ! $compact ) {
            echo '<p class="cw-claim-section-note">' . esc_html__( 'Each campaign is separate. Cancel a code if you entered the wrong one, then register the correct code for that same campaign.', 'creativewings-core' ) . '</p>';
        }
        foreach ( $pending_list as $p ) {
            $campaign_id = (int) $p['campaign_id'];
            $pending_id  = (int) $p['id'];
            $staged      = CW_Staged_Submissions::get_by_code( $p['submission_code'], $campaign_id );
            $has_artwork = class_exists( 'CW_Campaign_Fields' )
                ? CW_Campaign_Fields::staged_has_required_uploads( $staged, $campaign_id )
                : (int) ( $staged['artwork_attachment_id'] ?? 0 ) > 0;
            $ready       = $staged
                && ( $staged['status'] ?? '' ) === 'staged'
                && $has_artwork
                && ( $staged['moderation_status'] ?? 'approved' ) === 'approved';
            ?>
            <div class="cw-pending-card">
                <p class="cw-pending-card-code"><?php echo esc_html( $p['submission_code'] ); ?></p>
                <p class="cw-pending-card-title"><?php echo esc_html( get_the_title( $campaign_id ) ); ?></p>
                <?php if ( $ready ) : ?>
                    <p><?php esc_html_e( 'Artwork is ready — continue to confirm the student name and checkout.', 'creativewings-core' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'cw_claim_continue', 'cw_claim_nonce' ); ?>
                        <input type="hidden" name="action" value="cw_claim_continue">
                        <input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign_id ); ?>">
                        <div class="cw-claim-actions">
                            <button type="submit" class="cw-btn-primary"><?php esc_html_e( 'Continue to checkout', 'creativewings-core' ); ?></button>
                            <?php $this->render_cancel_button( $pending_id, $campaign_id ); ?>
                        </div>
                    </form>
                <?php else : ?>
                    <p><?php esc_html_e( 'Waiting for your school to upload this submission.', 'creativewings-core' ); ?></p>
                    <div class="cw-claim-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                            <?php wp_nonce_field( 'cw_claim_continue', 'cw_claim_nonce' ); ?>
                            <input type="hidden" name="action" value="cw_claim_continue">
                            <input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign_id ); ?>">
                            <button type="submit" class="cw-btn-outline-blue"><?php esc_html_e( 'Check again', 'creativewings-core' ); ?></button>
                        </form>
                        <?php $this->render_cancel_button( $pending_id, $campaign_id ); ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    private function render_cancel_button( $pending_id, $campaign_id ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cw-cancel-pending-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this code registration? You can enter the correct code afterwards.', 'creativewings-core' ) ); ?>');">
            <?php wp_nonce_field( 'cw_claim_cancel', 'cw_claim_nonce' ); ?>
            <input type="hidden" name="action" value="cw_claim_cancel">
            <input type="hidden" name="pending_id" value="<?php echo esc_attr( (string) $pending_id ); ?>">
            <input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $campaign_id ); ?>">
            <button type="submit" class="cw-btn-cancel"><?php esc_html_e( 'Cancel this code', 'creativewings-core' ); ?></button>
        </form>
        <?php
    }

    private function render_enter_step( $base ) {
        $this->render_claim_shell_open(
            __( 'Link your submission code', 'creativewings-core' ),
            __( 'Enter the code from your school (e.g. 0020500100001). Link one code per campaign — for several activities, enter each campaign’s code separately. We email you when the school uploads and you can checkout.', 'creativewings-core' )
        );
        if ( ! empty( $_GET['linked'] ) ) {
            echo '<div class="cw-alert success">' . esc_html__( 'Your code is saved. We will email you when your school has uploaded the artwork.', 'creativewings-core' ) . '</div>';
        }
        if ( ! empty( $_GET['cancelled'] ) ) {
            echo '<div class="cw-alert success">' . esc_html__( 'Code registration removed. You can enter the correct code below.', 'creativewings-core' ) . '</div>';
        }
        if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
            $pending_list = CW_Pending_Parent_Link::list_for_user( get_current_user_id() );
            if ( ! empty( $pending_list ) ) {
                $this->render_pending_cards( $pending_list, $base, true, __( 'Your registered codes (waiting for school)', 'creativewings-core' ) );
            }
        }
        if ( ! empty( $_GET['error'] ) ) {
            echo '<div class="cw-alert error">' . esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) . '</div>';
        }
        $this->render_enter_step_form( $base );
        $this->render_claim_shell_close();
    }

    private function render_confirm_step( $staged_id, $base ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CW_Staged_Submissions::table() . ' WHERE id = %d', $staged_id ), ARRAY_A );
        if ( ! $row || ( $row['status'] ?? '' ) !== 'staged' ) {
            $this->render_claim_shell_open( __( 'Confirm student name', 'creativewings-core' ), '' );
            echo '<div class="cw-alert error">' . esc_html__( 'Invalid submission.', 'creativewings-core' ) . '</div>';
            echo '<a href="' . esc_url( $base ) . '" class="cw-claim-back-link"><i class="fas fa-arrow-left" aria-hidden="true"></i> ' . esc_html__( 'Back to link code', 'creativewings-core' ) . '</a>';
            $this->render_claim_shell_close();
            return;
        }
        $mod = $row['moderation_status'] ?? 'approved';
        if ( 'approved' !== $mod ) {
            $this->render_claim_shell_open( __( 'Confirm student name', 'creativewings-core' ), '' );
            echo '<div class="cw-alert error">' . esc_html__( 'This artwork is awaiting school approval. Please try again later.', 'creativewings-core' ) . '</div>';
            echo '<a href="' . esc_url( $base ) . '" class="cw-claim-back-link"><i class="fas fa-arrow-left" aria-hidden="true"></i> ' . esc_html__( 'Back to link code', 'creativewings-core' ) . '</a>';
            $this->render_claim_shell_close();
            return;
        }
        $sess  = class_exists( 'CW_Security' ) ? CW_Security::get_claim_session( get_current_user_id() ) : null;
        $token = $sess['token'] ?? '';

        $art_html = '';
        if ( class_exists( 'CW_Campaign_Fields' ) ) {
            $campaign_id = (int) ( $row['campaign_id'] ?? 0 );
            $art_aid     = CW_Campaign_Fields::get_primary_artwork_attachment_id( $row, $campaign_id );
            if ( $art_aid ) {
                $art_html = wp_get_attachment_image( $art_aid, 'medium', false, [ 'alt' => '' ] );
            }
        }

        $this->render_claim_shell_open(
            __( 'Confirm student name', 'creativewings-core' ),
            __( 'Please verify the name your school entered matches your child before checkout.', 'creativewings-core' )
        );
        ?>
        <div class="cw-claim-card cw-claim-confirm">
            <?php if ( $art_html ) : ?>
                <div class="cw-claim-confirm-art"><?php echo $art_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            <?php else : ?>
                <div class="cw-claim-confirm-icon" aria-hidden="true"><i class="fas fa-user-check"></i></div>
            <?php endif; ?>
            <p class="cw-claim-confirm-label"><?php esc_html_e( 'Student name', 'creativewings-core' ); ?></p>
            <p class="cw-claim-confirm-name"><?php echo esc_html( $row['student_name'] ); ?></p>
            <p class="cw-claim-confirm-question"><?php esc_html_e( 'Is this correct?', 'creativewings-core' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cw_claim_confirm', 'cw_claim_nonce' ); ?>
                <input type="hidden" name="action" value="cw_claim_confirm">
                <input type="hidden" name="claim_token" value="<?php echo esc_attr( $token ); ?>">
                <input type="hidden" name="confirm" value="yes">
                <div class="cw-claim-actions">
                    <button type="submit" class="cw-btn-primary"><?php esc_html_e( 'Correct — continue to checkout', 'creativewings-core' ); ?></button>
                    <a href="<?php echo esc_url( $base ); ?>" class="cw-btn-outline-blue"><?php esc_html_e( 'Not correct', 'creativewings-core' ); ?></a>
                </div>
            </form>
        </div>
        <a href="<?php echo esc_url( $base ); ?>" class="cw-claim-back-link"><i class="fas fa-arrow-left" aria-hidden="true"></i> <?php esc_html_e( 'Enter a different code', 'creativewings-core' ); ?></a>
        <?php
        $this->render_claim_shell_close();
    }

    private function render_waiting_step( $base ) {
        $uid     = get_current_user_id();
        $pending = class_exists( 'CW_Pending_Parent_Link' )
            ? CW_Pending_Parent_Link::list_for_user( $uid )
            : [];
        if ( empty( $pending ) ) {
            wp_safe_redirect( $base );
            exit;
        }

        $this->render_claim_shell_open(
            __( 'Waiting for school upload', 'creativewings-core' ),
            __( 'We will email you when your school uploads. Each campaign has its own code — you can register for multiple activities at the same time.', 'creativewings-core' )
        );
        if ( ! empty( $_GET['linked'] ) ) {
            echo '<div class="cw-alert success">' . esc_html__( 'Your code is saved. We will email you when your school has uploaded the artwork.', 'creativewings-core' ) . '</div>';
        }
        if ( ! empty( $_GET['cancelled'] ) ) {
            echo '<div class="cw-alert success">' . esc_html__( 'Code registration removed.', 'creativewings-core' ) . '</div>';
        }
        $this->render_pending_cards( $pending, $base, false, '' );
        echo '<a href="' . esc_url( $base ) . '" class="cw-claim-back-link"><i class="fas fa-arrow-left" aria-hidden="true"></i> ' . esc_html__( 'Enter another code', 'creativewings-core' ) . '</a>';
        $this->render_claim_shell_close();
    }


    public function handle_cancel() {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['cw_claim_nonce'] ?? '', 'cw_claim_cancel' ) ) {
            wp_die( 'Security check failed', 403 );
        }

        $uid         = get_current_user_id();
        $pending_id  = absint( $_POST['pending_id'] ?? 0 );
        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $base        = $this->get_link_submission_url();
        $redirect    = add_query_arg( 'step', 'waiting', $base );

        if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
            if ( $pending_id ) {
                CW_Pending_Parent_Link::delete_for_user_by_id( $uid, $pending_id );
            } elseif ( $campaign_id ) {
                CW_Pending_Parent_Link::delete_for_user_campaign( $uid, $campaign_id );
            }
        }

        if ( class_exists( 'CW_Security' ) ) {
            $sess = CW_Security::get_claim_session( $uid );
            if ( $sess && ( ! $campaign_id || (int) ( $sess['campaign_id'] ?? 0 ) === $campaign_id ) ) {
                CW_Security::clear_claim_session( $uid );
            }
        }

        $remaining = class_exists( 'CW_Pending_Parent_Link' ) ? CW_Pending_Parent_Link::list_for_user( $uid ) : [];
        if ( empty( $remaining ) ) {
            $redirect = add_query_arg( 'cancelled', '1', $base );
        } else {
            $redirect = add_query_arg( 'cancelled', '1', $redirect );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_continue() {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['cw_claim_nonce'] ?? '', 'cw_claim_continue' ) ) {
            wp_die( 'Security check failed', 403 );
        }

        $uid         = get_current_user_id();
        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $base        = $this->get_link_submission_url();
        $pending     = class_exists( 'CW_Pending_Parent_Link' )
            ? CW_Pending_Parent_Link::get_for_user_campaign( $uid, $campaign_id )
            : null;

        if ( ! $pending ) {
            wp_safe_redirect( $base );
            exit;
        }

        $row = CW_Staged_Submissions::get_by_code( $pending['submission_code'], $campaign_id );
        $has_artwork = $row && (
            class_exists( 'CW_Campaign_Fields' )
                ? CW_Campaign_Fields::staged_has_required_uploads( $row, $campaign_id )
                : (int) ( $row['artwork_attachment_id'] ?? 0 ) > 0
        );
        if ( ! $has_artwork ) {
            wp_safe_redirect( add_query_arg( 'step', 'waiting', $base ) );
            exit;
        }

        if ( ( $row['status'] ?? '' ) === 'claimed' ) {
            CW_Pending_Parent_Link::delete_for_user_campaign( $uid, $campaign_id );
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'This code is already linked to an account.' ), $base ) );
            exit;
        }

        if ( ( $row['moderation_status'] ?? 'approved' ) !== 'approved' ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Artwork is not approved yet.' ), $base ) );
            exit;
        }

        $token = class_exists( 'CW_Security' )
            ? CW_Security::set_claim_session( $uid, (int) $row['id'], $campaign_id )
            : '';

        wp_safe_redirect( add_query_arg( [ 'step' => 'confirm', 'claim_token' => $token ], $base ) );
        exit;
    }

    public function handle_lookup() {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['cw_claim_nonce'] ?? '', 'cw_claim_lookup' ) ) {
            wp_die( 'Security check failed', 403 );
        }

        if ( class_exists( 'CW_Security' ) ) {
            $rl = CW_Security::rate_limit( CW_Security::RATE_REGISTRATION . 'claim', 20, 3600 );
            if ( is_wp_error( $rl ) ) {
                wp_safe_redirect( add_query_arg( 'error', rawurlencode( $rl->get_error_message() ), $this->get_link_submission_url() ) );
                exit;
            }
        }

        $base   = $this->get_link_submission_url();
        $parsed = CW_Submission_Code::parse( sanitize_text_field( $_POST['submission_code'] ?? '' ) );

        if ( ! $parsed['valid'] ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $parsed['error'] ), $base ) );
            exit;
        }

        $campaign_id = class_exists( 'CW_Campaign_Resolver' )
            ? CW_Campaign_Resolver::get_id_by_serial( $parsed['campaign'] )
            : 0;

        if ( ! $campaign_id || get_post_type( $campaign_id ) !== 'product' ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Campaign not found for this code.' ), $base ) );
            exit;
        }
        if ( ! CW_Submission_Code::matches_campaign_serial( $parsed, $campaign_id ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Campaign code does not match this campaign.' ), $base ) );
            exit;
        }

        $uid = get_current_user_id();
        if ( CW_Staged_Submissions::user_has_claimed_campaign( $uid, $campaign_id ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'You already linked a submission for this campaign.' ), $base ) );
            exit;
        }
        if ( class_exists( 'CW_Pending_Parent_Link' ) && CW_Pending_Parent_Link::user_has_pending( $uid, $campaign_id ) ) {
            $existing_pending = CW_Pending_Parent_Link::get_for_user_campaign( $uid, $campaign_id );
            if ( $existing_pending && $existing_pending['submission_code'] !== $parsed['normalized'] ) {
                wp_safe_redirect( add_query_arg( 'error', rawurlencode( __( 'You already registered a different code for this campaign. Cancel that code below (or on the waiting page), then enter the correct one.', 'creativewings-core' ) ), $base ) );
                exit;
            }
        }

        $row = CW_Staged_Submissions::get_by_code( $parsed['normalized'], $campaign_id );
        if ( ! $row ) {
            $held = class_exists( 'CW_Pending_Parent_Link' )
                ? CW_Pending_Parent_Link::get_by_code( $parsed['normalized'], $campaign_id )
                : null;
            if ( $held && (int) $held['user_id'] !== $uid ) {
                wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Another account has already registered this code while waiting for school upload.' ), $base ) );
                exit;
            }
            if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
                CW_Pending_Parent_Link::save( $uid, $parsed, $campaign_id );
            }
            wp_safe_redirect( add_query_arg( [ 'step' => 'waiting', 'linked' => '1' ], $base ) );
            exit;
        }

        if ( ( $row['status'] ?? '' ) === 'claimed' ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'This code is already linked to an account.' ), $base ) );
            exit;
        }

        if ( ( $row['moderation_status'] ?? 'approved' ) !== 'approved' ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Artwork is not approved yet.' ), $base ) );
            exit;
        }

        if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
            CW_Pending_Parent_Link::delete_for_user_campaign( $uid, $campaign_id );
        }

        $token = class_exists( 'CW_Security' )
            ? CW_Security::set_claim_session( $uid, (int) $row['id'], $campaign_id )
            : '';

        wp_safe_redirect( add_query_arg( [ 'step' => 'confirm', 'claim_token' => $token ], $base ) );
        exit;
    }

    public function handle_confirm() {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['cw_claim_nonce'] ?? '', 'cw_claim_confirm' ) ) {
            wp_die( 'Security check failed', 403 );
        }

        if ( empty( $_POST['confirm'] ) || 'yes' !== $_POST['confirm'] ) {
            wp_safe_redirect( $this->get_link_submission_url() );
            exit;
        }

        $uid   = get_current_user_id();
        $token = sanitize_text_field( $_POST['claim_token'] ?? '' );
        $sess  = class_exists( 'CW_Security' ) ? CW_Security::get_claim_session( $uid ) : null;

        if ( ! $sess || ! hash_equals( $sess['token'], $token ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Session expired.' ), $this->get_link_submission_url() ) );
            exit;
        }

        $staged_id = (int) $sess['staged_id'];
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CW_Staged_Submissions::table() . ' WHERE id = %d', $staged_id ), ARRAY_A );

        if ( ! $row || ( $row['status'] ?? '' ) !== 'staged' ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Invalid submission.' ), $this->get_link_submission_url() ) );
            exit;
        }

        if ( ! CW_Staged_Submissions::reserve_for_claim( $staged_id, $uid ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'This code is being claimed by another user. Try again shortly.' ), $this->get_link_submission_url() ) );
            exit;
        }

        $bracket = CW_Staged_Submissions::resolve_age_bracket( (int) $row['campaign_id'], CW_Staged_Submissions::get_user_birthdate( $uid ) );
        if ( is_wp_error( $bracket ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $bracket->get_error_message() ), $this->get_link_submission_url() ) );
            exit;
        }

        CW_Staged_Submissions::update( $staged_id, [ 'age_bracket_key' => $bracket['key'] ] );

        if ( class_exists( 'CW_Audit_Log' ) ) {
            CW_Audit_Log::log( 'claim_checkout_start', 'staged', $staged_id, [ 'code' => $row['submission_code'] ] );
        }

        $cart_ready = $this->ensure_wc_cart_loaded();
        if ( is_wp_error( $cart_ready ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $cart_ready->get_error_message() ), $this->get_link_submission_url() ) );
            exit;
        }

        $product_id = (int) $row['campaign_id'];
        $product    = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( __( 'Campaign not found.', 'creativewings-core' ) ), $this->get_link_submission_url() ) );
            exit;
        }

        $block = class_exists( 'CW_Shop' ) ? CW_Shop::get_registration_block_reason( $product_id, true ) : null;
        if ( $block ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $block ), $this->get_link_submission_url() ) );
            exit;
        }

        WC()->cart->empty_cart();

        $cart_item_data = [
            'cw_staged_id'         => $staged_id,
            'cw_claim_code'        => $row['submission_code'],
            'cw_age_bracket_key'   => $bracket['key'],
            'cw_age_bracket_label' => $bracket['label'],
            'unique_key'           => 'cw_claim_' . $staged_id,
        ];

        $GLOBALS['cw_claim_checkout_flow'] = true;
        if ( ! $product->is_purchasable() ) {
            unset( $GLOBALS['cw_claim_checkout_flow'] );
            $msg = __( 'This campaign cannot be added to the cart. Check that it is published and has a price set.', 'creativewings-core' );
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $msg ), $this->get_link_submission_url() ) );
            exit;
        }

        $added = WC()->cart->add_to_cart( $product_id, 1, 0, [], $cart_item_data );
        unset( $GLOBALS['cw_claim_checkout_flow'] );

        if ( ! $added ) {
            $msg = __( 'Could not add this campaign to your cart. Please try again.', 'creativewings-core' );
            if ( function_exists( 'wc_get_notices' ) ) {
                $errors = wc_get_notices( 'error' );
                if ( ! empty( $errors[0]['notice'] ) ) {
                    $msg = wp_strip_all_tags( $errors[0]['notice'] );
                }
                wc_clear_notices();
            }
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $msg ), $this->get_link_submission_url() ) );
            exit;
        }

        WC()->cart->calculate_totals();
        $this->persist_wc_cart_session();

        if ( class_exists( 'CW_Shop' ) ) {
            CW_Shop::set_school_claim_checkout_session( $product_id, $staged_id );
        }

        CW_Security::clear_claim_session( $uid );
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    /**
     * WooCommerce cart is not initialized on admin-post.php; load it before checkout redirect.
     *
     * @return true|WP_Error
     */
    private function ensure_wc_cart_loaded() {
        if ( ! function_exists( 'WC' ) || ! WC() ) {
            return new WP_Error( 'no_wc', __( 'WooCommerce is not available.', 'creativewings-core' ) );
        }

        $this->load_wc_frontend_functions();

        if ( null === WC()->cart ) {
            if ( is_callable( [ WC(), 'initialize_session' ] ) ) {
                WC()->initialize_session();
            }
            if ( is_callable( [ WC(), 'initialize_cart' ] ) ) {
                WC()->initialize_cart();
            } elseif ( function_exists( 'wc_load_cart' ) ) {
                wc_load_cart();
            }
        }

        if ( ! WC()->cart || ! WC()->cart instanceof WC_Cart ) {
            return new WP_Error( 'no_cart', __( 'Could not load the shopping cart. Please try again.', 'creativewings-core' ) );
        }

        return true;
    }

    /**
     * admin-post.php does not load storefront helpers; add_to_cart() needs wc_add_notice().
     */
    private function load_wc_frontend_functions() {
        if ( function_exists( 'wc_add_notice' ) ) {
            return;
        }

        if ( is_callable( [ WC(), 'frontend_includes' ] ) ) {
            WC()->frontend_includes();
        }

        if ( function_exists( 'wc_add_notice' ) ) {
            return;
        }

        if ( ! defined( 'WC_ABSPATH' ) ) {
            return;
        }

        $files = [
            'includes/wc-notice-functions.php',
            'includes/wc-template-functions.php',
        ];
        foreach ( $files as $file ) {
            $path = WC_ABSPATH . $file;
            if ( is_readable( $path ) ) {
                include_once $path;
            }
        }
    }

    private function persist_wc_cart_session() {
        if ( WC()->cart && is_callable( [ WC()->cart, 'set_session' ] ) ) {
            WC()->cart->set_session();
        }
        if ( WC()->session && is_callable( [ WC()->session, 'save_data' ] ) ) {
            WC()->session->save_data();
        }
    }

    public function display_claim_cart( $item_data, $cart_item ) {
        if ( ! empty( $cart_item['cw_claim_code'] ) ) {
            $item_data[] = [
                'name'  => __( 'Submission code', 'creativewings-core' ),
                'value' => esc_html( $cart_item['cw_claim_code'] ),
            ];
        }
        if ( ! empty( $cart_item['cw_age_bracket_label'] ) ) {
            $item_data[] = [
                'name'  => __( 'Category', 'creativewings-core' ),
                'value' => esc_html( $cart_item['cw_age_bracket_label'] ),
            ];
        }
        return $item_data;
    }

    public function maybe_zero_claim_line( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        foreach ( $cart->get_cart() as $item ) {
            if ( empty( $item['cw_staged_id'] ) ) {
                continue;
            }
            foreach ( WC()->cart->get_applied_coupons() as $code ) {
                $coupon = new WC_Coupon( $code );
                $cid    = (int) get_post_meta( $coupon->get_id(), '_cw_campaign_id', true );
                if ( $cid && (int) $item['product_id'] === $cid ) {
                    $item['data']->set_price( 0 );
                }
            }
        }
    }

    public function checkout_message_field() {
        if ( ! WC()->cart ) {
            return;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['cw_staged_id'] ) ) {
                continue;
            }
            $pid = (int) $item['product_id'];
            if ( get_post_meta( $pid, 'cw_enable_checkout_message', true ) !== 'yes' ) {
                return;
            }
            $label = get_post_meta( $pid, 'cw_checkout_message_label', true ) ?: __( 'Your message', 'creativewings-core' );
            $req   = get_post_meta( $pid, 'cw_checkout_message_required', true ) === 'yes';
            echo '<div class="cw-checkout-message-card">';
            woocommerce_form_field(
                'cw_checkout_message',
                [
                    'type'     => 'textarea',
                    'class'    => [ 'form-row-wide', 'cw-checkout-message-field' ],
                    'label'    => esc_html( $label ),
                    'required' => $req,
                ],
                WC()->checkout->get_value( 'cw_checkout_message' )
            );
            echo '</div>';
            break;
        }
    }

    public function validate_checkout_message() {
        if ( ! WC()->cart ) {
            return;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['cw_staged_id'] ) ) {
                continue;
            }
            $pid = (int) $item['product_id'];
            if ( get_post_meta( $pid, 'cw_enable_checkout_message', true ) === 'yes'
                && get_post_meta( $pid, 'cw_checkout_message_required', true ) === 'yes'
                && empty( $_POST['cw_checkout_message'] ) ) {
                wc_add_notice( __( 'Please enter your message.', 'creativewings-core' ), 'error' );
            }
        }
    }

    public function save_checkout_message( $order_id ) {
        if ( empty( $_POST['cw_checkout_message'] ) ) {
            return;
        }
        $msg = sanitize_textarea_field( wp_unslash( $_POST['cw_checkout_message'] ) );
        update_post_meta( $order_id, 'cw_checkout_message', $msg );
        update_post_meta( $order_id, '_cw_campaign_product', '' );

        if ( ! WC()->cart ) {
            return;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['cw_staged_id'] ) ) {
                CW_Staged_Submissions::update( (int) $item['cw_staged_id'], [ 'checkout_message' => $msg ] );
                update_post_meta( $order_id, '_cw_campaign_product', (string) (int) $item['product_id'] );
            }
        }
    }
}
