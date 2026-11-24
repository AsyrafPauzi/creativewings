# Discovery SEO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing Creative Wings pages match searches such as "competitions in Malaysia", "drawing competition", "art competition", and "design competition", with the brand name at the end of the Google title.

**Architecture:** Visible headings and intros stay in the child theme templates. Three category archives get one sentence from a small include. Google titles, descriptions, and focus keywords are Rank Math meta on the live site, and the same strings are stored in the bulk SEO script so a later bulk run cannot put the old brand-first titles back. Closed campaigns keep their titles and gain "Entries are closed."

**Tech Stack:** WordPress child theme PHP, WooCommerce category archives, Rank Math post and term meta, plain `php tests/*.php` source tests.

## Global Constraints

- English only.
- Pages speak to parents, students, and adults. One Smile, One World is a children's campaign, so that page alone may say children.
- A title is 60 characters or fewer. A description is 160 characters or fewer.
- A campaign whose deadline has passed says "Entries are closed." It never says it is open.
- About, login, checkout, cart, account, registration, legal pages, and empty categories stay as they are.
- No new pages, no Malay pages, no extra topic guides.
- Do not call `cw_bulk_seo_run()` on the live site. It rewrites every page and term.
- Do not change the product skip in `cw_bulk_seo_run()` that leaves a product alone when `rank_math_title` is already set.
- The homepage tagline "Together, We Help Young Talents Soar" stays.
- The homepage and the competitions page share the Google title `Competitions in Malaysia | Creative Wings` on purpose. Their descriptions differ.

---

### Task 1: Visible copy on the homepage, competitions, and activities

**Files:**
- Modify: `themes/astra-child-creativewings/page-homepage.php` (H1 around line 51)
- Modify: `themes/astra-child-creativewings/page-competitions.php` (H1 and intro around lines 28–29)
- Modify: `themes/astra-child-creativewings/page-activities.php` (H1 and intro around lines 28–29)
- Modify: `themes/astra-child-creativewings/assets/css/pages.css` (`.cw-home__seo-title` around line 242)
- Modify: `themes/astra-child-creativewings/functions.php` (`CW_CHILD_VERSION` line 10)
- Test: `tests/test-cw-discovery-seo-copy.php`

**Interfaces:**
- Consumes: nothing
- Produces: the three visible strings later live checks look for. Homepage intro class is `cw-home__intro`.

- [ ] **Step 1: Write the failing test**

Create `tests/test-cw-discovery-seo-copy.php`:

```php
<?php
/**
 * Discovery headings and intros on the three browse templates.
 * Run: php tests/test-cw-discovery-seo-copy.php
 */

$root = dirname( __DIR__ );

$expect = [
    'themes/astra-child-creativewings/page-homepage.php' => [
        'Together, We Help Young Talents Soar',
        'Competitions and Activities in Malaysia',
        'Open to students and adults. Join a competition or an activity and register online.',
    ],
    'themes/astra-child-creativewings/page-competitions.php' => [
        'Competitions in Malaysia',
        'Art, drawing, and design competitions. Open to students and adults. Pick one and register online.',
    ],
    'themes/astra-child-creativewings/page-activities.php' => [
        'Activities and Events in Malaysia',
        'Runs, art sessions, and talks. Open to families and adults. Pick one and register online.',
    ],
];

$banned = [
    'themes/astra-child-creativewings/page-homepage.php' => [
        'Creative Competitions & Activities for Students in Malaysia',
    ],
    'themes/astra-child-creativewings/page-competitions.php' => [
        'Creative Competitions in Malaysia',
        'young artists',
    ],
    'themes/astra-child-creativewings/page-activities.php' => [
        'Community Activities & Events in Malaysia',
        'for children and families',
    ],
];

$failed = false;
foreach ( $expect as $rel => $needles ) {
    $src = file_get_contents( $root . '/' . $rel );
    foreach ( $needles as $needle ) {
        if ( false === strpos( $src, $needle ) ) {
            fwrite( STDERR, "FAIL: {$rel} missing {$needle}\n" );
            $failed = true;
        }
    }
}
foreach ( $banned as $rel => $needles ) {
    $src = file_get_contents( $root . '/' . $rel );
    foreach ( $needles as $needle ) {
        if ( false !== strpos( $src, $needle ) ) {
            fwrite( STDERR, "FAIL: {$rel} still contains {$needle}\n" );
            $failed = true;
        }
    }
}

if ( $failed ) {
    exit( 1 );
}
echo "PASS\n";
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/test-cw-discovery-seo-copy.php`

