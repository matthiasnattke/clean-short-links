=== Clean Short Links ===
Contributors: couchdev
Tags: short links, url shortener, qr code, redirect, statistics
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.5.9
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
* Rules can come from a calendar: import an iCal file or subscribe to a calendar address – every event becomes a rule, its link the target.
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

* A panel in the block editor shows the short link with copy button, QR code and visits – or creates one with a click. The essentials – address, short form, pause, click limit, 301, link-in-bio and link check – can be changed right there; the link starts with the publication, also a scheduled one.
* Short links show up in the link search of the block editor and the classic editor – insert them like any other link.
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

Only for the link check and calendar addresses you enter: the link check requests the target addresses of your own short links (a HEAD request, with a small GET as fallback) to see whether they can be reached. Nothing else is sent, local and private network addresses are never contacted, and the automatic daily check can be switched off in the settings. QR codes are created in the browser.

= Which calendars work? =

Any iCal file or address (also webcal://), for example the public or secret iCal address of Google Calendar, Apple iCloud, Nextcloud or Outlook. The address is fetched twice a day; nothing is sent to it.

= Do I need Clean Mobile Connect? =

No. Clean Short Links works completely on its own. Only when the plugin Clean Mobile Connect (version 0.2 or later) is active, short links and their locations can also be offered as cards for Apple Wallet, with an address like `example.com/w/blue-couch`. Without it nothing of this shows up. Deactivating Clean Short Links leaves the cards as they are; uninstalling it ends them.

= Is it GDPR-friendly? =

Visits are only added up per day – no IP addresses, cookies or user IDs are stored. Only the host of a referring page is kept, never the full address.

== Changelog ==

= 0.5.9 =
* Buttons follow their short link: a button that points to a short link of the network only shows while the link leads to its target – it appears when the link starts and disappears when it ends, is paused, has reached its limit or was retired. Rows without a visible button leave no gap. On by default, under Settings → Default target; filter `clean_short_links_button_shows`.

= 0.5.8 =
* Nextcloud links have a lean editor: title, short address, QR code and visits. Target and end are shown and follow the share; everything the share settles itself is left out.
* Nextcloud links are no longer part of the link check – the sync knows whether the share exists.

= 0.5.7 =
* Pages of a short domain: languages translated by machine that nobody could check (Irish, Maltese, Estonian, Latvian, Lithuanian) say so and ask for corrections.

= 0.5.6 =
* Home page and error page of a short domain speak the visitor's language: the 24 official languages of the European Union, chosen by the browser's language, else the site's language.

= 0.5.5 =
* Home page of a short domain: shorter, with a small picture of how a short link leads to a page, a file or a card, and three facts with symbols.

= 0.5.4 =
* Home page of a short domain: general wording, no reference to the network.

= 0.5.3 =
* Error page of a short domain: a link that exists but does not lead anywhere right now says so instead of “does not exist”.

= 0.5.2 =
* A short domain has an error page of its own: short links that have ended, cannot be reached right now or do not exist are caught with a clear sentence each.
* Imprint and privacy links at the foot of the home page and the error page of a short domain (settings of the short domain).

= 0.5.1 =
* The home page of a short domain speaks the site's language; the editor on a short domain shows the address without prefix.

= 0.5.0 =
* The shortest address comes first: new short links are offered on the short domain right away, and lists, copy buttons, the block editor and the WordPress shortlink use that address.
* A short domain no longer offers the subdomains of the network's own domain.
* Calmer screens: cards that are not in use fold into one line with a summary and open on a click; rarely used switches sit under “More options”.
* A short domain has a home page that says what the service is; unknown short addresses show it with a note instead of leading to the main site.

= 0.4.2 =
* Nextcloud: a share label that holds our own short address (written by an earlier sync) no longer names the link; the file name does, and the label is brought up to date.

= 0.4.1 =
* A short domain answers its own short links first (without prefix), so it can be a link site of its own.
* Nextcloud links belong to the first short domain (managed in its dashboard) instead of the main site; links already made on the main site are moved on the next sync.

= 0.4.0 =
* Nextcloud (optional, set up in wp-config.php): a short link on the short domain for every public share link, named after the file, ending with the share and written into its label. Marked "Nextcloud"; deleted when the share is gone or has expired – its address then leads to Nextcloud for 60 days and is free again afterwards.

= 0.3.1 =
* Short domains: a link reads without prefix there (`short.de/sommer`), with or without its own short form; `q/` and `w/` for QR codes and Wallet. Everything else on the short domain leads to the main site, sign-in and admin stay reachable.
* Further domains: the editor checks while you type whether the short address is taken on a chosen domain and suggests free alternatives.

= 0.3.0 =
* Wallet cards (with the Clean Mobile Connect plugin): a link and each of its locations can offer an Apple Wallet card. The card fills itself from the link – title, image and description from sharing, date and expiry from the time window, colours and logo from the QR design, the next calendar event – and anything entered under "Customize card" takes precedence.
* Own guest address per card under the wallet prefix (/w/…, set like the QR prefix) that follows all rules of the link: pause, time window, click limit, fallback, alias hosts and further domains.
* The QR designer and the NFC section offer the Wallet guest addresses; the Wallet section shows the guest code in the link's design.
* Optional per link: on iPhone, iPad and Mac the QR code first shows the card page with a "Continue" link.
* Statistics: "Wallet" (scans of the guest code and of the code on the card) and "Wallet: added".
* Cards stay in sync with their link; removing a location or deleting a link ends its card; "Issue anew" replaces a card.
* Attach an existing stand-alone card to a short link from Clean Mobile Connect.

= 0.2.5 =
* Security: further domains can only be chosen by site administrators and only among the hosts the network offers – short domains and subdomains without a site; other sites' domains need a new network switch (off by default).
* Security: routes of suspended, archived or deleted sites stop working and are removed with the site and on uninstall.
* Security: link previews never show text of password-protected posts.
* Security: passed-on parameters never replace parameters of the target (e.g. redirect_to).
* Security: click limits are claimed atomically before the redirect.
* Robustness: at most 50 referring hosts per link and day, bounded calendar imports (size and work), link suggestions only for users who may manage short links, CSV cells never start a formula.

= 0.2.4 =
* Faster redirects with less server load: answered before the theme and init, short addresses kept in the object cache, the visit counted after the visitor already has the redirect; prefetching and HEAD requests are not counted.
* Different targets by device, operating system and screen width – e.g. one QR code for the App Store, Google Play and the website. Screen widths are decided in the browser by a tiny page with a strict content security policy.
* Further domains: offer a short link on other domains of the network and on short domains (an empty site with a short domain); the short domain's home page leads to the main site.
* Network admin: find short links on all sites.
* QR codes can show the short link in plain text below the code (SVG, PNG and PDF).
* NFC tags: an address of its own that counts visits as NFC tag; written directly from the edit screen in Chrome on Android.
* Subdomains without a site of their own can serve short links (also without prefix) – with the included sunrise.php; everything else there leads to a page set per subdomain (e.g. about.example.com → /about) or the main site.
* Optional: while registration is off, the sign-up page (also reached by unknown subdomains) leads to the home page.
* Locations: one short link with addresses of its own for several posters, flyers or tags, each counted separately; QR codes and NFC tags for each location.
* More privacy: the network can stop storing referring websites; visitors with Global Privacy Control or Do Not Track are counted without it; redirects send a strict referrer policy.
* Phones in landscape: compact header, section links in one row, slim save bar.
* Tests also run on PHP 8.5.

= 0.2.3 =
* Posts and pages get their short address from the title by default (the plain title, without “Private:”), all other targets a random or memorable one – both can be set in the settings.
* Editor sidebar: the link starts with the publication of the content, also a scheduled one; it has no end field.
* Links that start with the publication show “Starts later” while the content is a draft or scheduled, and may use a permanent redirect once published.
* Phones: compact header, filters and section links in one row to swipe, copy fields and switch texts stay inside the cards, one-row save bar.

= 0.2.2 =
* Block editor sidebar: change the essentials of a post's or page's short link right there – short address with suggestions and availability check, short form, pause, start and end, click limit, permanent redirect, link-in-bio and link check. Changes are saved with the content or by button.
* Admin pages use the full width on large screens.

= 0.2.1 =
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
* Short links in the link search of the block editor and the classic editor.
* Rules from a calendar: iCal file or calendar address, synced twice a day.
* Dashboard widget with the state of the short links and the ones with problems.
* Works together with Clean Maintenance: one shared rule per site for short links during maintenance (redirect, let targets through, pause) and for short links that do not work (error page or maintenance page).

= 0.2.0 =
* Admin screens in the Clean Maintenance design: header with key figures, section navigation, tiles, switches, a card per short link and a sticky save bar.
* QR codes: connected dots, round corners, a call-to-action pill (below the code or on a frame), transparent background and vector PDF export.
* New icon and banner.
* Asset versions include the file time, so browsers load updates right away.

= 0.1.0 =
* First version: short links for posts, pages and URLs, random/memorable/title/own short addresses, short form without prefix, aliases, time windows with fallback, rules by date and time, QR designer with logo, statistics, block editor panel, automatic links, bulk creation, import, network overview and settings.
