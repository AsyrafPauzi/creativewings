<?php
/**
 * Admin UI: points merchandise catalog + redemptions.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Points_Rewards_Admin {

    const CAP  = 'manage_options';
    const SLUG = 'cw-points-rewards';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_post_cw_points_reward_save', [ $this, 'handle_save' ] );
        add_action( 'admin_post_cw_points_reward_delete', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_cw_points_redemption_status', [ $this, 'handle_redemption_status' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function register_menus() {
        add_submenu_page(
            'woocommerce',
            __( 'Points Merchandise', 'creativewings-core' ),
            __( 'Points Merchandise', 'creativewings-core' ),
            self::CAP,
            self::SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue( $hook ) {
        if ( false === strpos( (string) $hook, self::SLUG ) ) {
            return;
        }
        wp_enqueue_media();
    }

    public function render_page() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Insufficient permissions', 'creativewings-core' ) );
        }
        if ( ! class_exists( 'CW_Points_Rewards' ) ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Rewards module missing.', 'creativewings-core' ) . '</p></div>';
            return;
        }

        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $edit    = $edit_id ? CW_Points_Rewards::get_reward( $edit_id ) : null;
        $rewards = CW_Points_Rewards::get_rewards( false );
        $pending = CW_Points_Rewards::get_redemptions( 'pending', 40 );
        $recent  = CW_Points_Rewards::get_redemptions( '', 40 );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Points Merchandise', 'creativewings-core' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Catalog items are visible only in My Account → Points. They are not public shop products.', 'creativewings-core' ); ?>
            </p>

            <?php if ( ! empty( $_GET['cw_msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['cw_msg'] ) ) ); ?></p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:minmax(280px,420px) 1fr;gap:24px;align-items:start;margin-top:16px;">
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;">
                    <h2 style="margin-top:0;"><?php echo $edit ? esc_html__( 'Edit reward', 'creativewings-core' ) : esc_html__( 'Add reward', 'creativewings-core' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="cw_points_reward_save">
                        <input type="hidden" name="reward_id" value="<?php echo (int) $edit_id; ?>">
                        <?php wp_nonce_field( 'cw_points_reward_save' ); ?>

                        <p>
                            <label><strong><?php esc_html_e( 'Title', 'creativewings-core' ); ?></strong></label><br>
                            <input type="text" name="title" class="widefat" required value="<?php echo esc_attr( $edit['title'] ?? '' ); ?>">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( 'Description', 'creativewings-core' ); ?></strong></label><br>
                            <textarea name="description" class="widefat" rows="3"><?php echo esc_textarea( $edit['description'] ?? '' ); ?></textarea>
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( 'Points cost', 'creativewings-core' ); ?></strong></label><br>
                            <input type="number" name="points_cost" min="1" required value="<?php echo esc_attr( (string) ( $edit['points_cost'] ?? 200 ) ); ?>">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( 'Stock (blank = unlimited)', 'creativewings-core' ); ?></strong></label><br>
                            <input type="number" name="stock" min="0" value="<?php echo isset( $edit['stock'] ) && $edit['stock'] !== null ? esc_attr( (string) $edit['stock'] ) : ''; ?>">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( 'Sort order', 'creativewings-core' ); ?></strong></label><br>
                            <input type="number" name="sort_order" value="<?php echo esc_attr( (string) ( $edit['sort_order'] ?? 0 ) ); ?>">
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="is_active" value="1" <?php checked( empty( $edit ) || ! empty( $edit['is_active'] ) ); ?>>
                                <?php esc_html_e( 'Active (visible in My Account)', 'creativewings-core' ); ?>
                            </label>
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( 'Image', 'creativewings-core' ); ?></strong></label><br>
                            <input type="hidden" name="image_id" id="cw-reward-image-id" value="<?php echo esc_attr( (string) ( $edit['image_id'] ?? 0 ) ); ?>">
                            <button type="button" class="button" id="cw-reward-pick-image"><?php esc_html_e( 'Select image', 'creativewings-core' ); ?></button>
                            <span id="cw-reward-image-preview" style="display:inline-block;margin-left:8px;vertical-align:middle;">
                                <?php
                                $img = ! empty( $edit['image_id'] ) ? wp_get_attachment_image_url( (int) $edit['image_id'], 'thumbnail' ) : '';
                                if ( $img ) {
                                    echo '<img src="' . esc_url( $img ) . '" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:6px;">';
                                }
                                ?>
                            </span>
                        </p>
                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save reward', 'creativewings-core' ); ?></button>
                            <?php if ( $edit ) : ?>
                                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'creativewings-core' ); ?></a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div>
                    <h2><?php esc_html_e( 'Catalog', 'creativewings-core' ); ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Title', 'creativewings-core' ); ?></th>
                                <th><?php esc_html_e( 'Cost', 'creativewings-core' ); ?></th>
                                <th><?php esc_html_e( 'Stock', 'creativewings-core' ); ?></th>
                                <th><?php esc_html_e( 'Active', 'creativewings-core' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $rewards ) ) : ?>
                                <tr><td colspan="5"><?php esc_html_e( 'No rewards yet.', 'creativewings-core' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $rewards as $r ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $r['title'] ); ?></td>
                                        <td><?php echo number_format( (int) $r['points_cost'] ); ?></td>
                                        <td><?php echo $r['stock'] === null ? '∞' : (int) $r['stock']; ?></td>
                                        <td><?php echo ! empty( $r['is_active'] ) ? '✓' : '—'; ?></td>
                                        <td>
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&edit=' . (int) $r['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'creativewings-core' ); ?></a>
                                            |
                                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cw_points_reward_delete&reward_id=' . (int) $r['id'] ), 'cw_points_reward_delete_' . (int) $r['id'] ) ); ?>" onclick="return confirm('Delete this reward?');"><?php esc_html_e( 'Delete', 'creativewings-core' ); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <h2 style="margin-top:28px;"><?php esc_html_e( 'Pending redemptions', 'creativewings-core' ); ?></h2>
                    <?php $this->render_redemptions_table( $pending ); ?>

                    <h2 style="margin-top:28px;"><?php esc_html_e( 'Recent redemptions', 'creativewings-core' ); ?></h2>
                    <?php $this->render_redemptions_table( $recent, true ); ?>
                </div>
            </div>
        </div>
        <script>
        jQuery(function($){
            $('#cw-reward-pick-image').on('click', function(e){
                e.preventDefault();
                var frame = wp.media({ title: 'Select reward image', multiple: false });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    $('#cw-reward-image-id').val(att.id);
                    $('#cw-reward-image-preview').html('<img src="'+(att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url)+'" style="width:48px;height:48px;object-fit:cover;border-radius:6px;" alt="">');
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    private function render_redemptions_table( $rows, $show_all_actions = false ) {
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'creativewings-core' ); ?></th>
                    <th><?php esc_html_e( 'User', 'creativewings-core' ); ?></th>
                    <th><?php esc_html_e( 'Reward', 'creativewings-core' ); ?></th>
                    <th><?php esc_html_e( 'Points', 'creativewings-core' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'creativewings-core' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'None.', 'creativewings-core' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['created_at'] ) ) ); ?></td>
                            <td><?php echo esc_html( ( $row['display_name'] ?? '' ) . ' <' . ( $row['user_email'] ?? '' ) . '>' ); ?></td>
                            <td><?php echo esc_html( $row['reward_title'] ?? ( '#' . $row['reward_id'] ) ); ?></td>
                            <td><?php echo number_format( (int) $row['points_spent'] ); ?></td>
                            <td><?php echo esc_html( $row['status'] ); ?></td>
                            <td>
                                <?php if ( $row['status'] === 'pending' || $show_all_actions ) : ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cw_points_redemption_status&id=' . (int) $row['id'] . '&status=fulfilled' ), 'cw_points_redemption_' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Mark fulfilled', 'creativewings-core' ); ?></a>
                                    |
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cw_points_redemption_status&id=' . (int) $row['id'] . '&status=cancelled' ), 'cw_points_redemption_' . (int) $row['id'] ) ); ?>"><?php esc_html_e( 'Cancel', 'creativewings-core' ); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    public function handle_save() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'cw_points_reward_save' );
        $id = absint( $_POST['reward_id'] ?? 0 );
        $stock_raw = isset( $_POST['stock'] ) ? wp_unslash( $_POST['stock'] ) : '';
        $data = [
            'title'       => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'points_cost' => $_POST['points_cost'] ?? 1,
            'stock'       => ( $stock_raw === '' ? null : $stock_raw ),
            'sort_order'  => $_POST['sort_order'] ?? 0,
            'is_active'   => ! empty( $_POST['is_active'] ),
            'image_id'    => $_POST['image_id'] ?? 0,
        ];
        $result = CW_Points_Rewards::save_reward( $data, $id );
        $msg = is_wp_error( $result ) ? $result->get_error_message() : __( 'Reward saved.', 'creativewings-core' );
        wp_safe_redirect( add_query_arg( 'cw_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }

    public function handle_delete() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Forbidden' );
        }
        $id = absint( $_GET['reward_id'] ?? 0 );
        check_admin_referer( 'cw_points_reward_delete_' . $id );
        CW_Points_Rewards::delete_reward( $id );
        wp_safe_redirect( add_query_arg( 'cw_msg', rawurlencode( __( 'Reward deleted.', 'creativewings-core' ) ), admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }

    public function handle_redemption_status() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Forbidden' );
        }
        $id = absint( $_GET['id'] ?? 0 );
        check_admin_referer( 'cw_points_redemption_' . $id );
        $status = sanitize_key( $_GET['status'] ?? '' );
        CW_Points_Rewards::set_redemption_status( $id, $status );
        wp_safe_redirect( add_query_arg( 'cw_msg', rawurlencode( __( 'Redemption updated.', 'creativewings-core' ) ), admin_url( 'admin.php?page=' . self::SLUG ) ) );
        exit;
    }
}
