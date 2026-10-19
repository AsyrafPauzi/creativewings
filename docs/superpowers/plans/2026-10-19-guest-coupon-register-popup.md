# B1 Guest Coupon → Register Popup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a guest on cart/checkout applies a Creative Wings school/sponsor coupon, intercept before WooCommerce applies it, show Register/Login popup, auto-login, then re-apply the coupon — without leaving the page.

**Architecture:** New `CW_Coupon_Auth_Popup` class owns AJAX (classify / register / login), asset enqueue, modal markup, and guest server safety-net. `CW_Sponsor_Coupons::is_cw_school_coupon()` detects coupons via `_cw_campaign_id`. Cart/checkout JS intercepts Apply coupon, opens modal, then re-applies via WooCommerce after auth.

**Tech Stack:** WordPress + WooCommerce, PHP 7.4+, jQuery (already on cart/checkout), existing `CW_Auth` / `CW_Security` patterns.

**Spec:** `docs/superpowers/specs/2026-10-19-guest-coupon-register-popup-design.md`

## Global Constraints

- Intercept **before** WC applies coupon (not after error).
- Trigger only for CW school/sponsor coupons (`_cw_campaign_id` present).
- Surfaces: **cart + checkout** only.
- Existing email → switch to **Login** in same modal.
- No email verification required for coupon unlock.
- Guest without school coupon may still pay full price.
- No social login in modal (B1).
- No register +50 / invite (B2/B4).
- Reuse contestant registration meta: `cw_full_name`, `birthdate`, PDPA, role `contestant`, `CW_Auth::mark_contestant_onboarded`.
- AJAX: nonce + rate limit; both `wp_ajax_` and `wp_ajax_nopriv_`.

## File map

| File | Responsibility |
|------|----------------|
| `includes/class-cw-sponsor-coupons.php` | Add `is_cw_school_coupon( $code ): bool` |
| `includes/class-cw-coupon-auth-popup.php` | **Create** — AJAX, enqueue, footer modal, guest WC reject |
| `includes/class-cw-loader.php` | `require_once` new class |
| `creativewings-core.php` | `new CW_Coupon_Auth_Popup()` near Guest_Join / Checkout |
| `assets/js/cw-coupon-auth-popup.js` | **Create** — intercept Apply, modal, re-apply |
| `assets/css/cw-style-checkout.css` | Modal styles |
| `tests/test-cw-coupon-auth-popup.php` | **Create** — CLI smoke for `is_cw_school_coupon` helpers if extractable; otherwise document manual QA only for WC |

---

### Task 1: School-coupon detector + CLI smoke test

**Files:**
- Modify: `includes/class-cw-sponsor-coupons.php`
- Create: `tests/test-cw-is-cw-school-coupon.php`

**Interfaces:**
- Produces: `CW_Sponsor_Coupons::is_cw_school_coupon( string $code ): bool`
- Consumes: `wc_get_coupon_id_by_code`, `get_post_meta( …, '_cw_campaign_id', true )`

- [ ] **Step 1: Write the failing CLI test**

Create `tests/test-cw-is-cw-school-coupon.php`:

