# Elementor Exit — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the same Creative Wings look for site chrome + main browse path without Elementor owning those surfaces: custom Astra child theme header/footer, plus Homepage / Activities / Competitions PHP shells wrapping existing `creativewings-core` shortcodes.

**Architecture:** New child theme `astra-child-creativewings` owns design tokens, header, footer, and three page templates. Plugin shortcodes stay the source of campaign UI. Elementor remains active (product/contest templates still need it) but Theme Builder header/footer display conditions are cleared after cutover, and the three pages leave Elementor builder mode.

**Tech Stack:** WordPress, Astra parent theme, PHP templates, CSS custom properties, existing shortcodes in `creativewings-core`, Novamira for live deploy/verify.

**Spec:** `docs/superpowers/specs/2026-11-06-elementor-exit-design.md`  
**Backup:** `docs/superpowers/backups/2026-11-06-elementor-pre-exit/` (+ server `wp-content/cw-private-backups/`)

## Global Constraints

- Visual parity with live Elementor pages — no redesign unless asked.
- Do not deactivate Elementor / Pro / Happy / Essential in Phase 1.
- Do not change `creativewings-core` business logic except tiny helpers if a shortcode wrapper is missing.
- Child theme source lives in-repo at `themes/astra-child-creativewings/` and deploys to `wp-content/themes/astra-child-creativewings/`.
- Keep Elementor header `#389` / footer `#163` data intact as rollback (only clear display conditions).
- Page IDs: Homepage `159`, Activities `2468`, Competitions `460`.
- Menus: `header-menu-main` (term 46), `footer` (term 52).
- Header KPI shortcodes: `[product_count]`, `[total_prize_money]` (prefix `000` before product count as live does).
- Header logo attachment: `3151` (`Logo-CW-Black.png`). SDG image: `Asset-2@2x-80.jpg` (from header tree). Mobile drawer logo: `3378` (`Logo-white-woridng.png`).
- Homepage hero image: attachment `2225` (`Asset-4-80.jpg`).
- Homepage shortcodes (Elementor source of truth):  
  `[custom_search_form]` · `[cw_featured_campaign]` · `[cw_how_it_works]` · `[cw_partners_marquee heading="Our Partners" campaign_id="3361"]` · `[cw_events_grid limit="6" columns="3" heading="Open Campaigns"]`
- Activities: intro copy + `[custom_search_form theme="solid"]` + `[cw_activities_grid view_toggle="1" per_page="12" pagination="more"]` + cross-promo to Competitions.
- Competitions: intro copy + `[custom_search_form theme="solid"]` + `[cw_competitions_grid view_toggle="1" per_page="12" pagination="more"]` + cross-promo to Activities.

---

### Task 1: Scaffold Astra child theme

**Files:**
- Create: `themes/astra-child-creativewings/style.css`
- Create: `themes/astra-child-creativewings/functions.php`
- Create: `themes/astra-child-creativewings/screenshot.png` (optional; skip if no asset)

**Interfaces:**
- Produces: activatable child theme named `astra-child-creativewings` with parent `astra`

- [ ] **Step 1: Create theme headers**

```css
/*
Theme Name: Creative Wings (Astra Child)
Theme URI: https://creativewings.asia
Description: Astra child theme — Elementor exit Phase 1 chrome + browse pages
Author: Creative Wings
Template: astra
Version: 0.1.0
Text Domain: cw-astra-child
*/
```

```php
<?php
/**
 * Creative Wings Astra child theme.
 * @package CW_Astra_Child
 */
defined( 'ABSPATH' ) || exit;

define( 'CW_CHILD_VERSION', '0.1.0' );
define( 'CW_CHILD_DIR', get_stylesheet_directory() );
define( 'CW_CHILD_URI', get_stylesheet_directory_uri() );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'astra-parent',
		get_template_directory_uri() . '/style.css',
		[],
		wp_get_theme( 'astra' )->get( 'Version' )
	);
	wp_enqueue_style(
		'cw-child-tokens',
		CW_CHILD_URI . '/assets/css/tokens.css',
		[ 'astra-parent' ],
		CW_CHILD_VERSION
	);
	wp_enqueue_style(
		'cw-child',
		CW_CHILD_URI . '/style.css',
		[ 'cw-child-tokens' ],
		CW_CHILD_VERSION
	);
	wp_enqueue_style(
		'cw-child-chrome',
		CW_CHILD_URI . '/assets/css/chrome.css',
		[ 'cw-child' ],
		CW_CHILD_VERSION
	);
	wp_enqueue_script(
		'cw-child-chrome',
		CW_CHILD_URI . '/assets/js/chrome.js',
		[],
		CW_CHILD_VERSION,
		true
	);
} );
```