Expected: FAIL because the homepage still says `Creative Competitions & Activities for Students in Malaysia`.

- [ ] **Step 3: Replace the visible copy**

In `page-homepage.php`, replace the H1 and add the intro between the H1 and the search form:

```php
			<h1 class="cw-home__seo-title" data-cw-hero-line><?php esc_html_e( 'Competitions and Activities in Malaysia', 'cw-astra-child' ); ?></h1>
			<p class="cw-home__intro" data-cw-hero-line><?php esc_html_e( 'Open to students and adults. Join a competition or an activity and register online.', 'cw-astra-child' ); ?></p>
```

Leave the tagline line above the H1 unchanged.

In `page-competitions.php`, replace the H1 and intro:

```php
			<h1 class="cw-browse__title" data-cw-browse-line><?php esc_html_e( 'Competitions in Malaysia', 'cw-astra-child' ); ?></h1>
			<p class="cw-browse__intro" data-cw-browse-line><?php esc_html_e( 'Art, drawing, and design competitions. Open to students and adults. Pick one and register online.', 'cw-astra-child' ); ?></p>
```

In `page-activities.php`, replace the H1 and intro:

```php
			<h1 class="cw-browse__title" data-cw-browse-line><?php esc_html_e( 'Activities and Events in Malaysia', 'cw-astra-child' ); ?></h1>
			<p class="cw-browse__intro" data-cw-browse-line><?php esc_html_e( 'Runs, art sessions, and talks. Open to families and adults. Pick one and register online.', 'cw-astra-child' ); ?></p>
```

In `assets/css/pages.css`, change `.cw-home__seo-title` margin from `0 auto 28px` to `0 auto 10px`, and add this rule immediately after that block:

```css
.cw-home__intro {
	font-family: var(--cw-font);
	font-size: clamp(0.95rem, 1.35vw, 1.125rem);
	font-weight: 500;
	letter-spacing: 0.01em;
	line-height: 1.4;
	margin: 0 auto 28px;
	color: rgba(255, 255, 255, 0.9);
	max-width: 34em;
	width: 100%;
	text-wrap: balance;
}
```

In `functions.php`, set `CW_CHILD_VERSION` to `0.12.2` so the homepage CSS is not served from the old cache.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/test-cw-discovery-seo-copy.php`

Expected: `PASS`

- [ ] **Step 5: Commit**

```bash
git add tests/test-cw-discovery-seo-copy.php themes/astra-child-creativewings/page-homepage.php themes/astra-child-creativewings/page-competitions.php themes/astra-child-creativewings/page-activities.php themes/astra-child-creativewings/assets/css/pages.css themes/astra-child-creativewings/functions.php
git commit -m "$(cat <<'EOF'
feat: lead browse pages with competition searches

