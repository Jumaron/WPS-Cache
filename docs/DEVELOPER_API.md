# WPS-Cache developer API

WPS-Cache 0.2 uses normal WordPress actions and filters. APIs run in the current
site context on multisite.

## Fragment cache

```php
use WPSCache\Cache\Fragment\FragmentCache;

$markup = FragmentCache::remember(
    'homepage-products-v2',
    static fn (): string => render_expensive_products(),
    10 * MINUTE_IN_SECONDS,
);

FragmentCache::forget('homepage-products-v2');
```

The default group is `wps-cache-fragments`. It uses persistent Redis when that
drop-in is active and WordPress's runtime object cache otherwise. This is PHP
fragment caching, not edge-side includes.

Logged-in role caching is deliberately disabled unless `WPSC_PRIVATE_CACHE_DIR`
is defined in `wp-config.php` as an absolute directory outside the public web
root. Allowed roles then receive per-user-and-role disk variants; they are never
eligible for the public early-serving drop-in.

## Cache lifecycle actions

- `wpsc_cache_cleared(bool $success, list<string> $errors)` fires after a full purge.
- `wpsc_cache_url_cleared(string $url, bool $success)` fires after targeted invalidation.
- `wpsc_url_purged(string $url)` reports removal of a page-cache directory.
- `wpsc_asset_unloaded(string $handle, string $type, string $path, string $role)` reports a Script Manager rule.
- `wpsc_image_optimized(string $file, int $savedBytes, list<string> $variants, int $attachmentId)` reports media work.
- `wpsc_image_restored(string $file)` reports an original restore.

## Provider filters

- `wpsc_optimize_image_file(?array $result, string $file, Settings $settings, int $attachmentId)` can replace local Imagick/GD processing. Return `null` for the built-in encoder.
- `wpsc_image_crop_focus(array $focus, int $width, int $height)` supplies focal coordinates from `0.0` to `1.0`.
- `wpsc_generate_image_alt_text(string $description, string $file, int $attachmentId)` supplies optional alt text. WPS-Cache never sends an image to an AI service itself.
- `wpsc_image_optimizer_capabilities(array $capabilities)` extends diagnostics.

## REST and WP-CLI

The REST cache accepts route wildcards and never caches mutations, failures,
authenticated requests, or `/wp/v2/users` by default.

```text
wp wps-cache purge [--url=<url>]
wp wps-cache preload [--limit=<count>]
wp wps-cache images [--path=<file-or-folder>] [--restore]
wp wps-cache database [<cleanup-key>...] [--all]
wp wps-cache status
```

## Conditional capabilities

WebP, AVIF, PDF, animated-image processing, Brotli, Redis TLS, server cache
purging, edge caching, and provider-generated alt text depend on the relevant
extension, server, provider, or account. Check Tools & Diagnostics and Image
Engine rather than assuming a codec or service is available.
