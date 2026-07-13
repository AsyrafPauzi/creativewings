# Guest Join Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let logged-out visitors join campaigns as guests (fill fields → checkout → pay), then complete account registration via a post-payment email link, while keeping the existing login/register flow and school claim flow unchanged.

**Architecture:** Reuse the existing campaign registration modal and WooCommerce cart/checkout. Add a logged-out “choose path” popup (Login / Register / Join as guest). Guest checkout collects DOB and validates age when brackets exist. After payment, create a single-use token on the order and email a complete-registration URL. A new shortcode page creates the contestant account, copies DOB, and attaches the order/entries.

**Tech Stack:** WordPress, WooCommerce classic checkout, PHP plugin classes, existing CW shortcodes/CSS/auth/email patterns.

**Spec:** `docs/superpowers/specs/2026-07-13-guest-join-design.md`

---

## File map

| File | Responsibility |
|------|----------------|
| Create `includes/class-cw-guest-join.php` | Guest DOB checkout field, age validation, token create/verify/consume, attach order to user, email trigger hook |
| Modify `includes/class-cw-loader.php` | `require_once` new class |
| Modify `creativewings-core.php` | Instantiate `CW_Guest_Join` |
| Modify `includes/class-cw-shortcodes.php` | CTA labels, gate popup, allow reg modal for guests, mobile CTA |
| Modify `includes/class-cw-shop.php` | Allow guest add-to-cart for campaign registration; preserve participant cart data |
| Modify `includes/class-cw-checkout.php` | Style DOB field if needed; ensure guest checkout UX |
| Modify `includes/class-cw-auth.php` | Shortcode + handler for complete-registration form |
| Modify `includes/class-cw-email.php` | Send complete-registration email |
| Modify `assets/css/cw-style-*.css` (campaign/auth as needed) | Gate popup + complete-reg page styles |
| Optional WP page | Page with `[cw_complete_guest_registration]` shortcode (document in plan; create via activator or manual note) |

---

### Task 1: Scaffold `CW_Guest_Join` and wire loader

**Files:**
- Create: `includes/class-cw-guest-join.php`
- Modify: `includes/class-cw-loader.php`
- Modify: `creativewings-core.php`

- [ ] **Step 1: Create the class stub**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Guest_Join {

    const ORDER_META_DOB           = 'cw_guest_dob';
    const ORDER_META_TOKEN_HASH    = 'cw_guest_complete_token_hash';
    const ORDER_META_TOKEN_EXPIRES = 'cw_guest_complete_token_expires';
    const ORDER_META_COMPLETED     = 'cw_guest_account_completed';
    const TOKEN_TTL_DAYS           = 14;

    public function __construct() {
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_guest_dob_field' ], 20 );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_guest_checkout' ], 20 );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_guest_checkout_meta' ], 20 );
        add_action( 'woocommerce_payment_complete', [ $this, 'maybe_send_complete_registration_email' ], 30 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_send_complete_registration_email' ], 30 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_send_complete_registration_email' ], 30 );
    }

    public static function cart_has_cw_campaign() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            $pid = (int) ( $item['product_id'] ?? 0 );
            if ( $pid && get_post_type( $pid ) === 'product' ) {
                return true;
            }
        }
        return false;
    }

    public static function is_guest_checkout_context() {
        return ! is_user_logged_in() && self::cart_has_cw_campaign();
    }

    // Stubs implemented in later tasks:
    public function render_guest_dob_field() {}
    public function validate_guest_checkout() {}
    public function save_guest_checkout_meta( $order_id ) {}
    public function maybe_send_complete_registration_email( $order_id ) {}
}
```

- [ ] **Step 2: Require and instantiate**

In `includes/class-cw-loader.php`, next to other `require_once` lines:

```php
require_once CW_PATH . 'includes/class-cw-guest-join.php';
```

In `creativewings-core.php` `init_plugin` (near `new CW_Checkout()`):

```php
new CW_Guest_Join();
```

- [ ] **Step 3: Verify PHP loads**

Run:

```bash
php -l includes/class-cw-guest-join.php
php -l includes/class-cw-loader.php
php -l creativewings-core.php
```

Expected: `No syntax errors detected` for each.

- [ ] **Step 4: Commit**

```bash
git add includes/class-cw-guest-join.php includes/class-cw-loader.php creativewings-core.php
git commit -m "$(cat <<'EOF'
Add CW_Guest_Join scaffold and wire it into the plugin loader.

