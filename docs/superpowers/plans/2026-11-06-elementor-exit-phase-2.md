# Phase 2 Implementation Plan — Auth + directories + light shells

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace Phase 2 Elementor pages with child-theme shells (Approach 2).  
**Architecture:** Shared `cw-phase2` chrome + existing plugin shortcodes; Contact/About copy in PHP.  
**Tech:** Astra child `astra-child-creativewings`, GSAP ScrollTrigger, Novamira deploy.

## File map

- `template-parts/phase2/shell-*.php` — shared open/close
- `assets/css/phase2.css`, `assets/js/phase2.js`
- `page-{login,registration,get-started,creators-directory,organizers-directory,sponsors,contact,about}.php`
- `functions.php` — enqueue + title suppress

## Tasks

1. Shared chrome + motion (done in implementation)
2. Auth + directory templates
3. Contact + About content port
4. Deploy theme 0.5.0; disable Elementor edit mode on the eight pages
5. Smoke QA all eight URLs desktop/mobile
