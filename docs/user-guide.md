# User guide

## Creating a short link

1. **Short links → Add new.**
2. **Target:** type to search posts and pages, or paste a URL. Posts and pages are stored by ID, so the short link survives a changed permalink.
3. **Short address:** pick a kind. A suggestion appears right away. Posts and pages get their address from the title by default, all other targets a random or memorable one – both defaults can be changed in the settings.
   - *Random* – five characters without look-alikes (`k7mq4`).
   - *Memorable* – two words (`blue-couch`), in German on German sites.
   - *From the title* – the first words of the title (`summer-party`).
   - *My own* – type anything; letters, digits and dashes. Availability is checked while you type.
4. **Short form:** tick *Also as short form* to make `example.com/blue-couch` work as well. The edit screen warns you if a page already uses that address – the page wins, the link with prefix keeps working.
5. **Save.** The side column now shows the short link, where it leads *right now*, the QR code and the statistics.

## Changing the short address

Type a new address and save. You decide what happens to the old one:

- **Keep it working** – it becomes an additional address (alias).
- **Retire it** – it answers *410 Gone* and is never handed out again.

Aliases and retired addresses are listed under *Earlier short addresses*; *Release* frees one.

## Time window

| Setting | Effect |
|---|---|
| Start *right away* | Works immediately. |
| Start *at* a date | Before that: fallback target, or *404 Not Found*. |
| Start *when the content is published* | Waits for a draft or scheduled post. |
| *Expires* | After that: fallback target, or *410 Gone*. |

All times are in the site's time zone.

## Rules: different targets by date and time

Each rule has an optional date range (*from* inclusive, *until* exclusive), weekdays, a daily time window and a target. Rules are checked top to bottom; the first one that fits wins, otherwise the main target applies. A daily window whose end is before its start runs over midnight (22:00–02:00).

Example for a festival poster:

1. *Stage evenings* – 10–12 July, daily 18:00–23:59 → stage plan
2. *Festival* – 10–12 July → programme
3. *Afterwards* – from 13 July → photo review

Redirects are always temporary (302) and sent with no-cache headers, so browsers never remember an old target.

## Different targets by device and screen

Under *Different targets by device and screen* a short link can lead elsewhere depending on the visitor: by device (phone, tablet, computer), operating system (iOS/iPadOS, Android, Windows, macOS, Linux/ChromeOS) and screen width in pixels. The first variant that fits decides; rules by date and time come first, variants apply to the main target. Typical: one QR code that sends iPhones to the App Store, Android phones to Google Play and everyone else to the website.

Device and system are recognised on the server, nothing of it is stored. Screen widths are only known to the browser, so links with such variants show a tiny page (about 1 KB, no external files) that forwards at once; without JavaScript it leads to the normal target.

## Further domains and short domains

In a network a short link can also be offered on the domains of other sites, e.g. `kuroikizu.couch-blog.de/s/sommer` – tick them under *Further domains*. Visits are still counted on the site the link belongs to. If the short address is already taken on another domain, that domain is marked and left out.

For a separate short domain (e.g. a short .de domain), create an empty site in the network with that domain and mark it under *Network admin → Short links → Short domains*. Every site can then offer its links there, also as short form without prefix (`short.de/sommer`); the home page of the short domain leads to the main site.

## Rules from a calendar

Below the rules, *Rules from a calendar* takes an iCal file (.ics, imported once) or a calendar address (also `webcal://`, fetched again twice a day – e.g. the public iCal address of a Google, Apple, Nextcloud or Outlook calendar).

- Every event becomes a rule: from its start to its end.
- Weekly events (with their weekdays) and daily events become one rule with weekdays and daily times; monthly and yearly events are listed one by one for the next year.
- The target is the event's URL field, otherwise the first link in its description or location – links to this site become posts and pages. Events without a link lead to the chosen *Target of events without a link* or, if empty, to the target of the short link.
- Past and cancelled events are left out. Rules you entered yourself stay first and untouched; rules from the calendar (marked “from calendar”) are replaced on every import.

## Click limit