- [ ] **Step 2: Deploy theme folder to live** via Novamira upload (zip) into `wp-content/themes/astra-child-creativewings/` — **do not activate yet**.

- [ ] **Step 3: Verify theme appears**

```php
// novamira/execute-php
return array_values( array_filter( wp_get_themes(), fn( $t ) => $t->get_stylesheet() === 'astra-child-creativewings' || $t->get( 'Name' ) ) );
```

Expected: child theme listed; `stylesheet` still `astra` until Task 5.

- [ ] **Step 4: Commit scaffold** (when user asks for git commit)

```bash
git add themes/astra-child-creativewings
git commit -m "$(cat <<'EOF'
feat: scaffold Astra child theme for Elementor exit Phase 1

EOF
)"
```

---

### Task 2: Design tokens + chrome CSS/JS shells

**Files:**
- Create: `themes/astra-child-creativewings/assets/css/tokens.css`
- Create: `themes/astra-child-creativewings/assets/css/chrome.css`
- Create: `themes/astra-child-creativewings/assets/css/pages.css`
- Create: `themes/astra-child-creativewings/assets/js/chrome.js`

**Interfaces:**
- Consumes: live colors from header (black bar, white text, Montserrat, coral accent ~Brand Story pink)
- Produces: CSS variables used by header/footer/page templates

- [ ] **Step 1: Write tokens from live reference**

Extract from live homepage/header (confirm with DevTools if needed). Baseline:

```css
:root {
  --cw-black: #000000;
  --cw-white: #ffffff;
  --cw-ink: #1a1a1a;
  --cw-muted: #475569;
  --cw-coral: #e85a7a; /* Brand Story / nav active — refine from computed style */
  --cw-blue: #1e4d8c;
  --cw-font: "Montserrat", system-ui, sans-serif;
  --cw-header-h: 72px;
  --cw-container: 1200px;
  --cw-radius-sm: 8px;
}
```

- [ ] **Step 2: Empty chrome/pages CSS with section comments** (`/* header */`, `/* footer */`, `/* homepage hero */`, etc.) so later tasks fill them without fighting Elementor classnames.

- [ ] **Step 3: `chrome.js` — mobile nav toggle only**

```js
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.querySelector('[data-cw-nav-toggle]');
  const panel = document.querySelector('[data-cw-nav-panel]');
  if (!btn || !panel) return;
  btn.addEventListener('click', () => {
    const open = panel.classList.toggle('is-open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
});
```

- [ ] **Step 4: Enqueue `pages.css` from `functions.php`** on the three Phase 1 pages only (by ID or template slug).

- [ ] **Step 5: Commit** (when asked)

---

### Task 3: Rebuild header (match Elementor `#389`)

**Files:**
- Create: `themes/astra-child-creativewings/header.php`
- Create: `themes/astra-child-creativewings/template-parts/chrome/header-bar.php`
- Modify: `themes/astra-child-creativewings/assets/css/chrome.css`
- Modify: `themes/astra-child-creativewings/functions.php` (optional: disable Astra header via `astra_header` remove / theme support)

**Interfaces:**
- Consumes: menu slug `header-menu-main`; shortcodes `product_count`, `total_prize_money`; logos `3151`, SDG asset, mobile logo `3378`
- Produces: `get_header()` output visually matching live black header

- [ ] **Step 1: Disable Astra default header** so only custom markup shows