Parents and students should see competitions in Malaysia before the brand name.
EOF
)"
```

---

### Task 2: One sentence on the drawing, design, and art categories

**Files:**
- Create: `themes/astra-child-creativewings/inc/discovery-category-intro.php`
- Modify: `themes/astra-child-creativewings/functions.php` (require the include after the constants)
- Modify: `themes/astra-child-creativewings/style.css` (append the intro rule)
- Test: `tests/test-cw-discovery-category-intro.php`

**Interfaces:**
- Consumes: nothing from Task 1
- Produces: `cw_child_discovery_category_intro_html( string $slug, string $competitions_url, string $osow_url ): string`
  - Returns a `<p class="cw-discovery-intro">` for `drawing`, `competitions-design`, and `art-activities`
  - Returns `''` for every other slug
  - Drawing and design link the words `See what is open.` to `$competitions_url`
  - Art links the words `One Smile, One World` to `$osow_url`

- [ ] **Step 1: Write the failing test**

Create `tests/test-cw-discovery-category-intro.php`:

```php
<?php
/**
 * Category intro HTML for the three discovery terms.
 * Run: php tests/test-cw-discovery-category-intro.php
 */

$root = dirname( __DIR__ );
require $root . '/themes/astra-child-creativewings/inc/discovery-category-intro.php';

function assert_same( $expected, $actual, $label ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$label}\n expected: {$expected}\n actual:   {$actual}\n" );
        exit( 1 );
    }
}

$competitions = 'https://creativewings.asia/competitions/';
$osow         = 'https://creativewings.asia/osow/';

assert_same(
    '<p class="cw-discovery-intro">The Merdeka Month drawing competition has closed. <a href="https://creativewings.asia/competitions/">See what is open.</a></p>',
    cw_child_discovery_category_intro_html( 'drawing', $competitions, $osow ),
    'drawing'
);
assert_same(
    '<p class="cw-discovery-intro">The ORBI design competition has closed. <a href="https://creativewings.asia/competitions/">See what is open.</a></p>',
    cw_child_discovery_category_intro_html( 'competitions-design', $competitions, $osow ),
    'design'
);
assert_same(
    '<p class="cw-discovery-intro"><a href="https://creativewings.asia/osow/">One Smile, One World</a> is open for children until 31 December 2026.</p>',
    cw_child_discovery_category_intro_html( 'art-activities', $competitions, $osow ),
    'art'
);
assert_same( '', cw_child_discovery_category_intro_html( 'esports', $competitions, $osow ), 'empty category' );
assert_same( '', cw_child_discovery_category_intro_html( 'poetry', $competitions, $osow ), 'poetry' );

$evil = cw_child_discovery_category_intro_html( 'drawing', 'https://creativewings.asia/competitions/?q="><script>', $osow );
if ( false !== strpos( $evil, '<script>' ) ) {
    fwrite( STDERR, "FAIL: competitions URL was not escaped\n" );
    exit( 1 );
}

echo "PASS\n";
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/test-cw-discovery-category-intro.php`

Expected: FAIL with a missing-file warning, because the include does not exist yet.

- [ ] **Step 3: Add the include, load it, and style the sentence**

Create `themes/astra-child-creativewings/inc/discovery-category-intro.php`:

```php
<?php
/**
 * One discovery sentence on the drawing, design, and art category archives.
 *
 * @package CW_Astra_Child
 */

