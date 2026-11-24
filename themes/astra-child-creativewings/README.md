# Creative Wings (Astra Child)

Elementor exit Phase 1 — site chrome + Homepage / Activities / Competitions.

## Deploy path

`wp-content/themes/astra-child-creativewings/`

## Preview (before activation)

While Astra parent is still the active theme, admins can preview:

`https://creativewings.asia/?cw_child_preview=1`

## Rollback

1. `switch_theme( 'astra' );`
2. Restore Theme Builder conditions for headers `389` / `161` and footer `163` from  
   `docs/superpowers/backups/2026-11-06-elementor-pre-exit/` (or re-set in Elementor UI).
3. For pages `159` / `2468` / `460`: restore `_elementor_edit_mode` = `builder` and `_elementor_data` from the same backup JSON files.
4. Clear Elementor CSS cache.

## Notes

- Primary desktop nav is hard-coded to match Elementor mega-menu labels (not `header-menu-main`).
- Footer uses WP menu slug `footer`.
- KPI strip uses `[product_count]` (prefixed with `000`) and `[total_prize_money]`.
