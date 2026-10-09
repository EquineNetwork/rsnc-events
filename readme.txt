=== RSNC Events ===
Contributors: equinenetwork
Tags: events, calendar, results
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: Proprietary

RSNC's show calendar and published results on rsnc.us, read from the RSNC admin's events feed.

== Description ==

The RSNC admin is the one place shows, flyers and results are entered. This plugin reads the
admin's public events feed and shows it on the website:

* `[rsnc_calendar]` — upcoming events grouped by month: name, dates, arena, city/state,
  producer, flyers (swipe on phones, arrows on desktop; PDFs open in a new tab), a
  "Book Stalls & RV" button when booking is open, and a CANCELLED label for cancelled
  events (they stay listed until their date passes).
* `[rsnc_results]` — this season's past events, newest first. Each day's results appear
  (tap "View results") once they are published in the admin; until then the day says
  "Results coming soon".

It does not touch Equine Event Manager, its `en_*` shortcodes or its `/event/` pages.

The feed is cached (15 minutes by default). If the feed can't be reached, the last good
copy keeps showing.

== Installation ==

1. Download `rsnc-events.zip` (GitHub repo EquineNetwork/rsnc-events → Releases).
2. On the WP Engine **staging** site: Plugins → Add New → Upload Plugin → choose the zip →
   Install Now → Activate.
3. Settings → RSNC Events: check the feed address. For testing it is
   `https://endev.rsnc.us/api/events.php`; change it to the live admin address once the
   feed is live. Save.
4. Create or edit a page (e.g. "Calendar") and add a Shortcode block with `[rsnc_calendar]`.
   Do the same on a "Results" page with `[rsnc_results]`.
5. After editing events in the admin, changes show within the cache time. To see them
   at once: Settings → RSNC Events → Clear cache.

Once it looks right on staging, repeat on the live site.

== Changelog ==

= 1.1.0 =
* Calendar and results: search, month, state, producer and venue filters; results also pick a season (this season and the four before). 10 events per page with page numbers (shortcode option per_page).
* Looks like the site's own event list: the theme's fonts, a date block, plain month labels, red "Find Events" button.
* "Book Stalls & RV" button removed for now: the feed no longer carries a booking link (Global Stall Manager will supply booking status).

= 1.0.0 =
* First version: calendar and results shortcodes, settings page, cache with last-good copy.
