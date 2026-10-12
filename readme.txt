=== RSNC Events ===
Contributors: equinenetwork
Tags: events, calendar, results
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.4.1
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
3. Settings → RSNC Events: check the feed address. The live feed is
   `https://legacy.rsnc.us/api/events.php` (moving to `https://admin.rsnc.us/api/events.php`).
   Save.
4. Create or edit a page (e.g. "Calendar") and add a Shortcode block with `[rsnc_calendar]`.
   Do the same on a "Results" page with `[rsnc_results]`.
5. After editing events in the admin, changes show within the cache time. To see them
   at once: Settings → RSNC Events → Clear cache.

Once it looks right on staging, repeat on the live site.

== Changelog ==

= 1.4.1 =
* Leaderboards: the Points column title lines up with the points.
* New [rsnc_awarded]: total paid out this season, from the RSNC admin (for the header counter).
* Settings page lists every shortcode.
* Featured Events: text cards without flyers (flyers="yes" brings them back); cards always fit the section.

= 1.4.0 =
* New [rsnc_leaderboard type="state|national|international|northeast|hall-of-fame|challenge"]: the RSNC points lists in the site look (pick a state or country; See status opens in a pop-up).
* Event Schedule: events with several flyers show the first one big with thumbnails of all of them underneath.

= 1.3.2 =
* Event pictures use the flyer uploaded in the RSNC admin first, then the website event featured image.

= 1.3.1 =
* Featured Events: three cards across, flyer picture from the website event (fixes a missing flyer).
* New [rsnc_reservations] for the Event Reservations page: RSNC Production Events, Reserve Now on events with a reservation attached.

= 1.3.0 =
* New [rsnc_featured number="3"]: the home page Featured Events cards (small flyer, date, name, city), from the RSNC admin.
* New [rsnc_upcoming limit="5"]: the next upcoming events as compact rows, no flyers, no page numbers.
* Both link to the event's page on this site (with Reserve Now) and use the event name riders know.

= 1.2.2 =
* The default feed address is the live feed, https://legacy.rsnc.us/api/events.php.
* Flyers show as pictures: a PDF flyer uploaded to the site shows the preview image WordPress made for it, and opens the PDF when clicked.
* An event with no flyer, or a flyer with no picture yet, shows "Flyer coming soon".

= 1.2.1 =
* Settings → RSNC Events shows the installed version and a "Check for updates now" link.

= 1.2.0 =
* Updates itself from GitHub: new releases show as "Update available" on the Plugins page, and auto-updates can be turned on.

= 1.1.0 =
* Calendar and results: search, month, state, producer and venue filters; results also pick a season (this season and the four before). 10 events per page with page numbers (shortcode option per_page).
* Phones: results use the full width with compact placings (time, cattle and points on one short line).
* Looks like the site's own event list: the theme's fonts, a date block, plain month labels, red "Find Events" button.
* "Book Stalls & RV" button removed for now: the feed no longer carries a booking link (Global Stall Manager will supply booking status).

= 1.0.0 =
* First version: calendar and results shortcodes, settings page, cache with last-good copy.
