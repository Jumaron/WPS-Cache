# WPSeiten / WPS-Cache — complete market feature comparison

Research date: **2026-08-14**  
Local version audited: **WPS-Cache 0.0.4 (experimental)**  
Comparison basis: shipped local PHP code plus current official vendor documentation.

## Executive conclusion

WPSeiten is already a broad **local caching and WordPress-tuning plugin**, but it is not yet a full image-optimization product. Its strongest differentiators are the unusual combination of static HTML caching, Redis object caching, tag-aware Varnish purge, precomputed Gzip/Brotli page variants, ETag/304 handling, local Google Fonts, WordPress bloat controls, database maintenance, and modern Speculation Rules navigation.

Its largest market gaps are:

1. **No actual image compression or conversion:** no lossy/lossless compression, bulk optimizer, upload-time optimizer, original backup/restore, WebP generation, AVIF generation, or adaptive image CDN.
2. **The UI cache lifetime is not effective:** both early-serving paths hard-code 3,600 seconds.
3. **Frontend transformations depend on page caching:** delay/defer JS, inline unused-CSS pruning, lazy loading, image dimensions, YouTube facade, Google Font localization, and CDN rewriting run inside the HTML-cache output pipeline. Turning page caching off also turns these features off in practice.
4. **WooCommerce protection is incomplete at the earliest cache layers:** the runtime cache writer checks WooCommerce pages and cookies, but the `advanced-cache.php` drop-in and Apache/LiteSpeed rewrite rules do not check WooCommerce cart/session cookies before serving an existing cached page.
5. **“Remove unused CSS” is much narrower than market usage:** it prunes only inline `<style>` blocks from the current DOM. It does not analyze linked stylesheets, generate per-page used CSS through a browser, or generate true critical CSS.
6. **Operational maturity trails leaders:** no WP-CLI, no demonstrated multisite support, no settings import/export despite the README claim, no safe/test mode, no real-user/Core Web Vitals monitoring, and no automated test suite found.

The best product direction is therefore not to copy every competitor. Preserve the strong local multi-layer cache core, then close the five table-stakes gaps: image pipeline, cache correctness, decoupled optimizations, true used/critical CSS, and production-grade operations.

## Scope and interpretation

“Every product on the market” is not literally finite: WordPress.org alone contains thousands of cache, minify, lazy-load, CDN, and image-conversion plugins, including many clones and abandoned projects. This report covers the products a buyer or product team is materially likely to compare in 2026:

- Full/cache/performance: **WP Rocket, LiteSpeed Cache, FlyingPress, NitroPack, W3 Total Cache, WP-Optimize, Hummingbird, Perfmatters, Autoptimize, SpeedyCache, Breeze, SiteGround Speed Optimizer, WP Super Cache, Cache Enabler, and Super Page Cache**.
- Dedicated image: **ShortPixel Image Optimizer, Imagify, EWWW Image Optimizer, Smush, Optimole, TinyPNG, Converter for Media, and reSmush.it**.
- Adjacent infrastructure that sets feature expectations: **Cloudflare APO/Images/Polish, Jetpack Site Accelerator, and Cloudinary**.

The report compares capabilities available in the product family, not free-plan entitlements or prices. Pricing changes too frequently and was not requested.

### Status legend

| Mark | Meaning |
|---|---|
| ✅ | Native, materially complete support verified in code or official documentation |
| ◐ | Partial, narrower than the row, host-dependent, SaaS-dependent, premium/add-on, or companion-product support |
| — | Not present, or not claimed in the cited official product documentation |
| ⚠ | Present but with a material correctness, scope, or coupling caveat |

For competitors, “—” means **not verified as a product feature in the cited official sources**; it does not claim that no custom code or third-party integration could provide it.

## A. WPSeiten shipped-feature audit

This is the authoritative inventory for the local plugin. “Market reference” names products that implement the fuller version of a partial/missing capability.

### A1. Page caching and delivery

| # | Feature | WPSeiten | Actual implementation / limitation | Market reference |
|---:|---|:---:|---|---|
| 1 | Static HTML full-page cache | ✅ | Writes page HTML to disk by host/path | Nearly every cache suite |
| 2 | WordPress `advanced-cache.php` drop-in | ✅ | Can serve cache before normal WordPress bootstrap | WP Rocket, W3TC, Super Page Cache |
| 3 | Direct web-server cache serving | ◐ | Apache/LiteSpeed rewrite rules; no generated Nginx config | LiteSpeed Cache, W3TC, host caches |
| 4 | Configurable cache lifetime | ⚠ | UI accepts TTL, but drop-in, response headers, and rewrite rules hard-code 3,600 seconds | WP Rocket, FlyingPress, Hummingbird |
| 5 | Automatic cache purge on post save | ✅ | Clears every registered local driver, not only affected URLs | LiteSpeed tag purge, FlyingPress related-page purge |
| 6 | Automatic purge on comments | ✅ | Full local driver flush | WP Rocket, Hummingbird |
| 7 | Automatic purge on theme/plugin changes | ✅ | Full purge plus selected OpCache invalidation | Most full suites |
| 8 | Manual purge all | ✅ | Admin toolbar/action | Universal |
| 9 | Manual purge by cache layer | ✅ | HTML, Redis, and Varnish handlers exist | W3TC, LiteSpeed Cache |
| 10 | Per-URL local page-cache purge | — | No targeted HTML-file purge API/UI | FlyingPress, WP Rocket, Super Page Cache |
| 11 | Tag-aware Varnish purge | ✅ | Post, archive, and term cache tags via PURGE headers | LiteSpeed tags/ESI, W3TC Varnish |
| 12 | Varnish full purge | ✅ | Regex/tag purge request | WP Rocket, Hummingbird, W3TC |
| 13 | Cache preload/warmup | ◐ | Manual queue up to 200 posts/pages/products; scheduled queue only homepage plus 50 recent entries | WP Rocket, FlyingPress, sitemap crawlers |
| 14 | Scheduled preload | ✅ | Hourly/daily/weekly setting; desktop and mobile requests | LiteSpeed crawler, WP Super Cache preload |
| 15 | Sitemap-driven complete preload | — | Uses WP queries rather than sitemaps; hard limits omit large-site long tail | WP Rocket, W3TC, Super Page Cache |
| 16 | Separate mobile cache | ✅ | `-mobile` variant selected by user-agent regex | WP Rocket, FlyingPress, Cloudflare APO device cache |
| 17 | Tablet-specific cache | — | Mobile/desktop only | Cloudflare APO, Hummingbird APO |
| 18 | Query-string cache variants | ◐ | PHP drop-in hashes sorted parameters; Apache/LiteSpeed rewrite path bypasses all query strings | W3TC, FlyingPress |
| 19 | Ignore tracking query parameters | — | No canonical ignore list for UTM, `fbclid`, etc. | WP Rocket, FlyingPress, Cloudflare |
| 20 | Query-string denial/allow list | — | Only four hard-coded bypass parameter names | Hummingbird, Super Page Cache |
| 21 | URL cache exclusions | ✅ | User-entered values compiled into a regex | Universal |
| 22 | Cookie-based bypass | ◐ | WordPress login/comment/password cookies at early layer; Woo cookies only at runtime | FlyingPress, Super Page Cache |
| 23 | Custom cookie bypass list | — | No UI/filter-driven list | FlyingPress, LiteSpeed Cache, Super Page Cache |
| 24 | User-agent exclusions | — | Static-extension guard and mobile detection only | W3TC, SpeedyCache |
| 25 | Logged-in-user bypass | ✅ | Logged-in users bypass runtime and early cache | Universal safe default |
| 26 | Cache for logged-in users/roles | — | No private/user-role cache | LiteSpeed Cache, FlyingPress, WP Rocket User Cache |
| 27 | WooCommerce sensitive-page bypass | ◐ | Runtime excludes cart, checkout, account, and WC API; early cache is not Woo-cookie aware | Most commercial suites |
| 28 | WooCommerce session/cart-cookie bypass | ⚠ | Runtime checks `woocommerce_items_in_cart` and `wp_woocommerce_session_*`; drop-in and rewrite rules can serve an existing cache before this check | FlyingPress, LiteSpeed Cache |
| 29 | Fragment cache / ESI | — | No dynamic-hole-punching layer | LiteSpeed ESI, W3TC Pro fragment cache |
| 30 | REST API response cache | — | No REST cache | W3TC Pro |
| 31 | Feed/search cache | ◐ | Page cache can write eligible GET HTML, but static extensions and special request behavior are not exposed as explicit controls | W3TC |
| 32 | Stale-while-revalidate / cache rebuild | — | Expired cache falls through synchronously | WP Super Cache rebuild, managed edge products |
| 33 | Cache stampede protection | — | Atomic writes prevent corruption but there is no request coalescing/lock around regeneration | Managed cache/CDN products |
| 34 | Precomputed Gzip page files | ✅ | `.html.gz` generated on cache write and negotiated at serve time | Cache Enabler, NitroPack/CDNs |
| 35 | Precomputed Brotli page files | ◐ | Generated only when PHP exposes `brotli_compress`; otherwise unavailable | Cache Enabler, FlyingCDN, LiteSpeed server |
| 36 | ETag and 304 responses | ✅ | File mtime/size-based ETag in drop-in | W3TC, Cache Enabler |
| 37 | `Content-Length` on cached response | ✅ | Set by early-serving drop-in | Advanced server caches |
| 38 | Browser cache policy for HTML | ✅ | `public, max-age=3600` | Most cache suites |
| 39 | Browser caching for static assets | — | No general CSS/JS/image expiry rules | W3TC, Hummingbird, SpeedyCache |
| 40 | Cache-status response header | ✅ | `X-WPS-Cache: HIT` on early/direct serves | Most mature cache products |

