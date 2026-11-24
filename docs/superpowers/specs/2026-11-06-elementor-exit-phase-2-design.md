# Phase 2 — Auth, directories, light shells

**Date:** 2026-11-06  
**Status:** Approved (Approach 2)  
**Parent:** `2026-11-06-elementor-exit-design.md`

## Approach

Shared Phase 2 chrome matching Phase 1 browse language (sky wash, compact hero, content panel). Existing shortcodes stay in the plugin. Contact/About copy ports into theme PHP (no Elementor wrappers).

## Pages

| Slug | Template | Body |
|---|---|---|
| `login` | `page-login.php` | `[custom_login_form]` |
| `registration` | `page-registration.php` | `[custom_creator_registration_form]` |
| `get-started` | `page-get-started.php` | `[cw_role_selection]` |
| `creators-directory` | `page-creators-directory.php` | `[cw_creators_directory]` |
| `organizers-directory` | `page-organizers-directory.php` | `[cw_organizers_directory]` |
| `sponsors` | `page-sponsors.php` | `[cw_sponsors campaign_id="3361" heading=""]` |
| `contact` | `page-contact.php` | Ported NAP + mailto form |
| `about` | `page-about.php` | Ported copy + `[cw_partners_marquee …]` |

## Visual / motion

- Brand: Montserrat, coral, blue; no glass, emoji icons, or meta-labels
- Auth: narrow panel; directories/content: full container
- GSAP: hero stagger + panel reveal; reduced-motion safe; never leave forms at opacity 0

## Exit criteria

- Eight pages render from child templates without Elementor page content
- Forms and directories still function
- Desktop + mobile smoke QA
