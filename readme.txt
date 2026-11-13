=== Clean Short Links ===
Contributors: couchdev
Tags: short links, url shortener, qr code, redirect, statistics
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.0
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
* A click limit ends a link after a number of visits – bots, link previews and logged-in editors do not count.
* A default target per site – the home page, a page, a post, another short link or a URL – catches mistyped short addresses and links that have run out.

**Social media and search engines**

* Link previews for WhatsApp, Facebook, Instagram, LinkedIn, X, Telegram, Mastodon and others. For posts and pages they come straight from the content – title, excerpt, featured image – nothing to maintain twice. Addresses outside the site can get a title, description and image of their own.
* Share buttons in the editor screen – plain links to each network's own share page, no scripts.
* Channel addresses like `example.com/s/blue-couch-ig` show which channel brings how many visitors, without cookies.
* UTM parameters on request, taken from the short link, channel and QR code.
* A link-in-bio page for Instagram, TikTok or Mastodon profiles with buttons for chosen short links; name, tagline and icon come from the site settings.
* Permanent redirects (301) for links that point to one page for good.

**Invitation links**

* Create personal links for a short link, group by group – e.g. 10 links for the family with one visit each, 5 for clubs with 20 visits each.
* Each has its own hard-to-guess address, optional expiry and limit, and follows target, rules and time window of the main link.
* See which ones were used, copy them all at once or export them as CSV.

**Link check**

* Checks once a day – or at the push of a button – whether each short link answers and whether all its targets (main target, rule targets, fallback) can still be reached.
* Problems show up on the short link's card and in a filter of their own.
* Links whose target is unreachable lead to their fallback or the default target instead – otherwise they answer “temporarily unavailable” (503), and after 30 days without a break “gone” (410). Once the target is back, the link works again.

**QR codes**

* Created on demand in the browser with a built-in encoder – no external service, no CDN.
* Logo or symbol from the media library in the middle, colours, square, rounded, dotted or connected modules and rounded corners. Error correction is raised automatically when a logo is used.
* An optional call to action – a pill like "Scan me" below the code or on a frame around it.
* QR codes lead through their own prefix (`/q/…`), so statistics tell scans from clicks. By default they use the same short address as the link.
* URLs are encoded in capitals where possible – this compact QR mode gives coarser codes that scan more reliably.
* Download as SVG or PDF (vector, for print) or PNG – each also with a transparent background – or save straight to the media library.

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

= Does the plugin contact other servers? =

Only for the link check: it requests the target addresses of your own short links (a HEAD request, with a small GET as fallback) to see whether they can be reached. Nothing else is sent, local and private network addresses are never contacted, and the automatic daily check can be switched off in the settings. QR codes are created in the browser.

= Is it GDPR-friendly? =

Visits are only added up per day – no IP addresses, cookies or user IDs are stored. Only the host of a referring page is kept, never the full address.

== Changelog ==

= 0.3.0 =
* Link check: once a day, when saving and at the push of a button – does each short link answer, and can its targets still be reached? Problems show on the card, as a key figure and in a filter.
* Unreachable targets are skipped: fallback, default target, otherwise “temporarily unavailable” (503) and after 30 days without a break “gone” (410). Once the target is back, the link works again.
* Click limit: a link ends after a number of real visits.
* Invitation links: personal links group by group, each with its own address, limit and expiry; copy all, CSV export.
* Default target per site – home page, page, post, short link or URL – for unknown and inactive short links.
* Other short links and the home page can be chosen as targets.
* Social media: link previews for messengers and networks – for posts and pages straight from the content, for outside addresses with title, description and image of their own; share buttons; channel addresses with their own statistics.
* UTM parameters on request, derived from short link, channel and QR code.
* Link-in-bio page with buttons for chosen short links.
* Permanent redirects (301) for links that cannot change by themselves.
* Works together with Clean Maintenance: one shared rule per site for short links during maintenance (redirect, let targets through, pause) and for short links that do not work (error page or maintenance page).

= 0.2.0 =
* Admin screens in the Clean Maintenance design: header with key figures, section navigation, tiles, switches, a card per short link and a sticky save bar.
* QR codes: connected dots, round corners, a call-to-action pill (below the code or on a frame), transparent background and vector PDF export.
* New icon and banner.
* Asset versions include the file time, so browsers load updates right away.

= 0.1.0 =
* First version: short links for posts, pages and URLs, random/memorable/title/own short addresses, short form without prefix, aliases, time windows with fallback, rules by date and time, QR designer with logo, statistics, block editor panel, automatic links, bulk creation, import, network overview and settings.
