<?php
/**
 * Admin badge manager.
 *
 *   - Adds "Badges" submenu under the cw_badge CPT (Tools / Admin)
 *   - "Manage" page: total earners per badge, recent awards
 *   - "Manual award" form: pick badge + user + tier
 *
 * @package CreativeWings
 * @since   11.0.60
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Badges_Admin {

    const CAP = 'manage_options';

    public function __construct() {
        add_action( 'admin_menu',        [ $this, 'register_menus' ] );
        add_action( 'admin_post_cw_badge_manual_award', [ $this, 'handle_manual_award' ] );
        add_action( 'admin_post_cw_badge_revoke',       [ $this, 'handle_revoke' ] );
    }

    public function register_menus() {
        add_submenu_page(
            'edit.php?post_type=' . CW_Badges_CPT::POST_TYPE,
            __( 'Badge Manager', 'creativewings-core' ),
            __( 'Badge Manager', 'creativewings-core' ),
            self::CAP,
            'cw-badge-manager',
            [ $this, 'render_manager_page' ]
        );
    }

    public function render_manager_page() {
        if ( ! current_user_can( self::CAP ) ) wp_die( 'Insufficient permissions' );
        global $wpdb;
        $table   = CW_Badges_Installer::table();
        $catalog = CW_Badges_Engine::get_catalog();

        // Earners-per-badge stats.
        $counts_by_badge = [];
        $rows = $wpdb->get_results( "SELECT badge_id, COUNT(DISTINCT user_id) AS owners FROM $table GROUP BY badge_id", ARRAY_A );
        foreach ( $rows as $row ) {
            $counts_by_badge[ (int) $row['badge_id'] ] = (int) $row['owners'];
        }
        $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

        // Recent awards.
        $recent = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT 25", ARRAY_A );

        ?>
        <div class="wrap">
            <h1><i class="dashicons dashicons-awards" style="font-size:28px;vertical-align:middle;"></i> <?php esc_html_e( 'Badge Manager', 'creativewings-core' ); ?></h1>

            <p class="description">
                <?php esc_html_e( 'Manage and award badges manually. Auto-awarding is handled by the engine; use this page for catch-up and special recognitions.', 'creativewings-core' ); ?>
            </p>

            <?php if ( isset( $_GET['cw_msg'] ) ) :
                $msg = sanitize_text_field( wp_unslash( $_GET['cw_msg'] ) );
                $is_err = $msg && strpos( $msg, 'err' ) === 0;
                printf(
                    '<div class="notice %s" style="margin-top:16px;"><p>%s</p></div>',
                    $is_err ? 'notice-error' : 'notice-success',
                    esc_html( str_replace( [ 'err:', 'ok:' ], '', $msg ) )
                );
            endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;margin:18px 0;">
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;">
                    <div style="font-size:11px;color:#555555;text-transform:uppercase;font-weight:700;letter-spacing:0.06em;">
                        <?php esc_html_e( 'Total badges in catalog', 'creativewings-core' ); ?>
                    </div>
                    <div style="font-size:26px;font-weight:800;color:#0f172a;"><?php echo (int) count( $catalog ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;">
                    <div style="font-size:11px;color:#555555;text-transform:uppercase;font-weight:700;letter-spacing:0.06em;">
                        <?php esc_html_e( 'Total awarded (rows)', 'creativewings-core' ); ?>
                    </div>
                    <div style="font-size:26px;font-weight:800;color:#0f172a;"><?php echo (int) $total_rows; ?></div>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;">
                    <div style="font-size:11px;color:#555555;text-transform:uppercase;font-weight:700;letter-spacing:0.06em;">
                        <?php esc_html_e( 'Recent awards', 'creativewings-core' ); ?>
                    </div>
                    <div style="font-size:26px;font-weight:800;color:#0f172a;"><?php echo (int) count( $recent ); ?></div>
                </div>
            </div>

            <h2 style="margin-top:28px;"><?php esc_html_e( 'Manual award', 'creativewings-core' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end;max-width:980px;">
                <input type="hidden" name="action" value="cw_badge_manual_award">
                <?php wp_nonce_field( 'cw_badge_manual_award', 'cw_badge_nonce' ); ?>

                <label>
                    <strong style="display:block;font-size:12px;color:#475569;margin-bottom:4px;"><?php esc_html_e( 'Badge', 'creativewings-core' ); ?></strong>
                    <select name="badge_id" required style="width:100%;">
                        <option value=""><?php esc_html_e( '— Choose a badge —', 'creativewings-core' ); ?></option>
                        <?php foreach ( $catalog as $b ) : ?>
                            <option value="<?php echo (int) $b['id']; ?>"><?php echo esc_html( $b['title'] ); ?> (<?php echo esc_html( $b['slug'] ); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <strong style="display:block;font-size:12px;color:#475569;margin-bottom:4px;"><?php esc_html_e( 'User (login or ID)', 'creativewings-core' ); ?></strong>
                    <input type="text" name="user_ref" required placeholder="user_login or 42" style="width:100%;">
                </label>

                <label>
                    <strong style="display:block;font-size:12px;color:#475569;margin-bottom:4px;"><?php esc_html_e( 'Tier', 'creativewings-core' ); ?></strong>
                    <select name="tier" style="width:100%;">
                        <option value="flat" selected>flat</option>
                        <option value="bronze">bronze</option>
                        <option value="silver">silver</option>
                        <option value="gold">gold</option>
                        <option value="platinum">platinum</option>
                    </select>
                </label>

                <button type="submit" class="button button-primary"><?php esc_html_e( 'Award badge', 'creativewings-core' ); ?></button>
            </form>

            <h2 style="margin-top:28px;"><?php esc_html_e( 'Earners by badge', 'creativewings-core' ); ?></h2>
            <table class="widefat striped" style="margin-top:8px;max-width:1000px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Badge', 'creativewings-core' ); ?></th>
                        <th><?php esc_html_e( 'Slug', 'creativewings-core' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'creativewings-core' ); ?></th>
                        <th><?php esc_html_e( 'Rule', 'creativewings-core' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Earners', 'creativewings-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $catalog as $b ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $b['title'] ); ?></strong></td>
                            <td><code><?php echo esc_html( $b['slug'] ); ?></code></td>
                            <td><?php echo esc_html( CW_Badges_CPT::target_roles()[ $b['target_role'] ] ?? $b['target_role'] ); ?></td>
                            <td><?php echo esc_html( CW_Badges_CPT::rule_types()[ $b['rule_type'] ] ?? $b['rule_type'] ); ?></td>
                            <td style="text-align:right;font-weight:700;"><?php echo (int) ( $counts_by_badge[ (int) $b['id'] ] ?? 0 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:28px;"><?php esc_html_e( 'Recent awards', 'creativewings-core' ); ?></h2>
            <table class="widefat striped" style="max-width:1000px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'When', 'creativewings-core' ); ?></th>
                        <th><?php esc_html_e( 'User', 'creativewings-core' ); ?></th>
                        <th><?php esc_html_e( 'Badge', 'creativewings-core' ); ?></th>
                        <th><?php esc_html_e( 'Tier', 'creativewings-core' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'creativewings-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! $recent ) : ?>
                        <tr><td colspan="5"><em><?php esc_html_e( 'No badges awarded yet.', 'creativewings-core' ); ?></em></td></tr>
                    <?php else : foreach ( $recent as $row ) :
                        $u = get_user_by( 'id', (int) $row['user_id'] );
                        $b = get_post( (int) $row['badge_id'] );
                        $revoke_url = wp_nonce_url( add_query_arg( [
                            'action'   => 'cw_badge_revoke',
                            'user_id'  => (int) $row['user_id'],
                            'badge_id' => (int) $row['badge_id'],
                            'tier'     => $row['tier'],
                        ], admin_url( 'admin-post.php' ) ), 'cw_badge_revoke', 'cw_badge_nonce' );
                    ?>
                        <tr>
                            <td><?php echo esc_html( $row['earned_at'] ); ?></td>
                            <td><?php echo $u ? esc_html( $u->user_login . ' (#' . $u->ID . ')' ) : '—'; ?></td>
                            <td><?php echo $b ? esc_html( get_the_title( $b ) ) : '—'; ?></td>
                            <td><code><?php echo esc_html( $row['tier'] ); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url( $revoke_url ); ?>" class="button button-link-delete"
                                   onclick="return confirm('<?php echo esc_js( __( 'Revoke this badge?', 'creativewings-core' ) ); ?>');">
                                    <?php esc_html_e( 'Revoke', 'creativewings-core' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_manual_award() {
        if ( ! current_user_can( self::CAP ) ) wp_die( 'No' );
        check_admin_referer( 'cw_badge_manual_award', 'cw_badge_nonce' );

        $badge_id = (int) ( $_POST['badge_id'] ?? 0 );
        $tier     = sanitize_key( $_POST['tier'] ?? 'flat' );
        $user_ref = trim( (string) ( $_POST['user_ref'] ?? '' ) );

        $user = is_numeric( $user_ref ) ? get_user_by( 'id', (int) $user_ref ) : get_user_by( 'login', $user_ref );
        if ( ! $user ) {
            $user = get_user_by( 'email', $user_ref );
        }
        if ( ! $user || ! $badge_id ) {
            wp_safe_redirect( add_query_arg( 'cw_msg', 'err:User or badge not found.', wp_get_referer() ) );
            exit;
        }

        $ok = CW_Badges_Engine::manual_award( $user->ID, $badge_id, $tier );
        $msg = $ok
            ? 'ok:' . sprintf( __( 'Awarded %1$s (%2$s) to %3$s.', 'creativewings-core' ), get_the_title( $badge_id ), $tier, $user->user_login )
            : 'err:' . __( 'Award failed (user may already own this tier).', 'creativewings-core' );

        wp_safe_redirect( add_query_arg( 'cw_msg', $msg, wp_get_referer() ) );
        exit;
    }

    public function handle_revoke() {
        if ( ! current_user_can( self::CAP ) ) wp_die( 'No' );
        check_admin_referer( 'cw_badge_revoke', 'cw_badge_nonce' );

        $user_id  = (int) ( $_GET['user_id'] ?? 0 );
        $badge_id = (int) ( $_GET['badge_id'] ?? 0 );
        $tier     = sanitize_key( $_GET['tier'] ?? 'flat' );

        $ok  = CW_Badges_Engine::revoke( $user_id, $badge_id, $tier );
        $msg = $ok ? 'ok:' . __( 'Badge revoked.', 'creativewings-core' ) : 'err:' . __( 'Revoke failed.', 'creativewings-core' );

        wp_safe_redirect( add_query_arg( 'cw_msg', $msg, wp_get_referer() ) );
        exit;
    }
}
