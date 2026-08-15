# WPS-Cache developer API

WPS-Cache 0.3 uses normal WordPress actions and filters. APIs run in the current
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

// For a public cached page whose fragment must stay dynamic:
echo FragmentCache::placeholder('account-summary');
```

The default group is `wps-cache-fragments`. It uses the selected persistent
Redis/Memcached drop-in when active and WordPress's runtime object cache
otherwise. With Fragment hole punching enabled, `placeholder()` emits a signed
element that the footer loader replaces through `/wps-cache/v1/fragment`. Supply
markup using the dynamic `wpsc_render_fragment_{key}` filter. Responses are
same-origin, no-store, rate-limited, and intended only for public fragments.

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
- `wpsc_media_offloaded(int $attachmentId, array $manifest)` reports verified remote objects.
- `wpsc_delete_offloaded_media(int $attachmentId, array $manifest)` lets a custom provider delete its objects.
- `wpsc_alt_text_error(int $attachmentId, string $message)` reports a built-in vision request failure without exposing the API key.

## Provider filters

- `wpsc_optimize_image_file(?array $result, string $file, Settings $settings, int $attachmentId)` can replace local Imagick/GD processing. Return `null` for the built-in encoder.
- `wpsc_image_crop_focus(array $focus, int $width, int $height)` supplies focal coordinates from `0.0` to `1.0`.
- `wpsc_generate_image_alt_text(string $description, string $file, int $attachmentId)` supplies optional alt text before the built-in provider. Return a non-empty string to avoid any OpenAI request.
- `wpsc_image_optimizer_capabilities(array $capabilities)` extends diagnostics.
- `wpsc_offload_media_file(?array $result, string $file, int $attachmentId, array $metadata)` uploads through a custom storage provider. Return `['url' => 'https://...', 'provider_key' => '...', 'provider' => 'my-provider', 'verified' => true]` after durable storage confirms the write.
- `wpsc_render_fragment_{key}(string $markup)` renders a named hole-punched fragment.

## Optional binaries and credentials

- `WPSC_JPEGTRAN_BINARY` must be an absolute trusted jpegtran executable path.
- `WPSC_GHOSTSCRIPT_BINARY` must be an absolute trusted Ghostscript executable path.
- `WPSC_PYFTSUBSET_BINARY` must be an absolute trusted pyftsubset executable path.
- `WPSC_S3_ACCESS_KEY` and `WPSC_S3_SECRET_KEY` override stored S3-compatible credentials.
- `WPSC_OPENAI_API_KEY` overrides the stored OpenAI API key.

The executable integrations use argument arrays without a shell and validate
their outputs. S3 uses HTTPS path-style AWS Signature Version 4 PUT/DELETE
requests. The built-in vision adapter calls the Responses API only when both alt
generation and OpenAI are explicitly enabled, skips unsupported/animated/large
inputs, requests low-detail analysis, and writes only missing alt fields.

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

WebP, AVIF, PDF, animated-image processing, lossless JPEG, WOFF2 subsetting,
Brotli, Redis TLS, Memcached, server-cache purging, global edge caching, S3,
PageSpeed, and OpenAI depend on the relevant extension, binary, server, provider,
or account. Check Tools & Diagnostics and Image Engine rather than assuming a
codec or service is available. A local plugin can integrate with a CDN, but it
cannot itself supply a worldwide point-of-presence network.