EOF
)"
```

---

### Task 2: Campaign CTA + “choose path” popup for logged-out users

**Files:**
- Modify: `includes/class-cw-shortcodes.php` (desktop CTA ~917–924, mobile CTA ~2011–2014, add gate modal HTML/JS near reg modal)
- Modify: relevant campaign CSS (e.g. styles used by `.cwd-cta-btn` / modal — typically campaign detail CSS already in shortcode page assets)

- [ ] **Step 1: Detect competition vs activity for CTA label**

Near where `$pid` / category flags already exist in the product detail shortcode, ensure a label helper:

```php
$join_cta_label = $is_activity
    ? __( 'Join activity', 'creativewings-core' )
    : __( 'Join competition', 'creativewings-core' );
```

(Reuse existing `$is_activity` detection if already present in that render method; if not, copy the same `has_term( 'activities', ... )` / parent-term logic used in `CW_Shop::render_dynamic_fields`.)

- [ ] **Step 2: Replace logged-out CTA**

Replace the “Log in to Join” anchor with a button that opens the gate popup:

```php
<?php elseif ( ! is_user_logged_in() ): ?>
    <button type="button" class="cwd-cta-btn cwd-cta-join" onclick="cwdOpenJoinGate()">
        <i class="fas fa-bolt"></i> <?php echo esc_html( $join_cta_label ); ?>
    </button>
<?php else: ?>
```

Do the same for the sticky mobile CTA.

- [ ] **Step 3: Add gate modal markup** (before or after `#cwd-reg-modal`)

```html
<div id="cwd-join-gate" class="cwd-modal-overlay" style="display:none;" aria-modal="true" role="dialog">
  <div class="cwd-modal-wrap cwd-join-gate-wrap">
    <div class="cwd-modal-head">
      <h3 class="cwd-modal-title"><?php echo esc_html( $join_cta_label ); ?></h3>
      <button type="button" class="cwd-modal-close" onclick="cwdCloseJoinGate()" aria-label="Close">&times;</button>
    </div>
    <div class="cwd-modal-body cwd-join-gate-body">
      <a class="cwd-cta-btn cwd-cta-join" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) . '?redirect_to=' . rawurlencode( get_permalink( $pid ) ) ); ?>">
        <?php esc_html_e( 'Already have an account? Log in', 'creativewings-core' ); ?>
      </a>
      <a class="cwd-cta-btn" href="<?php echo esc_url( /* existing register page URL used by CW_Auth */ ); ?>">
        <?php esc_html_e( 'Register', 'creativewings-core' ); ?>
      </a>
      <button type="button" class="cwd-link-guest" onclick="cwdCloseJoinGate(); cwdOpenRegModal();">
        <?php esc_html_e( 'Join as guest', 'creativewings-core' ); ?>
      </button>
    </div>
  </div>
</div>
```

Resolve the register URL the same way `CW_Auth` / site pages already do (find existing helper or page slug used for `[custom_creator_registration_form]`).

- [ ] **Step 4: Add JS open/close** next to `cwdOpenRegModal`

```javascript
function cwdOpenJoinGate() {
  var m = document.getElementById('cwd-join-gate');
  if (!m) return;
  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function cwdCloseJoinGate() {
  var m = document.getElementById('cwd-join-gate');
  if (m) m.style.display = 'none';
  document.body.style.overflow = '';
}
```

- [ ] **Step 5: Allow registration modal HTML for guests**

Today `#cwd-reg-modal` is rendered for everyone after the CTA block; confirm it is **not** wrapped in `is_user_logged_in()`. If any submit/validation JS assumes login, remove that assumption for guest.

- [ ] **Step 6: Manual check**

