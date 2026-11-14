# How it works

## Request flow

```
GET /s/blue-couch ─┐
GET /q/blue-couch ─┤  setup_theme (priority 1) – the fast way, before theme, init and query
                   ├─ found + live     → 302/301 → response sent → then counted, exit
                   ├─ screen variants  → tiny page, the browser picks the target
                   ├─ other site's link offered on this host → same, in that site
                   └─ dead / unknown / maintenance pause → the normal way:
                      parse_request (priority 1) → 404 / 410 / 503 page, default target
                      or maintenance page

GET /blue-couch ───── WordPress resolves the request first
                      └─ only if it is a 404: template_redirect (priority -10)
                         └─ link with short form enabled (also from another site) → as above
```

Prefixed links are answered as soon as WordPress can build permalinks – before the theme, `init`, the main query, templates, maintenance plugins and canonical redirects. That saves most of the work of a normal page view. The short form only runs after WordPress decided there is no content at that address, so it can never hide a page, post or archive.

### Light on the server

- **One lookup per visit:** the short address and its link are kept in the object cache (if the site has a persistent one, e.g. Memcached or Redis) until the next change of any link of the site. Links with a click limit or invitations are always read fresh.
- **Counting after the answer:** with PHP-FPM or LiteSpeed the redirect is handed to the visitor first (`fastcgi_finish_request()`), the two small database writes follow afterwards. Visits are added up per day in one row (`INSERT … ON DUPLICATE KEY UPDATE`).
- **Nothing to count:** robots, link previews, `HEAD` requests and prefetching (`Sec-Purpose: prefetch`) are not counted.
- **Permanent links** answer with 301 and may be kept by browsers for a day – repeat visits then never reach the server.
- **Variants by device and system** are decided from the client hint browsers send anyway (`Sec-CH-UA-Platform`) or the user agent – no extra request.
- **Variants by screen width** need the browser: a page of about 1 KB with one inline script (allowed by its hash in a strict `Content-Security-Policy`, nothing else may load), `location.replace()` so it does not stay in the history, and a `noscript` fallback to the normal target.

### Further domains

A network-wide table `{base_prefix}cleansl_routes` (host, short address → site, link) holds the further domains of each link. A request on a host first looks at its own site; if the short address is unknown there, the routes table answers and the link is handled inside its own site (`switch_to_blog()`), where the visit is counted. Saving a link writes its routes; a short address that is already taken on that host – by the site there or another route – is left out and shown as taken. The home page of a site marked as short domain leads to the main site.

## Resolving a link

`CleanShortLinks\resolve()` is a pure function (tested in `tests/run.php`):

1. Paused → 404.
2. Before the start (date, or publication of the target post) → fallback or 404.
3. After expiry → fallback or 410.
4. First matching rule → its target; otherwise the main target.
5. Target not available (draft, deleted) → fallback or 404.
6. Main target only: the first fitting variant by device, operating system and screen width (`variant_plan()`, also pure and tested).

## Data

Three tables per site:

| Table | Content |
|---|---|
| `{prefix}cleansl_links` | one row per link: target, main and QR short address, kind, short form flag, time window, fallback, rules (JSON), QR design (JSON), status |
| `{prefix}cleansl_slugs` | every short address in use: `main`, `qr`, `alias`, `retired` – one namespace, so no address is ever handed out twice |
| `{prefix}cleansl_clicks` | visits added up per day, link, source, device and referring host |

Options: `clean_short_links_site` (per site), `clean_short_links_network` (site option in a network), `clean_short_links_db` (schema version; tables are created on the first request of every site, including sites added later).

A daily cron event `clean_short_links_prune` removes statistics older than the configured retention.

## QR encoder

`assets/qr.js` implements ISO/IEC 18004 for versions 1–10, all error correction levels, alphanumeric and byte (UTF-8) mode and automatic mask selection. It picks the smallest version for the requested level and then the strongest level that fits that version. `tests/qr.test.js` checks it against the reference codewords of the "HELLO WORLD" 1-Q example.

## Hooks for developers

| Hook | Type | Purpose |
|---|---|---|
| `clean_short_links_capability` | filter | capability to manage links (default `edit_others_posts`) |
| `clean_short_links_redirect` | filter | change the target URL right before redirecting – `( $url, $link, $source )` |
| `pre_get_shortlink` | WordPress filter | used to return the short link for posts and pages |

REST routes (namespace `clean-short-links/v1`, for logged-in editors): `GET /suggest`, `GET /check`, `GET|POST /post/<id>`.

## Privacy

No IP addresses, cookies, user IDs, user agents or full referrer URLs are stored – only daily totals per link, source (link, QR code, short form, link-in-bio) and device class, and optionally the host of the referring website. The network can switch referring hosts off; visitors who send Global Privacy Control (`Sec-GPC: 1`) or Do Not Track are always counted without it. Device and operating system for variants are only looked at, never stored. Redirects send `Referrer-Policy: strict-origin-when-cross-origin`, so targets see at most the short domain, not the full short address with its parameters. The plugin makes no external requests; the QR code is generated in the browser.