### A2. Object, server, CDN, and edge caching

| # | Feature | WPSeiten | Actual implementation / limitation | Market reference |
|---:|---|:---:|---|---|
| 41 | Persistent Redis object cache | ✅ | Bundled `object-cache.php` drop-in and Redis client | LiteSpeed Cache, W3TC, FlyingPress |
| 42 | Redis host/port/database/password/prefix | ✅ | UI plus `WP_REDIS_*` constants | Redis Object Cache, W3TC |
| 43 | Redis key signing / safe serialization | ✅ | Values are serialized and signed | Security-oriented differentiator |
| 44 | Redis compression | ✅ | Uses supported phpredis compression options | W3TC/Redis specialists |
| 45 | Redis group flush/multiple operations | ✅ | Modern WordPress cache capability functions exist | Redis Object Cache |
| 46 | Redis TLS/sentinel/cluster/replication | — | Single host/port model | Redis Object Cache Pro, enterprise stacks |
| 47 | Memcached object cache | — | Redis only | LiteSpeed Cache, W3TC, SiteGround |
| 48 | Separate database-query cache | — | Persistent object cache can reduce queries; no W3TC-style DB cache engine | W3TC |
| 49 | Varnish integration | ✅ | Adds cache tags/control headers and sends async PURGE | W3TC, WP Rocket |
| 50 | Nginx FastCGI-cache integration | — | No Nginx config/purge integration | Nginx Helper, host plugins |
| 51 | Static CDN URL rewriting | ✅ | Rewrites `src`, `href`, `srcset`, and lazy-load attributes for selected extensions | WP Rocket, Perfmatters, Autoptimize |
| 52 | Multiple CDN hostnames by asset type | — | One CDN base URL | WP Rocket, W3TC |
| 53 | Built-in CDN | — | User supplies CDN URL | NitroPack, FlyingCDN, QUIC.cloud |
| 54 | Cloudflare API authentication | ✅ | Zone ID plus API token | WP Rocket, Hummingbird, Super Page Cache |
| 55 | Cloudflare purge on local clear | ✅ | Purges the entire Cloudflare zone cache | Many Cloudflare integrations |
| 56 | Granular Cloudflare URL purge | — | Always `purge_everything` | FlyingPress, Super Page Cache |
| 57 | Configure Cloudflare full-page edge cache | — | Purge only; no Cache Rules/APO/Worker configuration | FlyingPress, Super Page Cache, APO |
| 58 | Full-page edge CDN | — | No edge HTML service | NitroPack, FlyingCDN, QUIC.cloud, APO |
| 59 | CDN cache metrics | — | No edge hit/miss/bandwidth reporting | NitroPack, Cloudflare, CDN services |

### A3. CSS, JavaScript, HTML, and navigation

| # | Feature | WPSeiten | Actual implementation / limitation | Market reference |
|---:|---|:---:|---|---|
| 60 | CSS minification | ✅ | Local enqueued files up to 1 MB; skips already-minified/external files | Most optimizer suites |
| 61 | CSS minification exclusions | ✅ | Handles/filename/path patterns | Most optimizer suites |
| 62 | CSS combination | — | No aggregation | LiteSpeed Cache, W3TC, Autoptimize |
| 63 | Remove unused CSS from linked stylesheets | — | Linked `.css` files are not analyzed | WP Rocket, FlyingPress, Perfmatters |
| 64 | Remove unused CSS from inline styles | ◐ | Server-side selector pruning of `<style>` blocks against current DOM | Rare local/no-SaaS differentiator, but risky/narrow |
| 65 | CSS safelist | ✅ | Built-in and user wildcard safelist | WP Rocket, Perfmatters, LiteSpeed Cache |
| 66 | True per-page critical CSS generation | — | Class name says CriticalCSS, but implementation is inline tree-shaking, not above-the-fold rendering analysis | WP Rocket, LiteSpeed/QUIC.cloud, NitroPack |
| 67 | Async/deferred non-critical CSS | — | No stylesheet-loading strategy | LiteSpeed Cache, Autoptimize, W3TC Pro |
| 68 | CSS delivery test/safe mode | — | No unpublished preview/rollback flow | Hummingbird Safe Mode, NitroPack Test Mode |
| 69 | JavaScript minification | ✅ | Local enqueued files up to 1 MB; conservative tokenizer | Most optimizer suites |
| 70 | JS minification exclusions | ✅ | Handle/path/filename patterns | Most optimizer suites |
| 71 | JavaScript combination | — | No aggregation | W3TC, LiteSpeed Cache, Autoptimize |
| 72 | Defer external JavaScript | ✅ | Adds `defer`; excludes critical scripts; lowers fetch priority | WP Rocket, Perfmatters |
| 73 | Defer inline JavaScript | — | Inline scripts are not handled by defer mode | Perfmatters, Autoptimize |
| 74 | Delay external JavaScript until interaction | ✅ | Replaces type/source and restores sequentially | WP Rocket, FlyingPress, Perfmatters |
| 75 | Delay inline JavaScript until interaction | ✅ | Inline blocks of 100+ bytes; 8-second fallback | WP Rocket, Perfmatters |
| 76 | Delay/defer exclusions | ✅ | Built-in critical list plus user patterns | Most commercial optimizers |
| 77 | Delay execution timeout | ◐ | Fixed eight seconds; no UI control | Perfmatters |
| 78 | Remove unused JavaScript/assets per page | — | No script manager or per-page dequeue UI | Perfmatters, Hummingbird, Super Page Cache |
| 79 | HTML minification | — | Cached HTML is not minified | NitroPack, W3TC, Autoptimize, Breeze |
| 80 | Self-host external CSS/JS | — | Google Fonts only | FlyingPress |
| 81 | Lazy-render below-fold HTML/elements | — | No `content-visibility`/selector feature | FlyingPress, Perfmatters, SpeedyCache |
| 82 | Speculation Rules prerender | ✅ | Same-origin moderate prerender with sensitive-path exclusions | Perfmatters, newer performance plugins |
| 83 | Hover/intersection prefetch fallback | ✅ | Legacy-browser fallback uses prefetch | WP Rocket Preload Links, FlyingPress |
| 84 | DNS prefetch UI | — | No user-defined domains | W3TC, SpeedyCache, SiteGround |
| 85 | Preconnect UI | — | No general user-defined domains | Perfmatters, SpeedyCache |
| 86 | Generic resource preload UI | — | No font/image/file URL list | Perfmatters, Hummingbird, SpeedyCache |

