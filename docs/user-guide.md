# User guide

## Creating a short link

1. **Short links → Add new.**
2. **Target:** type to search posts and pages, or paste a URL. Posts and pages are stored by ID, so the short link survives a changed permalink.
3. **Short address:** pick a kind. A suggestion appears right away.
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

New and changed links are checked as soon as you save them. With *Do not send visitors to unreachable targets* (on by default) a link whose target was found unreachable leads to its fallback instead – or to the default target, or the “not found” page. The next successful check lets it lead to the target again.

## When a link does not work (any more)

In every case the order is the same: the link's own fallback → the site's default target (if set) → the “not found” page. This covers unknown short addresses, links that have not started or have expired, used-up limits, paused, deleted and retired links, content that is no longer published and targets the link check found unreachable.

## Default target

*Settings → Default target*: none, the home page, or your choice of a page, post, short link or URL. It can apply to unknown short addresses and to links that do not lead anywhere right now (not started, expired, limit reached, paused, retired) – a link's own fallback always comes first.

## QR codes

Open *QR code* on the edit screen and click *Create QR code*.

- The code contains `example.com/q/<short address>` – the same short address as the link unless you untick *QR code uses the same short address*. Scans are counted separately from clicks.
- *Compact code* writes the address in capitals. Short links ignore case, and the QR standard has a compact mode for capitals, digits and a few symbols – the code gets fewer, larger modules and scans more reliably.
- *Dots*: squares, rounded, dots or connected (neighbouring dots merge into capsules). *Corners*: square, rounded or round.
- *Call to action*: a pill with a scan symbol and your text (default "Scan me") – below the code, or sitting on a rounded frame around it.
- *Logo or symbol* places an image from the media library in the middle. Error correction is raised to the highest level automatically.
- Colours: keep a dark colour on a light background; the designer warns about low contrast. *Transparent* leaves out the background in every export.
- Exports: *SVG* and *PDF* are vector files for print (the PDF embeds the logo with its transparency), *PNG* in 512–2048 px, or *Save to media library*.
- The design is stored with the link when you save.

## Statistics

Per link: visits per day (7, 30, 90 or 365 days), split by source (link, QR code, short form), device (desktop, mobile, tablet) and referring site (host only). CSV export at the bottom.

Not counted: bots, link previews (WhatsApp, Telegram, …), tools like curl, HEAD requests and – unless enabled in the settings – logged-in authors and editors.

Stored: daily totals only. No IP address, cookie, user ID or full referrer is ever saved.

## Settings (per site)

- **Create automatically** for chosen post types on first publication.
- **Short form by default** for new memorable and own addresses.
- **Count editors** – off by default.
- **Pass parameters** – `?utm_source=…` is appended to the target.
- **Short links for existing content** – one click for every published post or page without a link.
- **Import** – `short address ; target ; title`, one per line. Imported links get the short form, so old short links like `example.com/abc` keep working.

## Network (multisite)

**Network admin → Short links** lists every site with its prefixes, number of links, visits and most visited link, and holds the network-wide settings: prefixes for links and QR codes, whether sites may choose their own, whether the short form is allowed, how long statistics are kept and reserved words.

## Block editor

The document sidebar has a *Short link* panel: copy the link and short form, open the QR designer, see the visits of the last 30 days – or create a link with one click.