```php
<?php
/**
 * Smoke: CW_Sponsor_Coupons::is_cw_school_coupon
 * Run: php tests/test-cw-is-cw-school-coupon.php
 */
if ( php_sapi_name() !== 'cli' ) {
    exit( 1 );
}

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}

// Minimal stubs — only what the static helper needs.
$GLOBALS['cw_test_coupons'] = [
    'SCHOOL-100' => [ 'id' => 10, 'campaign_id' => 55 ],
    'PROMO10'    => [ 'id' => 11, 'campaign_id' => 0 ],
];

if ( ! function_exists( 'add_action' ) ) {
    function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter() {}
}
if ( ! function_exists( '__' ) ) {
    function __( $s ) { return $s; }
}
if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
    function wc_get_coupon_id_by_code( $code ) {
        $code = strtoupper( trim( (string) $code ) );
        return isset( $GLOBALS['cw_test_coupons'][ $code ] )
            ? (int) $GLOBALS['cw_test_coupons'][ $code ]['id']
            : 0;
    }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        foreach ( $GLOBALS['cw_test_coupons'] as $row ) {
            if ( (int) $row['id'] === (int) $post_id && $key === '_cw_campaign_id' ) {
                return (int) $row['campaign_id'];
            }
        }
        return '';
    }
}

require_once $root . '/includes/class-cw-sponsor-coupons.php';

$fail = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
    echo ( $ok ? 'OK  ' : 'FAIL' ) . ' ' . $label . PHP_EOL;
    if ( ! $ok ) {
        $fail++;
    }
};

$check( 'school coupon true', CW_Sponsor_Coupons::is_cw_school_coupon( 'SCHOOL-100' ) === true );
$check( 'promo without campaign false', CW_Sponsor_Coupons::is_cw_school_coupon( 'PROMO10' ) === false );
$check( 'unknown code false', CW_Sponsor_Coupons::is_cw_school_coupon( 'NOPE' ) === false );
$check( 'empty code false', CW_Sponsor_Coupons::is_cw_school_coupon( '' ) === false );
$check( 'case/space tolerant', CW_Sponsor_Coupons::is_cw_school_coupon( '  school-100  ' ) === true );

exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test — expect FAIL**

Run: `php tests/test-cw-is-cw-school-coupon.php`  
Expected: FATAL or FAIL — method `is_cw_school_coupon` does not exist.

- [ ] **Step 3: Implement helper**

In `includes/class-cw-sponsor-coupons.php`, add public static method (near top of class, after constructor hooks is fine):

```php
/**
 * True when the coupon is a Creative Wings school/sponsor code
 * (synced with _cw_campaign_id meta).
 *
 * @param string $code Coupon code as typed by the shopper.
 */
public static function is_cw_school_coupon( $code ) {
    $code = wc_format_coupon_code( (string) $code );
    if ( $code === '' || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
        // Stub environments / unit tests may define wc_get_coupon_id_by_code without wc_format_coupon_code.
        $code = strtoupper( trim( (string) $code ) );
        if ( $code === '' ) {
            return false;
        }
    }
    if ( function_exists( 'wc_format_coupon_code' ) ) {
        $code = wc_format_coupon_code( (string) $code );
    } else {
        $code = strtoupper( trim( (string) $code ) );
    }
    if ( $code === '' ) {
        return false;
    }
    $coupon_id = (int) wc_get_coupon_id_by_code( $code );
    if ( $coupon_id <= 0 ) {
        return false;
    }
    return (int) get_post_meta( $coupon_id, '_cw_campaign_id', true ) > 0;
}
```

(Prefer the clean version below — avoid double-format. Use this final implementation:)

```php
/**
 * True when the coupon is a Creative Wings school/sponsor code
 * (has _cw_campaign_id meta from campaign sync).
 *
 * @param string $code Coupon code as typed by the shopper.
 * @return bool
 */
public static function is_cw_school_coupon( $code ) {
    if ( function_exists( 'wc_format_coupon_code' ) ) {
        $code = wc_format_coupon_code( (string) $code );
    } else {
        $code = strtoupper( trim( (string) $code ) );
    }
    if ( $code === '' || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
        return false;
    }
    $coupon_id = (int) wc_get_coupon_id_by_code( $code );
    if ( $coupon_id <= 0 ) {
        return false;
    }
    return (int) get_post_meta( $coupon_id, '_cw_campaign_id', true ) > 0;
}
```

- [ ] **Step 4: Run test — expect PASS**

Run: `php tests/test-cw-is-cw-school-coupon.php`  
Expected: all `OK` lines, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-cw-sponsor-coupons.php tests/test-cw-is-cw-school-coupon.php
git commit -m "$(cat <<'EOF'
feat(B1): detect CW school/sponsor coupons via campaign meta

EOF
)"
```

---

### Task 2: `CW_Coupon_Auth_Popup` skeleton + guest server safety net

**Files:**
- Create: `includes/class-cw-coupon-auth-popup.php`
- Modify: `includes/class-cw-loader.php` (add `require_once` in `init_woocommerce`)
- Modify: `creativewings-core.php` (instantiate after `CW_Guest_Join` / near checkout)

