=== Clean Short Links ===
Contributors: couchdev
Tags: short links, url shortener, qr code, redirect, statistics
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Short links with time windows, targets by date and time, QR codes with your logo and privacy-friendly statistics.

== Description ==

Clean Short Links gives posts, pages and any URL a short address on your own domain – `example.com/s/blue-couch` – and keeps everything on your site: no external service, no tracking, no cookies.

**Short addresses your way**

* Random (`k7mq4`, without easily confused characters like 0/O or 1/l), memorable (`blue-couch`), derived from the title (`summer-party`) or your own.
* Optionally also as a short form right on the main address – `example.com/blue-couch`. It is only used where your site has no content, so existing pages always win and nothing collides.
* Give a link a new short address at any time; the old one keeps working as an alias or is retired and answers "410 Gone".
* Posts and pages are followed by ID – the short link keeps working when their address changes.

**Time windows and changing targets**

* Start at a date or with the publication of the content, expire at a date. Before and after, the link leads to a fallback page of your choice – or answers "not found" and "gone".
* Rules send the same link to different targets by date range, weekday and time of day – e.g. during the festival to the programme, in the evening to the stage plan and afterwards to the review. The first matching rule wins.
* Redirects are temporary and never cached, so schedules work reliably – even for printed QR codes.

**QR codes**

* Created on demand in the browser with a built-in encoder – no external service, no CDN.
* Logo or symbol from the media library in the middle, colours, dots and rounded corners. Error correction is raised automatically when a logo is used.
* QR codes lead through their own prefix (`/q/…`), so statistics tell scans from clicks. By default they use the same short address as the link.
* URLs are encoded in capitals where possible – this compact QR mode gives coarser codes that scan more reliably.
* Download as SVG for print or PNG, or save straight to the media library.

**Statistics without personal data**

* Visits per day, split by link, QR code and short form, device class and referring site (host only).
* No IP addresses, no cookies, no user IDs. Bots, link previews and logged-in editors are left out.
* Chart per link and for the whole site, CSV export, configurable retention.

**In the editor and the network**

* A panel in the block editor shows the short link with copy button, QR code and visits – or creates one with a click.
* Automatic short links on publishing, per post type. WordPress' own "Copy short link" uses them too.
* In a multisite network every site has its own short links. The network admin shows all sites at a glance and sets prefixes, the short form, retention and reserved words for everyone.

== Installation ==

1. Install and activate the plugin – in a network, activate it network-wide.
2. Go to **Short links → Add new**, choose a target and a short address.
3. Optional: in **Short links → Settings** let new posts and pages get short links automatically, or import existing ones.

== Frequently Asked Questions ==

= Which addresses does it use? =

`/s/…` for links and `/q/…` for QR codes by default. In a network the prefixes are set by the network admin, who may allow every site its own.

= Can the short form collide with a page? =

No. The short form `example.com/slug` is only looked at when WordPress found nothing at that address. If a page with that address appears later, the page wins – the link with prefix keeps working. The edit screen warns you about it.

= Can I take over existing short links? =

Yes. Under **Settings → Import** add one line per link: `short address ; target ; title`. Imported links also get the short form, so `example.com/abc` keeps working.

= Does it work with page caches? =

Short-link redirects send no-cache headers. If your cache ignores them, exclude the paths `/s/` and `/q/`.

= Is it GDPR-friendly? =

Visits are only added up per day – no IP addresses, cookies or user IDs are stored. Only the host of a referring page is kept, never the full address.

== Changelog ==

= 0.1.0 =
* First version: short links for posts, pages and URLs, random/memorable/title/own short addresses, short form without prefix, aliases, time windows with fallback, rules by date and time, QR designer with logo, statistics, block editor panel, automatic links, bulk creation, import, network overview and settings.