if ( ! function_exists( 'cw_child_discovery_category_intro_html' ) ) {
	/**
	 * @param string $slug              Product category slug.
	 * @param string $competitions_url  Competitions page URL.
	 * @param string $osow_url          One Smile, One World product URL.
	 */
	function cw_child_discovery_category_intro_html( $slug, $competitions_url, $osow_url ) {
		$competitions_url = esc_url( $competitions_url );
		$osow_url         = esc_url( $osow_url );
		if ( 'drawing' === $slug ) {
			return '<p class="cw-discovery-intro">The Merdeka Month drawing competition has closed. <a href="' . $competitions_url . '">See what is open.</a></p>';
		}
		if ( 'competitions-design' === $slug ) {
			return '<p class="cw-discovery-intro">The ORBI design competition has closed. <a href="' . $competitions_url . '">See what is open.</a></p>';
		}
		if ( 'art-activities' === $slug ) {
			return '<p class="cw-discovery-intro"><a href="' . $osow_url . '">One Smile, One World</a> is open for children until 31 December 2026.</p>';
		}
		return '';
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action(
		'woocommerce_archive_description',
		static function () {
			if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
				return;
			}
			$term = get_queried_object();
			if ( ! $term || empty( $term->slug ) ) {
				return;
			}
			$html = cw_child_discovery_category_intro_html(
				(string) $term->slug,
				home_url( '/competitions/' ),
				get_permalink( 3361 )
			);
			if ( '' !== $html ) {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the builder.
			}
		}
	);
}
```

The test must not define `esc_url`. The include's fallback runs under `php tests/`, and WordPress keeps its own `esc_url` because that function already exists there.

In `functions.php`, after the `CW_CHILD_HOME_HERO_ID` constant, add:

```php
require_once CW_CHILD_DIR . '/inc/discovery-category-intro.php';
```

Set `CW_CHILD_VERSION` to `0.12.3`.

Append to `themes/astra-child-creativewings/style.css`:

```css
.cw-discovery-intro {
	margin: 0 0 1.25rem;
	max-width: 42rem;
	font-size: 1rem;
	line-height: 1.5;
}

.cw-discovery-intro a {
	text-decoration: underline;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/test-cw-discovery-category-intro.php`

Expected: `PASS`

- [ ] **Step 5: Commit**

```bash
git add tests/test-cw-discovery-category-intro.php themes/astra-child-creativewings/inc/discovery-category-intro.php themes/astra-child-creativewings/functions.php themes/astra-child-creativewings/style.css
git commit -m "$(cat <<'EOF'
feat: tell category visitors which competition is open

Drawing and design say the last campaign closed. Art points at One Smile, One World.
EOF
)"
```

---

### Task 3: Keep the bulk SEO script from restoring the old titles

**Files:**
- Modify: `scripts/bulk-rank-math-seo.php` (`cw_bulk_seo_page_map` around line 146, `cw_bulk_seo_term_payload` around line 250)
- Test: `tests/test-cw-discovery-seo-bulk.php`

**Interfaces:**
- Consumes: the Google titles, descriptions, and focus keywords from the spec
- Produces: `cw_bulk_seo_page_map()` entries for `homepage`, `competitions`, and `activities`; `cw_bulk_seo_term_payload()` returns the discovery payload for slugs `drawing`, `competitions-design`, and `art-activities` before the generic `"$name | Creative Wings"` payload. The product skip inside `cw_bulk_seo_run()` stays.

- [ ] **Step 1: Write the failing test**

Create `tests/test-cw-discovery-seo-bulk.php`:

```php
<?php
/**
 * Bulk SEO map keeps discovery titles and the product skip.
 * Run: php tests/test-cw-discovery-seo-bulk.php
 */

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) {
        return $text;
    }
}

if ( ! class_exists( 'WP_Term' ) ) {
    class WP_Term {}
}

$root = dirname( __DIR__ );
require $root . '/scripts/bulk-rank-math-seo.php';

function assert_same( $expected, $actual, $label ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$label}\n expected: {$expected}\n actual:   {$actual}\n" );
        exit( 1 );
    }
}

function assert_len( $value, $max, $label ) {
    $len = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
    if ( $len > $max ) {
        fwrite( STDERR, "FAIL: {$label} is {$len} characters\n" );
        exit( 1 );
    }
}

