# WPS-Cache architecture

## Design goals

The runtime remains dependency-free and compatible with WordPress drop-in
loading. Development tools may use Composer, but no `vendor/` directory is
required or shipped. Components receive configuration and collaborators through
constructors; only the composition root constructs the object graph.

## Source layout

```text
WPS-Cache/
├── assets/                         Admin CSS and JavaScript
├── includes/                       Standalone WordPress drop-in templates
├── src/
│   ├── Admin/                      Admin page, actions, settings, metrics
│   ├── Bootstrap/                  Composition root
│   ├── Cli/                        WP-CLI command boundary
│   ├── Cache/                      Cache registry and concrete cache layers
│   │   ├── Fragment/               PHP fragments and signed hole-punch endpoint
│   │   ├── Object/                 Redis and Memcached integrations
│   │   ├── Page/                   Static HTML page cache
│   │   ├── Rest/                   Anonymous REST response caching
│   │   └── ReverseProxy/           Varnish and Nginx integrations
│   ├── Config/                     Immutable settings and repository
│   ├── Contracts/                  Small module/processor contracts
│   ├── Infrastructure/             Filesystem, server headers, drop-in, wp-config I/O
│   ├── Integration/                Third-party compatibility boundaries
│   ├── Lifecycle/                  Activation, settings changes, deactivation
│   ├── Maintenance/                Database maintenance
│   ├── Monitoring/                 Local RUM, lab timing, and uptime checks
│   ├── Optimization/               Asset, HTML, navigation, and WP optimizers
│   ├── Scheduling/                 Preload and maintenance schedules
│   └── Support/                    Shared low-level implementation helpers
├── uninstall.php                   Dependency-free uninstall routine
└── wps-cache.php                   Metadata, constants, autoload, bootstrap
```

Repository-only concerns live outside the distributable plugin:

```text
docs/       Architecture and engineering documentation
tests/      Zero-dependency unit and integration tests
tools/      Lint and deterministic build commands
dist/       Versioned ZIP archives and SHA-256 checksums
```

## Runtime flow

1. `wps-cache.php` validates PHP, defines paths/version, registers the PSR-4
   fallback autoloader, and invokes `Bootstrap\Application`.
2. `Application` loads one immutable `Settings` instance and explicitly wires
   cache layers, optimizers, integrations, schedules, lifecycle, and admin code.
3. `CacheManager` boots and purges only modules that own cache state. CSS and
   JavaScript minifiers are optimization modules, not fake key/value drivers.
4. `PageCache` starts the outer response buffer. `FrontendOptimizer` starts an
   inner buffer during `template_redirect`; its output is cached when page cache
   is enabled and still works when page cache is disabled.
5. Activation and settings updates generate `runtime.php` for the standalone
   page-cache drop-in. Redis and Memcached share a selectable, mode-restricted
   early configuration boundary because `object-cache.php` loads before plugins
   and cannot read normal options safely. Only one persistent backend is active.
6. Filesystem, drop-in, and `wp-config.php` mutations are isolated in
   infrastructure services and guarded by ownership checks. The plugin does not
   write web-server configuration; upgrades only remove legacy owned blocks.
7. The lifecycle reconciler refreshes owned drop-ins once per version upgrade
   and removes early page-cache serving when that layer is disabled.
8. Query canonicalization and device classification are shared policy objects;
   the standalone drop-in receives their validated scalar/list configuration.
9. Rendered-CSS and adaptive-image endpoints use same-origin paths, bounded
   payloads, daily HMAC signatures, immutable generated assets, and cache-owned
   directories. Browser profiles are split by page and device class.
10. Image work is local-first and provider-extensible. Encoder diagnostics report
   actual Imagick/GD/jpegtran/Ghostscript support rather than assuming codecs.
   Background work is bounded and resumable; the source-preserving mode creates
   delivery variants without rewriting originals.
11. External integrations are explicit adapters. S3-compatible media offload and
   OpenAI missing-alt generation are disabled by default, retain secrets on blank
   saves, omit credentials from exports, and permit wp-config.php constants.
12. Local RUM feeds learned above-fold media into delivery. PageSpeed provides
   optional Lighthouse lab data, while the local uptime job can emit a dead-man
   heartbeat so complete origin failure is externally observable.
13. Admin rendering is split between core settings and capability-focused
    feature screens. Runtime modules do not depend on either renderer.

## Architectural rules

- Do not call the application singleton from feature code.
- Do not read `wpsc_settings` outside `Config` or narrowly scoped admin display
  code; pass `Settings` or normalized arrays through constructors.
- A `Module` registers hooks; a `Purgeable` owns cache state; an
  `HtmlProcessor` transforms a document. Implement only the contracts that
  describe real behavior.
- Drop-ins must remain procedural and dependency-free because WordPress loads
  them before normal plugin bootstrap.
- New filesystem writes belong in `Infrastructure` or a tested filesystem
  helper and should use atomic replacement.
- Every bug fix or module should include a unit or integration regression test.

## Quality and release commands

```bash
php tools/lint.php
php tests/run.php
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=2G
php tools/build.php
```

The build is rejected if the plugin header, `WPSC_VERSION`, and WordPress.org
`Stable tag` do not match. ZIP entries are sorted and use a fixed timestamp,
making repeated builds byte-for-byte reproducible.