### A4. Images, iframes, video, and fonts

| # | Feature | WPSeiten | Actual implementation / limitation | Market reference |
|---:|---|:---:|---|---|
| 87 | Image lossy compression | — | No image encoder or cloud API | ShortPixel, Imagify, EWWW, Smush |
| 88 | Image lossless compression | — | No image encoder or cloud API | ShortPixel, Imagify, EWWW, TinyPNG |
| 89 | Smart/content-aware compression | — | None | Optimole, Imagify, TinyPNG |
| 90 | Automatic optimization on upload | — | None | All dedicated image optimizers |
| 91 | Bulk optimization of existing library | — | None | All dedicated image optimizers |
| 92 | Original-image backup and restore | — | None | ShortPixel, Imagify, EWWW, reSmush.it |
| 93 | Resize oversized uploads | — | Does not resize files | ShortPixel, Imagify, TinyPNG, SiteGround |
| 94 | Optimize thumbnails/image sizes selectively | — | None | ShortPixel, LiteSpeed Cache, TinyPNG |
| 95 | Optimize custom folders | — | None | ShortPixel, EWWW, Converter for Media |
| 96 | PDF optimization | — | None | ShortPixel, Imagify, EWWW Pro |
| 97 | Animated GIF optimization | — | None | ShortPixel, EWWW, NitroPack |
| 98 | EXIF preserve/strip control | — | None | ShortPixel, WP-Optimize, TinyPNG, reSmush.it |
| 99 | Generate WebP files | — | Recognizes/rewrites existing `.webp`, but never creates one | Most image optimizers |
| 100 | Generate AVIF files | — | Recognizes/rewrites existing `.avif`, but never creates one | LiteSpeed, Imagify, EWWW, Smush, Optimole |
| 101 | Browser-aware next-gen fallback | — | No format negotiation for images | Dedicated image optimizers/CDNs |
| 102 | Image CDN | ◐ | Generic CDN rewrite can deliver images, but does not transform/optimize them | Optimole, EWWW Easy IO, Smush CDN |
| 103 | On-the-fly adaptive resize | — | None | Optimole, NitroPack, Cloudinary, Cloudflare Images |
| 104 | Generate responsive `srcset`/`sizes` | — | Preserves/rewrites existing `srcset`; does not create it | FlyingPress, Optimole, Smush |
| 105 | Image lazy loading | ✅ | Native `loading="lazy"` plus `decoding="async"` | Nearly all frontend optimizers |
| 106 | CSS background-image lazy loading | — | `<img>` only | WP Rocket, Perfmatters, Optimole |
| 107 | Iframe lazy loading | ✅ | Native `loading="lazy"` | WP Rocket, FlyingPress, Smush |
| 108 | Video lazy loading | ◐ | Generic iframe lazy load; no native `<video>` handling | FlyingPress, Cloudinary |
| 109 | YouTube facade | ✅ | Replaces iframe with thumbnail/player bootloader | WP Rocket, FlyingPress, Perfmatters |
| 110 | Vimeo/Google Maps facade | — | YouTube only | Perfmatters, W3TC Pro |
| 111 | Missing image dimensions | ◐ | Local physical images only; runs only through HTML-cache pipeline | WP Rocket, FlyingPress, Perfmatters |
| 112 | Automatically detect above-fold/LCP image | — | First N images are assumed above-fold; no rendered viewport analysis | FlyingPress, NitroPack, WP Rocket |
| 113 | Configurable leading-image lazy-load exclusion | ✅ | Numeric first-image count, default three | Perfmatters |
| 114 | LCP image preload | ◐ | Marks leading images eager/high priority but does not emit `<link rel="preload">` | FlyingPress, NitroPack, Smush |
| 115 | LQIP/blur placeholder | — | None | LiteSpeed/QUIC.cloud, Optimole, Smush |
| 116 | Smart crop/focal subject | — | None | ShortPixel, Optimole, Cloudinary |
| 117 | Retina/DPR-aware delivery | — | None | Optimole, EWWW, Cloudinary |
| 118 | Watermarking | — | None | Optimole, EWWW Pro |
| 119 | Media offload/cloud library | — | None | Optimole, Cloudinary |
| 120 | AI alt text/captioning | — | None | ShortPixel (beta), media/DAM products |
| 121 | Localize Google Fonts | ✅ | Downloads Google CSS and font files to local cache | Perfmatters, FlyingPress, WP Rocket |
| 122 | `font-display: swap` | ✅ | Rewrites inline/downloaded `@font-face` rules | Most frontend optimizers |
| 123 | Font preload | — | No automatic or manual font preloads | WP Rocket, FlyingPress, Perfmatters |
| 124 | Font subsetting | — | None | NitroPack |
| 125 | Font conversion/compression to WOFF2 | — | None | NitroPack |
| 126 | System-font-first strategy | — | None | FlyingPress |
| 127 | Local Gravatar cache | — | None | FlyingPress, LiteSpeed Cache, SpeedyCache |

### A5. Database, WordPress bloat, security, and operations

