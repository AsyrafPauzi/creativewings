# Discovery SEO Design

**Date:** 2026-11-24
**Status:** Approved — wording and application locked
**Site:** https://creativewings.asia
**Audience:** Parents and students who want to join, and adults. English only.
**Approach:** Rewrite existing pages. No new guide pages.

---

## 1. Goal

A person who has never heard of Creative Wings should find the platform by searching for a competition in Malaysia. The search phrase leads the Google title. "Creative Wings" stays at the end so people who already know the name still recognize the result.

This change makes the result match the search. It does not, by itself, create visits. School groups, campaign posts, and a verified Google Search Console property are how those titles turn into traffic. Search Console verification stays a manual step in the Google account. It is outside this spec.

## 2. Locked rules

- English only.
- Pages speak to parents, students, and adults. One Smile, One World is a children's campaign, so that page alone may say children.
- A title is 60 characters or fewer. A description is 160 characters or fewer.
- A campaign whose deadline has passed says "Entries are closed." It never says it is open.
- About, login, checkout, cart, account, registration, legal pages, and empty categories stay as they are.
- No new pages, no Malay pages, no extra topic guides.

## 3. Pages

### 3.1 Homepage

Template: `themes/astra-child-creativewings/page-homepage.php`
Live page slug: `homepage` (ID 159). Rank Math meta is updated on the live page and in `scripts/bulk-rank-math-seo.php` so a later bulk run does not restore the old title.

The tagline "Together, We Help Young Talents Soar" stays.

| Field | Copy |
| --- | --- |
| Visible H1 | Competitions and Activities in Malaysia |
| Visible intro, new line under the H1 | Open to students and adults. Join a competition or an activity and register online. |
| Google title | Competitions in Malaysia \| Creative Wings |
| Meta description | Join art, drawing, and design competitions in Malaysia. Open to students and adults. Browse what is open and register online. |
| Focus keyword | competitions in Malaysia |

### 3.2 Competitions

Template: `themes/astra-child-creativewings/page-competitions.php`
Live page slug: `competitions`.

| Field | Copy |
| --- | --- |
| Visible H1 | Competitions in Malaysia |
| Visible intro | Art, drawing, and design competitions. Open to students and adults. Pick one and register online. |
| Google title | Competitions in Malaysia \| Creative Wings |
| Meta description | Art, drawing, and design competitions in Malaysia. Open to students and adults. Pick one and register online. |
| Focus keyword | art drawing design competitions |

The homepage and this page share a Google title on purpose. The descriptions differ: the homepage covers competitions and activities, and this page covers the competition list.

### 3.3 Activities

Template: `themes/astra-child-creativewings/page-activities.php`
Live page slug: `activities`.

| Field | Copy |
| --- | --- |
| Visible H1 | Activities and Events in Malaysia |
| Visible intro | Runs, art sessions, and talks. Open to families and adults. Pick one and register online. |
| Google title | Activities and Events in Malaysia \| Creative Wings |
| Meta description | Runs, art sessions, and talks in Malaysia. Open to families and adults. Pick one and register online. |
| Focus keyword | activities and events in Malaysia |

### 3.4 Category archives

Drawing, design, and art have no page template. The visible heading stays the category name. Rank Math supplies the Google title and description. A new include prints one sentence above the campaign list.

Create `themes/astra-child-creativewings/inc/discovery-category-intro.php` and load it from `themes/astra-child-creativewings/functions.php`. It hooks `woocommerce_archive_description` and runs only for these terms:

| Term | ID | Slug | Google title | Meta description | Visible sentence |
| --- | --- | --- | --- | --- | --- |
| Drawing | 75 | `drawing` | Drawing Competitions in Malaysia \| Creative Wings | Drawing competitions in Malaysia for students and adults. The Merdeka Month drawing competition has closed. See what is open. | The Merdeka Month drawing competition has closed. See what is open. |
| Design | 74 | `competitions-design` | Design Competitions in Malaysia \| Creative Wings | Design competitions in Malaysia for students and adults. The ORBI design competition has closed. See what is open. | The ORBI design competition has closed. See what is open. |
| Art | 73 | `art-activities` | Art Competitions in Malaysia \| Creative Wings | Art competitions in Malaysia. One Smile, One World is open for children until 31 December 2026. Register online. | One Smile, One World is open for children until 31 December 2026. |

Focus keywords: `drawing competitions in Malaysia`, `design competitions in Malaysia`, `art competitions in Malaysia`.

"See what is open." links to `/competitions/`. "One Smile, One World" links to product 3361.

If the queried term is any other category, the include prints nothing.

`scripts/bulk-rank-math-seo.php` gets a slug override for these three terms. The generic `"$name | Creative Wings"` payload must not overwrite them.

### 3.5 Campaigns

Titles on closed campaigns stay. Stored descriptions end with "Entries are closed."

| Product | ID | Google title | Meta description |
| --- | --- | --- | --- |
| Merdeka Month drawing | 3944 | Merdeka Month Drawing Competition 2026 \| Creative Wings | Drawing competition for Malaysian primary and secondary students. Draw on B4 paper and submit online. RM15 entry; U13 residents join free. Entries are closed. |
| ORBI design | 3557 | Design Your Own ORBI Glide Case 2026 \| Creative Wings | Nationwide design campaign by ORBI: create artwork for an eco-friendly toothbrush case and see it printed as a real product. RM15 entry. Entries are closed. |
| Happy Feet run | 2114 | Happy Feet, Tapir Beat! 4KM Family Run \| Creative Wings | A 4 km community team run in Shah Alam for the Malayan Tapir, with the Malaysian Nature Society. Entries are closed. |
| One Smile, One World | 3361 | One Smile, One World 2026 Art Competition \| Creative Wings | Malaysia's national parent appreciation movement: children draw the smile of a parent, teacher or hero who inspires them. RM15 entry. |

Product 4175, the closed parent talk, is out of scope.

The bulk product writer skips a product that already has a Rank Math title, so these description edits survive a later bulk run. Do not change that skip.

## 4. Where the words live

Visible headings and intros for the homepage, competitions, and activities live in the child theme templates. The category sentence lives in the new include. Google titles, descriptions, and focus keywords live in Rank Math post meta and term meta on creativewings.asia, and the same strings live in `scripts/bulk-rank-math-seo.php` for the three pages and three terms.

No new database tables. No new post types.

## 5. Checks

After the theme is on the live site and the Rank Math fields are saved:

- Homepage, `/competitions/`, and `/activities/` show the H1 and intro in section 3, and the title tag and meta description match the table.
- Drawing, design, and art category pages show the one sentence, the correct link, and the Rank Math title and description.
- Any other category page has no new sentence.
- Merdeka, ORBI, and Happy Feet descriptions contain "Entries are closed." One Smile, One World uses the new title and still says children.
- The about page title is still "About Creative Wings | Student Competitions & Campaigns in Malaysia".
- Every title in this spec is 60 characters or fewer. Every description is 160 characters or fewer.

## 6. Out of scope

- Google Search Console verification and query reports.
- New landing pages.
- Malay copy.
- Empty categories: esports, poetry, photography, marathon, workshop, volunteer, seminar.
- About, brand story, directories, SDGs, sponsors, rewards, login, checkout, and legal pages.
- Product 4175.