**Interfaces:**
- Consumes: `CW_Sponsor_Coupons::is_cw_school_coupon( $code ): bool`
- Produces: class constructed; filter `woocommerce_coupon_is_valid` rejects guest school coupons

- [ ] **Step 1: Create class file**

```php
<?php
/**
 * B1: Guest school-coupon → register/login popup (cart + checkout).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Coupon_Auth_Popup {

    const NONCE_ACTION = 'cw_coupon_auth_popup';

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 35 );
        add_action( 'wp_footer', [ $this, 'render_modal_markup' ], 40 );

        add_filter( 'woocommerce_coupon_is_valid', [ $this, 'reject_guest_school_coupon' ], 5, 3 );

        add_action( 'wp_ajax_cw_coupon_requires_auth', [ $this, 'ajax_coupon_requires_auth' ] );
        add_action( 'wp_ajax_nopriv_cw_coupon_requires_auth', [ $this, 'ajax_coupon_requires_auth' ] );

        add_action( 'wp_ajax_cw_checkout_register', [ $this, 'ajax_checkout_register' ] );
        add_action( 'wp_ajax_nopriv_cw_checkout_register', [ $this, 'ajax_checkout_register' ] );

        add_action( 'wp_ajax_cw_checkout_login', [ $this, 'ajax_checkout_login' ] );
        add_action( 'wp_ajax_nopriv_cw_checkout_login', [ $this, 'ajax_checkout_login' ] );
    }

    /**
     * Server safety net: guests cannot apply CW school coupons without JS popup.
     *
     * @param bool      $valid
     * @param WC_Coupon $coupon
     * @param mixed     $discount
     * @return bool
     */
    public function reject_guest_school_coupon( $valid, $coupon, $discount ) {
        if ( ! $valid || is_user_logged_in() || ! is_a( $coupon, 'WC_Coupon' ) ) {
            return $valid;
        }
        $code = $coupon->get_code();
        if ( ! class_exists( 'CW_Sponsor_Coupons' ) || ! CW_Sponsor_Coupons::is_cw_school_coupon( $code ) ) {
            return $valid;
        }
        $login = wp_login_url();
        $reg   = home_url( '/registration/' );
        throw new Exception(
            sprintf(
                /* translators: 1: login URL 2: register URL */
                __( 'School coupons require an account. Please <a href="%1$s">log in</a> or <a href="%2$s">register</a>, then apply your coupon.', 'creativewings-core' ),
                esc_url( $login ),
                esc_url( $reg )
            )
        );
    }

    public function enqueue_assets() { /* Task 4 */ }
    public function render_modal_markup() { /* Task 4 */ }
    public function ajax_coupon_requires_auth() { /* Task 3 */ }
    public function ajax_checkout_register() { /* Task 3 */ }
    public function ajax_checkout_login() { /* Task 3 */ }
}
```

- [ ] **Step 2: Require + instantiate**

In `includes/class-cw-loader.php` inside `init_woocommerce()`, after sponsor coupons require:

```php
require_once CW_PATH . 'includes/class-cw-coupon-auth-popup.php';
```

In `creativewings-core.php` after `new CW_Guest_Join();`:

```php
if ( class_exists( 'CW_Coupon_Auth_Popup' ) ) {
    new CW_Coupon_Auth_Popup();
}
```

- [ ] **Step 3: Manual smoke (no JS yet)**

As guest on checkout, apply a known school coupon.  
Expected: WooCommerce error mentioning login/register. Logged-in user: coupon still works.

- [ ] **Step 4: Commit**

```bash
git add includes/class-cw-coupon-auth-popup.php includes/class-cw-loader.php creativewings-core.php
git commit -m "$(cat <<'EOF'
feat(B1): block guest school coupons server-side until auth popup ships

EOF
)"
```

---

### Task 3: AJAX classify + register + login

**Files:**
- Modify: `includes/class-cw-coupon-auth-popup.php`

**Interfaces:**
- Consumes: `CW_Sponsor_Coupons::is_cw_school_coupon`, `CW_Auth::mark_contestant_onboarded`, `CW_Security::rate_limit`
- Produces: JSON for actions `cw_coupon_requires_auth`, `cw_checkout_register`, `cw_checkout_login`

- [ ] **Step 1: Implement `ajax_coupon_requires_auth`**

