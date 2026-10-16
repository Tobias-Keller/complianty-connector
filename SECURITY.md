# Security Policy

Please report security issues privately by e-mail to mail@complianty.de.

Do not open public issues for vulnerabilities. We acknowledge reports within 3 working days.

## Supported versions

The latest release on WordPress.org.

## What is in scope

- Handling of the connection token (storage, transport, display, logs).
- Output of data from the service in the admin and on the website (XSS).
- Capability and nonce checks of the admin pages and REST routes.
- Anything that makes the plugin contact a host other than the Complianty service, or send more than the token and the plugin version.

## Token model

The token belongs to one website and one integration. It is stored encrypted (libsodium, key derived from the WordPress `auth` salt) or provided by the constant `COMPLIANTY_API_TOKEN`. The encryption protects against leaks of the database alone, not against access to `wp-config.php`. A leaked token can be revoked in the Complianty account at any time.

Other plugins can observe outgoing HTTP requests through WordPress hooks (for example debugging tools that list requests with their headers). That is a property of the WordPress HTTP API. Do not run such tools on production sites.