| # | Feature | WPSeiten | Actual implementation / limitation | Market reference |
|---:|---|:---:|---|---|
| 128 | Clean post revisions | ✅ | Manual and scheduled | WP-Optimize, WP Rocket |
| 129 | Clean auto-drafts | ✅ | Manual and scheduled | WP-Optimize, WP Rocket |
| 130 | Clean trashed posts | ✅ | Manual and scheduled | WP-Optimize, Super Page Cache |
| 131 | Clean spam/trashed comments | ✅ | Manual and scheduled | WP-Optimize, WP Rocket |
| 132 | Clean expired transients | ✅ | Manual and scheduled | Most DB optimizers |
| 133 | Clean all transients | ✅ | Optional, manual/scheduled | WP-Optimize |
| 134 | Optimize database tables | ✅ | Only site-prefix tables with overhead; batched | WP-Optimize, SiteGround |
| 135 | Orphan metadata/table analysis | — | No orphan relationship cleanup/report | WP-Optimize Premium |
| 136 | Scheduled database cleanup | ✅ | Disabled/hourly/daily/weekly controls | WP-Optimize, WP Rocket |
| 137 | Disable emojis | ✅ | Frontend/admin/feed/mail hooks | Perfmatters, LiteSpeed Cache |
| 138 | Disable embeds | ✅ | Discovery, host JS, TinyMCE, rewrite rules | Perfmatters, SpeedyCache |
| 139 | Disable XML-RPC | ✅ | Filter plus Apache file block/header removal | Perfmatters, SpeedyCache |
| 140 | Block user enumeration | ✅ | Author redirects plus REST endpoint removal plus Apache query rule | Security/performance overlap |
| 141 | Hide WordPress version | ✅ | Generator removal | Perfmatters/security plugins |
| 142 | Remove WLW/RSD links | ✅ | Head-link removal | Perfmatters |
| 143 | Remove shortlink | ✅ | Head-link removal | Perfmatters |
| 144 | Disable RSS feeds | ✅ | Setting exists but is not exposed in the visible settings UI | Perfmatters, SpeedyCache |
| 145 | Disable self-pingbacks | ✅ | Removes same-site URLs before ping | Perfmatters |
| 146 | Remove jQuery Migrate | ✅ | Removes dependency for frontend jQuery | Perfmatters, SpeedyCache |
| 147 | Remove Dashicons for visitors | ✅ | Keeps them for logged-in admin-bar users | Perfmatters, SpeedyCache |
| 148 | Remove version query strings | ✅ | Removes `ver` from CSS/JS URLs | Breeze, SiteGround |
| 149 | Granular Heartbeat frequency | ✅ | 15–120 seconds | WP Rocket, Perfmatters, Breeze |
| 150 | Disable Heartbeat by area | ✅ | Admin, dashboard, editor, frontend | Perfmatters, SiteGround |
| 151 | Disable WooCommerce cart fragments | — | No frontend bloat control | Hummingbird, Perfmatters, SpeedyCache |
| 152 | Disable WooCommerce scripts/styles by page | — | No script manager/asset unloading | Perfmatters, SpeedyCache |
| 153 | Security response headers | ✅ | HSTS on HTTPS, nosniff, frame policy, referrer and permissions policies | Unusual for cache plugins |
| 154 | Cache/Redis dashboard | ◐ | Cached-page file count/size and Redis connection/hit ratio; no page-hit data | W3TC stats, NitroPack dashboard |
| 155 | Configurable metrics retention | ⚠ | Setting exists but retention is not used by the collector | Monitoring suites |
| 156 | Lab performance tests | — | No PageSpeed/Lighthouse test | Hummingbird, SiteGround |
| 157 | Real-user Core Web Vitals | — | No RUM | FlyingPress, NitroPack/Rocket add-ons |
| 158 | Uptime monitoring | — | None | Hummingbird Pro |
| 159 | Settings import/export | ⚠ | README claims it; no implementation found in source | WP Rocket, Breeze, LiteSpeed Cache |
| 160 | Presets | — | No optimization presets | LiteSpeed Cache, NitroPack |
| 161 | Safe/test mode | — | No preview-only optimization mode | Hummingbird, NitroPack |
| 162 | Version/config rollback | — | None | WP Rocket, preset/config products |
| 163 | WP-CLI | — | No commands | W3TC, LiteSpeed Cache, image leaders |
| 164 | Multisite/network controls | — | No explicit network activation/settings implementation found | W3TC, Autoptimize, image leaders |
| 165 | Public developer API/hooks | ◐ | A few actions exist; no documented API surface | W3TC, LiteSpeed Cache, ShortPixel |
| 166 | Automated tests | — | No test directory/configuration found; all 31 PHP files do pass `php -l` | Mature commercial products |
| 167 | Uninstall cleanup | ✅ | Dedicated `uninstall.php` exists | Standard expectation |

## B. Market matrix — caching and delivery

### B1. Full-suite leaders

Product codes: **WPS** WPSeiten, **WPR** WP Rocket, **LSC** LiteSpeed Cache, **FP** FlyingPress, **NP** NitroPack, **W3** W3 Total Cache, **WPO** WP-Optimize, **HB** Hummingbird.

| Capability | WPS | WPR | LSC | FP | NP | W3 | WPO | HB |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Static full-page cache | ✅ | ✅ | ◐ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Early/server-bypass delivery | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Effective configurable TTL | ⚠ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Smart related-page/tag purge | ◐ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ | ✅ |
| Per-URL purge | ◐ | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ |
| Full/sitemap cache warmup | ◐ | ✅ | ◐ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Scheduled/automatic warmup | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Separate mobile cache | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ |
| Query-string cache variants | ◐ | ◐ | ✅ | ✅ | ◐ | ✅ | ◐ | ✅ |
| Ignore tracking parameters | — | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ |
| URL exclusions | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Custom cookie bypass | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Custom user-agent bypass | — | ✅ | ✅ | ◐ | ◐ | ✅ | ◐ | ◐ |
| Cache logged-in users/roles | — | ◐ | ✅ | ✅ | — | ◐ | ◐ | — |
| WooCommerce-aware caching | ⚠ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Fragment cache / ESI | — | — | ✅ | — | — | ◐ | — | — |
| REST API response cache | — | — | ◐ | — | — | ◐ | — | — |
| Redis object cache | ✅ | — | ✅ | ✅ | — | ✅ | — | ◐ |
| Memcached object cache | — | — | ✅ | — | — | ✅ | — | ◐ |
| Varnish integration/purge | ✅ | ✅ | — | — | — | ✅ | — | ✅ |
| Nginx FastCGI cache purge | — | — | — | — | — | ◐ | — | — |
| Static-asset CDN integration | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | ◐ |
| Bundled/global CDN | — | ◐ | ◐ | ◐ | ✅ | ◐ | — | ◐ |
| Full-page edge cache | — | — | ◐ | ✅ | ✅ | ◐ | — | ◐ |
| Cloudflare full-page integration | — | — | — | ✅ | — | ◐ | — | ✅ |
| Browser cache for static assets | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Gzip delivery | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Brotli delivery | ◐ | ◐ | ✅ | ◐ | ✅ | ◐ | — | ◐ |
| ETag/304 | ✅ | ◐ | ✅ | ◐ | ◐ | ✅ | ◐ | ◐ |

### B2. Lightweight, specialist, and host-integrated products

Product codes: **PM** Perfmatters, **AO** Autoptimize, **SC** SpeedyCache, **BR** Breeze, **SG** SiteGround Speed Optimizer, **WPSC** WP Super Cache, **CE** Cache Enabler, **SPC** Super Page Cache.

| Capability | WPS | PM | AO | SC | BR | SG | WPSC | CE | SPC |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Static full-page cache | ✅ | — | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Early/server-bypass delivery | ✅ | — | — | ✅ | ✅ | ◐ | ✅ | ✅ | ✅ |
| Effective configurable TTL | ⚠ | — | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Smart related-page/tag purge | ◐ | — | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Per-URL purge | ◐ | — | — | ◐ | ◐ | ✅ | ◐ | ◐ | ✅ |
| Full/sitemap cache warmup | ◐ | — | — | ✅ | ◐ | ◐ | ✅ | — | ✅ |
| Scheduled/automatic warmup | ✅ | — | — | ✅ | ◐ | ◐ | ✅ | — | ✅ |
| Separate mobile cache | ✅ | — | — | ◐ | ◐ | ◐ | ✅ | ✅ | ◐ |
| Query-string cache variants | ◐ | — | — | ◐ | ◐ | ◐ | ◐ | ◐ | ✅ |
| Ignore tracking parameters | — | — | — | ◐ | ◐ | ◐ | — | — | ✅ |
| URL exclusions | ✅ | — | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Custom cookie bypass | — | — | — | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ |
| Custom user-agent bypass | — | — | — | ✅ | ◐ | ◐ | ✅ | ◐ | ◐ |
| Cache logged-in users/roles | — | — | — | ◐ | — | — | ◐ | — | — |
| WooCommerce-aware caching | ⚠ | — | — | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ |
| Fragment cache / ESI | — | — | — | — | — | — | — | — | — |
| Redis object cache | ✅ | — | — | ◐ | — | — | — | — | — |
| Memcached object cache | — | — | — | — | — | ◐ | — | — | — |
| Varnish integration/purge | ✅ | — | — | ✅ | ✅ | — | — | — | — |
| Static-asset CDN integration | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ | — | ✅ |
| Bundled/global CDN | — | — | ◐ | ◐ | ◐ | ◐ | — | — | ◐ |
| Full-page edge cache | — | — | — | — | — | ◐ | — | — | ✅ |
| Cloudflare full-page integration | — | — | — | — | — | — | — | — | ✅ |
| Browser cache for static assets | — | — | — | ✅ | ✅ | ✅ | ◐ | — | ✅ |
| Gzip delivery | ✅ | — | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Brotli delivery | ◐ | — | — | ◐ | — | ◐ | — | ✅ | ◐ |
| ETag/304 | ✅ | — | — | ◐ | ◐ | ◐ | ◐ | ◐ | ◐ |

