# Contributing to Clean Short Links

Thanks for helping! Whether it is a bug report, an idea, a pull request or a translation – every contribution counts.

## Branches

| Branch | Purpose |
|---|---|
| `dev` | Ongoing development. **Pull requests go here.** |
| `beta` | Versions being tested on real sites before a release. Pre-release tags like `v0.2.0-beta.1` live here. |
| `main` | Released versions only. Every commit on `main` carries a version tag like `v0.2.0`. |

Changes flow `dev` → `beta` → `main`.

## Pull requests

1. Fork the repository and create a branch from `dev`.
2. Make your change. Keep it small and focused; follow the [WordPress coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
3. Run the checks locally:

   ```sh
   php tests/run.php          # tests, no dependencies needed
   node tools/i18n.js build   # only if you changed texts
   node tools/guidelines.js   # WordPress.org plugin guidelines
   ```

4. Open the pull request against `dev`.

Every pull request is checked automatically by GitHub Actions:

- **PHP syntax and tests** on PHP 8.0 to 8.4,
- **Translations**: the template is up to date and every bundled language is complete,
- **Plugin guidelines**: `tools/guidelines.js` checks the shipped plugin against the machine-checkable parts of the [WordPress.org Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) – GPL license, readable code, no tracking or external requests, no remote scripts or CDNs, no credit links, no dashboard hijacking, readme rules, version numbers, trademarks,
- **WordPress Plugin Check**, the [official tool](https://wordpress.org/plugins/plugin-check/) used for the WordPress.org plugin directory.

The release workflow runs the same checks again on the package before anything is published – a release with a failing check is never created.

## Versions and tags

Version numbers follow [Semantic Versioning](https://semver.org): `MAJOR.MINOR.PATCH`. While the plugin is in the `0.x` range, minor versions may still change behaviour.

The version appears in three places – plugin header, the constant `CLEAN_SHORT_LINKS_VERSION` and `Stable tag` in `readme.txt`. Never edit them by hand, use:

```sh
tools/version.sh 0.2.0
```

It updates all three and adds a changelog entry to `readme.txt` for you to fill in. The tests fail if the numbers ever disagree.

Releasing (maintainers):

```sh
# Pre-release for testing, on beta
git checkout beta && git merge --ff-only dev
git tag -a v0.2.0-beta.1 -m "Clean Short Links 0.2.0 beta 1"
git push origin beta v0.2.0-beta.1

# Final release, on main
git checkout main && git merge --ff-only beta
git tag -a v0.2.0 -m "Clean Short Links 0.2.0"
git push origin main v0.2.0
```

Pushing a tag starts the release workflow: it checks that the tag matches the plugin version (final versions must be on `main`), runs the tests, builds the installable ZIP and creates a GitHub release – marked as pre-release for tags with a suffix. Once the plugin is listed on WordPress.org and the secrets `SVN_USERNAME` and `SVN_PASSWORD` are set, final versions are deployed there automatically, including icon and banner from `.wordpress-org/`.

## Translations

All texts in the plugin are in English and translatable (text domain `clean-short-links`). German is included.

### On translate.wordpress.org (preferred, once listed)

As soon as Clean Short Links is listed in the WordPress.org plugin directory, translations happen on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/clean-short-links/). That is the easiest way: no tools, no GitHub account – just a WordPress.org account. WordPress then delivers the translation to every site automatically.

### Here on GitHub

Until then – or if you prefer GitHub – you can add a translation here. Pick one of the two ways:

**With Poedit (or any gettext editor)**

1. Open [`languages/clean-short-links.pot`](languages/clean-short-links.pot) in [Poedit](https://poedit.net) and create a new translation for your language.
2. Save it as `languages/clean-short-links-<locale>.po` – for example `clean-short-links-fr_FR.po` or `clean-short-links-nl_NL.po`. Poedit writes the matching `.mo` file next to it.
3. Open a pull request against `dev` with both files.

**With a JSON file (what the bundled German uses)**

1. Copy [`languages/de_DE.json`](languages/de_DE.json) to `languages/<locale>.json`, e.g. `fr_FR.json`.
2. Replace each German value with your translation. Keys are the English originals – leave them as they are. Plurals are lists, for example `"%d site changed.": ["%d site modifié.", "%d sites modifiés."]`. If your language has other plural rules, add `"_plural_forms": "nplurals=…; plural=…;"`.
3. See what is still missing and build the files:

   ```sh
   node tools/i18n.js missing fr_FR
   node tools/i18n.js build
   ```

4. Open a pull request against `dev` with the `.json`, `.po` and `.mo` files.

Some tips:

- Texts with `%s`, `%d` or `%1$s` are placeholders – keep them; with numbered ones you may change the order.
- Comments starting with `translators:` in the template explain what a placeholder stands for.
- Use the informal or formal address that is common for WordPress in your language.

### Improving an existing translation

Spotted a clumsy wording? Edit the language's `.json` file (or `.po` file), run `node tools/i18n.js build` and open a pull request – or simply [open an issue](https://github.com/matthiasnattke/clean-short-links/issues) with the better wording.

## Reporting bugs

Please [open an issue](https://github.com/matthiasnattke/clean-short-links/issues) with your WordPress and PHP version, whether it is a single site or multisite, and the steps to reproduce.

## License

By contributing you agree that your contribution is licensed under [GPL-2.0-or-later](LICENSE).