Logged out on an open competition: button says “Join competition”, opens gate, “Join as guest” opens field modal. Activity page says “Join activity”.

- [ ] **Step 7: Commit**

```bash
git add includes/class-cw-shortcodes.php assets/css/
git commit -m "$(cat <<'EOF'
Add join gate popup with login, register, and join-as-guest for logged-out visitors.

EOF
)"
```

---

### Task 3: Allow guest add-to-cart for campaign registration

**Files:**
- Modify: `includes/class-cw-shop.php` (validation / add-to-cart filters that require login)
- Modify: `includes/class-cw-design-submission.php` only if design upload blocks guests

- [ ] **Step 1: Find login gates**

Search `includes/class-cw-shop.php` and related for `is_user_logged_in` around add-to-cart / `validate_dynamic_data`. Around line 306 there is already a logged-in branch — read that block and ensure guest POSTs from `#cwd-reg-form` are accepted when campaign is open.

- [ ] **Step 2: Allow guest cart addition**

Pattern to follow: if cart item comes from registration form (`$_POST['cw_reg_nonce']` / `add-to-cart` = campaign id) and `CW_Shop::get_registration_block_reason( $product_id, false )` is null, do **not** require login. Keep one-entry-per-user checks only when `is_user_logged_in()`.

Example adjustment inside validation:

```php
if ( is_user_logged_in() && self::campaign_limits_to_one_entry( (int) $product_id ) && self::user_already_has_entry( get_current_user_id(), (int) $product_id ) ) {
    wc_add_notice( __( 'You have already registered…', 'creativewings-core' ), 'error' );
    return false;
}
```

Do not run that block for guests.

- [ ] **Step 3: Ensure cart redirect still goes to checkout**

`CW_Shop::redirect_to_checkout` should already force checkout URL — verify guest sessions get the same redirect after successful add-to-cart.

- [ ] **Step 4: Manual check**

Logged out → Join as guest → fill required fields → Submit → land on checkout with line item + participant meta visible.

- [ ] **Step 5: Commit**

```bash
git add includes/class-cw-shop.php includes/class-cw-design-submission.php
git commit -m "$(cat <<'EOF'
Allow guest campaign registration to add to cart without login.

EOF
)"
```

---

### Task 4: Guest DOB field + age validation on checkout

**Files:**
- Modify: `includes/class-cw-guest-join.php`
- Optionally modify: `assets/css/cw-style-checkout.css`

- [ ] **Step 1: Render DOB field for guests only**

```php
public function render_guest_dob_field() {
    if ( ! self::is_guest_checkout_context() ) {
        return;
    }
    $value = WC()->checkout->get_value( 'cw_guest_dob' );
    if ( ! is_string( $value ) ) {
        $value = '';
    }
    echo '<div class="cw-checkout-message-section cw-guest-dob-section">';
    echo '<h3 class="cw-checkout-message-heading">' . esc_html__( 'Date of birth', 'creativewings-core' ) . ' <abbr class="required" title="required">*</abbr></h3>';
    woocommerce_form_field( 'cw_guest_dob', [
        'type'        => 'text',
        'class'       => [ 'form-row-wide', 'cw-guest-dob-field' ],
        'label'       => __( 'Date of birth', 'creativewings-core' ),
        'required'    => true,
        'placeholder' => 'dd/mm/yyyy',
        'autocomplete'=> 'bday',
    ], $value );
    echo '</div>';
}
```

Match existing site DOB format (`dd/mm/yyyy` used in contestant profile / `#birthdate`).

- [ ] **Step 2: Validate required DOB + existing email + age brackets**