```php
public function ajax_coupon_requires_auth() {
    check_ajax_referer( self::NONCE_ACTION, 'nonce' );
    $code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
    $is_school = class_exists( 'CW_Sponsor_Coupons' ) && CW_Sponsor_Coupons::is_cw_school_coupon( $code );
    $requires  = $is_school && ! is_user_logged_in();
    wp_send_json_success(
        [
            'is_cw_school'  => $is_school,
            'requires_auth' => $requires,
        ]
    );
}
```

- [ ] **Step 2: Implement `ajax_checkout_register`**

Mirror `CW_Auth::handle_registration` field rules, but return JSON and do **not** redirect to dashboard:

```php
public function ajax_checkout_register() {
    check_ajax_referer( self::NONCE_ACTION, 'nonce' );

    if ( is_user_logged_in() ) {
        wp_send_json_success( [ 'ok' => true, 'coupon' => $this->posted_coupon() ] );
    }

    if ( class_exists( 'CW_Security' ) ) {
        $rl = CW_Security::rate_limit( CW_Security::RATE_REGISTRATION, 10, 3600 );
        if ( is_wp_error( $rl ) ) {
            wp_send_json_error( [ 'code' => 'rate_limit', 'message' => $rl->get_error_message() ], 429 );
        }
    }

    // Honeypot
    if ( ! empty( $_POST['cw_hp_check'] ) ) {
        wp_send_json_success( [ 'ok' => true, 'coupon' => $this->posted_coupon() ] );
    }

    $email     = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    $pass      = (string) ( $_POST['password'] ?? '' );
    $full_name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    $birthdate = sanitize_text_field( wp_unslash( $_POST['dob'] ?? '' ) );
    $coupon    = $this->posted_coupon();

    if ( $email === '' || $pass === '' || $full_name === '' || $birthdate === '' ) {
        wp_send_json_error( [ 'code' => 'missing_fields', 'message' => __( 'Please fill in all fields.', 'creativewings-core' ) ], 400 );
    }
    if ( empty( $_POST['pdpa'] ) ) {
        wp_send_json_error( [ 'code' => 'pdpa_required', 'message' => __( 'Please accept the PDPA notice.', 'creativewings-core' ) ], 400 );
    }
    if ( email_exists( $email ) ) {
        wp_send_json_error( [ 'code' => 'email_exists', 'message' => __( 'Account found — please log in.', 'creativewings-core' ) ], 409 );
    }

    $username = explode( '@', $email )[0] . wp_rand( 10, 999 );
    $user_id  = wp_create_user( $username, $pass, $email );
    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( [ 'code' => 'generic', 'message' => __( 'Could not create account. Please try again.', 'creativewings-core' ) ], 500 );
    }

    $parts = preg_split( '/\s+/', trim( $full_name ), 2 );
    $first = $parts[0] ?? $full_name;
    $last  = $parts[1] ?? '';

    update_user_meta( $user_id, 'cw_full_name', $full_name );
    update_user_meta( $user_id, 'birthdate', $birthdate );
    update_user_meta( $user_id, 'first_name', $first );
    update_user_meta( $user_id, 'last_name', $last );
    update_user_meta( $user_id, 'account_type', 'contestant' );
    update_user_meta( $user_id, 'cw_pdpa_consent', 'yes' );
    update_user_meta( $user_id, 'cw_pdpa_consent_at', current_time( 'mysql' ) );
    update_user_meta( $user_id, 'cw_pdpa_consent_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

    wp_update_user(
        [
            'ID'           => $user_id,
            'display_name' => $full_name,
            'first_name'   => $first,
            'last_name'    => $last,
        ]
    );

    $user = new WP_User( $user_id );
    $user->set_role( 'contestant' );
    if ( class_exists( 'CW_Auth' ) ) {
        CW_Auth::mark_contestant_onboarded( $user_id );
    }

    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true );

    wp_send_json_success( [ 'ok' => true, 'coupon' => $coupon ] );
}

private function posted_coupon() {
    return isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : '';
}
```

- [ ] **Step 3: Implement `ajax_checkout_login`**

