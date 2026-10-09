# RSNC Events (WordPress plugin)

Shows RSNC's calendar and published results on rsnc.us from the RSNC admin's events feed
(`/api/events.php` in EquineNetwork/legacy-rnsc-us — see `api/README.md` there).

- Shortcodes: `[rsnc_calendar]`, `[rsnc_results]`
- Settings → RSNC Events: feed address, cache minutes, optional site name header, Clear cache
- Install steps: see `readme.txt`

## Build

```sh
./build.sh   # → dist/rsnc-events.zip
```

Releases on GitHub carry the zip for upload to WordPress.

## Layout

- `rsnc-events.php` — plugin header, loads the classes
- `includes/class-rsnc-ev-feed.php` — fetch, transient cache, last good copy
- `includes/class-rsnc-ev-settings.php` — settings page
- `includes/class-rsnc-ev-render.php` — shortcodes
- `assets/` — scoped CSS (`rsnc-ev-` prefix) and the small gallery script (no libraries)