$pages = cw_bulk_seo_page_map();
assert_same( 'Competitions in Malaysia | Creative Wings', $pages['homepage']['seo_title'], 'home title' );
assert_same( 'Join art, drawing, and design competitions in Malaysia. Open to students and adults. Browse what is open and register online.', $pages['homepage']['meta_description'], 'home description' );
assert_same( [ 'competitions in Malaysia' ], $pages['homepage']['focus_keywords'], 'home focus' );
assert_same( 'Competitions in Malaysia | Creative Wings', $pages['competitions']['seo_title'], 'competitions title' );
assert_same( 'Art, drawing, and design competitions in Malaysia. Open to students and adults. Pick one and register online.', $pages['competitions']['meta_description'], 'competitions description' );
assert_same( [ 'art drawing design competitions' ], $pages['competitions']['focus_keywords'], 'competitions focus' );
assert_same( 'Activities and Events in Malaysia | Creative Wings', $pages['activities']['seo_title'], 'activities title' );
assert_same( 'Runs, art sessions, and talks in Malaysia. Open to families and adults. Pick one and register online.', $pages['activities']['meta_description'], 'activities description' );
assert_same( [ 'activities and events in Malaysia' ], $pages['activities']['focus_keywords'], 'activities focus' );

$terms = [
    'drawing' => [
        'title' => 'Drawing Competitions in Malaysia | Creative Wings',
        'desc'  => 'Drawing competitions in Malaysia for students and adults. The Merdeka Month drawing competition has closed. See what is open.',
        'focus' => [ 'drawing competitions in Malaysia' ],
    ],
    'competitions-design' => [
        'title' => 'Design Competitions in Malaysia | Creative Wings',
        'desc'  => 'Design competitions in Malaysia for students and adults. The ORBI design competition has closed. See what is open.',
        'focus' => [ 'design competitions in Malaysia' ],
    ],
    'art-activities' => [
        'title' => 'Art Competitions in Malaysia | Creative Wings',
        'desc'  => 'Art competitions in Malaysia. One Smile, One World is open for children until 31 December 2026. Register online.',
        'focus' => [ 'art competitions in Malaysia' ],
    ],
];

foreach ( $terms as $slug => $row ) {
    $term       = new WP_Term();
    $term->slug = $slug;
    $term->name = 'Should Not Appear';
    $payload    = cw_bulk_seo_term_payload( $term, null );
    assert_same( $row['title'], $payload['seo_title'], $slug . ' title' );
    assert_same( $row['desc'], $payload['meta_description'], $slug . ' description' );
    assert_same( $row['focus'], $payload['focus_keywords'], $slug . ' focus' );
}

$other       = new WP_Term();
$other->slug = 'esports';
$other->name = 'Esports';
$generic     = cw_bulk_seo_term_payload( $other, null );
assert_same( 'Esports | Creative Wings', $generic['seo_title'], 'empty category stays generic' );

foreach ( [ 'homepage', 'competitions', 'activities' ] as $slug ) {
    assert_len( $pages[ $slug ]['seo_title'], 60, $slug . ' title' );
    assert_len( $pages[ $slug ]['meta_description'], 160, $slug . ' description' );
}
foreach ( $terms as $slug => $row ) {
    assert_len( $row['title'], 60, $slug . ' title length' );
    assert_len( $row['desc'], 160, $slug . ' description length' );
}

$src = file_get_contents( $root . '/scripts/bulk-rank-math-seo.php' );
if ( false === strpos( $src, "get_post_meta( \$post->ID, 'rank_math_title', true )" ) ) {
    fwrite( STDERR, "FAIL: product title skip was removed\n" );
    exit( 1 );
}

echo "PASS\n";
```

About stays out of this test. Task 4 checks the live about title.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/test-cw-discovery-seo-bulk.php`

Expected: FAIL on the homepage title, which is still `Creative Wings | Competitions, Activities & Campaigns in Malaysia`.

- [ ] **Step 3: Update the page map and the term payload**

Replace the `homepage`, `competitions`, and `activities` entries inside `cw_bulk_seo_page_map()`:

```php
        'homepage'                  => [
            'seo_title'        => 'Competitions in Malaysia | Creative Wings',
            'meta_description' => 'Join art, drawing, and design competitions in Malaysia. Open to students and adults. Browse what is open and register online.',
            'focus_keywords'   => [ 'competitions in Malaysia' ],
        ],
        'competitions'              => [
            'seo_title'        => 'Competitions in Malaysia | Creative Wings',
            'meta_description' => 'Art, drawing, and design competitions in Malaysia. Open to students and adults. Pick one and register online.',
            'focus_keywords'   => [ 'art drawing design competitions' ],
        ],
        'activities'                => [
            'seo_title'        => 'Activities and Events in Malaysia | Creative Wings',
            'meta_description' => 'Runs, art sessions, and talks in Malaysia. Open to families and adults. Pick one and register online.',
            'focus_keywords'   => [ 'activities and events in Malaysia' ],
        ],
```

Leave every other page-map entry as it is.

Add this function above `cw_bulk_seo_term_payload()`:

```php
function cw_bulk_seo_discovery_term_payload( $slug ) {
    $map = [
        'drawing' => [
            'seo_title'        => 'Drawing Competitions in Malaysia | Creative Wings',
            'meta_description' => 'Drawing competitions in Malaysia for students and adults. The Merdeka Month drawing competition has closed. See what is open.',
            'focus_keywords'   => [ 'drawing competitions in Malaysia' ],
            'robots'           => [ 'index' => 'index', 'follow' => 'follow' ],
        ],
        'competitions-design' => [
            'seo_title'        => 'Design Competitions in Malaysia | Creative Wings',
            'meta_description' => 'Design competitions in Malaysia for students and adults. The ORBI design competition has closed. See what is open.',
            'focus_keywords'   => [ 'design competitions in Malaysia' ],
            'robots'           => [ 'index' => 'index', 'follow' => 'follow' ],
        ],
        'art-activities' => [
            'seo_title'        => 'Art Competitions in Malaysia | Creative Wings',
            'meta_description' => 'Art competitions in Malaysia. One Smile, One World is open for children until 31 December 2026. Register online.',
            'focus_keywords'   => [ 'art competitions in Malaysia' ],
            'robots'           => [ 'index' => 'index', 'follow' => 'follow' ],
        ],
    ];
    return $map[ $slug ] ?? null;
}
```

At the start of `cw_bulk_seo_term_payload()`, before building `$ctx`, return the override:

```php
    $override = cw_bulk_seo_discovery_term_payload( $term->slug );
    if ( null !== $override ) {
        return $override;
    }
```

Do not change the `rank_math_title` skip inside the product loop of `cw_bulk_seo_run()`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/test-cw-discovery-seo-bulk.php`

Expected: `PASS`

Also re-run:

Run: `php tests/test-cw-discovery-seo-copy.php`

Expected: `PASS`

Run: `php tests/test-cw-discovery-category-intro.php`

Expected: `PASS`

- [ ] **Step 5: Commit**

```bash
git add scripts/bulk-rank-math-seo.php tests/test-cw-discovery-seo-bulk.php
git commit -m "$(cat <<'EOF'
feat: store discovery titles in the bulk SEO map