*Maximum number of visits* ends a link after that many uses; it then behaves as if it had expired (fallback, or *410 Gone*). Only real uses count – not bots, link previews, logged-in editors or the way to a fallback page. *Reset counter* starts again from zero.

## Invitation links

On a saved short link, *Invitation links* creates personal links group by group: a group name, how many, visits each (0 = unlimited) and an optional expiry. Each invitation gets a random eight-character address and follows the main link's target, rules and time window; its own limit and expiry apply on top, and pausing or expiring the main link ends all invitations.

Per group you see how many were used, can copy all addresses at once and delete single ones. *Export all invitations as CSV* lists group, link, QR address, uses, limit, expiry and state.

## Link check

Once a day the 25 short links checked longest ago are checked – and *Check now* (edit screen) or *Check links* (list) checks right away:

- content of the site: still there and published,
- other short links: still there and without problems,
- URLs: a HEAD request (small GET as fallback); 2xx/3xx is fine, 401/403/429/503 are worth a look, anything else counts as unreachable,
- the short link itself: does the site answer with the status it should right now?

Results appear on the short link's card, in the header of the edit screen and in the *Problems* filter.

New and changed links are checked as soon as you save them. With *Do not send visitors to unreachable targets* (on by default) a link whose target was found unreachable leads to its fallback instead – or to the default target, or the “not found” page. While a target has been unreachable for less than 30 days (*Settings → Link check*), the link counts as *temporarily unreachable*: without a fallback or default target visitors get “try again later” (503), and the daily check looks at such links first. Unreachable for 30 days without a break, it counts as *permanently unreachable* and answers “gone” (410). Either way, the next successful check lets it lead to the target again.

## When a link does not work (any more)

In every case the order is the same: the link's own fallback → the site's default target (if set) → the “not found” page. This covers unknown short addresses, links that have not started or have expired, used-up limits, paused, deleted and retired links, content that is no longer published and targets the link check found unreachable.

## Default target

*Settings → Default target*: none, the home page, or your choice of a page, post, short link or URL. It can apply to unknown short addresses and to links that do not lead anywhere right now (not started, expired, limit reached, paused, retired) – a link's own fallback always comes first.

## QR codes

Open *QR code* on the edit screen and click *Create QR code*.

- The code contains `example.com/q/<short address>` – the same short address as the link unless you untick *QR code uses the same short address*. Scans are counted separately from clicks.
- *Show the short link below the code* prints the address in plain text under the code (and under the call to action) – for people who cannot scan. It shows the normal short link, so typed visits count as link, not as QR code.
- *Compact code* writes the address in capitals. Short links ignore case, and the QR standard has a compact mode for capitals, digits and a few symbols – the code gets fewer, larger modules and scans more reliably.
- *Dots*: squares, rounded, dots or connected (neighbouring dots merge into capsules). *Corners*: square, rounded or round.
- *Call to action*: a pill with a scan symbol and your text (default "Scan me") – below the code, or sitting on a rounded frame around it.
- *Logo or symbol* places an image from the media library in the middle. Error correction is raised to the highest level automatically.
- Colours: keep a dark colour on a light background; the designer warns about low contrast. *Transparent* leaves out the background in every export.
- Exports: *SVG* and *PDF* are vector files for print (the PDF embeds the logo with its transparency), *PNG* in 512–2048 px, or *Save to media library*.
- The design is stored with the link when you save.

## Sharing and social media

The card *Sharing* on each short link:

- **Link preview** – what WhatsApp, Facebook & co. show. For posts and pages it is read live from the content (title, excerpt, featured image; the site icon if there is no image) – change it in the post, never twice. For the home page: site name, tagline and icon. Only addresses outside the site can get their own title, description and image; empty means networks read the target page themselves. Preview robots get a small page with these details; people are redirected as always.
- **Share** – buttons that open the share pages of WhatsApp, Telegram, Facebook, LinkedIn, X, Bluesky and e-mail with the short link.
- **Channel addresses** – tick Instagram, Newsletter, Print … and the link gets `…/blue-couch-ig`, `…/blue-couch-nl` and so on. They lead to the same target; the statistics list them as “Channel: Instagram”.
- **UTM parameters** – `utm_source` (channel, `qr`, `linkinbio` or `shortlink`), `utm_medium` (`social`, `email`, `print`, `qr`, `shortlink`) and `utm_campaign` (the short address) are added to the target. Parameters the target already has are kept.
- **Permanent redirect (301)** – for links that point to one page for good. Used only while the link has no time window, rules or limit.
- **Link-in-bio page** – switch it on in *Settings → Social media* (address `…/s/links` by default) and tick *Show on the link-in-bio page* at the links that should appear. Name, tagline and icon come from the site settings; the buttons are labelled with the short link's title or, if it has none, the post's title. Visits from the page count as source “Link-in-bio page”.

