# Clean Short Links

Short links for WordPress posts, pages and any URL – on your own domain, with time windows, targets that change by date and time, QR codes with your logo and privacy-friendly statistics. Multisite-ready.

```
example.com/s/blue-couch     link
example.com/q/blue-couch     QR code (counted separately)
example.com/blue-couch       optional short form – only where the site has no content
```

## Features

- **Short addresses:** random, memorable, from the title or your own; new address any time, old one kept as alias or retired.
- **Time windows:** start at a date or on publication, expiry date, fallback target.
- **Rules:** different targets by date range, weekday and time of day – the first matching rule wins.
- **QR codes:** built-in encoder (versions 1–10, compact alphanumeric mode), logo from the media library, colours and shapes, SVG/PNG export.
- **Statistics:** daily totals per link, source, device and referring host – no IP addresses, no cookies.
- **Editor:** block editor panel, automatic links on publishing, `wp_get_shortlink()` integration.
- **Network:** every site has its own links; the network admin shows all sites and sets prefixes and rules.

## Languages

The plugin is written in English. A complete German translation is included; WordPress picks it automatically on German sites. Memorable short addresses use English or German words to match the site language. More languages are welcome – see [CONTRIBUTING.md](CONTRIBUTING.md#translations).

## Documentation

- [User guide](docs/user-guide.md) – creating links, time windows, rules, QR codes, statistics, settings
- [How it works](docs/how-it-works.md) – request flow, data, QR encoder, hooks, privacy

## Development

```sh
php tests/run.php          # PHP tests, no dependencies
node tests/qr.test.js      # QR encoder tests
node tools/i18n.js build   # translations
node tools/guidelines.js   # WordPress.org plugin guidelines
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for branches, releases and translations.

## License

GPL-2.0-or-later