```php
public function validate_guest_checkout() {
    if ( ! self::is_guest_checkout_context() ) {
        return;
    }

    $email = sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) );
    if ( $email && email_exists( $email ) ) {
        wc_add_notice(
            __( 'This email already has an account. Please log in to continue — your registration details will be kept.', 'creativewings-core' ),
            'error'
        );
        // Persist return URL for login redirect (campaign permalink if known from cart).
        return;
    }

    $dob = isset( $_POST['cw_guest_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_guest_dob'] ) ) : '';
    if ( '' === trim( $dob ) || null === CW_Staged_Submissions::age_from_birthdate( $dob ) ) {
        wc_add_notice( __( 'Please enter a valid date of birth (dd/mm/yyyy).', 'creativewings-core' ), 'error' );
        return;
    }

    foreach ( WC()->cart->get_cart() as $item ) {
        $pid = (int) $item['product_id'];
        if ( get_post_meta( $pid, 'cw_enable_age_brackets', true ) !== 'yes' ) {
            continue;
        }
        $result = CW_Staged_Submissions::resolve_age_bracket( $pid, $dob );
        if ( is_wp_error( $result ) ) {
            wc_add_notice( $result->get_error_message(), 'error' );
            return;
        }
        // Optional: stash bracket on session for order meta later.
        WC()->session->set( 'cw_guest_age_bracket_' . $pid, $result );
    }
}
```

If `resolve_age_bracket` errors with `no_brackets` when toggle is on but empty, show a clear organiser-facing message or treat as no check — prefer: if toggle on and empty brackets, do not block guests (only block on `no_match` / invalid DOB). Adjust:

```php
if ( is_wp_error( $result ) && $result->get_error_code() === 'no_match' ) {
    wc_add_notice( $result->get_error_message(), 'error' );
}
```

- [ ] **Step 3: Save DOB on order**

```php
public function save_guest_checkout_meta( $order_id ) {
    if ( is_user_logged_in() ) {
        return;
    }
    $dob = isset( $_POST['cw_guest_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_guest_dob'] ) ) : '';
    if ( $dob ) {
        update_post_meta( $order_id, self::ORDER_META_DOB, $dob );
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( self::ORDER_META_DOB, $dob );
            $order->save();
        }
    }
}
```

- [ ] **Step 4: Manual check**

Guest checkout without DOB → error. Wrong age for bracketed campaign → error. Valid DOB → order places; meta `cw_guest_dob` present.

- [ ] **Step 5: Commit**

```bash
git add includes/class-cw-guest-join.php assets/css/cw-style-checkout.css
git commit -m "$(cat <<'EOF'
Collect guest date of birth at checkout and enforce campaign age brackets.

EOF
)"
```

---

### Task 5: A+ existing-email → login → restore cart / form

**Files:**
- Modify: `includes/class-cw-guest-join.php`
- Modify: `includes/class-cw-auth.php` or login redirect (`custom_login_redirect`)
- Modify: `includes/class-cw-shortcodes.php` (optional query flag to reopen modal)

- [ ] **Step 1: On existing-email notice, store resume context in WC session**

```php
WC()->session->set( 'cw_guest_resume_after_login', [
    'campaign_id' => $campaign_id_from_cart,
    'checkout_url'=> wc_get_checkout_url(),
] );
```

Cart already persists in WC session for the same browser; after login WC merges/keeps cart for many setups — verify. If cart is lost on login, snapshot cart item data into session before redirecting to login.

- [ ] **Step 2: Point the notice to login with redirect**

Build login URL:

```php
$login = add_query_arg(
    [ 'redirect_to' => rawurlencode( get_permalink( $campaign_id ) . '?cw_resume_join=1' ) ],
    wc_get_page_permalink( 'myaccount' )
);
```

Include `$login` in the wc notice HTML (allowed tags) or as a follow-up notice.

- [ ] **Step 3: After login, if `?cw_resume_join=1`, auto-open reg modal**

In shortcode JS:

```javascript
if (new URLSearchParams(window.location.search).get('cw_resume_join') === '1') {
  document.addEventListener('DOMContentLoaded', function(){ cwdOpenRegModal(); });
}
```

Participant field values may need re-entry if browser cleared the form; cart line should still hold `_cw_participant_data` if already added. Prefer: if cart already has the campaign line, skip modal and send user straight to checkout after login.

Preferred resume order:

1. If cart has campaign → redirect to checkout after login.  
2. Else open join modal on campaign page.

- [ ] **Step 4: Manual check**