## Statistics

Per link: visits per day (7, 30, 90 or 365 days), split by source (link, QR code, short form), device (desktop, mobile, tablet) and referring site (host only). CSV export at the bottom.

Not counted: bots, link previews (WhatsApp, Telegram, …), tools like curl, HEAD requests and – unless enabled in the settings – logged-in authors and editors.

Stored: daily totals only. No IP address, cookie, user ID or full referrer is ever saved.

## Settings (per site)

- **Create automatically** for chosen post types on first publication, and the default kind of short address: for posts and pages (from the title unless you choose otherwise) and for all other targets (random or memorable).
- **Short form by default** for new memorable and own addresses.
- **Count editors** – off by default.
- **Pass parameters** – `?utm_source=…` is appended to the target.
- **Short links for existing content** – one click for every published post or page without a link.
- **Import** – `short address ; target ; title`, one per line. Imported links get the short form, so old short links like `example.com/abc` keep working.

## Maintenance mode (with Clean Maintenance)

With Clean Maintenance active, *Settings → Maintenance mode* holds one rule per site that both plugins share – the same card appears in the maintenance mode settings, and changing it in one place changes it in the other.

- **While maintenance mode is on:** *Redirect as usual* (short links work; pages of this site show the maintenance page), *Let targets through* (pages and posts that active short links lead to – and the home page if a link leads there – stay reachable, like an automatic exception) or *Pause short links* (they show the maintenance page too).
- **Short links that do not work:** *Error page* (“not found” / “gone”), *Maintenance page when temporary* (for targets that are temporarily unreachable) or *Always the maintenance page* (instead of every error page, also when maintenance mode is off). A link's fallback and the default target always come first.

Exceptions of the maintenance mode (slugs, addresses, access pass) still apply: a short link whose address matches an exception keeps working while the others pause.

## Network (multisite)

**Network admin → Short links** lists every site with its prefixes, number of links, visits and most visited link. A search finds short links on all sites at once – by title, short address or target – and leads straight to their edit screen. It also holds the network-wide settings: prefixes for links and QR codes, whether sites may choose their own, whether the short form is allowed, short domains, whether referring websites are stored, how long statistics are kept and reserved words.

## Dashboard

The dashboard of each site shows a *Short links* widget: number of short links, visits in 30 days, problems, how many start later, have expired, reached their limit or are paused – and, only when there are any, the short links with problems, each with the reason and a link to it.

## Link search while writing

When you add a link in the block editor – in a paragraph, button, navigation, image and so on – or in the classic editor's link dialog, matching short links appear among the suggestions, marked “Short link”, with their title and short address. Choosing one inserts the short link. Only active short links are suggested, and only to people who may write posts.

## Block editor

The document sidebar has a *Short link* panel: copy the link and short form, open the QR designer, see the visits of the last 30 days – or create a link with one click.

The essentials can be changed right there, without leaving the post:

- **Active** – pause the link and switch it back on.
- **Short address** – type your own or let it suggest a random, memorable or title-based one; the old address stays as an alias unless you untick that.
- **Short form without prefix** – when the network allows it, with a warning if the address is already taken by a page.
- **Click limit** – with the clicks used so far and a way to reset the counter.
- **Permanent redirect (301)** and **Show on the link-in-bio page**.
- **Link check** – the latest result with any problems, and *Check now*.

The link always starts with the publication of the post or page – also when the publication is scheduled. Changes are saved together with the post or page, or right away with *Save short link*. An end, rules, QR design, the calendar, invitations, channels, social previews and statistics stay on the full edit screen, linked from the panel.
