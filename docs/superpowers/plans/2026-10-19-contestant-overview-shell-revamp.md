# Contestant Shell + Overview Revamp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Soften the Contestant Portal shell and rebuild Overview into welcome → metrics bento → recent cards, while keeping CW brand tokens and passing unslop-ui bans (no glass, icon tiles, glow, gradient buttons, entrance GSAP).

**Architecture:** Add a contestant-only layout modifier class on the existing dashboard shell; scope shell CSS under that modifier so creator/business stay unchanged. Replace `render_overview()` markup with new BEM-ish classes (`cw-ov-*`); style them in `cw-style-contestant.css`. No JS/GSAP this pass. Same PHP data queries as today.

**Tech Stack:** WordPress + WooCommerce My Account shortcode `[cw_dashboard]`, PHP, existing CW CSS variables (Montserrat, `#125B9A`).

**Spec:** `docs/superpowers/specs/2026-10-19-contestant-overview-shell-revamp-design.md`

## Global Constraints

- Scope: **Contestant shell + Overview only** — other tab bodies, creator/business, auth pages out of scope.
- Brand: keep `--cw-primary` / accent / Montserrat — do not introduce Inter or decorative serif.
- unslop bans: no icon-in-rounded-square on metrics; no glass/`backdrop-filter`; no gradient text/buttons; at most one subtle main-area wash; no glowing shadows; no entrance animations; no meta-labels; no `border-left` + `border-radius` accent bars.
- Motion: CSS hover/focus only, 150–300ms, single transform max.
- Data unchanged: submissions count, pending review, points (+ rank/expiry), recent 5, upgrade CTA URLs.
- Bump `CW_VERSION` once at the end for asset cache bust.

## File map

| File | Responsibility |
|------|----------------|
| `includes/dashboard/class-cw-dashboard-manager.php` | Add `cw-layout--contestant` (and keep existing classes) when role is contestant |
| `assets/css/cw-style-general.css` | Contestant-scoped shell refinements under `.cw-layout--contestant` |
| `includes/dashboard/class-cw-dashboard-contestant.php` | Rewrite `render_overview()` markup only |
| `assets/css/cw-style-contestant.css` | New Overview styles (`cw-ov-*`); leave unused old overview rules or comment as legacy |
| `creativewings-core.php` | Bump `CW_VERSION` |
| `tests/test-cw-contestant-overview-markup.php` | CLI guard: overview source uses `cw-ov-*` and omits banned metric icon wrappers |
| `docs/superpowers/specs/2026-10-19-contestant-overview-shell-revamp-design.md` | Mark Status: Approved |

---

### Task 1: Spec status + contestant layout modifier

**Files:**
- Modify: `docs/superpowers/specs/2026-10-19-contestant-overview-shell-revamp-design.md` (Status line)
- Modify: `includes/dashboard/class-cw-dashboard-manager.php` (layout container class)
- Create: `tests/test-cw-contestant-overview-markup.php` (initial failing assertion on layout class in manager file)

**Interfaces:**
- Produces: root markup `div.cw-layout-container.cw-layout--contestant` when `$role === 'contestant'` (else no `--contestant` modifier)
- Consumes: existing `$role` from `CW_Roles::get_dashboard_role()`

- [ ] **Step 1: Mark spec approved**

In the spec header, set:

```markdown
**Status:** Approved — 2026-10-19
```

- [ ] **Step 2: Write failing CLI guard (layout class)**

Create `tests/test-cw-contestant-overview-markup.php`:

```php
<?php
/**
 * Guard: contestant shell + overview markup conventions.
 * Run: php -n tests/test-cw-contestant-overview-markup.php
 */
if ( php_sapi_name() !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );
$fail = 0;

function cw_assert( $cond, $msg ) {
	global $fail;
	if ( $cond ) {
		echo "OK  $msg\n";
		return;
	}
	echo "FAIL $msg\n";
	$fail++;
}

$manager = file_get_contents( $root . '/includes/dashboard/class-cw-dashboard-manager.php' );
cw_assert(
	strpos( $manager, 'cw-layout--contestant' ) !== false,
	'manager references cw-layout--contestant'
);

$contestant = file_get_contents( $root . '/includes/dashboard/class-cw-dashboard-contestant.php' );
// Overview-specific classes (will fail until Task 3).
cw_assert(
	strpos( $contestant, 'cw-ov-welcome' ) !== false,
	'overview has cw-ov-welcome'
);
cw_assert(
	strpos( $contestant, 'cw-ov-metrics' ) !== false,
	'overview has cw-ov-metrics'
);
cw_assert(
	strpos( $contestant, 'cw-ov-recent' ) !== false,
	'overview has cw-ov-recent'
);
cw_assert(
	strpos( $contestant, 'stat-icon' ) === false
		|| strpos( $contestant, 'function render_overview' ) === false,
	'overview markup must not use metric icon tiles (checked after rewrite)'
);

// Stricter: between render_overview and the next public function, no .stat-icon
if ( preg_match( '/function render_overview\(\)\s*\{([\s\S]*?)\n    public function /', $contestant, $m ) ) {
	$body = $m[1];
	cw_assert( strpos( $body, 'stat-icon' ) === false, 'render_overview body has no stat-icon' );
	cw_assert( strpos( $body, 'cw-ov-welcome' ) !== false, 'render_overview body has cw-ov-welcome' );
	cw_assert( strpos( $body, '<table' ) === false, 'render_overview body has no table' );
}

$css = file_get_contents( $root . '/assets/css/cw-style-contestant.css' );
cw_assert( strpos( $css, '.cw-ov-welcome' ) !== false, 'contestant CSS defines .cw-ov-welcome' );
cw_assert( strpos( $css, 'backdrop-filter' ) === false, 'contestant CSS has no backdrop-filter' );

$gen = file_get_contents( $root . '/assets/css/cw-style-general.css' );
cw_assert(
	strpos( $gen, '.cw-layout--contestant' ) !== false,
	'general CSS scopes contestant shell'
);

exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 3: Run guard — expect FAIL on missing pieces**

Run:

```bash
php -n tests/test-cw-contestant-overview-markup.php
```

Expected: several `FAIL` lines (layout class, overview classes, CSS). Exit code 1.

- [ ] **Step 4: Add layout modifier in manager**

In `includes/dashboard/class-cw-dashboard-manager.php`, change the layout root from:

```php
<div class="cw-layout-container">
```

to:

```php
<div class="cw-layout-container<?php echo ( 'contestant' === $role ) ? ' cw-layout--contestant' : ''; ?>">
```

Do not change menu items, tab routing, or creator/business branches.

- [ ] **Step 5: Re-run guard**

```bash
php -n tests/test-cw-contestant-overview-markup.php
```

Expected: `OK  manager references cw-layout--contestant`; other overview/CSS asserts still FAIL.

- [ ] **Step 6: Commit**

```bash
git add docs/superpowers/specs/2026-10-19-contestant-overview-shell-revamp-design.md \
  includes/dashboard/class-cw-dashboard-manager.php \
  tests/test-cw-contestant-overview-markup.php
git commit -m "$(cat <<'EOF'
feat(dashboard): mark contestant layout for shell revamp

Add cw-layout--contestant on the portal shell and a CLI markup guard so Overview styling stays scoped and unslop-safe.
EOF
)"
```

---

### Task 2: Contestant-scoped shell CSS

**Files:**
- Modify: `assets/css/cw-style-general.css` (append contestant shell block near sidebar section end, after existing `.cw-sidebar-*` rules ~line 439+)

**Interfaces:**
- Consumes: `.cw-layout--contestant` from Task 1
- Produces: warmer main wash, calmer nav active, flatter user block — **only** when modifier present

- [ ] **Step 1: Append scoped shell styles**

Add at the end of the sidebar / layout section in `assets/css/cw-style-general.css` (after existing footer/logout rules for sidebar — search for `.cw-nav-link.logout` and place a new block after that cluster):

```css
/* --------------------------------------------------------------------------
   Contestant portal shell (scoped — creator/business unchanged)
   Spec: 2026-10-19-contestant-overview-shell-revamp-design.md
   -------------------------------------------------------------------------- */
.cw-layout--contestant {
    background: #f7f8fa;
}

.cw-layout--contestant .cw-main-area {
    background:
        radial-gradient(1200px 480px at 100% -10%, rgba(18, 91, 154, 0.06), transparent 55%),
        #f7f8fa;
}

.cw-layout--contestant .cw-sidebar {
    background: #fafafa;
    border-right-color: #e8ecf1;
}

.cw-layout--contestant .cw-logo-area {
    padding: 20px 18px;
    border-bottom-color: #e8ecf1;
}

.cw-layout--contestant .cw-logo-circle {
    border-radius: 10px;
    box-shadow: none;
}

