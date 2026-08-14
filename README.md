# WPS-Cache

WPS-Cache is an experimental, dependency-free WordPress performance plugin with
static HTML caching, Redis object-cache integration, Varnish purging, asset and
frontend optimizations, database maintenance, and WordPress bloat controls.

The 0.1 architecture separates WordPress bootstrap, configuration, cache layers,
HTML processing, infrastructure mutations, integrations, schedules, maintenance,
and admin code. See [the architecture guide](docs/ARCHITECTURE.md) for the object
graph and contribution boundaries.

> This plugin is experimental. Test releases on staging before production use.

## Requirements

- WordPress 6.3 or newer
- PHP 8.3 or newer
- Optional: phpredis for persistent Redis caching
- Optional: Varnish for reverse-proxy caching
- Apache or LiteSpeed for generated direct-serving rules; PHP drop-in serving
  remains available on other servers

## Repository layout

```text
WPS-Cache/   Distributable WordPress plugin
docs/        Architecture documentation
tests/       Unit and integration tests
tools/       Lint and deterministic build scripts
dist/        Versioned release ZIPs and checksums
```

## Development

The runtime uses its own small PSR-4 autoloader and ships without dependencies.
The test and build commands also work without Composer:

```bash
php tools/lint.php
php tests/run.php
php tools/build.php
```

Optional static analysis uses Composer development dependencies:

```bash
composer install
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=2G
```

The test suite includes subprocess coverage of the standalone early page-cache
drop-in. The release builder validates version consistency and creates a
byte-for-byte reproducible ZIP plus SHA-256 checksum in `dist/`.

## Installation

1. Build or download `dist/wps-cache-<version>.zip`.
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload and activate the ZIP.
4. Configure **WPS Cache** in the WordPress admin.

Activation prepares the runtime cache directories, safely installs the owned
`advanced-cache.php` drop-in, enables `WP_CACHE` when possible, and adds
Apache/LiteSpeed rules only when the detected server supports them. Existing
third-party drop-ins are never overwritten.

## License

GPL-2.0-or-later.