```php
public function ajax_checkout_login() {
    check_ajax_referer( self::NONCE_ACTION, 'nonce' );

    if ( is_user_logged_in() ) {
        wp_send_json_success( [ 'ok' => true, 'coupon' => $this->posted_coupon() ] );
    }

    if ( class_exists( 'CW_Security' ) ) {
        $rl = CW_Security::rate_limit( 'cw_rate_coupon_login_', 20, 3600 );
        if ( is_wp_error( $rl ) ) {
            wp_send_json_error( [ 'code' => 'rate_limit', 'message' => $rl->get_error_message() ], 429 );
        }
    }

    $email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    $pass     = (string) ( $_POST['password'] ?? '' );
    $coupon   = $this->posted_coupon();

    $user = wp_authenticate( $email, $pass );
    if ( is_wp_error( $user ) ) {
        wp_send_json_error(
            [
                'code'    => 'invalid_credentials',
                'message' => __( 'Incorrect email or password.', 'creativewings-core' ),
            ],
            401
        );
    }

    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true );

    wp_send_json_success( [ 'ok' => true, 'coupon' => $coupon ] );
}
```

- [ ] **Step 4: Manual API smoke with browser/Network or curl**

Logged-out: POST `admin-ajax.php?action=cw_coupon_requires_auth` with nonce + school code → `requires_auth: true`.  
Register with new email → `ok: true` and session cookie set.

- [ ] **Step 5: Commit**

```bash
git add includes/class-cw-coupon-auth-popup.php
git commit -m "$(cat <<'EOF'
feat(B1): AJAX register/login for guest school-coupon auth

EOF
)"
```

---

### Task 4: Modal markup, CSS, enqueue

**Files:**
- Modify: `includes/class-cw-coupon-auth-popup.php` (`enqueue_assets`, `render_modal_markup`)
- Modify: `assets/css/cw-style-checkout.css`
- Create: `assets/js/cw-coupon-auth-popup.js` (empty IIFE stub in this task is OK; wire fully in Task 5)

**Interfaces:**
- Produces: `window.cwCouponAuth = { ajaxUrl, nonce, isLoggedIn, loginUrl, registerUrl, i18n }`

- [ ] **Step 1: Implement enqueue**

```php
public function enqueue_assets() {
    if ( is_user_logged_in() ) {
        return;
    }
    if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! ( is_checkout() && ! is_order_received_page() ) ) ) {
        return;
    }

    wp_enqueue_style( 'cw-style-checkout' ); // already enqueued by CW_Checkout; dependency ok

    wp_enqueue_script(
        'cw-coupon-auth-popup',
        CW_URL . 'assets/js/cw-coupon-auth-popup.js',
        [ 'jquery' ],
        CW_VERSION,
        true
    );

    $pdpa_page = get_page_by_path( 'pdpa' );
    $pdpa_url  = $pdpa_page ? get_permalink( $pdpa_page ) : home_url( '/pdpa/' );

    wp_localize_script(
        'cw-coupon-auth-popup',
        'cwCouponAuth',
        [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
            'isLoggedIn'  => false,
            'loginUrl'    => wp_login_url(),
            'registerUrl' => home_url( '/registration/' ),
            'pdpaUrl'     => $pdpa_url,
            'i18n'        => [
                'titleRegister' => __( 'Register to use this school coupon', 'creativewings-core' ),
                'titleLogin'    => __( 'Log in to use this school coupon', 'creativewings-core' ),
                'submitRegister'=> __( 'Create account & apply coupon', 'creativewings-core' ),
                'submitLogin'   => __( 'Log in & apply coupon', 'creativewings-core' ),
                'switchLogin'   => __( 'Already have an account? Log in', 'creativewings-core' ),
                'switchRegister'=> __( 'Need an account? Register', 'creativewings-core' ),
                'emailExists'   => __( 'Account found — please log in.', 'creativewings-core' ),
                'genericError'  => __( 'Something went wrong. Please try again.', 'creativewings-core' ),
            ],
        ]
    );
}
```

- [ ] **Step 2: Render modal in footer**

Only when same cart/checkout + logged-out conditions as enqueue. Markup structure:

```html
<div id="cw-coupon-auth-modal" class="cw-coupon-auth" hidden>
  <div class="cw-coupon-auth__backdrop" data-cw-close></div>
  <div class="cw-coupon-auth__dialog" role="dialog" aria-modal="true" aria-labelledby="cw-coupon-auth-title">
    <button type="button" class="cw-coupon-auth__close" data-cw-close aria-label="Close">&times;</button>
    <h2 id="cw-coupon-auth-title" class="cw-coupon-auth__title"></h2>
    <p class="cw-coupon-auth__msg" id="cw-coupon-auth-msg" hidden></p>

    <form id="cw-coupon-auth-register" class="cw-coupon-auth__form">
      <input type="text" name="cw_hp_check" value="" tabindex="-1" autocomplete="off" class="cw-coupon-auth__hp" aria-hidden="true">
      <label>Email <input type="email" name="email" required autocomplete="email"></label>
      <label>Password <input type="password" name="password" required autocomplete="new-password" minlength="6"></label>
      <label>Full name <input type="text" name="name" required autocomplete="name"></label>
      <label>Date of birth <input type="text" name="dob" required placeholder="DD/MM/YYYY" inputmode="numeric"></label>
      <label class="cw-coupon-auth__pdpa"><input type="checkbox" name="pdpa" value="1" required> PDPA consent (link via localized pdpaUrl)</label>
      <button type="submit" class="cw-coupon-auth__submit"></button>
      <button type="button" class="cw-coupon-auth__switch" data-cw-mode="login"></button>
    </form>

    <form id="cw-coupon-auth-login" class="cw-coupon-auth__form" hidden>
      <label>Email <input type="email" name="email" required autocomplete="email"></label>
      <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
      <button type="submit" class="cw-coupon-auth__submit"></button>
      <button type="button" class="cw-coupon-auth__switch" data-cw-mode="register"></button>
    </form>
  </div>
</div>
```

(Use `esc_html__` / `esc_attr` properly in PHP when rendering labels.)

- [ ] **Step 3: Add CSS to `assets/css/cw-style-checkout.css`**

Minimal overlay: fixed full-screen backdrop, centered dialog, max-width ~420px, brand primary for CTA, hide honeypot with `position:absolute;left:-9999px`. Match existing checkout spacing tokens if present (`--cwd-*` or checkout locals).

- [ ] **Step 4: Visual check**

Temporarily remove `hidden` on modal in DevTools on cart — layout readable on mobile width.

- [ ] **Step 5: Commit**

```bash
git add includes/class-cw-coupon-auth-popup.php assets/css/cw-style-checkout.css assets/js/cw-coupon-auth-popup.js
git commit -m "$(cat <<'EOF'
feat(B1): coupon auth modal markup and checkout styles

EOF
)"
```

---

### Task 5: Cart/checkout JS intercept + re-apply coupon

**Files:**
- Modify: `assets/js/cw-coupon-auth-popup.js`

**Interfaces:**
- Consumes: `window.cwCouponAuth`, AJAX actions from Task 3
- Produces: intercept `#coupon_code` + apply buttons; modal open/close; WC coupon re-apply

- [ ] **Step 1: Implement JS**

Full file outline:

