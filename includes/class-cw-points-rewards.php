<?php
/**
 * Points merchandise catalog + redemption log (My Account only — no public shop).
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Points_Rewards {

    const DB_OPTION      = 'cw_points_rewards_db_version';
    const DB_VERSION     = '1.0.0';
    const OPTION_CATALOG = 'cw_points_rewards_catalog';

    public static function register_hooks() {
        add_action( 'init', [ __CLASS__, 'maybe_install' ], 6 );
    }

    public static function rewards_table() {
        global $wpdb;
        return $wpdb->prefix . 'cw_points_rewards';
    }

    public static function redemptions_table() {
        global $wpdb;
        return $wpdb->prefix . 'cw_points_redemptions';
    }

    public static function maybe_install() {
        if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
            return;
        }
        self::create_tables();
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $rewards = self::rewards_table();
        $redemptions = self::redemptions_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE $rewards (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(191) NOT NULL DEFAULT '',
                description TEXT NULL,
                image_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                points_cost INT(11) NOT NULL DEFAULT 0,
                stock INT(11) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT(11) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY is_active (is_active),
                KEY sort_order (sort_order)
            ) $charset;"
        );

        dbDelta(
            "CREATE TABLE $redemptions (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                reward_id BIGINT(20) UNSIGNED NOT NULL,
                points_spent INT(11) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                note TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY reward_id (reward_id),
                KEY status (status)
            ) $charset;"
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_rewards( $active_only = false ) {
        global $wpdb;
        self::maybe_install();
        $table = self::rewards_table();
        $sql   = "SELECT * FROM {$table}";
        if ( $active_only ) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id DESC';
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    public static function get_reward( $id ) {
        global $wpdb;
        self::maybe_install();
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::rewards_table() . ' WHERE id = %d', (int) $id ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    public static function save_reward( array $data, $id = 0 ) {
        global $wpdb;
        self::maybe_install();
        $now = current_time( 'mysql' );
        $title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
        if ( $title === '' ) {
            return new WP_Error( 'cw_reward_title', __( 'Title is required.', 'creativewings-core' ) );
        }

        $stock_sql = 'NULL';
        $stock_val = null;
        if ( array_key_exists( 'stock', $data ) && $data['stock'] !== '' && $data['stock'] !== null ) {
            $stock_val = max( 0, (int) $data['stock'] );
            $stock_sql = (string) $stock_val;
        }

        $fields = [
            'title'       => $title,
            'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
            'image_id'    => absint( $data['image_id'] ?? 0 ),
            'points_cost' => max( 1, (int) ( $data['points_cost'] ?? 1 ) ),
            'is_active'   => ! empty( $data['is_active'] ) ? 1 : 0,
            'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
            'updated_at'  => $now,
        ];

        $id = (int) $id;
        $table = self::rewards_table();

        if ( $id > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET title = %s, description = %s, image_id = %d, points_cost = %d,
                     stock = {$stock_sql}, is_active = %d, sort_order = %d, updated_at = %s WHERE id = %d",
                    $fields['title'],
                    $fields['description'],
                    $fields['image_id'],
                    $fields['points_cost'],
                    $fields['is_active'],
                    $fields['sort_order'],
                    $fields['updated_at'],
                    $id
                )
            );
            return $id;
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (title, description, image_id, points_cost, stock, is_active, sort_order, created_at, updated_at)
                 VALUES (%s, %s, %d, %d, {$stock_sql}, %d, %d, %s, %s)",
                $fields['title'],
                $fields['description'],
                $fields['image_id'],
                $fields['points_cost'],
                $fields['is_active'],
                $fields['sort_order'],
                $now,
                $now
            )
        );
        return (int) $wpdb->insert_id;
    }

    public static function delete_reward( $id ) {
        global $wpdb;
        return (bool) $wpdb->delete( self::rewards_table(), [ 'id' => (int) $id ], [ '%d' ] );
    }

    /**
     * Redeem merch for the current user.
     *
     * @return true|WP_Error
     */
    public static function redeem( $user_id, $reward_id ) {
        $user_id   = (int) $user_id;
        $reward_id = (int) $reward_id;
        if ( $user_id <= 0 || $reward_id <= 0 ) {
            return new WP_Error( 'cw_redeem_invalid', __( 'Invalid redemption request.', 'creativewings-core' ) );
        }
        if ( ! class_exists( 'CW_Points' ) ) {
            return new WP_Error( 'cw_redeem_points', __( 'Points system unavailable.', 'creativewings-core' ) );
        }

        $reward = self::get_reward( $reward_id );
        if ( ! $reward || empty( $reward['is_active'] ) ) {
            return new WP_Error( 'cw_redeem_missing', __( 'This reward is not available.', 'creativewings-core' ) );
        }

        $cost = (int) $reward['points_cost'];
        if ( $cost <= 0 ) {
            return new WP_Error( 'cw_redeem_cost', __( 'Invalid reward cost.', 'creativewings-core' ) );
        }

        if ( $reward['stock'] !== null && (int) $reward['stock'] <= 0 ) {
            return new WP_Error( 'cw_redeem_stock', __( 'This reward is out of stock.', 'creativewings-core' ) );
        }

        if ( CW_Points::get_balance( $user_id ) < $cost ) {
            return new WP_Error( 'cw_redeem_balance', __( 'Not enough points.', 'creativewings-core' ) );
        }

        global $wpdb;

        // Reserve stock first when limited.
        if ( $reward['stock'] !== null ) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . self::rewards_table() . ' SET stock = stock - 1, updated_at = %s WHERE id = %d AND stock > 0',
                    current_time( 'mysql' ),
                    $reward_id
                )
            );
            if ( ! $updated ) {
                return new WP_Error( 'cw_redeem_stock', __( 'This reward is out of stock.', 'creativewings-core' ) );
            }
        }

        $ok = CW_Points::debit(
            $user_id,
            $cost,
            'redeem_merch',
            'reward',
            $reward_id,
            sprintf( 'Redeemed: %s', $reward['title'] )
        );
        if ( ! $ok ) {
            if ( $reward['stock'] !== null ) {
                $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE ' . self::rewards_table() . ' SET stock = stock + 1, updated_at = %s WHERE id = %d AND stock IS NOT NULL',
                        current_time( 'mysql' ),
                        $reward_id
                    )
                );
            }
            return new WP_Error( 'cw_redeem_debit', __( 'Could not debit points.', 'creativewings-core' ) );
        }

        $now = current_time( 'mysql' );
        $wpdb->insert(
            self::redemptions_table(),
            [
                'user_id'      => $user_id,
                'reward_id'    => $reward_id,
                'points_spent' => $cost,
                'status'       => 'pending',
                'note'         => '',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );

        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_user_redemptions( $user_id, $limit = 20 ) {
        global $wpdb;
        self::maybe_install();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.*, rw.title AS reward_title
                 FROM ' . self::redemptions_table() . ' r
                 LEFT JOIN ' . self::rewards_table() . ' rw ON rw.id = r.reward_id
                 WHERE r.user_id = %d
                 ORDER BY r.id DESC
                 LIMIT %d',
                (int) $user_id,
                max( 1, (int) $limit )
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_redemptions( $status = '', $limit = 50 ) {
        global $wpdb;
        self::maybe_install();
        $limit = max( 1, min( 200, (int) $limit ) );
        if ( $status !== '' ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT r.*, rw.title AS reward_title, u.display_name, u.user_email
                     FROM ' . self::redemptions_table() . ' r
                     LEFT JOIN ' . self::rewards_table() . ' rw ON rw.id = r.reward_id
                     LEFT JOIN ' . $wpdb->users . ' u ON u.ID = r.user_id
                     WHERE r.status = %s
                     ORDER BY r.id DESC
                     LIMIT %d',
                    sanitize_key( $status ),
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT r.*, rw.title AS reward_title, u.display_name, u.user_email
                     FROM ' . self::redemptions_table() . ' r
                     LEFT JOIN ' . self::rewards_table() . ' rw ON rw.id = r.reward_id
                     LEFT JOIN ' . $wpdb->users . ' u ON u.ID = r.user_id
                     ORDER BY r.id DESC
                     LIMIT %d',
                    $limit
                ),
                ARRAY_A
            );
        }
        return is_array( $rows ) ? $rows : [];
    }

    public static function set_redemption_status( $id, $status ) {
        global $wpdb;
        $status = sanitize_key( $status );
        if ( ! in_array( $status, [ 'pending', 'fulfilled', 'cancelled' ], true ) ) {
            return false;
        }

        $id  = (int) $id;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::redemptions_table() . ' WHERE id = %d', $id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return false;
        }

        $ok = (bool) $wpdb->update(
            self::redemptions_table(),
            [
                'status'     => $status,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        // Refund points when cancelling a pending redemption.
        if ( $ok && $status === 'cancelled' && ( $row['status'] ?? '' ) === 'pending' && class_exists( 'CW_Points' ) ) {
            CW_Points::credit_refund(
                (int) $row['user_id'],
                (int) $row['points_spent'],
                'reward',
                (int) $row['reward_id'],
                'Redemption cancelled — refund'
            );
            if ( $row['reward_id'] && $wpdb->get_var( $wpdb->prepare( 'SELECT stock FROM ' . self::rewards_table() . ' WHERE id = %d', (int) $row['reward_id'] ) ) !== null ) {
                $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE ' . self::rewards_table() . ' SET stock = stock + 1, updated_at = %s WHERE id = %d AND stock IS NOT NULL',
                        current_time( 'mysql' ),
                        (int) $row['reward_id']
                    )
                );
            }
        }

        return $ok;
    }
}