## C. Market matrix — CSS, JavaScript, HTML, navigation

### C1. Full-suite leaders

| Capability | WPS | WPR | LSC | FP | NP | W3 | WPO | HB |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| CSS minification | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| CSS combination | — | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ |
| Linked-stylesheet unused CSS removal | — | ✅ | ✅ | ✅ | ✅ | ◐ | — | ◐ |
| Inline unused CSS pruning | ◐ | ✅ | ✅ | ✅ | ✅ | ◐ | — | ◐ |
| True critical CSS generation | — | ✅ | ✅ | ✅ | ✅ | ◐ | — | ◐ |
| Async/deferred CSS delivery | — | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ | ✅ |
| CSS safelist/exclusions | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| JavaScript minification | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| JavaScript combination | — | ✅ | ✅ | — | ✅ | ✅ | ✅ | ✅ |
| Defer external JS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Defer inline JS | — | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ |
| Delay external JS to interaction | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ | ✅ |
| Delay inline JS to interaction | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ | ✅ |
| Delay timeout/strategy choices | ◐ | ◐ | ◐ | ✅ | ✅ | ◐ | ◐ | ◐ |
| JS exclusions | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| HTML minification | — | — | ✅ | — | ✅ | ✅ | ✅ | — |
| Per-page asset/script manager | — | — | ◐ | — | — | ◐ | — | ✅ |
| Self-host external CSS/JS | — | ◐ | ✅ | ✅ | ◐ | ◐ | — | — |
| Lazy-render below-fold HTML | — | ✅ | ✅ | ✅ | ✅ | ◐ | — | ◐ |
| Optimization safe/test mode | — | — | ◐ | — | ✅ | — | — | ✅ |
| Import/export optimization config | — | ✅ | ✅ | ◐ | ✅ | ✅ | ◐ | ✅ |

### C2. Lightweight, specialist, and host-integrated products

| Capability | WPS | PM | AO | SC | BR | SG | WPSC | CE | SPC |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| CSS minification | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ◐ |
| CSS combination | — | — | ✅ | ✅ | ✅ | ✅ | — | — | — |
| Linked-stylesheet unused CSS removal | — | ✅ | ◐ | ◐ | — | — | — | — | ✅ |
| Inline unused CSS pruning | ◐ | ✅ | ◐ | ◐ | — | — | — | — | ✅ |
| True critical CSS generation | — | — | ◐ | ◐ | — | — | — | — | ◐ |
| Async/deferred CSS delivery | — | ✅ | ✅ | ◐ | ◐ | ◐ | — | — | ◐ |
| CSS safelist/exclusions | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ✅ |
| JavaScript minification | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ◐ |
| JavaScript combination | — | — | ✅ | ✅ | ✅ | ✅ | — | — | — |
| Defer external JS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ✅ |
| Defer inline JS | — | ✅ | ✅ | ◐ | ✅ | ◐ | — | — | ◐ |
| Delay external JS to interaction | ✅ | ✅ | ◐ | ✅ | — | — | — | — | ◐ |
| Delay inline JS to interaction | ✅ | ✅ | ◐ | ✅ | — | — | — | — | ◐ |
| Delay timeout/strategy choices | ◐ | ✅ | ◐ | ◐ | — | — | — | — | ◐ |
| JS exclusions | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ✅ |
| HTML minification | — | — | ✅ | ✅ | ✅ | ✅ | — | ✅ | ◐ |
| Per-page asset/script manager | — | ✅ | ◐ | ◐ | — | — | — | — | ✅ |
| Self-host external CSS/JS | — | ◐ | ◐ | ◐ | — | — | — | — | ◐ |
| Lazy-render below-fold HTML | — | ✅ | — | ✅ | — | — | — | — | ◐ |
| Optimization safe/test mode | — | — | — | ◐ | — | — | — | — | ◐ |

### C3. Resource hints and perceived navigation

| Capability | WPS | WPR | LSC | FP | NP | W3 | PM | SC | BR | SG |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| DNS prefetch | — | ◐ | ✅ | ◐ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Preconnect | — | ◐ | ✅ | ◐ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ |
| Generic resource preload | — | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ | ✅ | ✅ | ✅ |
| Font preload | — | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ | ✅ | ✅ | ✅ |
| LCP/critical-image preload | ◐ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ | ✅ | — | ◐ |
| Link-hover preload/prefetch | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ | ✅ | ✅ | ✅ | ◐ |
| Speculation Rules prerender | ✅ | ◐ | ◐ | ◐ | ◐ | ◐ | ✅ | ✅ | ◐ | — |

## D. Market matrix — media and font delivery

### D1. Full-suite leaders

| Capability | WPS | WPR | LSC | FP | NP | W3 | WPO | HB |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Compress image files | — | ◐ | ✅ | ✅ | ✅ | ◐ | ✅ | ◐ |
| Generate WebP | — | ◐ | ✅ | ✅ | ✅ | ◐ | ✅ | ◐ |
| Generate AVIF | — | ◐ | ✅ | ✅ | — | ◐ | — | ◐ |
| Transforming image CDN | — | ◐ | ◐ | ◐ | ✅ | ◐ | — | ◐ |
| Lazy-load `<img>` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ |
| Lazy-load CSS backgrounds | — | ✅ | ✅ | ✅ | ◐ | ◐ | — | ◐ |
| Lazy-load iframe/video | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ | ◐ |
| YouTube/video facade | ✅ | ✅ | ◐ | ✅ | ✅ | ◐ | — | ◐ |
| Add missing dimensions | ◐ | ✅ | ✅ | ✅ | ✅ | ◐ | — | ◐ |
| Responsive/properly sized delivery | — | ◐ | ◐ | ✅ | ✅ | ◐ | — | ◐ |
| Automatic above-fold detection | — | ✅ | ✅ | ✅ | ✅ | ◐ | — | ◐ |
| LQIP/placeholder | — | ◐ | ✅ | ◐ | ◐ | — | — | ◐ |
| Host Google Fonts locally | ✅ | ✅ | ◐ | ✅ | ✅ | — | — | ◐ |
| Force `font-display` behavior | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | — | ✅ |
| Preload fonts | — | ✅ | ✅ | ✅ | ✅ | ◐ | — | ✅ |
| Font subsetting | — | — | — | — | ✅ | — | — | — |
| Font conversion/compression | — | — | — | — | ✅ | — | — | ◐ |
| Local Gravatar caching | — | — | ✅ | ✅ | — | — | — | ✅ |

Notes: WP Rocket image compression/next-gen creation is supplied by its separate Imagify product; Hummingbird image processing is supplied by Smush; several LSC features use QUIC.cloud; FlyingCDN is an add-on; W3 image conversion/advanced delivery includes extension/pro capabilities.

### D2. Lightweight, specialist, and host-integrated products