Guest fills form → checkout → use existing account email → error with login link → login → return to checkout (or campaign with cart intact) without re-uploading if cart survived.

- [ ] **Step 5: Commit**

```bash
git add includes/class-cw-guest-join.php includes/class-cw-auth.php includes/class-cw-shortcodes.php
git commit -m "$(cat <<'EOF'
Block guest checkout for existing emails and resume join after login.

EOF
)"
```

---

### Task 6: Post-payment token + complete-registration email

**Files:**
- Modify: `includes/class-cw-guest-join.php`
- Modify: `includes/class-cw-email.php`

- [ ] **Step 1: Token helpers on `CW_Guest_Join`**

```php
public static function create_completion_token( $order_id ) {
    $token  = bin2hex( random_bytes( 32 ) );
    $hash   = hash( 'sha256', $token );
    $expiry = time() + ( self::TOKEN_TTL_DAYS * DAY_IN_SECONDS );
    $order  = wc_get_order( $order_id );
    if ( ! $order ) {
        return '';
    }
    $order->update_meta_data( self::ORDER_META_TOKEN_HASH, $hash );
    $order->update_meta_data( self::ORDER_META_TOKEN_EXPIRES, $expiry );
    $order->update_meta_data( self::ORDER_META_COMPLETED, 'no' );
    $order->save();
    return $token; // plaintext only for email URL
}

public static function verify_completion_token( $order_id, $token ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_user_id() ) {
        return false;
    }
    if ( $order->get_meta( self::ORDER_META_COMPLETED ) === 'yes' ) {
        return false;
    }
    $expires = (int) $order->get_meta( self::ORDER_META_TOKEN_EXPIRES );
    if ( $expires && time() > $expires ) {
        return false;
    }
    $hash = (string) $order->get_meta( self::ORDER_META_TOKEN_HASH );
    return $hash && hash_equals( $hash, hash( 'sha256', (string) $token ) );
}
```

- [ ] **Step 2: Send email once after payment**

```php
public function maybe_send_complete_registration_email( $order_id ) {
    $order_id = (int) $order_id;
    $order    = wc_get_order( $order_id );
    if ( ! $order || $order->get_user_id() ) {
        return;
    }
    if ( $order->get_meta( '_cw_guest_complete_email_sent' ) === 'yes' ) {
        return;
    }
    if ( ! $order->is_paid() && ! in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
        return;
    }
    $token = self::create_completion_token( $order_id );
    if ( ! $token ) {
        return;
    }
    if ( class_exists( 'CW_Email' ) ) {
        CW_Email::send_guest_complete_registration( $order, $token );
    }
    $order->update_meta_data( '_cw_guest_complete_email_sent', 'yes' );
    $order->save();
}
```

- [ ] **Step 3: Implement `CW_Email::send_guest_complete_registration`**

Follow existing branded email pattern in `includes/class-cw-email.php` (same from-name / HTML wrapper as other CW emails).

URL:

```php
$page = get_permalink( /* page id with shortcode, or home_url path */ );
$url  = add_query_arg( [
    'cw_guest_order' => $order->get_id(),
    'cw_guest_token' => $token,
], $page );
```

Body: thank them for joining; explain entry is already submitted; button “Complete registration”.

- [ ] **Step 4: Ensure entries still create for guest orders**

In `CW_Shop::create_entries_from_order`, confirm `$order->get_user_id()` being `0` still creates entries (uses billing name). If code assumes non-zero user, set `post_author` to `0` or a system user and store billing email on entry meta `cw_guest_email`. Add minimal fix if needed:

```php
$user_id = $order->get_user_id();
// allow 0 for guest
```

Copy DOB onto entry meta when creating from guest order:

```php
$dob = $order->get_meta( CW_Guest_Join::ORDER_META_DOB );
if ( $dob ) {
    update_post_meta( $entry_id, CW_Guest_Join::ORDER_META_DOB, $dob );
}
```

- [ ] **Step 5: Manual check**

Place guest paid order (or set processing) → email sent once → order has token hash + expiry; plaintext token only in email.

- [ ] **Step 6: Commit**