.cw-layout--contestant .cw-nav-list {
    padding: 16px 12px;
}

.cw-layout--contestant .cw-nav-link {
    padding: 11px 14px;
    margin-bottom: 4px;
    border-radius: 8px;
    transition: background 0.2s ease, color 0.2s ease;
}

.cw-layout--contestant .cw-nav-link.active {
    background: var(--cw-primary-light);
    color: var(--cw-primary);
    box-shadow: none;
}

.cw-layout--contestant .cw-sidebar-user {
    background: transparent;
    border-radius: 8px;
    margin: 0 4px 4px;
    padding: 10px 12px;
}

.cw-layout--contestant .cw-sidebar-user:hover {
    background: #eef2f7;
}

.cw-layout--contestant .cw-sidebar-avatar {
    border-width: 1px;
}

.cw-layout--contestant .cw-mobile-header {
    background: #fafafa;
    border-bottom: 1px solid #e8ecf1;
}
```

Rules:
- Do **not** add `backdrop-filter`.
- Do **not** change unscoped `.cw-sidebar` defaults (creator/business).
- One radial wash on `.cw-main-area` only (allowed single wash).

- [ ] **Step 2: Confirm general CSS mention in guard**

```bash
php -n tests/test-cw-contestant-overview-markup.php
```

Expected: `OK  general CSS scopes contestant shell`; overview asserts still FAIL.

- [ ] **Step 3: Commit**

```bash
git add assets/css/cw-style-general.css
git commit -m "$(cat <<'EOF'
style(dashboard): soften contestant portal shell

Scope nav and main-stage atmosphere under .cw-layout--contestant so other roles stay unchanged.
EOF
)"
```

---

### Task 3: Rewrite Overview markup

**Files:**
- Modify: `includes/dashboard/class-cw-dashboard-contestant.php` — replace the HTML block inside `render_overview()` (from the opening `<div class="cw-content-wrapper">` through its closing `</div>` before `<?php` / end of method). Keep all PHP data fetching above unchanged.

**Interfaces:**
- Consumes: existing locals `$u`, `$entries_count`, `$pending_review_count`, `$points_*`, `$renderable_recent`, `$activities_url`, `$explore_url`, `$upgrade_url`, `$points_url`
- Produces: markup using `cw-ov-welcome`, `cw-ov-metrics`, `cw-ov-metric`, `cw-ov-upgrade`, `cw-ov-recent`, `cw-ov-recent-item`, `cw-ov-empty`

- [ ] **Step 1: Replace overview HTML**

Keep the method’s PHP preparation (entries, points, `$renderable_recent`) exactly as-is. Replace only the output starting at `<div class="cw-content-wrapper">` with:

```php
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
                    <p class="cw-ov-upgrade-title"><?php esc_html_e( 'Looking to host?', 'creativewings-core' ); ?></p>
                    <p class="cw-ov-upgrade-copy"><?php esc_html_e( 'Upgrade to Creator to unlock full features.', 'creativewings-core' ); ?></p>
                    <a href="<?php echo esc_url( $upgrade_url ); ?>" class="cw-btn-primary cw-ov-upgrade-btn">
                        <?php esc_html_e( 'Upgrade now', 'creativewings-core' ); ?>
                    </a>
                </div>
            </div>

            <section class="cw-ov-recent" aria-labelledby="cw-ov-recent-heading">
                <h3 id="cw-ov-recent-heading" class="cw-ov-recent-heading">
                    <?php esc_html_e( 'Recent submissions', 'creativewings-core' ); ?>
                </h3>

                <?php if ( ! empty( $renderable_recent ) ) : ?>
                    <ul class="cw-ov-recent-list">
                        <?php foreach ( $renderable_recent as $row ) : ?>
                            <li class="cw-ov-recent-item">
                                <div class="cw-ov-recent-main">
                                    <span class="cw-ov-recent-name"><?php echo esc_html( $row['product']->get_name() ); ?></span>
                                    <span class="cw-ov-recent-type"><?php echo esc_html( $row['type_tag'] ); ?></span>
                                </div>
                                <div class="cw-ov-recent-meta">
                                    <time datetime="<?php echo esc_attr( get_the_date( 'c', $row['entry']->ID ) ); ?>">
                                        <?php echo esc_html( get_the_date( 'Y-m-d', $row['entry']->ID ) ); ?>
                                    </time>
                                    <span class="cw-status-badge <?php echo esc_attr( $row['status_class'] ); ?>">
                                        <?php echo esc_html( $row['status_label'] ); ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
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
```

Do **not** include `stat-icon`, `<table>`, gradient upgrade card classes (`cw-upgrade-card-host`), or emoji.

- [ ] **Step 2: Re-run guard**

```bash
php -n tests/test-cw-contestant-overview-markup.php
```

Expected: overview markup asserts OK; `.cw-ov-welcome` CSS assert still FAIL until Task 4.

- [ ] **Step 3: Commit**

```bash
git add includes/dashboard/class-cw-dashboard-contestant.php
git commit -m "$(cat <<'EOF'
feat(dashboard): rebuild contestant overview markup