```php
// functions.php
add_action( 'after_setup_theme', function () {
	remove_action( 'astra_header', 'astra_header_markup' );
} );
add_action( 'astra_header', function () {
	get_template_part( 'template-parts/chrome/header', 'bar' );
} );
```

(If Astra version uses different hooks, inspect parent `header.php` and adapt — keep one custom bar.)

- [ ] **Step 2: Implement header bar structure**

Desktop order (from Elementor tree): CW logo → SDG image → primary nav (Brand Story / Activities / Competitions…) → KPI pill (`Site Campaigns` + `000[product_count]` | `Site Prize Pool` + `[total_prize_money]`) → Login/Signup (from menu or hard link to `/login/`).

Mobile: logo + hamburger; drawer uses white wordmark `3378` + `header-menu-main`.

```php
<?php
$logo = wp_get_attachment_image( 3151, 'full', false, [ 'class' => 'cw-header__logo', 'alt' => 'Creative Wings' ] );
$sdg  = /* attachment ID for Asset-2@2x-80.jpg — resolve via URL in setup */ ;
?>
<header class="cw-header" role="banner">
  <div class="cw-header__inner">
    <a class="cw-header__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo $logo; ?></a>
    <!-- SDG link/image -->
    <nav class="cw-header__nav" aria-label="Primary">
      <?php wp_nav_menu( [ 'menu' => 'header-menu-main', 'container' => false, 'menu_class' => 'cw-header__menu' ] ); ?>
    </nav>
    <div class="cw-header__kpi" aria-label="Site stats">
      <div class="cw-header__kpi-item">
        <span class="cw-header__kpi-label">Site Campaigns</span>
        <span class="cw-header__kpi-value">000<?php echo do_shortcode( '[product_count]' ); ?></span>
      </div>
      <div class="cw-header__kpi-item">
        <span class="cw-header__kpi-label">Site Prize Pool</span>
        <span class="cw-header__kpi-value"><?php echo do_shortcode( '[total_prize_money]' ); ?></span>
      </div>
    </div>
    <button type="button" class="cw-header__toggle" data-cw-nav-toggle aria-expanded="false" aria-controls="cw-nav-panel">Menu</button>
  </div>
  <div id="cw-nav-panel" class="cw-header__drawer" data-cw-nav-panel hidden>
    <?php wp_nav_menu( [ 'menu' => 'header-menu-main', 'container' => false ] ); ?>
  </div>
</header>
```

Resolve SDG attachment ID once via execute-php (`guid` LIKE `%Asset-2@2x-80%`) and hardcode the ID in the template.

- [ ] **Step 3: Style to match** — black background, white menu boxes / active coral, KPI white pill. Use side-by-side screenshots vs live.

- [ ] **Step 4: Local/staging visual check** before sitewide activation (Task 5). Prefer a `?cw_child_preview=1` query flag gated to admins if activating early is risky:

```php
add_filter( 'stylesheet', function ( $sheet ) {
	if ( ! empty( $_GET['cw_child_preview'] ) && current_user_can( 'manage_options' ) ) {
		return 'astra-child-creativewings';
	}
	return $sheet;
}, 1 );
add_filter( 'template', function ( $t ) {
	if ( ! empty( $_GET['cw_child_preview'] ) && current_user_can( 'manage_options' ) ) {
		return 'astra';
	}
	return $t;
}, 1 );
```

(Remove preview filters after cutover.)

- [ ] **Step 5: Commit** (when asked)

---

### Task 4: Rebuild footer (match Elementor `#163`)

**Files:**
- Create: `themes/astra-child-creativewings/template-parts/chrome/footer-bar.php`
- Modify: `themes/astra-child-creativewings/functions.php`
- Modify: `themes/astra-child-creativewings/assets/css/chrome.css`

**Interfaces:**
- Consumes: menu slug `footer` (term 52); footer logos from backup `elementor_library-163-*.json`
- Produces: `astra_footer` replacement matching live

- [ ] **Step 1: Disable Astra default footer; render custom part**

```php
remove_action( 'astra_footer', 'astra_footer_markup' );
add_action( 'astra_footer', function () {
	get_template_part( 'template-parts/chrome/footer', 'bar' );
} );
```

