<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Guest_Join {

    const ORDER_META_DOB           = 'cw_guest_dob';
    const ORDER_META_FULL_NAME     = 'cw_guest_full_name';
    const ORDER_META_PROFILE       = 'cw_guest_profile';
    const ORDER_META_TOKEN_HASH    = 'cw_guest_complete_token_hash';
    const ORDER_META_TOKEN_EXPIRES = 'cw_guest_complete_token_expires';
    const ORDER_META_COMPLETED     = 'cw_guest_account_completed';
    const SESSION_RESUME_KEY       = 'cw_guest_resume_after_login';
    const META_CHECKOUT_FIELDS     = 'cw_guest_checkout_fields';
    const TOKEN_TTL_DAYS           = 14;

    public function __construct() {
        add_filter( 'pre_option_woocommerce_enable_guest_checkout', [ $this, 'filter_enable_guest_checkout_option' ] );
        add_filter( 'woocommerce_checkout_registration_required', [ $this, 'filter_checkout_registration_required' ] );
        add_filter( 'woocommerce_checkout_fields', [ $this, 'filter_checkout_fields' ], 99 );
        add_filter( 'woocommerce_checkout_posted_data', [ $this, 'filter_checkout_posted_data' ], 20 );
        add_filter( 'woocommerce_checkout_get_value', [ $this, 'filter_checkout_get_value' ], 10, 2 );
        add_filter( 'woocommerce_order_button_text', [ $this, 'filter_order_button_text' ], 20 );
        add_filter( 'body_class', [ $this, 'filter_body_class' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_guest_checkout_assets' ], 30 );
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_guest_dob_field' ], 20 );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_guest_checkout' ], 20 );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_guest_checkout_meta' ], 20 );
        // Guest complete-registration email is sent by CW_Post_Checkout async (not on payment hooks).
        add_filter( 'woocommerce_login_redirect', [ $this, 'filter_woocommerce_login_redirect' ], 20, 2 );
        add_action( 'template_redirect', [ $this, 'maybe_redirect_resume_join' ], 5 );
    }

    /**
     * Organiser-controlled guest checkout extras (hidden|optional|required).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_profile_field_catalogue() {
        return [
            'phone' => [
                'label'  => __( 'Phone Number', 'creativewings-core' ),
                'type'   => 'tel',
                'wc_key' => 'billing_phone',
            ],
            'ic_passport' => [
                'label'    => __( 'IC / Passport', 'creativewings-core' ),
                'type'     => 'text',
                'meta_key' => 'cw_guest_ic_passport',
            ],
            'address' => [
                'label' => __( 'Address (WooCommerce billing address)', 'creativewings-core' ),
                'type'  => 'address',
            ],
            'gender' => [
                'label'    => __( 'Gender', 'creativewings-core' ),
                'type'     => 'select',
                'meta_key' => 'cw_guest_gender',
                'options'  => [
                    ''                   => __( 'Select…', 'creativewings-core' ),
                    'female'             => __( 'Female', 'creativewings-core' ),
                    'male'               => __( 'Male', 'creativewings-core' ),
                    'other'              => __( 'Other', 'creativewings-core' ),
                    'prefer_not_to_say'  => __( 'Prefer not to say', 'creativewings-core' ),
                ],
            ],
            'school' => [
                'label'    => __( 'School', 'creativewings-core' ),
                'type'     => 'text',
                'meta_key' => 'cw_guest_school',
            ],
            'parent_info' => [
                'label'    => __( 'Parent Information', 'creativewings-core' ),
                'type'     => 'textarea',
                'meta_key' => 'cw_guest_parent_info',
            ],
            'emergency_contact' => [
                'label'    => __( 'Emergency Contact', 'creativewings-core' ),
                'type'     => 'text',
                'meta_key' => 'cw_guest_emergency_contact',
            ],
            'order_notes' => [
                'label'    => __( 'Order notes', 'creativewings-core' ),
                'type'     => 'textarea',
                'group'    => 'order',
                'wc_key'   => 'order_comments',
                'default'  => 'optional',
            ],
        ];
    }

    /**
     * @param int $campaign_id
     * @return array<string, string> field_key => hidden|optional|required
     */
    public static function get_checkout_field_modes( $campaign_id ) {
        $catalogue   = self::get_profile_field_catalogue();
        $modes       = [];
        foreach ( $catalogue as $key => $def ) {
            $modes[ $key ] = ( ! empty( $def['default'] ) && in_array( $def['default'], [ 'hidden', 'optional', 'required' ], true ) )
                ? $def['default']
                : 'hidden';
        }
        $campaign_id = (int) $campaign_id;
        if ( $campaign_id <= 0 ) {
            return $modes;
        }

        $stored = get_post_meta( $campaign_id, self::META_CHECKOUT_FIELDS, true );
        if ( ! is_array( $stored ) ) {
            return $modes;
        }

        $allowed = [ 'hidden', 'optional', 'required' ];
        foreach ( $modes as $key => $default ) {
            if ( empty( $stored[ $key ] ) ) {
                continue;
            }
            $mode = sanitize_key( (string) $stored[ $key ] );
            if ( in_array( $mode, $allowed, true ) ) {
                $modes[ $key ] = $mode;
            }
        }

        return $modes;
    }

    /**
     * WooCommerce billing address keys used when organiser enables Address.
     *
     * @return string[]
     */
    public static function get_wc_address_field_keys() {
        return [
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_country',
        ];
    }

    /**
     * Address keys that must be filled when Address mode is required.
     * address_2 stays optional (WooCommerce default).
     *
     * @return string[]
     */
    public static function get_required_wc_address_field_keys() {
        return [
            'billing_address_1',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_country',
        ];
    }

    /**
     * Name / email / DOB already on the member account (for read-only checkout).
     *
     * @return array{full_name:string,email:string,dob:string}
     */
    public static function get_known_identity() {
        $out = [
            'full_name' => '',
            'email'     => '',
            'dob'       => '',
        ];

        if ( ! is_user_logged_in() ) {
            return $out;
        }

        $user = wp_get_current_user();
        if ( ! ( $user instanceof WP_User ) || ! $user->ID ) {
            return $out;
        }

        $name = trim( (string) get_user_meta( $user->ID, 'cw_full_name', true ) );
        if ( '' === $name ) {
            $name = trim( (string) $user->display_name );
        }
        if ( '' === $name ) {
            $first = trim( (string) get_user_meta( $user->ID, 'billing_first_name', true ) );
            $last  = trim( (string) get_user_meta( $user->ID, 'billing_last_name', true ) );
            if ( '.' === $last || '-' === $last ) {
                $last = '';
            }
            $name = trim( $first . ' ' . $last );
        }

        $out['full_name'] = $name;
        $out['email']     = sanitize_email( (string) $user->user_email );
        $out['dob']       = trim( (string) get_user_meta( $user->ID, 'birthdate', true ) );

        return $out;
    }

    /**
     * Mark a WooCommerce form field as read-only (still submitted).
     *
     * @param array<string, mixed> $row
     * @param string               $value
     * @return array<string, mixed>
     */
    public static function mark_readonly_field( $row, $value ) {
        if ( ! is_array( $row ) ) {
            $row = [];
        }
        $attrs = isset( $row['custom_attributes'] ) && is_array( $row['custom_attributes'] )
            ? $row['custom_attributes']
            : [];
        $attrs['readonly'] = 'readonly';
        $row['custom_attributes'] = $attrs;
        $row['default'] = $value;

        $classes = isset( $row['class'] ) ? (array) $row['class'] : [];
        $classes[] = 'cw-identity-readonly';
        $row['class'] = array_values( array_unique( $classes ) );

        // Soft hint under the label.
        $desc = isset( $row['description'] ) ? (string) $row['description'] : '';
        if ( '' === $desc ) {
            $row['description'] = __( 'From your account — contact support if this needs changing.', 'creativewings-core' );
        }

        return $row;
    }

    /**
     * @param mixed $raw
     * @return array<string, string>
     */
    public static function sanitize_checkout_field_modes( $raw ) {
        $modes     = [];
        $allowed   = [ 'hidden', 'optional', 'required' ];
        $catalogue = self::get_profile_field_catalogue();
        foreach ( $catalogue as $key => $def ) {
            $fallback = ( ! empty( $def['default'] ) && in_array( $def['default'], $allowed, true ) )
                ? $def['default']
                : 'hidden';
            $mode = is_array( $raw ) && isset( $raw[ $key ] ) ? sanitize_key( (string) $raw[ $key ] ) : $fallback;
            $modes[ $key ] = in_array( $mode, $allowed, true ) ? $mode : $fallback;
        }
        return $modes;
    }

    /**
     * Display name from order billing (strips placeholder last name ".").
     *
     * @param WC_Order $order
     * @return string
     */
    public static function get_order_full_name( $order ) {
        if ( ! ( $order instanceof WC_Order ) ) {
            return '';
        }
        $stored = $order->get_meta( self::ORDER_META_FULL_NAME );
        if ( is_string( $stored ) && '' !== trim( $stored ) ) {
            return trim( $stored );
        }
        $first = trim( (string) $order->get_billing_first_name() );
        $last  = trim( (string) $order->get_billing_last_name() );
        if ( '.' === $last || '-' === $last ) {
            $last = '';
        }
        return trim( $first . ' ' . $last );
    }

    /**
     * Allow guest checkout for CW campaign carts even when the site option is off.
     *
     * @param mixed $value Stored option value or false when not yet loaded.
     * @return mixed
     */
    public function filter_enable_guest_checkout_option( $value ) {
        if ( self::is_guest_checkout_context() ) {
            return 'yes';
        }

        return $value;
    }

    /**
     * Skip forced account creation on checkout for CW guest registration carts.
     *
     * @param bool $required Whether registration is required.
     * @return bool
     */
    public function filter_checkout_registration_required( $required ) {
        if ( self::is_guest_checkout_context() ) {
            return false;
        }

        return $required;
    }

    /**
     * Repopulate custom checkout fields after validation errors.
     *
     * @param mixed  $value Field value.
     * @param string $input Field key.
     * @return mixed
     */
    public function filter_checkout_get_value( $value, $input ) {
        $post_keys = [
            self::ORDER_META_DOB,
            self::ORDER_META_FULL_NAME,
            'cw_guest_ic_passport',
            'cw_guest_gender',
            'cw_guest_school',
            'cw_guest_parent_info',
            'cw_guest_emergency_contact',
        ];

        if ( isset( $_POST[ $input ] ) && in_array( $input, $post_keys, true ) ) {
            $raw = wp_unslash( $_POST[ $input ] );
            return ( 'cw_guest_parent_info' === $input )
                ? sanitize_textarea_field( $raw )
                : sanitize_text_field( $raw );
        }

        if ( self::cart_has_cw_campaign() ) {
            $known = self::get_known_identity();
            if ( 'billing_email' === $input && $known['email'] ) {
                return $known['email'];
            }
            if ( self::ORDER_META_FULL_NAME === $input && $known['full_name'] ) {
                return $known['full_name'];
            }
            if ( self::ORDER_META_DOB === $input && $known['dob'] ) {
                return $known['dob'];
            }
            if ( $known['full_name'] && in_array( $input, [ 'billing_first_name', 'billing_last_name' ], true ) ) {
                $parts = preg_split( '/\s+/', $known['full_name'], 2 );
                if ( 'billing_first_name' === $input ) {
                    return $parts[0] ?? $known['full_name'];
                }
                return ! empty( $parts[1] ) ? $parts[1] : '.';
            }
        }

        if ( in_array( $input, $post_keys, true ) ) {
            return $value;
        }

        return $value;
    }

    /**
     * Minimal guest billing: Full Name + Email (+ organiser extras). Hide shipping.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public function filter_checkout_fields( $fields ) {
        if ( self::is_guest_checkout_context() ) {
            $fields = $this->build_guest_minimal_billing_fields( $fields );
            $fields = $this->apply_known_identity_readonly_to_guest_fields( $fields );
            return $this->apply_order_notes_mode( $fields );
        }

        if ( is_user_logged_in() && self::cart_has_cw_campaign() ) {
            $fields = $this->apply_logged_in_identity_readonly( $fields );
            return $this->apply_order_notes_mode( $fields );
        }

        return $fields;
    }

    /**
     * Apply organiser Hidden / Optional / Required setting for WooCommerce order notes.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function apply_order_notes_mode( $fields ) {
        $campaign_id = self::get_campaign_id_from_cart();
        if ( $campaign_id <= 0 ) {
            return $fields;
        }

        $modes = self::get_checkout_field_modes( $campaign_id );
        $mode  = $modes['order_notes'] ?? 'optional';

        if ( 'hidden' === $mode ) {
            unset( $fields['order']['order_comments'] );
            if ( empty( $fields['order'] ) ) {
                unset( $fields['order'] );
            }
            return $fields;
        }

        if ( ! isset( $fields['order']['order_comments'] ) || ! is_array( $fields['order']['order_comments'] ) ) {
            $fields['order']['order_comments'] = [
                'type'        => 'textarea',
                'class'       => [ 'notes' ],
                'label'       => __( 'Order notes', 'woocommerce' ),
                'placeholder' => esc_attr__(
                    'Notes about your order, e.g. special notes for delivery.',
                    'woocommerce'
                ),
            ];
        }

        $fields['order']['order_comments']['required'] = ( 'required' === $mode );
        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function build_guest_minimal_billing_fields( $fields ) {
        $email = isset( $fields['billing']['billing_email'] ) && is_array( $fields['billing']['billing_email'] )
            ? $fields['billing']['billing_email']
            : [
                'type'     => 'email',
                'label'    => __( 'Email address', 'woocommerce' ),
                'required' => true,
                'validate' => [ 'email' ],
            ];

        $billing = [
            self::ORDER_META_FULL_NAME => [
                'type'         => 'text',
                'label'        => __( 'Full name', 'creativewings-core' ),
                'required'     => true,
                'class'        => [ 'form-row-wide', 'cw-guest-full-name-field' ],
                'autocomplete' => 'name',
                'priority'     => 10,
            ],
            'billing_email' => array_merge(
                $email,
                [
                    'required' => true,
                    'priority' => 20,
                    'class'    => array_values( array_unique( array_merge( (array) ( $email['class'] ?? [] ), [ 'form-row-wide' ] ) ) ),
                ]
            ),
        ];

        $modes     = self::get_checkout_field_modes( self::get_campaign_id_from_cart() );
        $catalogue = self::get_profile_field_catalogue();
        $priority  = 40;

        foreach ( $catalogue as $key => $def ) {
            // Order notes live in the WooCommerce "order" field group, not billing.
            if ( ( $def['group'] ?? '' ) === 'order' ) {
                continue;
            }

            $mode = $modes[ $key ] ?? 'hidden';
            if ( 'hidden' === $mode ) {
                continue;
            }
            $required = ( 'required' === $mode );

            if ( 'address' === ( $def['type'] ?? '' ) ) {
                $addr_defaults = $fields['billing'] ?? [];
                foreach ( self::get_wc_address_field_keys() as $addr_key ) {
                    $row = isset( $addr_defaults[ $addr_key ] ) && is_array( $addr_defaults[ $addr_key ] )
                        ? $addr_defaults[ $addr_key ]
                        : [
                            'type'  => 'text',
                            'label' => $addr_key,
                        ];

                    if ( 'required' === $mode ) {
                        $row['required'] = ( 'billing_address_2' !== $addr_key );
                    } else {
                        $row['required'] = false;
                    }

                    $row['priority'] = $priority;
                    $billing[ $addr_key ] = $row;
                    $priority += 5;
                }
                continue;
            }

            if ( ! empty( $def['wc_key'] ) ) {
                $wc_key = $def['wc_key'];
                $row    = isset( $fields['billing'][ $wc_key ] ) && is_array( $fields['billing'][ $wc_key ] )
                    ? $fields['billing'][ $wc_key ]
                    : [
                        'type'  => $def['type'] ?? 'text',
                        'label' => $def['label'],
                    ];
                $row['label']    = $def['label'];
                $row['required'] = $required;
                $row['priority'] = $priority;
                $row['class']    = [ 'form-row-wide' ];
                $billing[ $wc_key ] = $row;
                $priority += 5;
                continue;
            }

            $meta_key = $def['meta_key'] ?? '';
            if ( ! $meta_key ) {
                continue;
            }

            $row = [
                'type'     => $def['type'] ?? 'text',
                'label'    => $def['label'],
                'required' => $required,
                'class'    => [ 'form-row-wide' ],
                'priority' => $priority,
            ];
            if ( 'select' === ( $def['type'] ?? '' ) && ! empty( $def['options'] ) && is_array( $def['options'] ) ) {
                $row['options'] = $def['options'];
            }
            $billing[ $meta_key ] = $row;
            $priority += 5;
        }

        $fields['billing'] = $billing;
        unset( $fields['shipping'] );

        return $fields;
    }

    /**
     * Guest fields stay editable unless we somehow have known identity (normally empty for guests).
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function apply_known_identity_readonly_to_guest_fields( $fields ) {
        $known = self::get_known_identity();
        if ( empty( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
            return $fields;
        }
        if ( $known['full_name'] && isset( $fields['billing'][ self::ORDER_META_FULL_NAME ] ) ) {
            $fields['billing'][ self::ORDER_META_FULL_NAME ] = self::mark_readonly_field(
                $fields['billing'][ self::ORDER_META_FULL_NAME ],
                $known['full_name']
            );
        }
        if ( $known['email'] && isset( $fields['billing']['billing_email'] ) ) {
            $fields['billing']['billing_email'] = self::mark_readonly_field(
                $fields['billing']['billing_email'],
                $known['email']
            );
        }
        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private function apply_logged_in_identity_readonly( $fields ) {
        if ( empty( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
            return $fields;
        }

        $known = self::get_known_identity();

        if ( $known['email'] && isset( $fields['billing']['billing_email'] ) ) {
            $fields['billing']['billing_email'] = self::mark_readonly_field(
                $fields['billing']['billing_email'],
                $known['email']
            );
        }

        if ( $known['full_name'] ) {
            $parts = preg_split( '/\s+/', $known['full_name'], 2 );
            $first = $parts[0] ?? $known['full_name'];
            $last  = ! empty( $parts[1] ) ? $parts[1] : '.';

            if ( isset( $fields['billing']['billing_first_name'] ) ) {
                $fields['billing']['billing_first_name'] = self::mark_readonly_field(
                    $fields['billing']['billing_first_name'],
                    $first
                );
            }
            if ( isset( $fields['billing']['billing_last_name'] ) ) {
                $fields['billing']['billing_last_name'] = self::mark_readonly_field(
                    $fields['billing']['billing_last_name'],
                    $last
                );
            }
        }

        return $fields;
    }

    /**
     * Map Full Name → billing first/last; ensure country when address is hidden.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function filter_checkout_posted_data( $data ) {
        if ( ! self::is_guest_checkout_context() ) {
            return $data;
        }

        $full = '';
        if ( isset( $_POST[ self::ORDER_META_FULL_NAME ] ) ) {
            $full = sanitize_text_field( wp_unslash( $_POST[ self::ORDER_META_FULL_NAME ] ) );
        } elseif ( ! empty( $data[ self::ORDER_META_FULL_NAME ] ) ) {
            $full = sanitize_text_field( (string) $data[ self::ORDER_META_FULL_NAME ] );
        }
        $full = trim( $full );

        if ( '' !== $full ) {
            $parts = preg_split( '/\s+/', $full, 2 );
            $data['billing_first_name'] = $parts[0] ?? $full;
            $data['billing_last_name']  = ! empty( $parts[1] ) ? $parts[1] : '.';
            $data[ self::ORDER_META_FULL_NAME ] = $full;
        }

        $modes = self::get_checkout_field_modes( self::get_campaign_id_from_cart() );
        if ( ( $modes['address'] ?? 'hidden' ) === 'hidden' ) {
            if ( empty( $data['billing_country'] ) ) {
                $base = function_exists( 'WC' ) && WC()->countries
                    ? WC()->countries->get_base_country()
                    : '';
                $data['billing_country'] = $base ?: 'MY';
            }
        }

        return $data;
    }

    /**
     * @param string[] $classes
     * @return string[]
     */
    public function filter_body_class( $classes ) {
        if ( function_exists( 'is_checkout' )
            && is_checkout()
            && ! is_order_received_page()
            && self::cart_has_cw_campaign() ) {
            if ( self::is_guest_checkout_context() ) {
                $classes[] = 'cw-checkout-guest-minimal';
            }
            $known = self::get_known_identity();
            if ( $known['full_name'] || $known['email'] || $known['dob'] ) {
                $classes[] = 'cw-checkout-identity-locked';
            }
        }
        return $classes;
    }

    /**
     * Whether a cart line represents a CW campaign registration (not a bare WC product or school claim).
     *
     * @param array<string, mixed> $item
     */
    private static function cart_item_is_cw_registration( $item ) {
        if ( ! is_array( $item ) ) {
            return false;
        }

        // School claim lines require login; do not treat them as guest registration checkout.
        if ( ! empty( $item['cw_staged_id'] ) || ! empty( $item['cw_claim_code'] ) ) {
            return false;
        }

        if ( isset( $item['cw_participants'] ) && is_array( $item['cw_participants'] ) && count( $item['cw_participants'] ) > 0 ) {
            return true;
        }

        if ( ! empty( $item['cw_addons_meta'] ) && is_array( $item['cw_addons_meta'] ) && count( $item['cw_addons_meta'] ) > 0 ) {
            return true;
        }

        if ( class_exists( 'CW_Design_Submission' ) && ! empty( $item[ CW_Design_Submission::CART_FLAG ] ) ) {
            return true;
        }

        return false;
    }

    public static function cart_has_cw_campaign() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( self::cart_item_is_cw_registration( $item ) ) {
                return true;
            }
        }
        return false;
    }

    public static function is_guest_checkout_context() {
        return ! is_user_logged_in() && self::cart_has_cw_campaign();
    }

    /**
     * Whether the campaign product is an activity (vs competition / seminar).
     *
     * @param int $product_id
     */
    public static function campaign_is_activity( $product_id ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return false;
        }

        if ( has_term( 'activities', 'product_cat', $product_id ) ) {
            return true;
        }

        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return false;
        }

        foreach ( $terms as $term ) {
            if ( $term->parent ) {
                $parent = get_term( $term->parent, 'product_cat' );
                if ( $parent && ! is_wp_error( $parent ) && $parent->slug === 'activities' ) {
                    return true;
                }
            }
            if ( false !== strpos( strtolower( (string) $term->slug ), 'activit' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checkout place-order label for CW registration carts.
     */
    public static function get_join_order_button_text( $product_id = 0 ) {
        $product_id = (int) $product_id;
        if ( ! $product_id ) {
            $product_id = self::get_campaign_id_from_cart();
        }

        if ( $product_id && self::campaign_is_activity( $product_id ) ) {
            return __( 'Join activity', 'creativewings-core' );
        }

        return __( 'Join participant', 'creativewings-core' );
    }

    /**
     * Age brackets payload for guest DOB UI (empty when feature off / unconfigured).
     *
     * @return array{enabled:bool,brackets:array<int,array{label:string,min_age:int,max_age:int,key:string}>}
     */
    public static function get_cart_age_bracket_config() {
        $campaign_id = self::get_campaign_id_from_cart();
        $enabled     = $campaign_id && get_post_meta( $campaign_id, 'cw_enable_age_brackets', true ) === 'yes';
        $brackets    = [];

        if ( $enabled ) {
            $raw = get_post_meta( $campaign_id, 'cw_age_brackets', true );
            if ( is_array( $raw ) ) {
                foreach ( $raw as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }
                    $label = trim( (string) ( $row['label'] ?? '' ) );
                    if ( '' === $label ) {
                        continue;
                    }
                    $brackets[] = [
                        'label'   => $label,
                        'min_age' => (int) ( $row['min_age'] ?? 0 ),
                        'max_age' => (int) ( $row['max_age'] ?? 99 ),
                        'key'     => ! empty( $row['key'] ) ? (string) $row['key'] : sanitize_key( $label ),
                    ];
                }
            }
        }

        return [
            'enabled'  => (bool) $enabled,
            'brackets' => $brackets,
        ];
    }

    /**
     * @param string $text
     * @return string
     */
    public function filter_order_button_text( $text ) {
        if ( ! self::cart_has_cw_campaign() ) {
            return $text;
        }
        if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
            return self::get_join_order_button_text();
        }
        return $text;
    }

    public function enqueue_guest_checkout_assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
            return;
        }
        if ( ! self::is_guest_checkout_context() && ! ( is_user_logged_in() && self::cart_has_cw_campaign() ) ) {
            return;
        }

        $js = class_exists( 'CW_Core_Platform' )
            ? CW_Core_Platform::asset( 'assets/js/cw-guest-checkout.js' )
            : [
                'url'     => CW_URL . 'assets/js/cw-guest-checkout.js',
                'version' => defined( 'CW_VERSION' ) ? CW_VERSION : null,
            ];

        wp_enqueue_script(
            'cw-guest-checkout',
            $js['url'],
            [ 'jquery' ],
            $js['version'] ?? null,
            true
        );

        $age_cfg = self::get_cart_age_bracket_config();
        $known   = self::get_known_identity();
        wp_localize_script(
            'cw-guest-checkout',
            'cwGuestCheckout',
            [
                'ageBracketsEnabled' => ! empty( $age_cfg['enabled'] ),
                'brackets'           => $age_cfg['brackets'],
                'orderButtonText'    => self::get_join_order_button_text(),
                'identityLocked'     => (bool) ( $known['dob'] || $known['email'] || $known['full_name'] ),
                'i18n'               => [
                    'enterDob'         => __( 'Enter your date of birth (dd/mm/yyyy) to check eligibility.', 'creativewings-core' ),
                    'eligibleCategory' => __( 'Eligible category: %s', 'creativewings-core' ),
                    'eligibleJoin'     => __( 'Eligible to join (age %d).', 'creativewings-core' ),
                    'notEligible'      => __( 'Your age does not match any category for this campaign. You cannot place this order.', 'creativewings-core' ),
                    'usingAccountDob'  => __( 'Using the date of birth saved on your account.', 'creativewings-core' ),
                ],
            ]
        );
    }

    /**
     * First CW registration campaign product ID in the cart, if any.
     */
    public static function get_campaign_id_from_cart() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! self::cart_item_is_cw_registration( $item ) ) {
                continue;
            }

            $campaign_id = (int) ( $item['product_id'] ?? 0 );
            if ( $campaign_id ) {
                return $campaign_id;
            }
        }

        return 0;
    }

    /**
     * Whether a URL targets guest-join resume (checkout or campaign flag).
     *
     * @param string $url
     */
    public static function url_is_guest_resume_target( $url ) {
        if ( ! is_string( $url ) || '' === $url ) {
            return false;
        }

        if ( function_exists( 'wc_get_checkout_url' ) ) {
            $checkout = wc_get_checkout_url();
            if ( $checkout && untrailingslashit( $url ) === untrailingslashit( $checkout ) ) {
                return true;
            }
        }

        $query = wp_parse_url( $url, PHP_URL_QUERY );
        if ( ! is_string( $query ) || '' === $query ) {
            return false;
        }

        parse_str( $query, $args );
        return isset( $args['cw_resume_join'] ) && '1' === (string) $args['cw_resume_join'];
    }

    /**
     * Post-login destination: checkout when cart still has registration, else campaign modal URL.
     *
     * @param int $campaign_id Optional campaign product ID fallback.
     */
    public static function build_post_login_resume_url( $campaign_id = 0 ) {
        if ( self::cart_has_cw_campaign() && function_exists( 'wc_get_checkout_url' ) ) {
            return wc_get_checkout_url();
        }

        if ( ! $campaign_id && function_exists( 'WC' ) && WC()->session ) {
            $resume = WC()->session->get( self::SESSION_RESUME_KEY );
            if ( is_array( $resume ) ) {
                $campaign_id = (int) ( $resume['campaign_id'] ?? 0 );
            }
        }

        if ( $campaign_id ) {
            return add_query_arg( 'cw_resume_join', '1', get_permalink( $campaign_id ) );
        }

        return '';
    }

    /**
     * Login page URL that returns the user to checkout or the campaign join modal after auth.
     *
     * @param int $campaign_id
     */
    public static function get_resume_login_url( $campaign_id = 0 ) {
        $resume_url = self::build_post_login_resume_url( $campaign_id );
        if ( ! $resume_url ) {
            return home_url( '/login' );
        }

        return add_query_arg( 'redirect_to', rawurlencode( $resume_url ), home_url( '/login' ) );
    }

    /**
     * Clear stored resume context from the WooCommerce session.
     */
    public static function consume_resume_session() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return null;
        }

        $resume = WC()->session->get( self::SESSION_RESUME_KEY );
        if ( ! is_array( $resume ) ) {
            return null;
        }

        WC()->session->set( self::SESSION_RESUME_KEY, null );
        return $resume;
    }

    /**
     * Resolve login redirect for guest join resume. Returns null when not applicable.
     *
     * @param string   $redirect Requested redirect URL.
     * @param WP_User  $user     Authenticated user.
     * @return string|null
     */
    public static function resolve_login_redirect( $redirect, $user ) {
        if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) || ! $user->exists() ) {
            return null;
        }

        $has_resume_session = false;
        if ( function_exists( 'WC' ) && WC()->session ) {
            $stored = WC()->session->get( self::SESSION_RESUME_KEY );
            $has_resume_session = is_array( $stored ) && ! empty( $stored );
        }

        if ( ! $has_resume_session && ! self::url_is_guest_resume_target( $redirect ) ) {
            return null;
        }

        $resume_url = self::build_post_login_resume_url();
        if ( $resume_url ) {
            self::consume_resume_session();
            return $resume_url;
        }

        if ( $has_resume_session ) {
            self::consume_resume_session();
        }

        return null;
    }

    /**
     * WooCommerce login forms (checkout / my account).
     *
     * @param string  $redirect Default redirect URL.
     * @param WP_User $user     Authenticated user.
     */
    public function filter_woocommerce_login_redirect( $redirect, $user ) {
        $resolved = self::resolve_login_redirect( $redirect, $user );
        return null !== $resolved ? $resolved : $redirect;
    }

    /**
     * Logged-in user with cart + cw_resume_join should go straight to checkout.
     */
    public function maybe_redirect_resume_join() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        if ( ! isset( $_GET['cw_resume_join'] ) || '1' !== (string) wp_unslash( $_GET['cw_resume_join'] ) ) {
            return;
        }

        if ( ! self::cart_has_cw_campaign() || ! function_exists( 'wc_get_checkout_url' ) ) {
            return;
        }

        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    public function render_guest_dob_field() {
        $is_guest  = self::is_guest_checkout_context();
        $is_member = is_user_logged_in() && self::cart_has_cw_campaign();
        if ( ! $is_guest && ! $is_member ) {
            return;
        }

        $known = self::get_known_identity();
        $value = '';
        if ( function_exists( 'WC' ) && WC()->checkout() ) {
            $value = WC()->checkout()->get_value( 'cw_guest_dob' );
        }
        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            $value = $known['dob'];
        }

        $locked = ( '' !== trim( (string) $known['dob'] ) );
        $attrs  = [
            'inputmode'        => 'numeric',
            'pattern'          => '[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}',
            'maxlength'        => '10',
            'aria-describedby' => 'cw-guest-age-status',
        ];
        if ( $locked ) {
            $attrs['readonly'] = 'readonly';
        }

        $age_cfg = self::get_cart_age_bracket_config();
        $classes = [ 'form-row-wide', 'cw-guest-dob-field' ];
        if ( $locked ) {
            $classes[] = 'cw-identity-readonly';
        }

        echo '<div class="cw-checkout-message-section cw-guest-dob-section">';
        echo '<h3 class="cw-checkout-message-heading">' . esc_html__( 'Date of birth', 'creativewings-core' );
        if ( ! $locked ) {
            echo ' <abbr class="required" title="' . esc_attr__( 'required', 'creativewings-core' ) . '">*</abbr>';
        }
        echo '</h3>';
        woocommerce_form_field(
            'cw_guest_dob',
            [
                'type'              => 'text',
                'class'             => $classes,
                'label'             => __( 'Date of birth', 'creativewings-core' ),
                'required'          => ! $locked,
                'placeholder'       => 'dd/mm/yyyy',
                'autocomplete'      => 'bday',
                'custom_attributes' => $attrs,
                'description'       => $locked
                    ? __( 'From your account — contact support if this needs changing.', 'creativewings-core' )
                    : '',
            ],
            $value
        );
        echo '<p id="cw-guest-age-status" class="cw-guest-age-status is-pending" role="status" aria-live="polite">';
        echo $locked
            ? esc_html__( 'Using the date of birth saved on your account.', 'creativewings-core' )
            : esc_html__( 'Enter your date of birth (dd/mm/yyyy) to check eligibility.', 'creativewings-core' );
        echo '</p>';

        if ( ! empty( $age_cfg['enabled'] ) && ! empty( $age_cfg['brackets'] ) ) {
            echo '<div class="cw-guest-age-brackets" aria-label="' . esc_attr__( 'Age categories', 'creativewings-core' ) . '">';
            foreach ( $age_cfg['brackets'] as $bracket ) {
                printf(
                    '<span class="cw-guest-age-chip" data-key="%s" data-min="%d" data-max="%d">%s</span>',
                    esc_attr( $bracket['key'] ),
                    (int) $bracket['min_age'],
                    (int) $bracket['max_age'],
                    esc_html( $bracket['label'] )
                );
            }
            echo '</div>';
        }

        echo '</div>';
    }

    public function validate_guest_checkout() {
        if ( self::is_guest_checkout_context() ) {
            $this->validate_guest_identity_and_extras();
            return;
        }

        if ( is_user_logged_in() && self::cart_has_cw_campaign() ) {
            $this->validate_logged_in_dob_for_age_brackets();
        }
    }

    /**
     * Guest checkout validation (name, email, DOB, organiser extras, age brackets).
     */
    private function validate_guest_identity_and_extras() {
        $known = self::get_known_identity();

        $full_name = isset( $_POST[ self::ORDER_META_FULL_NAME ] )
            ? sanitize_text_field( wp_unslash( $_POST[ self::ORDER_META_FULL_NAME ] ) )
            : '';
        if ( '' === trim( $full_name ) && ! empty( $known['full_name'] ) ) {
            $full_name = $known['full_name'];
        }
        if ( '' === trim( $full_name ) ) {
            wc_add_notice( __( 'Please enter your full name.', 'creativewings-core' ), 'error' );
        }

        $email = sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) );
        if ( ! $email && ! empty( $known['email'] ) ) {
            $email = $known['email'];
        }
        if ( $email && email_exists( $email ) ) {
            $campaign_id = self::get_campaign_id_from_cart();

            if ( function_exists( 'WC' ) && WC()->session ) {
                WC()->session->set(
                    self::SESSION_RESUME_KEY,
                    [
                        'campaign_id'  => $campaign_id,
                        'checkout_url' => wc_get_checkout_url(),
                    ]
                );
            }

            $login_url = self::get_resume_login_url( $campaign_id );
            wc_add_notice(
                sprintf(
                    /* translators: %s: login URL */
                    __( 'This email already has an account. Please <a href="%s">log in</a> to continue — your registration details will be kept.', 'creativewings-core' ),
                    esc_url( $login_url )
                ),
                'error'
            );
            return;
        }

        $dob = isset( $_POST['cw_guest_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_guest_dob'] ) ) : '';
        if ( '' === trim( $dob ) && ! empty( $known['dob'] ) ) {
            $dob = $known['dob'];
        }
        if ( '' === trim( $dob ) || null === CW_Staged_Submissions::age_from_birthdate( $dob ) ) {
            wc_add_notice( __( 'Please enter a valid date of birth (dd/mm/yyyy).', 'creativewings-core' ), 'error' );
            return;
        }

        $this->validate_required_profile_extras();
        $this->apply_age_bracket_session_from_dob( $dob );
    }

    /**
     * Logged-in members: DOB from account when set; still enforce age brackets.
     */
    private function validate_logged_in_dob_for_age_brackets() {
        $known = self::get_known_identity();
        $dob   = isset( $_POST['cw_guest_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_guest_dob'] ) ) : '';
        if ( '' === trim( $dob ) && ! empty( $known['dob'] ) ) {
            $dob = $known['dob'];
        }

        $age_cfg = self::get_cart_age_bracket_config();
        if ( empty( $age_cfg['enabled'] ) ) {
            // Still accept DOB on order when provided/locked for consistency.
            return;
        }

        if ( '' === trim( $dob ) || null === CW_Staged_Submissions::age_from_birthdate( $dob ) ) {
            wc_add_notice( __( 'Please enter a valid date of birth (dd/mm/yyyy).', 'creativewings-core' ), 'error' );
            return;
        }

        $this->apply_age_bracket_session_from_dob( $dob );
    }

    private function validate_required_profile_extras() {
        $campaign_id = self::get_campaign_id_from_cart();
        $modes       = self::get_checkout_field_modes( $campaign_id );
        $catalogue   = self::get_profile_field_catalogue();

        foreach ( $catalogue as $key => $def ) {
            if ( ( $modes[ $key ] ?? 'hidden' ) !== 'required' ) {
                continue;
            }

            if ( ( $def['group'] ?? '' ) === 'order' && ! empty( $def['wc_key'] ) ) {
                $val = isset( $_POST[ $def['wc_key'] ] ) ? trim( (string) wp_unslash( $_POST[ $def['wc_key'] ] ) ) : '';
                if ( '' === $val ) {
                    wc_add_notice(
                        sprintf(
                            /* translators: %s: field label */
                            __( '%s is required.', 'creativewings-core' ),
                            $def['label']
                        ),
                        'error'
                    );
                }
                continue;
            }

            if ( 'address' === ( $def['type'] ?? '' ) ) {
                $missing = false;
                foreach ( self::get_required_wc_address_field_keys() as $addr_key ) {
                    $val = trim( (string) wp_unslash( $_POST[ $addr_key ] ?? '' ) );
                    if ( 'billing_state' === $addr_key ) {
                        $country    = trim( (string) wp_unslash( $_POST['billing_country'] ?? '' ) );
                        $has_states = false;
                        if ( $country && function_exists( 'WC' ) && WC()->countries ) {
                            $states     = WC()->countries->get_states( $country );
                            $has_states = is_array( $states ) && count( $states ) > 0;
                        }
                        if ( ! $has_states ) {
                            continue;
                        }
                    }
                    if ( '' === $val ) {
                        $missing = true;
                        break;
                    }
                }
                if ( $missing ) {
                    wc_add_notice(
                        sprintf(
                            /* translators: %s: field label */
                            __( '%s is required.', 'creativewings-core' ),
                            __( 'Address', 'creativewings-core' )
                        ),
                        'error'
                    );
                }
                continue;
            }

            if ( ! empty( $def['wc_key'] ) ) {
                $val = isset( $_POST[ $def['wc_key'] ] ) ? trim( (string) wp_unslash( $_POST[ $def['wc_key'] ] ) ) : '';
            } else {
                $meta_key = $def['meta_key'] ?? '';
                $val      = $meta_key && isset( $_POST[ $meta_key ] ) ? trim( (string) wp_unslash( $_POST[ $meta_key ] ) ) : '';
            }

            if ( '' === $val ) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: field label */
                        __( '%s is required.', 'creativewings-core' ),
                        $def['label']
                    ),
                    'error'
                );
            }
        }
    }

    /**
     * @param string $dob dd/mm/yyyy
     */
    private function apply_age_bracket_session_from_dob( $dob ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! self::cart_item_is_cw_registration( $item ) ) {
                continue;
            }

            $pid = (int) ( $item['product_id'] ?? 0 );
            if ( ! $pid || get_post_meta( $pid, 'cw_enable_age_brackets', true ) !== 'yes' ) {
                continue;
            }

            $result = CW_Staged_Submissions::resolve_age_bracket( $pid, $dob );
            if ( is_wp_error( $result ) ) {
                if ( $result->get_error_code() === 'no_match' ) {
                    wc_add_notice( $result->get_error_message(), 'error' );
                    return;
                }
                continue;
            }

            if ( WC()->session ) {
                WC()->session->set( 'cw_guest_age_bracket_' . $pid, $result );
            }
        }
    }

    public function save_guest_checkout_meta( $order_id ) {
        $order_id = (int) $order_id;
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Logged-in: still stamp DOB onto the order when present (account or posted).
        if ( is_user_logged_in() ) {
            $known = self::get_known_identity();
            $dob   = isset( $_POST['cw_guest_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_guest_dob'] ) ) : '';
            if ( '' === trim( $dob ) && ! empty( $known['dob'] ) ) {
                $dob = $known['dob'];
            }
            if ( $dob ) {
                update_post_meta( $order_id, self::ORDER_META_DOB, $dob );
                $order->update_meta_data( self::ORDER_META_DOB, $dob );
                $order->save();
            }
            return;
        }

        $dob = isset( $_POST['cw_guest_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_guest_dob'] ) ) : '';
        if ( $dob ) {
            update_post_meta( $order_id, self::ORDER_META_DOB, $dob );
            $order->update_meta_data( self::ORDER_META_DOB, $dob );
        }

        $full = isset( $_POST[ self::ORDER_META_FULL_NAME ] )
            ? sanitize_text_field( wp_unslash( $_POST[ self::ORDER_META_FULL_NAME ] ) )
            : '';
        if ( '' === $full ) {
            $full = self::get_order_full_name( $order );
        }
        if ( $full ) {
            update_post_meta( $order_id, self::ORDER_META_FULL_NAME, $full );
            $order->update_meta_data( self::ORDER_META_FULL_NAME, $full );
        }

        $campaign_id = 0;
        foreach ( $order->get_items() as $item ) {
            $pid = (int) $item->get_product_id();
            if ( $pid && get_post_meta( $pid, 'cw_campaign_serial', true ) ) {
                $campaign_id = $pid;
                break;
            }
        }
        if ( ! $campaign_id ) {
            $campaign_id = self::get_campaign_id_from_cart();
        }

        $profile   = [];
        $modes     = self::get_checkout_field_modes( $campaign_id );
        $catalogue = self::get_profile_field_catalogue();

        foreach ( $catalogue as $key => $def ) {
            $mode = $modes[ $key ] ?? 'hidden';
            if ( 'hidden' === $mode ) {
                continue;
            }

            if ( 'address' === ( $def['type'] ?? '' ) ) {
                $parts = [];
                foreach ( self::get_wc_address_field_keys() as $addr_key ) {
                    $piece = sanitize_text_field( wp_unslash( $_POST[ $addr_key ] ?? '' ) );
                    if ( '' !== $piece ) {
                        $parts[] = $piece;
                    }
                }
                $value = implode( ', ', $parts );
            } elseif ( ! empty( $def['wc_key'] ) ) {
                $value = isset( $_POST[ $def['wc_key'] ] )
                    ? sanitize_text_field( wp_unslash( $_POST[ $def['wc_key'] ] ) )
                    : '';
            } else {
                $meta_key = $def['meta_key'] ?? '';
                $raw      = $meta_key && isset( $_POST[ $meta_key ] ) ? wp_unslash( $_POST[ $meta_key ] ) : '';
                $value    = 'textarea' === ( $def['type'] ?? '' )
                    ? sanitize_textarea_field( $raw )
                    : sanitize_text_field( $raw );
                if ( 'select' === ( $def['type'] ?? '' ) && ! empty( $def['options'][ $value ] ) ) {
                    $value = (string) $def['options'][ $value ];
                }
            }

            if ( '' === trim( (string) $value ) ) {
                continue;
            }

            $profile[ $key ] = [
                'label' => $def['label'],
                'value' => $value,
            ];

            if ( ! empty( $def['meta_key'] ) ) {
                $order->update_meta_data( $def['meta_key'], $value );
            }
        }

        if ( ! empty( $profile ) ) {
            update_post_meta( $order_id, self::ORDER_META_PROFILE, $profile );
            $order->update_meta_data( self::ORDER_META_PROFILE, $profile );
        }

        $order->save();
    }

    /**
     * Profile extras collected at guest checkout for entry details.
     *
     * @param array<int, array<string, string>> $fields
     * @param int                               $order_id
     * @return array<int, array<string, string>>
     */
    public static function append_profile_to_fields( $fields, $order_id ) {
        if ( ! is_array( $fields ) ) {
            $fields = [];
        }
        $profile = get_post_meta( (int) $order_id, self::ORDER_META_PROFILE, true );
        if ( ! is_array( $profile ) ) {
            return $fields;
        }
        foreach ( $profile as $row ) {
            if ( ! is_array( $row ) || empty( $row['label'] ) || ! isset( $row['value'] ) || '' === trim( (string) $row['value'] ) ) {
                continue;
            }
            $fields[] = [
                'label' => (string) $row['label'],
                'value' => (string) $row['value'],
            ];
        }
        return $fields;
    }

    /**
     * Whether the order includes CW campaign registration line items.
     *
     * @param WC_Order $order
     */
    public static function order_has_cw_registration( $order ) {
        if ( ! ( $order instanceof WC_Order ) ) {
            return false;
        }

        foreach ( $order->get_items() as $item ) {
            if ( $item->get_meta( '_cw_participant_data' ) || $item->get_meta( '_cw_staged_id' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a one-time completion token; store hash + expiry on the order.
     *
     * @param int $order_id
     * @return string Plaintext token for the email URL, or empty on failure.
     */
    public static function create_completion_token( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return '';
        }

        $token  = bin2hex( random_bytes( 32 ) );
        $hash   = hash( 'sha256', $token );
        $expiry = time() + ( self::TOKEN_TTL_DAYS * DAY_IN_SECONDS );

        $order->update_meta_data( self::ORDER_META_TOKEN_HASH, $hash );
        $order->update_meta_data( self::ORDER_META_TOKEN_EXPIRES, $expiry );
        $order->update_meta_data( self::ORDER_META_COMPLETED, 'no' );
        $order->save();

        return $token;
    }

    /**
     * Verify a completion token for a guest order.
     *
     * @param int    $order_id
     * @param string $token
     */
    public static function verify_completion_token( $order_id, $token ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_user_id() ) {
            return false;
        }

        if ( 'yes' === $order->get_meta( self::ORDER_META_COMPLETED ) ) {
            return false;
        }

        $expires = (int) $order->get_meta( self::ORDER_META_TOKEN_EXPIRES );
        if ( $expires && time() > $expires ) {
            return false;
        }

        $hash = (string) $order->get_meta( self::ORDER_META_TOKEN_HASH );
        if ( ! $hash ) {
            return false;
        }

        return hash_equals( $hash, hash( 'sha256', (string) $token ) );
    }

    /**
     * Link a completed guest order to the new user and invalidate the token.
     *
     * @param int $order_id
     * @param int $user_id
     * @return bool
     */
    public static function attach_order_to_user( $order_id, $user_id ) {
        $order_id = (int) $order_id;
        $user_id  = (int) $user_id;

        if ( ! $order_id || ! $user_id ) {
            return false;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_user_id() ) {
            return false;
        }

        $order->set_customer_id( $user_id );
        $order->update_meta_data( self::ORDER_META_COMPLETED, 'yes' );
        $order->delete_meta_data( self::ORDER_META_TOKEN_HASH );
        $order->delete_meta_data( self::ORDER_META_TOKEN_EXPIRES );
        $order->save();

        if ( ! class_exists( 'CW_Shop' ) ) {
            return true;
        }

        $entries = get_posts( [
            'post_type'   => CW_Shop::entry_post_types(),
            'meta_key'    => 'order_id',
            'meta_value'  => $order_id,
            'numberposts' => -1,
            'fields'      => 'ids',
            'post_status' => 'any',
        ] );

        foreach ( $entries as $entry_id ) {
            $entry_id = (int) $entry_id;
            if ( ! $entry_id ) {
                continue;
            }

            wp_update_post( [
                'ID'          => $entry_id,
                'post_author' => $user_id,
            ] );
            update_post_meta( $entry_id, 'customer_id', $user_id );
        }

        /**
         * Guest order attached to a new user account (points + badges).
         *
         * @param int $order_id
         * @param int $user_id
         */
        do_action( 'cw_guest_account_attached', $order_id, $user_id );

        return true;
    }

    /**
     * Send guest complete-registration email (idempotent). Used by CW_Post_Checkout async.
     *
     * @param int $order_id
     */
    public static function send_complete_registration_email_for_order( $order_id ) {
        $order_id = (int) $order_id;
        $order    = wc_get_order( $order_id );
        if ( ! $order || $order->get_user_id() ) {
            return;
        }

        if ( ! self::order_has_cw_registration( $order ) ) {
            return;
        }

        if ( 'yes' === $order->get_meta( '_cw_guest_complete_email_sent' ) ) {
            return;
        }

        if ( ! $order->is_paid() && ! in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
            return;
        }

        $token = self::create_completion_token( $order_id );
        if ( ! $token ) {
            return;
        }

        if ( ! class_exists( 'CW_Email' ) ) {
            return;
        }

        $sent = CW_Email::send_guest_complete_registration( $order, $token );
        if ( ! $sent ) {
            return;
        }

        $order->update_meta_data( '_cw_guest_complete_email_sent', 'yes' );
        $order->save();
    }

    public function maybe_send_complete_registration_email( $order_id ) {
        self::send_complete_registration_email_for_order( $order_id );
    }
}
