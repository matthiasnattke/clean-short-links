# How it works

## Request flow

```
GET /s/blue-couch ─┐
GET /q/blue-couch ─┤  parse_request (priority 1)
                   ├─ found + live  → count visit → 302 to target, exit
                   ├─ not live      → 404 page (or 410 for expired / retired)
                   └─ unknown       → WordPress continues normally

GET /blue-couch ───── WordPress resolves the request first
                      └─ only if it is a 404: template_redirect (priority 0)
                         └─ link with short form enabled → as above
```

Prefixed links are handled before templates, maintenance plugins and canonical redirects. The short form only runs after WordPress decided there is no content at that address, so it can never hide a page, post or archive.

## Resolving a link

`CleanShortLinks\resolve()` is a pure function (tested in `tests/run.php`):

1. Paused → 404.
2. Before the start (date, or publication of the target post) → fallback or 404.
3. After expiry → fallback or 410.
4. First matching rule → its target; otherwise the main target.
5. Target not available (draft, deleted) → fallback or 404.

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

No IP addresses, cookies, user IDs or full referrer URLs are stored – only daily totals. The plugin makes no external requests; the QR code is generated in the browser.