- [ ] **Step 2: Markup** — logos, `Let’s Chat : hello@creativewings.asia`, `wp_nav_menu( ['menu'=>'footer'] )`, copyright line matching live.

- [ ] **Step 3: Visual QA** with `?cw_child_preview=1` on Homepage.

- [ ] **Step 4: Commit** (when asked)

---

### Task 5: Activate child theme + retire Elementor Theme Builder chrome

**Files:**
- Live only (options / Elementor conditions) — document in this plan; no plugin code required

**Interfaces:**
- Consumes: working header/footer under preview
- Produces: sitewide child theme; Elementor header/footer no longer displayed

- [ ] **Step 1: Activate child theme**

```php
// novamira/execute-php
switch_theme( 'astra-child-creativewings' );
return [ 'stylesheet' => get_stylesheet(), 'template' => get_template() ];
```

Expected: `stylesheet=astra-child-creativewings`, `template=astra`.

- [ ] **Step 2: Clear Elementor Theme Builder display conditions for header 389 and footer 163**  
  Use Elementor Pro conditions API or set `_elementor_conditions` meta to empty / exclude all, so Theme Builder does not inject chrome. **Do not delete** templates 389/163.

```php
// Prefer Elementor API if available; else:
delete_post_meta( 389, '_elementor_conditions' );
delete_post_meta( 163, '_elementor_conditions' );
if ( class_exists( '\Elementor\Plugin' ) ) {
  \Elementor\Plugin::$instance->files_manager->clear_cache();
}
```

Verify conditions location in DB first (`get_post_meta(389,'_elementor_conditions',true)` and any `elementor_pro` options) — adjust to whatever this install uses.

- [ ] **Step 3: Hard refresh homepage** — one header, one footer, no double chrome.

- [ ] **Step 4: Rollback note** (README in theme): re-add conditions from backup JSON + `switch_theme('astra')` if needed.

---

### Task 6: Homepage template (page 159)

**Files:**
- Create: `themes/astra-child-creativewings/page-homepage.php` (slug `homepage`)  
  or `templates/page-cw-home.php` + assign via `_wp_page_template`
- Modify: `assets/css/pages.css`
- Live: update post `159` content + leave Elementor builder

**Interfaces:**
- Consumes: shortcodes listed in Global Constraints; hero image `2225`
- Produces: front page without Elementor canvas

- [ ] **Step 1: Create `page-homepage.php`**

```php
<?php
/**
 * Homepage — Elementor exit Phase 1
 */
get_header();
$hero = wp_get_attachment_image_url( 2225, 'full' );
?>
<main id="primary" class="cw-home">
  <section class="cw-home__hero" style="--cw-hero-image:url('<?php echo esc_url( $hero ); ?>')">
    <div class="cw-home__hero-inner">
      <p class="cw-home__tagline">“Together, We Help Young Talents Soar”</p>
      <h1 class="cw-home__seo-title">Creative Competitions &amp; Activities for Students in Malaysia</h1>
      <?php echo do_shortcode( '[custom_search_form]' ); ?>
    </div>
  </section>
  <div class="cw-home__sections">
    <?php echo do_shortcode( '[cw_featured_campaign]' ); ?>
    <?php echo do_shortcode( '[cw_how_it_works]' ); ?>
    <?php echo do_shortcode( '[cw_partners_marquee heading="Our Partners" campaign_id="3361"]' ); ?>
    <?php echo do_shortcode( '[cw_events_grid limit="6" columns="3" heading="Open Campaigns"]' ); ?>
  </div>
</main>
<?php
get_footer();
```

- [ ] **Step 2: Port Elementor custom CSS** for `.custom-search-form` from page 159 `_elementor_page_settings.custom_css` into `pages.css`.

- [ ] **Step 3: Cut over page 159**