A later bulk run keeps competition searches in front of the brand name.
EOF
)"
```

---

### Task 4: Save Rank Math on the live site and check the pages

**Files:**
- Live only. No further repo edits unless a check fails and a string from Tasks 1–3 is wrong.
- Deploy these paths to `wp-content/themes/astra-child-creativewings/` on creativewings.asia:
  - `page-homepage.php`
  - `page-competitions.php`
  - `page-activities.php`
  - `functions.php`
  - `style.css`
  - `assets/css/pages.css`
  - `inc/discovery-category-intro.php`

**Interfaces:**
- Consumes: the copy locked in Tasks 1–3
- Produces: Rank Math meta on page 159, the `competitions` and `activities` pages, terms 75, 74, and 73, and products 3944, 3557, 2114, and 3361

- [ ] **Step 1: Deploy the theme files**

Copy the seven paths onto the live child theme, keeping the same relative paths. Then bust the HTML cache. On the live site, run:

```php
if ( class_exists( 'CW_Page_Cache' ) ) {
    CW_Page_Cache::bust_all();
}
return 'cache busted';
```

- [ ] **Step 2: Write the live meta update**

Run this on creativewings.asia. It updates only the rows in the spec. It does not call `cw_bulk_seo_run()`. It refuses a title over 60 characters or a description over 160.

```php
function cw_discovery_seo_put( $kind, $id, $title, $description, $focus ) {
    $title_len = mb_strlen( $title );
    $desc_len  = mb_strlen( $description );
    if ( $title_len > 60 || $desc_len > 160 ) {
        return new WP_Error( 'cw_seo_length', $kind . ' ' . $id . ' title ' . $title_len . ' desc ' . $desc_len );
    }
    if ( 'term' === $kind ) {
        update_term_meta( $id, 'rank_math_title', $title );
        update_term_meta( $id, 'rank_math_description', $description );
        update_term_meta( $id, 'rank_math_focus_keyword', $focus );
        return [
            'title' => get_term_meta( $id, 'rank_math_title', true ),
            'desc'  => get_term_meta( $id, 'rank_math_description', true ),
            'focus' => get_term_meta( $id, 'rank_math_focus_keyword', true ),
        ];
    }
    update_post_meta( $id, 'rank_math_title', $title );
    update_post_meta( $id, 'rank_math_description', $description );
    update_post_meta( $id, 'rank_math_focus_keyword', $focus );
    return [
        'title' => get_post_meta( $id, 'rank_math_title', true ),
        'desc'  => get_post_meta( $id, 'rank_math_description', true ),
        'focus' => get_post_meta( $id, 'rank_math_focus_keyword', true ),
    ];
}

$pages = [
    'homepage' => [
        'title' => 'Competitions in Malaysia | Creative Wings',
        'desc'  => 'Join art, drawing, and design competitions in Malaysia. Open to students and adults. Browse what is open and register online.',
        'focus' => 'competitions in Malaysia',
    ],
    'competitions' => [
        'title' => 'Competitions in Malaysia | Creative Wings',
        'desc'  => 'Art, drawing, and design competitions in Malaysia. Open to students and adults. Pick one and register online.',
        'focus' => 'art drawing design competitions',
    ],
    'activities' => [
        'title' => 'Activities and Events in Malaysia | Creative Wings',
        'desc'  => 'Runs, art sessions, and talks in Malaysia. Open to families and adults. Pick one and register online.',
        'focus' => 'activities and events in Malaysia',
    ],
];

$report = [ 'pages' => [], 'terms' => [], 'products' => [] ];
foreach ( $pages as $slug => $row ) {
    $page = get_page_by_path( $slug );
    if ( ! $page ) {
        return new WP_Error( 'cw_seo_missing_page', $slug );
    }
    $saved = cw_discovery_seo_put( 'post', (int) $page->ID, $row['title'], $row['desc'], $row['focus'] );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }
    $report['pages'][ $slug ] = $saved;
}

$terms = [
    75 => [
        'title' => 'Drawing Competitions in Malaysia | Creative Wings',
        'desc'  => 'Drawing competitions in Malaysia for students and adults. The Merdeka Month drawing competition has closed. See what is open.',
        'focus' => 'drawing competitions in Malaysia',
    ],
    74 => [
        'title' => 'Design Competitions in Malaysia | Creative Wings',
        'desc'  => 'Design competitions in Malaysia for students and adults. The ORBI design competition has closed. See what is open.',
        'focus' => 'design competitions in Malaysia',
    ],
    73 => [
        'title' => 'Art Competitions in Malaysia | Creative Wings',
        'desc'  => 'Art competitions in Malaysia. One Smile, One World is open for children until 31 December 2026. Register online.',
        'focus' => 'art competitions in Malaysia',
    ],
];
foreach ( $terms as $term_id => $row ) {
    $saved = cw_discovery_seo_put( 'term', (int) $term_id, $row['title'], $row['desc'], $row['focus'] );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }
    $report['terms'][ $term_id ] = $saved;
}