| Capability | WPS | PM | AO | SC | BR | SG | WPSC | CE | SPC |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Compress image files | — | — | ◐ | ✅ | — | ◐ | — | — | — |
| Generate WebP | — | — | ◐ | ✅ | — | ◐ | — | — | — |
| Generate AVIF | — | — | ◐ | — | — | — | — | — | — |
| Transforming image CDN | — | — | ◐ | ◐ | — | ◐ | — | — | — |
| Lazy-load `<img>` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ✅ |
| Lazy-load CSS backgrounds | — | ✅ | ✅ | ◐ | — | ◐ | — | — | ✅ |
| Lazy-load iframe/video | ✅ | ✅ | ◐ | ✅ | ◐ | ✅ | — | — | ✅ |
| YouTube/video facade | ✅ | ✅ | — | ◐ | — | ◐ | — | — | ◐ |
| Add missing dimensions | ◐ | ✅ | — | ✅ | — | ◐ | — | — | ◐ |
| Responsive/properly sized delivery | — | ◐ | ◐ | ◐ | — | ◐ | — | — | ◐ |
| Automatic above-fold detection | — | ✅ | ◐ | ◐ | — | ◐ | — | — | ◐ |
| LQIP/placeholder | — | ◐ | ◐ | ◐ | — | — | — | — | ◐ |
| Host Google Fonts locally | ✅ | ✅ | ◐ | ✅ | — | ◐ | — | — | ✅ |
| Force `font-display` behavior | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ | — | — | ◐ |
| Preload fonts | — | ✅ | ✅ | ✅ | ✅ | ✅ | — | — | ◐ |
| Font subsetting/compression | — | — | — | — | — | — | — | — | — |
| Local Gravatar caching | — | — | — | ✅ | — | ◐ | — | — | — |

## E. Dedicated image-optimization deep comparison

Product codes: **SP** ShortPixel Image Optimizer, **IMG** Imagify, **EWWW** EWWW Image Optimizer, **SM** Smush, **OPT** Optimole, **TINY** TinyPNG, **CFM** Converter for Media, **RSM** reSmush.it.

### E1. File creation, compression, and safety

| Capability | WPS | SP | IMG | EWWW | SM | OPT | TINY | CFM | RSM |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Lossy compression | — | ✅ | ✅ | ◐ | ✅ | ✅ | ✅ | ◐ | ✅ |
| Lossless compression | — | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ | ◐ | — |
| Smart/content-aware compression | — | ✅ | ✅ | ◐ | ✅ | ✅ | ✅ | — | ◐ |
| Optimize automatically on upload | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Bulk optimize existing library | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Background/cron processing | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Original backup and restore | — | ✅ | ✅ | ✅ | ✅ | ◐ | — | ✅ | ✅ |
| Resize oversized originals | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — |
| Choose WordPress thumbnail sizes | — | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ | ✅ | — |
| Optimize custom/non-library folders | — | ✅ | ◐ | ✅ | ✅ | ◐ | — | ✅ | — |
| Optimize PDF files | — | ✅ | ✅ | ◐ | — | — | — | — | — |
| Optimize animated GIF/APNG | — | ✅ | ◐ | ✅ | ◐ | ◐ | ✅ | ✅ | ✅ |
| Preserve/strip EXIF metadata | — | ✅ | ◐ | ✅ | ◐ | ◐ | ✅ | — | ✅ |
| Generate WebP | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| Generate AVIF | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | — |
| Optimize existing WebP/AVIF input | — | ✅ | ◐ | ✅ | ◐ | ◐ | ✅ | — | — |
| Local/no-cloud processing option | — | — | — | ✅ | ◐ | — | — | ✅ | — |
| Optimize without changing originals | — | ◐ | ✅ | ◐ | ◐ | ✅ | — | ✅ | ✅ |
| Per-image exclusions | ◐ | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ | ✅ |
| WP-CLI | — | ✅ | ✅ | ✅ | ◐ | ◐ | — | ✅ | ✅ |
| Multisite | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ◐ | ◐ |

Notes: CFM primarily converts/re-encodes rather than offering a full backup/compression workflow; Optimole transforms at delivery and retains origin files unless offload is selected; TinyPNG normally overwrites optimized originals and does not provide the same restore workflow as backup-based products.

### E2. Delivery, responsiveness, and Core Web Vitals

| Capability | WPS | SP | IMG | EWWW | SM | OPT | TINY | CFM | RSM |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Browser-aware WebP/AVIF fallback | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| Built-in/optional image CDN | ◐ | ✅ | — | ✅ | ✅ | ✅ | — | ◐ | — |
| On-the-fly viewport resize | — | ◐ | — | ✅ | ✅ | ✅ | — | — | — |
| Responsive `srcset`/breakpoints | — | ◐ | — | ✅ | ✅ | ✅ | — | — | — |
| Retina/DPR-aware delivery | — | ◐ | — | ✅ | ◐ | ✅ | — | — | — |
| Image lazy loading | ✅ | ◐ | — | ✅ | ✅ | ✅ | — | — | — |
| CSS background optimization | — | ✅ | — | ✅ | ✅ | ✅ | — | — | — |
| CSS background lazy loading | — | ◐ | — | ✅ | ◐ | ✅ | — | — | — |
| Iframe/video lazy loading | ✅ | — | — | ◐ | ✅ | ✅ | — | — | — |
| Video facade | ✅ | — | — | ◐ | ✅ | ✅ | — | — | — |
| Missing width/height / CLS reserve | ◐ | — | — | ✅ | ✅ | ✅ | — | — | — |
| Above-fold/LCP lazy-load exclusion | ◐ | — | — | ◐ | ✅ | ✅ | — | — | — |
| LCP image preload/high priority | ◐ | — | — | ◐ | ✅ | ◐ | — | — | — |
| LQIP/blur placeholder | — | ◐ | — | ✅ | ✅ | ✅ | — | — | — |
| Smart crop/focal point | — | ✅ | — | ◐ | — | ✅ | — | — | — |
| Watermarking | — | ◐ | — | ✅ | — | ✅ | — | — | — |
| Media offload/cloud library | — | — | — | ◐ | — | ✅ | — | — | — |
| AI alt text/captioning | — | ✅ | — | — | — | — | — | — | — |

### E3. Image features inside all-in-one performance products

| Capability | WPS | LSC | FP | NP | WPO | SG |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Lossy compression | — | ✅ | ✅ | ✅ | ✅ | ◐ |
| Lossless compression | — | ✅ | ✅ | — | ✅ | ◐ |
| Upload-time automation | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| Bulk existing-library processing | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| Original backup/restore | — | ✅ | ✅ | ◐ | ✅ | ◐ |
| Resize oversized originals | — | ◐ | — | ✅ | ◐ | ✅ |
| WebP generation/delivery | — | ✅ | ✅ | ✅ | ✅ | ✅ |
| AVIF generation/delivery | — | ✅ | ✅ | — | — | — |
| Image CDN | ◐ | ◐ | ◐ | ✅ | — | ◐ |
| Adaptive per-device sizing | — | ◐ | ✅ | ✅ | — | ◐ |
| Image/background lazy load | ◐ | ✅ | ✅ | ✅ | ◐ | ✅ |
| Missing dimensions | ◐ | ✅ | ✅ | ✅ | — | ◐ |
| Rendered above-fold detection | — | ✅ | ✅ | ✅ | — | ◐ |

## F. Database, bloat, monitoring, and operations matrix

### F1. Full-suite leaders