```php
$p = get_post( 159 );
// Keep a meta backup if not already in zip
update_post_meta( 159, '_elementor_data_backup_phase1', wp_slash( get_post_meta( 159, '_elementor_data', true ) ) );
delete_post_meta( 159, '_elementor_edit_mode' );
update_post_meta( 159, '_wp_page_template', 'default' ); // uses page-homepage.php by slug
wp_update_post( [ 'ID' => 159, 'post_content' => '' ] ); // template owns content
if ( class_exists( '\Elementor\Plugin' ) ) {
  \Elementor\Plugin::$instance->files_manager->clear_cache();
}
clean_post_cache( 159 );
```

- [ ] **Step 4: QA** — hero, search, featured campaign, how-it-works, partners, events grid; mobile hero height.

- [ ] **Step 5: Commit** (when asked)

---

### Task 7: Activities page template (2468)

**Files:**
- Create: `themes/astra-child-creativewings/page-activities.php`
- Modify: `assets/css/pages.css`

**Copy from live Elementor (Activities):**

- H1: `Community Activities & Events in Malaysia`
- Intro: `Art sessions, community runs and parent talks for children and families. Pick one and register online.`
- Search: `[custom_search_form theme="solid"]`
- Grid: `[cw_activities_grid view_toggle="1" per_page="12" pagination="more"]`
- Cross-promo band: heading `Art competitions for young talents`, text `Submit artwork, gather public votes and win prizes.`, buttons Browse competitions → `/competitions/`, Our story → `/brand-story/`

- [ ] **Step 1: Implement `page-activities.php`** with the structure above.

- [ ] **Step 2: Cut over 2468** (same pattern as Homepage: backup `_elementor_data`, delete `_elementor_edit_mode`, clear Elementor cache).

- [ ] **Step 3: QA** grid, view toggle, pagination, cross-promo CTAs.

- [ ] **Step 4: Commit** (when asked)

---

### Task 8: Competitions page template (460)

**Files:**
- Create: `themes/astra-child-creativewings/page-competitions.php`
- Modify: `assets/css/pages.css`

**Copy from live Elementor (Competitions):**

- H1: `Creative Competitions in Malaysia`
- Intro: `Drawing and design competitions for young artists. Submit your artwork, gather public votes and win prizes.`
- Search: `[custom_search_form theme="solid"]`
- Grid: `[cw_competitions_grid view_toggle="1" per_page="12" pagination="more"]`
- Cross-promo: `Activities and events for families` → Browse activities `/activities/` + Our story

- [ ] **Step 1–4:** Mirror Task 7 for page `460`.

---

### Task 9: Phase 1 verification checklist

- [ ] **Desktop + mobile screenshots** for: header, footer, Homepage, Activities, Competitions vs pre-exit backup / memory of live Elementor.
- [ ] **No double header/footer.**
- [ ] **KPI numbers** still update (`product_count`, `total_prize_money`).
- [ ] **Login / Signup** still reachable from header.
- [ ] **Menus** current-page state on Brand Story / Activities / Competitions.
- [ ] **Woo product / contest pages** still render (Elementor templates still active) — smoke open one competition product.
- [ ] **Page weight:** note transfer size Homepage before/after (DevTools Network).
- [ ] **Rollback drill documented** in `themes/astra-child-creativewings/README.md`:
  1. `switch_theme('astra')`
  2. Restore Elementor conditions for 389/163 from backup
  3. Restore `_elementor_edit_mode=builder` + `_elementor_data` on 159/2468/460 from `docs/superpowers/backups/...` JSON

- [ ] **Update spec** Phase 1 exit criteria → done when checklist passes.

---

### Task 10: Stop here (Phase 1 boundary)

**Do not start** Phase 2 (auth/directories) or deactivate Elementor plugins until a new plan is written.

**Optional follow-up plan:** `docs/superpowers/plans/2026-11-XX-elementor-exit-phase-2.md`

---

## Self-review

- Spec Phase 1 surfaces covered: header, footer, Homepage, Activities, Competitions — yes (Tasks 3–8).
- Backup referenced — yes.
- Elementor stays installed — yes (Task 5/10).
- No TBD placeholders for shortcodes/IDs — filled from live inventory.
- Child theme path consistent: `themes/astra-child-creativewings/`.