$products = [
    3944 => [
        'title' => 'Merdeka Month Drawing Competition 2026 | Creative Wings',
        'desc'  => 'Drawing competition for Malaysian primary and secondary students. Draw on B4 paper and submit online. RM15 entry; U13 residents join free. Entries are closed.',
        'focus' => 'Merdeka drawing competition, Visit Malaysia 2026, drawing competition Malaysia',
    ],
    3557 => [
        'title' => 'Design Your Own ORBI Glide Case 2026 | Creative Wings',
        'desc'  => 'Nationwide design campaign by ORBI: create artwork for an eco-friendly toothbrush case and see it printed as a real product. RM15 entry. Entries are closed.',
        'focus' => 'ORBI design competition, design competition Malaysia, toothbrush case design, Creative Wings',
    ],
    2114 => [
        'title' => 'Happy Feet, Tapir Beat! 4KM Family Run | Creative Wings',
        'desc'  => 'A 4 km community team run in Shah Alam for the Malayan Tapir, with the Malaysian Nature Society. Entries are closed.',
        'focus' => 'Happy Feet Tapir Beat, community run Shah Alam, Malayan Tapir, Creative Wings',
    ],
    3361 => [
        'title' => 'One Smile, One World 2026 Art Competition | Creative Wings',
        'desc'  => "Malaysia's national parent appreciation movement: children draw the smile of a parent, teacher or hero who inspires them. RM15 entry.",
        'focus' => 'One Smile One World, children drawing competition Malaysia, 笑一笑世界更美妙, Creative Wings',
    ],
];
foreach ( $products as $product_id => $row ) {
    $saved = cw_discovery_seo_put( 'post', (int) $product_id, $row['title'], $row['desc'], $row['focus'] );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }
    $report['products'][ $product_id ] = $saved;
}

$about = get_page_by_path( 'about' );
$report['about_title'] = $about ? get_post_meta( $about->ID, 'rank_math_title', true ) : 'missing';
$report['talk_4175_title'] = get_post_meta( 4175, 'rank_math_title', true );

if ( class_exists( 'CW_Page_Cache' ) ) {
    CW_Page_Cache::bust_all();
}

return $report;
```

Expected: each saved `title` and `desc` equals the input. `about_title` is `About Creative Wings | Student Competitions & Campaigns in Malaysia`. `talk_4175_title` is still empty.

Product focus keywords stay the ones already stored, listed above, so this task does not invent new campaign keywords. The spec locks titles and descriptions for campaigns, not a new focus list.

- [ ] **Step 3: Check the public pages**

Fetch these URLs after the cache bust. Confirm the title tag and meta description match Task 4 Step 2, and the visible sentence is present.

- `https://creativewings.asia/` shows H1 `Competitions and Activities in Malaysia` and the students-and-adults intro. Title tag is `Competitions in Malaysia | Creative Wings`.
- `https://creativewings.asia/competitions/` shows H1 `Competitions in Malaysia` and the art, drawing, and design intro.
- `https://creativewings.asia/activities/` shows H1 `Activities and Events in Malaysia` and the families-and-adults intro.
- The drawing category (term 75, from `get_term_link`) shows `The Merdeka Month drawing competition has closed.` and a link whose href is the competitions page.
- The design category (term 74) shows `The ORBI design competition has closed.` and the same competitions link.
- The art category (term 73) shows a link named `One Smile, One World` pointing at product 3361, followed by `is open for children until 31 December 2026.`
- An empty category such as esports does not contain `cw-discovery-intro`.
- `https://creativewings.asia/about/` title tag is still `About Creative Wings | Student Competitions & Campaigns in Malaysia`.

- [ ] **Step 4: Commit**

No repo commit when the live meta matches and no file changed. If a check forced a copy fix, commit that fix with:

```bash
git commit -m "$(cat <<'EOF'
fix: match live discovery copy to the public pages

EOF
)"
```
