# Clean Short Links

<p align="center">
  <a href="https://couch-blog.de/projekte/clean-short-links/"><img src="https://img.shields.io/badge/WordPress-Plugin-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress plugin"></a>
  <a href="https://github.com/matthiasnattke/clean-short-links/releases/latest"><img src="https://img.shields.io/github/v/release/matthiasnattke/clean-short-links?style=for-the-badge&label=Release" alt="Release"></a>
  <img src="https://img.shields.io/badge/WordPress-6.4%2B-21759B?style=for-the-badge" alt="WordPress 6.4+">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.0+">
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-GPL--2.0--or--later-blue?style=for-the-badge" alt="License: GPL-2.0-or-later"></a>
  <a href="https://buymeacoffee.com/couch"><img src="https://img.shields.io/badge/Buy%20me%20a-coffee-FFDD00?style=for-the-badge&logo=buymeacoffee&logoColor=black" alt="Buy me a coffee"></a>
</p>

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
- **Wallet cards:** with Clean Mobile Connect, a short link and each location can be offered as an Apple Wallet card (`/w/…`); title, image and QR code follow the link. Works fully without Connect.
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
