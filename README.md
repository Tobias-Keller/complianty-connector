# Complianty Connector

WordPress plugin that connects a site to a [Complianty](https://complianty.de/) account. It shows the scan status in the admin, can start a scan, and outputs the approved privacy policy from the account through a shortcode or a block.

The plugin directory text lives in [readme.txt](readme.txt). This file is for developers.

- WordPress 6.3 or newer, PHP 7.4 or newer, GPL-2.0-or-later
- No runtime dependencies. `vendor/` in a release holds only the Composer autoloader.
- No build step for JavaScript. `assets/admin.js` and `blocks/dse/edit.js` are the source.

## How it works

| Part | Where |
|---|---|
| Only place that talks to the service | `src/Api/Client.php` (enforced by a PHPCS rule) |
| Response validation, whitelist per endpoint | `src/Api/Schema.php`, `src/Api/Parser.php` |
| Token storage (constant or libsodium encrypted option) | `src/Storage/TokenStore.php`, `src/Security/Crypto.php` |
| Privacy policy filtering (`wp_kses`, narrow whitelist) | `src/Frontend/DseRenderer.php` |
| Shortcode `[complianty_dse]`, block `complianty/dse` | `src/Frontend/` |
| Settings page, status, free check | `src/Admin/` |
| REST routes for the admin screen | `src/Rest/` |

Rules that the code and the tests hold to:

- Nothing is sent before a token is entered. The optional free check sends only `home_url()`, only after a click, and has no address field.
- The token travels only in the `Authorization` header. It is never logged, shown, stored in plain text or put into HTML or JavaScript.
- Everything from the service is untrusted: validated against a schema, length capped, escaped on output. The privacy policy HTML is filtered with a narrow whitelist when it is stored and again when it is output.
- Visitors never contact the service. With a local copy present there is no synchronous remote call on a page view.
- Failures pause further requests with a backoff (5 minutes, doubling up to 6 hours).

## Development

```bash
composer install
composer check        # PHPCS (WordPress Coding Standards), PHPStan level 6, unit tests
```

Integration tests run against real WordPress inside [wp-env](https://github.com/WordPress/gutenberg/tree/trunk/packages/env) (Docker and Node needed):

```bash
npm ci
npx wp-env start
npm run test:integration      # WordPress test suite, 370+ tests incl. XSS and attack scenarios
```

Other WordPress or PHP versions: `WP_ENV_CORE=https://wordpress.org/wordpress-6.3.zip WP_ENV_PHP_VERSION=7.4 npx wp-env start --update`.

For the Plugin Check tool, build the release tree first, then:

```bash
bin/build-release.sh
npm run plugin-check
```

To point the plugin at another API during development, define `COMPLIANTY_API_BASE` (https only, ignored when `WP_ENVIRONMENT_TYPE` is `production`).

## Release

1. Add a changelog entry to `readme.txt`, then `php bin/version.php set 1.2.3` (updates the plugin header, the constant, `Stable tag` and `composer.json`).
2. Commit, tag `v1.2.3`, push the tag.
3. The `Release` workflow checks that the tag matches the version, runs all checks, builds the ZIP with `composer install --no-dev --classmap-authoritative`, runs Plugin Check on it, publishes the ZIP with its SHA-256 checksum, and, after approval of the `wordpress-org` environment, deploys to the WordPress.org SVN (`trunk` and `tags/1.2.3`, assets from `.wordpress-org/`).

`bin/build-release.sh` builds the same ZIP locally and fails if it contains tests, VCS files, dev packages or anything else it should not.

Required repository secrets: `SVN_USERNAME`, `SVN_PASSWORD`. Use 2FA on the WordPress.org account.

## Security

See [SECURITY.md](SECURITY.md).