Replace rigid stats table home with welcome, metric grid, and recent list cards without icon tiles.
EOF
)"
```

---

### Task 4: Overview CSS (`cw-ov-*`)

**Files:**
- Modify: `assets/css/cw-style-contestant.css` — prepend new Overview block after the file header comment (before or replacing reliance on `.cw-stats-container` for home). Keep old `.cw-stat-box-v2` rules for now (may still be used elsewhere); Overview no longer depends on them.

**Interfaces:**
- Consumes: Task 3 class names
- Produces: 2×2 dense metrics grid, welcome flex, recent list styles

- [ ] **Step 1: Add Overview styles**

Insert after the top banner comment in `assets/css/cw-style-contestant.css`:

```css
/* --------------------------------------------------------------------------
   Overview revamp (cw-ov-*) — unslop-safe, contestant home
   -------------------------------------------------------------------------- */

.cw-ov {
    padding: 0 0 48px;
    max-width: 100%;
    min-width: 0;
}

.cw-ov-welcome {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px 28px;
    margin-bottom: 28px;
    padding-bottom: 4px;
}

.cw-ov-welcome-title {
    margin: 0 0 8px;
    font-size: clamp(1.5rem, 2.2vw, 1.85rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    color: var(--cw-text);
    line-height: 1.25;
    max-width: 36rem;
}

.cw-ov-welcome-sub {
    margin: 0;
    font-size: 14px;
    line-height: 1.5;
    color: var(--cw-text-soft);
    max-width: 34rem;
}

.cw-ov-welcome-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 14px;
}

.cw-ov-btn-primary {
    text-decoration: none;
}

.cw-ov-btn-secondary {
    display: inline-flex;
    align-items: center;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    color: var(--cw-primary);
    text-decoration: none;
    border: 1.5px solid var(--cw-primary);
    border-radius: var(--cw-radius-pill, 999px);
    background: transparent;
    transition: background 0.2s ease, color 0.2s ease;
}

.cw-ov-btn-secondary:hover {
    background: var(--cw-primary-light);
    color: var(--cw-primary);
}

.cw-ov-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    grid-auto-flow: dense;
    gap: 14px;
    margin-bottom: 32px;
}

.cw-ov-metric,
.cw-ov-upgrade {
    background: var(--cw-surface, #fff);
    border: 1px solid var(--cw-border);
    border-radius: var(--cw-radius, 12px);
    padding: 20px 22px;
    box-shadow: none;
    min-width: 0;
}

.cw-ov-metric-value {
    margin: 0 0 6px;
    font-size: clamp(1.75rem, 3vw, 2rem);
    font-weight: 800;
    letter-spacing: -0.04em;
    color: var(--cw-text);
    line-height: 1.1;
}

.cw-ov-metric-link {
    color: inherit;
    text-decoration: none;
}

.cw-ov-metric-link:hover {
    color: var(--cw-primary);
}

.cw-ov-metric-label {
    margin: 0;
    font-size: 13px;
    font-weight: 500;
    color: var(--cw-text-soft);
}

.cw-ov-metric-meta {
    font-weight: 600;
    color: var(--cw-text);
}

.cw-ov-metric-hint {
    margin: 8px 0 0;
    font-size: 11px;
    color: #64748b;
    line-height: 1.4;
}

.cw-ov-upgrade-title {
    margin: 0 0 6px;
    font-size: 16px;
    font-weight: 800;
    color: var(--cw-text);
}

.cw-ov-upgrade-copy {
    margin: 0 0 14px;
    font-size: 13px;
    line-height: 1.45;
    color: var(--cw-text-soft);
}

.cw-ov-upgrade-btn {
    text-decoration: none;
}

.cw-ov-recent-heading {
    margin: 0 0 14px;
    font-size: 17px;
    font-weight: 700;
    color: var(--cw-text);
}

.cw-ov-recent-list {
    list-style: none;
    margin: 0;
    padding: 0;
    border: 1px solid var(--cw-border);
    border-radius: var(--cw-radius, 12px);
    background: var(--cw-surface, #fff);
    overflow: hidden;
}

.cw-ov-recent-item {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px 16px;
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.2s ease;
}

.cw-ov-recent-item:last-child {
    border-bottom: none;
}

.cw-ov-recent-item:hover {
    background: #f8fafc;
}

.cw-ov-recent-main {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.cw-ov-recent-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--cw-text);
}

.cw-ov-recent-type {
    font-size: 12px;
    font-weight: 500;
    color: var(--cw-text-soft);
}

.cw-ov-recent-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    color: var(--cw-text-soft);
}