```bash
git add includes/class-cw-guest-join.php includes/class-cw-email.php includes/class-cw-shop.php
git commit -m "$(cat <<'EOF'
Email a one-time complete-registration link after guest payment.

EOF
)"
```

---

### Task 7: Complete-registration shortcode page (account only)

**Files:**
- Modify: `includes/class-cw-auth.php`
- Modify: `includes/class-cw-guest-join.php` (consume token + attach)
- Modify: `assets/css/cw-style-general.css` (auth card reuse)
- Document / optionally create WP page in `CW_Activator` if the plugin already auto-creates auth pages

- [ ] **Step 1: Register shortcode**

In `CW_Auth::__construct`:

```php
add_shortcode( 'cw_complete_guest_registration', [ $this, 'render_complete_guest_registration_form' ] );
add_action( 'admin_post_nopriv_cw_complete_guest_registration', [ $this, 'process_complete_guest_registration' ] );
add_action( 'admin_post_cw_complete_guest_registration', [ $this, 'process_complete_guest_registration' ] );
```

- [ ] **Step 2: Render form (token required)**

```php
public function render_complete_guest_registration_form() {
    $order_id = isset( $_GET['cw_guest_order'] ) ? absint( $_GET['cw_guest_order'] ) : 0;
    $token    = isset( $_GET['cw_guest_token'] ) ? sanitize_text_field( wp_unslash( $_GET['cw_guest_token'] ) ) : '';

    if ( ! $order_id || ! $token || ! CW_Guest_Join::verify_completion_token( $order_id, $token ) ) {
        return $this->render_static_auth_message(
            __( 'Invalid or expired link', 'creativewings-core' ),
            __( 'This registration link is invalid, expired, or already used.', 'creativewings-core' )
        );
    }

    $order = wc_get_order( $order_id );
    $email = $order->get_billing_email();
    $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    $dob   = $order->get_meta( CW_Guest_Join::ORDER_META_DOB );

    // Form: read-only email, name, dob; password + confirm; hidden order_id + token; nonce.
    // action = admin-post.php?action=cw_complete_guest_registration
}
```

Reuse `.cw-auth-wrapper` / `.cw-auth-card` markup from existing register form.

- [ ] **Step 3: Process handler**

```php
public function process_complete_guest_registration() {
    // verify nonce
    $order_id = absint( $_POST['cw_guest_order'] ?? 0 );
    $token    = sanitize_text_field( wp_unslash( $_POST['cw_guest_token'] ?? '' ) );
    if ( ! CW_Guest_Join::verify_completion_token( $order_id, $token ) ) {
        wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Invalid link' ), wp_get_referer() ?: home_url() ) );
        exit;
    }
    $order = wc_get_order( $order_id );
    $email = $order->get_billing_email();
    if ( email_exists( $email ) ) {
        // Ask them to log in instead; do not create duplicate.
        wp_safe_redirect( /* login with message */ );
        exit;
    }
    $pass = (string) ( $_POST['cw_password'] ?? '' );
    $pass2= (string) ( $_POST['cw_password_confirm'] ?? '' );
    // validate password strength same as handle_registration
    $user_id = wc_create_new_customer( $email, '', $pass );
    // or wp_create_user + set role contestant like CW_Auth::handle_registration
    update_user_meta( $user_id, 'birthdate', $order->get_meta( CW_Guest_Join::ORDER_META_DOB ) );
    // copy billing name to first/last if empty
    $order->set_customer_id( $user_id );
    $order->update_meta_data( CW_Guest_Join::ORDER_META_COMPLETED, 'yes' );
    $order->delete_meta_data( CW_Guest_Join::ORDER_META_TOKEN_HASH );
    $order->save();

    // Reassign entries:
    $entries = get_posts( [
        'post_type' => CW_Shop::entry_post_types(),
        'meta_key'  => 'order_id',
        'meta_value'=> $order_id,
        'numberposts'=> -1,
        'fields'    => 'ids',
    ] );
    foreach ( $entries as $eid ) {
        wp_update_post( [ 'ID' => $eid, 'post_author' => $user_id ] );
        update_post_meta( $eid, 'customer_id', $user_id );
    }

    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id );
    wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
    exit;
}
```

