# Host capacity request — creativewings.asia

**Date:** 2026-11-06  
**Account:** `creativewings` on `ipsone.yibonmag.com` (cPanel EA-PHP 8.3)  
**Goal:** Raise concurrent campaign-join headroom without overloading a shared host.

---

## Please apply / confirm

### 1) PHP OPcache (required)
Current: `opcache.memory_consumption=128`, `max_accelerated_files=4000`, **0 MB free**, hit rate ~**9%** (thrashing).

Requested (EA4 MultiPHP INI / php.d — these are `PHP_INI_SYSTEM` and cannot be set from `.user.ini`):

```ini
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 60
opcache.validate_timestamps = 1
```

If RAM allows for this account pool: **512** MB opcache is better.

### 2) Redis + php-redis (required for object cache)
Not installed today (`extension_loaded('redis') = false`).

Please provide:
- Redis 6+ listening on a private socket or `127.0.0.1:6379`
- PHP 8.3 `redis` extension enabled for this account
- Confirm maxmemory policy (`allkeys-lru` preferred) and a dedicated DB index we may use (e.g. `1`)

We will then enable WordPress Redis Object Cache (object-cache.php drop-in).

### 3) MySQL connections
Current server `max_connections = 80` (historical peak used: 26).

Please:
- Raise global `max_connections` to **150–200** if hardware allows, **or**
- Confirm per-user `max_user_connections` and whether 80 is shared across all cPanel accounts on this MySQL instance
- Share InnoDB buffer pool size confirmation (we see ~2 GB)

### 4) CloudLinux LVE caps (please paste)
For user `creativewings` / UID `1007`:
- SPEED / CPU cores
- PMEM / VMEM
- EP / NPROC
- IOPS / IO

We need these to size safe concurrency (PHP workers × ~90–150 MB RSS).

### 5) Disk headroom
`/` shows ~**11 GB free** of ~549 GB. Please confirm quota vs real free space and whether log/backup cleanup is needed.

---

## What we already do on the app side
- Guest HTML page cache (`WP_CACHE` + `advanced-cache.php`) for logged-out marketing/campaign pages
- Skip empty WooCommerce cart cookies on browse
- Cloudflare in front (needs a Cache Rule for HTML — see below)

## Cloudflare (our side / DNS)
Please note: origin already sends `Cloudflare-CDN-Cache-Control: max-age=3600` on page-cache HITs.  
If HTML remains `cf-cache-status: DYNAMIC`, add a Cache Rule:

- If hostname is `creativewings.asia` and path is not `/cart*`, `/checkout*`, `/my-account*`, `/wp-admin*`, `/wp-json*`  
- and cookie does not contain `wordpress_logged_in_|woocommerce_|wp_woocommerce_session_`  
- Then: **Eligible for cache** / Edge TTL 1 hour / Respect origin override

Do **not** cache cart, checkout, account, or logged-in HTML.

---

## Safe concurrency target (after changes)
| Stage | Comfortable concurrent checkouts | Browse (cached) |
|---|---|---|
| Today (shared, no Redis, OPcache thrash) | ~15–25 | higher if cache HIT |
| After OPcache + Redis + CF HTML | ~40–60 | much higher |

Please reply with applied values + LVE dump so we can re-benchmark.