.cw-ov-empty {
    padding: 36px 24px;
    text-align: center;
    border: 1px solid var(--cw-border);
    border-radius: var(--cw-radius, 12px);
    background: var(--cw-surface, #fff);
}

.cw-ov-empty-title {
    margin: 0 0 8px;
    font-size: 16px;
    font-weight: 700;
    color: var(--cw-text);
}

.cw-ov-empty-desc {
    margin: 0 0 18px;
    font-size: 14px;
    color: var(--cw-text-soft);
    line-height: 1.5;
}

@media (min-width: 900px) {
    .cw-ov-metrics {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .cw-ov-welcome-actions {
        width: 100%;
    }

    .cw-ov-btn-primary,
    .cw-ov-btn-secondary {
        width: 100%;
        justify-content: center;
        text-align: center;
    }
}
```

Banned in this block: `backdrop-filter`, `linear-gradient` on buttons, `transform: scale`, icon tile rules, glowing `box-shadow` with brand color.

- [ ] **Step 2: Run full guard — expect PASS**

```bash
php -n tests/test-cw-contestant-overview-markup.php
```

Expected: all `OK`, exit 0.

- [ ] **Step 3: Manual unslop checklist (document in commit body mentally)**

Verify by reading the new CSS/HTML:
- [ ] No icon-in-rounded-square on metrics
- [ ] No glass
- [ ] No gradient buttons
- [ ] No entrance animations
- [ ] Creator/business CSS files untouched

- [ ] **Step 4: Commit**

```bash
git add assets/css/cw-style-contestant.css tests/test-cw-contestant-overview-markup.php
git commit -m "$(cat <<'EOF'
style(dashboard): style contestant overview home

Add cw-ov welcome, metric grid, and recent list styles under unslop constraints.
EOF
)"
```

---

### Task 5: Version bump + live QA notes

**Files:**
- Modify: `creativewings-core.php` — `CW_VERSION` (e.a. `11.1.42` → `11.1.43`)

- [ ] **Step 1: Bump version**

```php
define( 'CW_VERSION', '11.1.43' );
```

(Use current local value +1 if already past 11.1.42.)

- [ ] **Step 2: Local visual QA checklist**

With a contestant session on My Account:

1. `/my-account/?tab=overview` — welcome + 4 metrics + recent/empty; numbers match pre-change counts.  
2. Switch to `?tab=activities` — shell still uses new contestant styling; tab body unchanged.  
3. Log in as creator — **no** `cw-layout--contestant`; shell looks as before.  
4. Mobile width &lt; 640 — CTAs stack; sidebar toggle works; no horizontal scroll.  
5. Squint test: not one blue goo blob; primary CTA is the Explore / Upgrade buttons.

- [ ] **Step 3: Commit**

```bash
git add creativewings-core.php
git commit -m "$(cat <<'EOF'
chore: bump CW_VERSION for contestant overview cache bust
EOF
)"
```

- [ ] **Step 4: Stop — do not deploy unless user asks**

---

## Plan self-review

| Spec requirement | Task |
|------------------|------|
| Contestant layout modifier | Task 1 |
| Shell atmosphere scoped | Task 2 |
| Welcome + CTAs | Task 3 |
| 2×2/4 metrics without icon tiles | Task 3–4 |
| Recent cards not table | Task 3–4 |
| unslop bans / no GSAP | Tasks 2–4 + guard |
| Creator/business unchanged | Task 1–2 scoping |
| CW_VERSION bump | Task 5 |
| Acceptance mobile + checklist | Task 5 |

Placeholder scan: none. Class names consistent: `cw-layout--contestant`, `cw-ov-*`.