Match contestant role assignment from `CW_Auth::handle_registration` (do not invent a new role).

- [ ] **Step 4: Create or document WP page**

If `CW_Activator` creates auth pages, add `complete-guest-registration` page with shortcode `[cw_complete_guest_registration]`. Otherwise add admin notice listing required page (same pattern as `maybe_show_missing_pages_notice`).

- [ ] **Step 5: Manual check**

- Valid token → form shows email/DOB locked, password works → user created, order linked, entries authored by user, token dead.  
- Invalid/expired/used token → error page, no form.  
- Logged-in random user visiting without token → cannot use page.

- [ ] **Step 6: Commit**

```bash
git add includes/class-cw-auth.php includes/class-cw-guest-join.php includes/class-cw-activator.php
git commit -m "$(cat <<'EOF'
Add token-gated complete registration for guest joiners.

EOF
)"
```

---

### Task 8: WooCommerce guest checkout enablement + polish

**Files:**
- Modify: `includes/class-cw-guest-join.php` and/or `includes/class-cw-checkout.php`
- Modify: `includes/class-cw-shop.php` if entry creation needs guest polish

- [ ] **Step 1: Ensure guest checkout is possible**

If site has `woocommerce_enable_guest_checkout` = `no`, either:

```php
add_filter( 'pre_option_woocommerce_enable_guest_checkout', function( $value ) {
    if ( CW_Guest_Join::is_guest_checkout_context() ) {
        return 'yes';
    }
    return $value;
} );
```

or document that admins must enable Guest checkout. Prefer the filter scoped to CW campaign carts so the feature works without a global settings change.

- [ ] **Step 2: End-to-end QA checklist**

| # | Scenario | Expected |
|---|----------|----------|
| 1 | Logged-out competition CTA | “Join competition” → gate popup |
| 2 | Logged-out activity CTA | “Join activity” |
| 3 | Login / Register from gate | Existing auth pages with return |
| 4 | Join as guest → submit fields | Cart + checkout as guest |
| 5 | Guest checkout missing DOB | Blocked |
| 6 | Guest DOB outside age brackets | Blocked with message |
| 7 | Guest DOB OK, new email | Order succeeds |
| 8 | Guest email already registered | Login prompt; after login resume |
| 9 | Payment success | One email with complete-reg link |
| 10 | Complete reg with token | Account + DOB + linked entries |
| 11 | Reuse token | Rejected |
| 12 | Never complete account | Entry still exists / valid |
| 13 | Logged-in Join Now | Unchanged |
| 14 | School claim / link code | Still requires login |

- [ ] **Step 3: Commit**

```bash
git add includes/class-cw-guest-join.php includes/class-cw-checkout.php
git commit -m "$(cat <<'EOF'
Ensure guest checkout works for campaign carts and finish guest-join QA polish.

EOF
)"
```

---

## Spec coverage check

| Spec requirement | Task |
|------------------|------|
| Join competition / activity CTA | Task 2 |
| Gate popup Login / Register / Guest | Task 2 |
| Guest fills campaign fields | Tasks 2–3 |
| Guest checkout + DOB | Task 4 |
| Age brackets block guest only | Task 4 |
| Existing email A+ resume | Task 5 |
| Email after payment | Task 6 |
| Entry valid without account | Tasks 6–8 |
| Token-gated complete reg (account only, DOB prefilled) | Task 7 |
| Claim/school unchanged | Tasks 2–3 (no claim changes) |
| Every open campaign | Task 2 (no organiser toggle) |

## Placeholder / consistency scan

- Meta keys consistently use `CW_Guest_Join::ORDER_META_*`.
- DOB format aligned with existing `birthdate` user meta (`dd/mm/yyyy`).
- Age logic reuses `CW_Staged_Submissions::age_from_birthdate` / `resolve_age_bracket`.
- No PHPUnit suite in repo — verification is manual QA (Task 8).