```javascript
(function ($) {
    'use strict';
    var cfg = window.cwCouponAuth;
    if (!cfg || cfg.isLoggedIn) return;

    var pendingCoupon = '';
    var $modal = $('#cw-coupon-auth-modal');
    if (!$modal.length) return;

    function couponField() {
        return $('#coupon_code, input[name="coupon_code"]').first();
    }

    function readCoupon() {
        return String(couponField().val() || '').trim();
    }

    function showMsg(text, isError) {
        var $m = $('#cw-coupon-auth-msg');
        if (!text) { $m.attr('hidden', true).text(''); return; }
        $m.prop('hidden', false).text(text).toggleClass('is-error', !!isError);
    }

    function setMode(mode) {
        var isLogin = mode === 'login';
        $('#cw-coupon-auth-register').prop('hidden', isLogin);
        $('#cw-coupon-auth-login').prop('hidden', !isLogin);
        $('#cw-coupon-auth-title').text(isLogin ? cfg.i18n.titleLogin : cfg.i18n.titleRegister);
        $('#cw-coupon-auth-register .cw-coupon-auth__submit').text(cfg.i18n.submitRegister);
        $('#cw-coupon-auth-login .cw-coupon-auth__submit').text(cfg.i18n.submitLogin);
        $('#cw-coupon-auth-register .cw-coupon-auth__switch').text(cfg.i18n.switchLogin);
        $('#cw-coupon-auth-login .cw-coupon-auth__switch').text(cfg.i18n.switchRegister);
    }

    function openModal(coupon, mode) {
        pendingCoupon = coupon;
        setMode(mode || 'register');
        showMsg('');
        $modal.prop('hidden', false);
        $('body').addClass('cw-coupon-auth-open');
    }

    function closeModal() {
        $modal.prop('hidden', true);
        $('body').removeClass('cw-coupon-auth-open');
    }

    function applyCouponViaWc(code) {
        var $field = couponField();
        if ($field.length) {
            $field.val(code);
        }
        // Classic cart: button[name=apply_coupon]
        var $btn = $('button[name="apply_coupon"], input[name="apply_coupon"]').first();
        if ($btn.length) {
            // Mark so our interceptor allows this one through
            $btn.data('cw-auth-bypass', 1);
            $btn.trigger('click');
            return;
        }
        // Checkout block / AJAX coupon form
        $(document.body).trigger('apply_coupon', [code]);
        if (typeof wc_checkout_params !== 'undefined') {
            // Fallback: submit coupon via checkout update
            $.post(wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'), {
                security: wc_checkout_params.apply_coupon_nonce,
                coupon_code: code
            }).always(function () {
                $(document.body).trigger('update_checkout');
            });
        }
    }

    function checkRequiresAuth(code, onYes, onNo) {
        $.post(cfg.ajaxUrl, {
            action: 'cw_coupon_requires_auth',
            nonce: cfg.nonce,
            code: code
        }).done(function (res) {
            if (res && res.success && res.data && res.data.requires_auth) {
                onYes();
            } else {
                onNo();
            }
        }).fail(function () {
            onNo(); // fail open to WC (server safety net still protects)
        });
    }

    // Intercept Apply coupon clicks (cart + checkout)
    $(document).on('click', 'button[name="apply_coupon"], input[name="apply_coupon"]', function (e) {
        var $btn = $(this);
        if ($btn.data('cw-auth-bypass')) {
            $btn.removeData('cw-auth-bypass');
            return; // allow
        }
        var code = readCoupon();
        if (!code) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        checkRequiresAuth(code, function () {
            openModal(code, 'register');
        }, function () {
            $btn.data('cw-auth-bypass', 1);
            $btn.trigger('click');
        });
    });

    // Also intercept Enter in coupon field
    $(document).on('keydown', '#coupon_code, input[name="coupon_code"]', function (e) {
        if (e.key !== 'Enter') return;
        var code = readCoupon();
        if (!code) return;
        e.preventDefault();
        $('button[name="apply_coupon"], input[name="apply_coupon"]').first().trigger('click');
    });

    $modal.on('click', '[data-cw-close]', closeModal);
    $modal.on('click', '[data-cw-mode]', function () {
        setMode($(this).data('cw-mode'));
        showMsg('');
    });

    $('#cw-coupon-auth-register').on('submit', function (e) {
        e.preventDefault();
        var $f = $(this);
        $.post(cfg.ajaxUrl, {
            action: 'cw_checkout_register',
            nonce: cfg.nonce,
            email: $f.find('[name=email]').val(),
            password: $f.find('[name=password]').val(),
            name: $f.find('[name=name]').val(),
            dob: $f.find('[name=dob]').val(),
            pdpa: $f.find('[name=pdpa]').is(':checked') ? 1 : 0,
            cw_hp_check: $f.find('[name=cw_hp_check]').val(),
            coupon: pendingCoupon
        }).done(function (res) {
            if (res && res.success) {
                closeModal();
                applyCouponViaWc(res.data.coupon || pendingCoupon);
                return;
            }
            var err = (res && res.data) || {};
            if (err.code === 'email_exists') {
                $('#cw-coupon-auth-login [name=email]').val($f.find('[name=email]').val());
                setMode('login');
                showMsg(cfg.i18n.emailExists, true);
                return;
            }
            showMsg(err.message || cfg.i18n.genericError, true);
        }).fail(function (xhr) {
            var err = (xhr.responseJSON && xhr.responseJSON.data) || {};
            if (err.code === 'email_exists') {
                $('#cw-coupon-auth-login [name=email]').val($f.find('[name=email]').val());
                setMode('login');
                showMsg(cfg.i18n.emailExists, true);
                return;
            }
            showMsg(err.message || cfg.i18n.genericError, true);
        });
    });

    $('#cw-coupon-auth-login').on('submit', function (e) {
        e.preventDefault();
        var $f = $(this);
        $.post(cfg.ajaxUrl, {
            action: 'cw_checkout_login',
            nonce: cfg.nonce,
            email: $f.find('[name=email]').val(),
            password: $f.find('[name=password]').val(),
            coupon: pendingCoupon
        }).done(function (res) {
            if (res && res.success) {
                closeModal();
                applyCouponViaWc(res.data.coupon || pendingCoupon);
                return;
            }
            var err = (res && res.data) || {};
            showMsg(err.message || cfg.i18n.genericError, true);
        }).fail(function (xhr) {
            var err = (xhr.responseJSON && xhr.responseJSON.data) || {};
            showMsg(err.message || cfg.i18n.genericError, true);
        });
    });
})(jQuery);
```