| Capability | WPS | WPR | LSC | FP | NP | W3 | WPO | HB |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Database cleanup | ✅ | ✅ | ✅ | ✅ | — | — | ✅ | ✅ |
| Scheduled database cleanup | ✅ | ✅ | ✅ | ✅ | — | — | ✅ | ◐ |
| Table optimization | ✅ | ✅ | ✅ | ✅ | — | — | ✅ | ✅ |
| Disable emoji assets | ✅ | ◐ | ✅ | ✅ | — | — | ◐ | ✅ |
| Disable embeds | ✅ | ◐ | ✅ | ✅ | — | — | — | ◐ |
| Disable XML-RPC | ✅ | — | ◐ | ✅ | — | — | — | — |
| Remove query strings | ✅ | — | ✅ | ✅ | — | ◐ | ◐ | ✅ |
| Granular Heartbeat control | ✅ | ✅ | ✅ | ✅ | — | — | ◐ | ✅ |
| Woo cart-fragment/assets controls | — | — | ✅ | ✅ | — | ◐ | — | ✅ |
| Cache statistics | ◐ | ◐ | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ |
| PageSpeed/lab test | — | ◐ | ✅ | — | ✅ | ◐ | — | ✅ |
| Real-user Core Web Vitals | — | ◐ | ◐ | ✅ | ✅ | ◐ | — | ◐ |
| Uptime monitoring | — | — | — | — | — | — | — | ✅ |
| Import/export settings | — | ✅ | ✅ | ◐ | ✅ | ✅ | ◐ | ✅ |
| Presets | — | — | ✅ | ◐ | ✅ | ◐ | — | ✅ |
| Safe/test mode | — | — | ◐ | — | ✅ | — | — | ✅ |
| WP-CLI | — | ◐ | ✅ | ◐ | ◐ | ✅ | ◐ | ◐ |
| Multisite/network controls | — | ✅ | ✅ | ◐ | ◐ | ✅ | ✅ | ✅ |

### F2. Lightweight, specialist, and host-integrated products

| Capability | WPS | PM | AO | SC | BR | SG | WPSC | CE | SPC |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Database cleanup | ✅ | ✅ | — | ✅ | ✅ | ✅ | — | — | ✅ |
| Scheduled database cleanup | ✅ | ✅ | — | ✅ | ◐ | ◐ | — | — | ✅ |
| Disable emoji assets | ✅ | ✅ | ✅ | ✅ | ◐ | ✅ | — | — | ◐ |
| Disable embeds/XML-RPC | ✅ | ✅ | — | ✅ | — | ◐ | — | — | ◐ |
| Remove query strings | ✅ | ✅ | ◐ | ✅ | ✅ | ✅ | — | — | ◐ |
| Granular Heartbeat control | ✅ | ✅ | — | ✅ | ✅ | ✅ | — | — | ◐ |
| Woo cart-fragment/assets controls | — | ✅ | — | ✅ | ◐ | ◐ | — | — | ◐ |
| Cache/performance statistics | ◐ | — | ◐ | ✅ | ◐ | ✅ | ◐ | ◐ | ✅ |
| PageSpeed/lab test | — | — | — | ◐ | — | ✅ | — | — | ◐ |
| Real-user Core Web Vitals | — | — | — | — | — | ◐ | — | — | ◐ |
| Import/export settings | — | ✅ | ◐ | ◐ | ✅ | ◐ | ◐ | — | ✅ |
| Presets/safe mode | — | — | — | ◐ | — | ◐ | — | — | ◐ |
| WP-CLI | — | ✅ | ✅ | ◐ | ◐ | ✅ | ◐ | ✅ | ◐ |
| Multisite/network controls | — | ✅ | ✅ | ◐ | ✅ | ✅ | ✅ | ✅ | ✅ |

## G. Adjacent services buyers will also compare

These are not one-for-one cache plugins, but they define expectations that WPSeiten will encounter in sales and product reviews.

| Service | Relevant capabilities | How WPSeiten compares |
|---|---|---|
| Cloudflare APO | Full WordPress HTML edge caching, automatic invalidation, logged-in bypass, optional device-type cache | WPSeiten only purges a configured Cloudflare zone; it does not configure or operate edge HTML caching |
| Cloudflare Images / Polish | Lossy/lossless compression, WebP/AVIF, browser fallback, on-the-fly resizing and transforms | WPSeiten can rewrite to a CDN hostname but has no transform/compression layer |
| Jetpack Site Accelerator | Global image/static CDN, image optimization, WebP, width-aware resizing | WPSeiten has broader local cache features but no transforming image CDN |
| Cloudinary WordPress | Media sync/offload, automatic format/quality, responsive breakpoints, lazy load, AI crop, image/video transforms, CDN | Far beyond WPSeiten media scope; useful reference for a future optional media service rather than a local-cache feature target |

## H. Competitive position by product

| Product | Main reason buyers choose it | WPSeiten advantage | WPSeiten disadvantage |
|---|---|---|---|
| LiteSpeed Cache | Deep server cache plus broad optimization/image stack | Works without requiring LiteSpeed for its local page cache; includes Varnish path | Much less mature image/CSS/edge stack; LSC has ESI and Memcached |
| WP Rocket | Safe defaults, polished workflow, cloud used-CSS and automatic priority optimization | Redis and Varnish are built in; more WordPress bloat/security toggles | Weaker correctness/UX, no image engine, no safe ecosystem maturity |
| FlyingPress | Browser-rendered cloud analysis, accurate above-fold handling, broad cache controls | More local/self-contained; Varnish support | FlyingPress leads on used CSS, adaptive media, preload, edge cache, monitoring |
| NitroPack | Fully managed cloud optimization and CDN | Local control, reversibility without SaaS dependence, Redis/Varnish | NitroPack is substantially ahead on automatic CSS/media/font processing and CDN |
| W3 Total Cache | Extremely configurable cache backends and CDN integrations | Simpler conceptual stack and modern built-in UX features | W3TC has Memcached, DB/fragment/REST cache, richer statistics and WP-CLI |
| WP-Optimize | Cache + image compression + strong database cleanup | Redis/Varnish, JS delay, fonts, bloat controls | WP-Optimize has the missing full image pipeline and deeper DB focus |
| Hummingbird + Smush | Integrated performance monitoring, cache/assets, image CDN | Local Redis/Varnish and fewer account dependencies | Monitoring, safe mode, CDN, image delivery, and uptime are much broader |
| Perfmatters | Script Manager and precise front-end/bloat controls | Actual page/object/Varnish caching included | No per-page asset manager; narrower CSS/media controls |
| Autoptimize | Lightweight CSS/JS/HTML aggregation and CDN-compatible front-end optimization | Full page/Redis/Varnish/database stack | Autoptimize is more mature at asset aggregation and HTML optimization |
| SpeedyCache | Wide checklist in one plugin, including image and bloat features | Stronger Redis implementation and tag-aware Varnish | SpeedyCache covers more table-stakes items, especially image/critical CSS |
| Breeze | Free cache/minify/database/heartbeat workflow and Cloudways integration | Redis/Varnish depth and modern navigation | Breeze has HTML minify, asset combine, import/export, broader browser cache |
| SiteGround Speed Optimizer | Tight hosting/server integration, image compression/WebP | Host-agnostic local cache design and Varnish | SiteGround users get stronger server cache, Memcached, image and test tooling |
| WP Super Cache | Stable, focused static cache with preload/rebuild | Much broader optimization, object cache, DB and CDN features | WP Super Cache has a mature cache lifecycle and established compatibility |
| Cache Enabler | Minimal fast disk cache with Brotli/Gzip and HTML minification | Far broader feature set | Cache Enabler is simpler and has effective TTL/precompression maturity |
| Super Page Cache | Disk plus Cloudflare edge cache, granular controls and DB tools | Redis and Varnish breadth | Much stronger Cloudflare operation, exclusions, per-page asset control, and import/export |

## I. Recommended WPSeiten roadmap

### P0 — correctness and table stakes

1. **Wire `cache_lifetime` end-to-end** into drop-in TTL checks, generated rewrite headers, and cleanup; regenerate configs safely on save.
2. **Add WooCommerce cookies to every early bypass layer** (`advanced-cache.php` and Apache/LiteSpeed rules), plus explicit cache-safety integration tests.
3. **Decouple HTML transformations from page caching.** A standalone frontend output optimizer should run when its own features are enabled, regardless of `html_cache`.
4. **Build an image engine:** upload-time and bulk queues, lossy/lossless modes, originals/restore, max dimensions, WebP and AVIF creation, browser fallback, statistics, exclusions, and WP-CLI. Start local (Imagick/GD/cwebp) with a pluggable cloud adapter.
5. **Replace/rename the current unused-CSS feature.** Either label it accurately as “Prune unused inline CSS (experimental)” or build true linked-style used-CSS generation with browser rendering and safe fallback.