- [ ] **Step 2: End-to-end QA (spec §8)**

1. Guest + school coupon on **checkout** → modal → register → coupon applied.  
2. Same on **cart**.  
3. Existing email → login path → coupon applied.  
4. Non-CW coupon → no modal.  
5. Logged-in → no modal / no script.  
6. Guest full-pay unchanged.  
7. Disable JS → school coupon blocked with login/register links.

- [ ] **Step 3: Fix any cart vs checkout apply quirks**

If classic cart uses form submit instead of button click, also bind:

```javascript
$(document).on('submit', 'form.woocommerce-cart-form, form.checkout_coupon', function (e) {
    // same intercept pattern when apply_coupon present
});
```

Verify both themes used on creativewings.asia.

- [ ] **Step 4: Commit**

```bash
git add assets/js/cw-coupon-auth-popup.js
git commit -m "$(cat <<'EOF'
feat(B1): intercept coupon apply and finish join after popup auth

EOF
)"
```

---

### Task 6: Spec status + checklist doc

**Files:**
- Modify: `docs/superpowers/specs/2026-10-19-guest-coupon-register-popup-design.md` (add Implementation status line when done)
- Create: `docs/superpowers/checklists/2026-10-19-guest-coupon-register-popup.md`

- [ ] **Step 1: Write QA checklist** mirroring design §8 (checkboxes for cart/checkout/register/login/non-CW/logged-in/JS-off).

- [ ] **Step 2: Mark plan tasks complete in this file as you finish.**

- [ ] **Step 3: Commit docs**

```bash
git add docs/superpowers/checklists/2026-10-19-guest-coupon-register-popup.md docs/superpowers/specs/2026-10-19-guest-coupon-register-popup-design.md docs/superpowers/plans/2026-10-19-guest-coupon-register-popup.md
git commit -m "$(cat <<'EOF'
docs(B1): QA checklist and implementation plan for coupon auth popup

EOF
)"
```

---

## Spec coverage (self-review)

| Spec item | Task |
|-----------|------|
| Intercept before WC apply | Task 5 |
| School/sponsor only (`_cw_campaign_id`) | Task 1 + Task 3 classify |
| Register fields + no verify | Task 3 |
| Existing email → Login | Task 5 JS + Task 3 `email_exists` |
| Cart + checkout | Task 4 enqueue + Task 5 |
| Guest full-pay OK | Task 2 only blocks school coupons |
| Server safety net | Task 2 |
| Out of scope B2/B4/social | Global constraints — not implemented |

## Placeholder scan

No TBD/TODO left in task steps. AJAX response shapes match design §6.

## Type consistency

- Action names: `cw_coupon_requires_auth`, `cw_checkout_register`, `cw_checkout_login`
- Helper: `CW_Sponsor_Coupons::is_cw_school_coupon( string ): bool`
- Localized object: `window.cwCouponAuth`