### P1 — credible all-in-one competitor

6. Per-URL/related-URL HTML purge, sitemap discovery, unlimited resumable preload queue, concurrency/load controls, and cache-rebuild locking.
7. Full Nginx/FastCGI integration and documented server recipes; retain Apache/LiteSpeed direct serving.
8. HTML minification; responsive image `srcset`/`sizes`; CSS background lazy load; viewport-derived above-fold images; real LCP preload; LQIP.
9. Cloudflare Cache Rules/full-page edge integration with granular URL purge and synchronized bypass rules.
10. Import/export, presets, safe/test mode, config rollback, diagnostics, and compatibility conflict detection.
11. WP-CLI for purge/preload/image/database operations and explicit multisite/network support.

### P2 — differentiation

12. Per-page Script Manager with role/page-type rules and WooCommerce asset controls.
13. Font preload discovery, WOFF2 conversion/subsetting, and local Gravatar cache.
14. Core Web Vitals/RUM dashboard and cache/preload/optimization job telemetry.
15. Redis TLS/Sentinel/cluster support, optional Memcached, and documented public hooks.
16. Preserve the local-first advantage: make any SaaS image/CSS service optional and expose a provider interface.

## J. Source register

### Local WPSeiten sources

- [Default settings and plugin wiring](WPS-Cache/src/Plugin.php)
- [HTML cache and output-optimization pipeline](WPS-Cache/src/Cache/Drivers/HTMLCache.php)
- [Early page-cache drop-in](WPS-Cache/includes/advanced-cache-template.php)
- [Apache/LiteSpeed rules and response headers](WPS-Cache/src/Server/ServerConfigManager.php)
- [Redis driver](WPS-Cache/src/Cache/Drivers/RedisCache.php) and [object-cache drop-in](WPS-Cache/includes/object-cache.php)
- [Varnish integration](WPS-Cache/src/Cache/Drivers/VarnishCache.php)
- [CSS minifier](WPS-Cache/src/Cache/Drivers/MinifyCSS.php), [JS minifier](WPS-Cache/src/Cache/Drivers/MinifyJS.php), [JS defer/delay](WPS-Cache/src/Optimization/JSOptimizer.php), and [inline CSS pruning](WPS-Cache/src/Optimization/CriticalCSSManager.php)
- [Media optimization](WPS-Cache/src/Optimization/MediaOptimizer.php), [font optimization](WPS-Cache/src/Optimization/FontOptimizer.php), and [CDN/Cloudflare](WPS-Cache/src/Optimization/CdnManager.php)
- [Database optimizer](WPS-Cache/src/Optimization/DatabaseOptimizer.php), [bloat/Heartbeat optimizer](WPS-Cache/src/Optimization/BloatOptimizer.php), and [WooCommerce compatibility](WPS-Cache/src/Compatibility/CommerceManager.php)
- [Preloader](WPS-Cache/src/Cron/CronManager.php), [metrics](WPS-Cache/src/Admin/Analytics/MetricsCollector.php), and [settings UI](WPS-Cache/src/Admin/Settings/SettingsManager.php)

### Official performance-product sources

- WP Rocket: [feature overview](https://wp-rocket.me/features/), [complete behavior overview](https://docs.wp-rocket.me/article/67-what-exactly-does-wp-rocket-do), and [Remove Unused CSS](https://docs.wp-rocket.me/article/1529-remove-unused-css)
- LiteSpeed Cache: [beginner/feature overview](https://docs.litespeedtech.com/lscache/lscwp/beginner/), [page optimization](https://docs.litespeedtech.com/lscache/lscwp/pageopt/), and [image optimization](https://docs.litespeedtech.com/lscache/lscwp/imageopt/)
- FlyingPress: [complete feature collection](https://docs.flyingpress.com/en/collections/12930847-configuration-features), [image optimization](https://docs.flyingpress.com/en/articles/13435045-image-optimization), [properly sized images](https://docs.flyingpress.com/en/articles/11406831-properly-size-images), and [Cloudflare edge integration](https://docs.flyingpress.com/en/articles/11977701-flyingpress-cloudflare-integration-full-page-caching-setup-guide)
- NitroPack: [feature overview](https://nitropack.io/features/), [optimization architecture](https://support.nitropack.io/en/articles/8390395-how-does-nitropack-optimize-my-website), [image optimization](https://support.nitropack.io/en/articles/8390289-image-optimization), and [font subsetting](https://support.nitropack.io/en/articles/8390311-font-subsetting-remove-unused-glyphs-from-your-font-files)
- [W3 Total Cache official WordPress listing](https://wordpress.org/plugins/w3-total-cache/)
- [WP-Optimize official WordPress listing](https://wordpress.org/plugins/wp-optimize/)
- [Hummingbird official documentation](https://wpmudev.com/docs/wpmu-dev-plugins/hummingbird/)
- [Perfmatters feature reference](https://perfmatters.io/features/)
- [Autoptimize official WordPress listing](https://wordpress.org/plugins/autoptimize/)
- [SpeedyCache official WordPress listing](https://wordpress.org/plugins/speedycache/)
- [Breeze official WordPress listing](https://wordpress.org/plugins/breeze/)
- [SiteGround Speed Optimizer official WordPress listing](https://en-gb.wordpress.org/plugins/sg-cachepress/)
- [WP Super Cache official WordPress listing](https://en-gb.wordpress.org/plugins/wp-super-cache/)
- [Cache Enabler official WordPress listing](https://en-gb.wordpress.org/plugins/cache-enabler/)
- [Super Page Cache official WordPress listing](https://wordpress.org/plugins/wp-cloudflare-page-cache/)

### Official image-product and infrastructure sources

- [ShortPixel Image Optimizer official WordPress listing](https://wordpress.org/plugins/shortpixel-image-optimiser/)
- [Imagify official WordPress listing](https://wordpress.org/plugins/imagify/)
- [EWWW Image Optimizer official WordPress listing](https://en-gb.wordpress.org/plugins/ewww-image-optimizer/)
- [Smush official documentation](https://wpmudev.com/docs/wpmu-dev-plugins/smush/)
- [Optimole official WordPress listing](https://wordpress.org/plugins/optimole-wp/)
- [TinyPNG official WordPress listing](https://wordpress.org/plugins/tiny-compress-images/)
- [Converter for Media official WordPress listing](https://wordpress.org/plugins/webp-converter-for-media/)
- [reSmush.it official WordPress listing](https://wordpress.org/plugins/resmushit-image-optimizer/)
- Cloudflare: [APO](https://developers.cloudflare.com/automatic-platform-optimization/), [image optimization](https://developers.cloudflare.com/use-cases/performance/image-optimization/), and [Polish compression](https://developers.cloudflare.com/images/polish/compression/)
- [Jetpack Site Accelerator](https://jetpack.com/support/site-accelerator/)
- [Cloudinary WordPress integration](https://cloudinary.com/documentation/wordpress_integration)

## Verification notes

- All 31 PHP files in the local plugin passed `php -l` on 2026-08-14.
- The audit is code-based, not a live WordPress integration benchmark. Runtime behavior that depends on server configuration, WordPress hooks, conflicting drop-ins, filesystem permissions, Redis/Varnish availability, or remote APIs still needs integration tests.
- Competitor capabilities are documentation-based and were not independently benchmarked. Conditional marks deliberately combine paid-tier, host-specific, and external-service dependencies; consult the cited product source before making a procurement or parity commitment.
