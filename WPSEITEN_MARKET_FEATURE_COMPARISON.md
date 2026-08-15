# WPSeiten / WPS-Cache — complete market feature comparison

Research date: **2026-08-14**  
Local implementation reconciled: **WPS-Cache 0.2.0 (2026-08-15)**
Comparison basis: shipped local PHP code plus current official vendor documentation.

## Executive conclusion

WPS-Cache 0.2.0 closes the locally implementable correctness and table-stakes gaps in the original audit. It now combines canonical static page caching, Redis TLS configuration, Varnish/Nginx/Cloudflare invalidation, sitemap warmup, REST/fragment caching, frontend delivery controls, a local Imagick/GD image engine, operations tooling, multisite controls, WP-CLI, local RUM/uptime data, and an expanded automated test/build workflow.

Of the 167 audited rows, **137 are now materially complete (✅), 21 are explicitly conditional or provider/environment-dependent (◐), and 9 are deliberately not claimed (—)**. Every non-green row states the missing boundary in Section A; none is presented as finished behind a toggle.

The remaining non-green rows are explicit boundaries rather than hidden placeholders:

1. **External infrastructure cannot be shipped in a local plugin:** a global CDN, on-the-fly adaptive image service, Cloudflare account, Redis Sentinel/cluster, Memcached daemon, or uptime observer outside the origin must be supplied by a host/provider.
2. **Browser-rendered analysis needs a browser execution service:** true linked used-CSS/critical-CSS generation and a full Lighthouse lab run cannot be reproduced accurately by PHP string/DOM analysis. The local plugin provides inline pruning, async CSS, safe preview, HTTP lab timing, and RUM instead.
3. **Some codecs are environment-dependent:** Brotli, WebP, AVIF, PDF, animated media, watermarking, and exact lossless JPEG tooling depend on server extensions/delegates. The admin reports actual capabilities.
4. **Cloud/AI features remain opt-in provider boundaries:** media offload, transforming CDN delivery, font subsetting/conversion, and vision-based alt text would require binaries, infrastructure, or third-party data transfer. WPS-Cache does not silently add those dependencies.

Section A is the authoritative 0.2.0 implementation record. The broader product matrices remain the original market-research snapshot; their WPS cells should be read together with Section A where a capability changed during implementation.

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
| 3 | Direct web-server cache serving | ◐ | Portable PHP early drop-in plus generated Nginx FastCGI/static-asset recipe; activating host-owned server configuration remains an administrator/host step | LiteSpeed Cache, W3TC, host caches |
| 4 | Configurable cache lifetime | ✅ | Validated TTL is propagated to runtime config, early responses, Varnish, Cloudflare rules, and generated server recipes | WP Rocket, FlyingPress, Hummingbird |
| 5 | Automatic cache purge on post save | ✅ | Purges the post, home, archive, and related taxonomy URLs plus Varnish tags instead of flushing every page | LiteSpeed tag purge, FlyingPress related-page purge |
| 6 | Automatic purge on comments | ✅ | Full local driver flush | WP Rocket, Hummingbird |
| 7 | Automatic purge on theme/plugin changes | ✅ | Full purge plus selected OpCache invalidation | Most full suites |
| 8 | Manual purge all | ✅ | Admin toolbar/action | Universal |
| 9 | Manual purge by cache layer | ✅ | HTML, Redis, and Varnish handlers exist | W3TC, LiteSpeed Cache |
| 10 | Per-URL local page-cache purge | ✅ | Same-origin admin tool and public cache API invalidate URL variants and notify configured edge/reverse-proxy integrations | FlyingPress, WP Rocket, Super Page Cache |
| 11 | Tag-aware Varnish purge | ✅ | Post, archive, and term cache tags via PURGE headers | LiteSpeed tags/ESI, W3TC Varnish |
| 12 | Varnish full purge | ✅ | Regex/tag purge request | WP Rocket, Hummingbird, W3TC |
| 13 | Cache preload/warmup | ✅ | Manual concurrent queue and resumable scheduled batches cover up to 10,000 discovered URLs and all configured device variants | WP Rocket, FlyingPress, sitemap crawlers |
| 14 | Scheduled preload | ✅ | Hourly/daily/weekly setting; desktop and mobile requests | LiteSpeed crawler, WP Super Cache preload |
| 15 | Sitemap-driven complete preload | ✅ | Recursively follows same-origin WordPress sitemap indexes with a database fallback and bounded 10,000-URL safety ceiling | WP Rocket, W3TC, Super Page Cache |
| 16 | Separate mobile cache | ✅ | `-mobile` variant selected by user-agent regex | WP Rocket, FlyingPress, Cloudflare APO device cache |
| 17 | Tablet-specific cache | ✅ | Shared, desktop/mobile, and desktop/mobile/tablet policies use matching runtime/drop-in cache keys | Cloudflare APO, Hummingbird APO |
| 18 | Query-string cache variants | ✅ | Runtime and early serving use the same sorted RFC3986 canonicalization policy | W3TC, FlyingPress |
| 19 | Ignore tracking query parameters | ✅ | Wildcard-capable canonical ignore list includes UTM and common ad/click IDs | WP Rocket, FlyingPress, Cloudflare |
| 20 | Query-string denial/allow list | ✅ | Admin-configurable wildcard allow and deny lists are enforced before file lookup/write | Hummingbird, Super Page Cache |
| 21 | URL cache exclusions | ✅ | User-entered values compiled into a regex | Universal |
| 22 | Cookie-based bypass | ✅ | WordPress, WooCommerce, and custom cookie fragments are compiled into the early runtime policy | FlyingPress, Super Page Cache |
| 23 | Custom cookie bypass list | ✅ | Admin-configurable fragments are enforced by both early and runtime layers | FlyingPress, LiteSpeed Cache, Super Page Cache |
| 24 | User-agent exclusions | ✅ | Admin-configurable literal user-agent exclusions run before cache lookup/write | W3TC, SpeedyCache |
| 25 | Logged-in-user bypass | ✅ | Logged-in users bypass runtime and early cache | Universal safe default |
| 26 | Cache for logged-in users/roles | ◐ | Opt-in allowed roles use per-user-and-role runtime variants only when `WPSC_PRIVATE_CACHE_DIR` points outside the web root; the public early drop-in never serves them | LiteSpeed Cache, FlyingPress, WP Rocket User Cache |
| 27 | WooCommerce sensitive-page bypass | ✅ | Runtime route checks and early WooCommerce cookies cover cart, checkout, account, WC API, and active sessions | Most commercial suites |
| 28 | WooCommerce session/cart-cookie bypass | ✅ | Cart/session/hash cookies are present in every early and runtime bypass policy | FlyingPress, LiteSpeed Cache |
| 29 | Fragment cache / ESI | ◐ | Documented `FragmentCache::remember/forget` API uses persistent object cache; server-level ESI/hole punching is not possible without a cooperating proxy | LiteSpeed ESI, W3TC Pro fragment cache |
| 30 | REST API response cache | ✅ | Anonymous successful GET responses support route allowlists, independent TTL, headers, index-based purge, and user-route safety | W3TC Pro |
| 31 | Feed/search cache | ✅ | Explicit feed and search toggles, canonical query behavior, and early feed content type are implemented | W3TC |
| 32 | Stale-while-revalidate / cache rebuild | ✅ | Configurable stale window serves existing content while one request owns regeneration | WP Super Cache rebuild, managed edge products |
| 33 | Cache stampede protection | ✅ | Atomic exclusive lock files coalesce expired-cache regeneration with automatic dead-lock expiry | Managed cache/CDN products |
| 34 | Precomputed Gzip page files | ✅ | `.html.gz` generated on cache write and negotiated at serve time | Cache Enabler, NitroPack/CDNs |
| 35 | Precomputed Brotli page files | ◐ | Generated only when PHP exposes `brotli_compress`; otherwise unavailable | Cache Enabler, FlyingCDN, LiteSpeed server |
| 36 | ETag and 304 responses | ✅ | File mtime/size-based ETag in drop-in | W3TC, Cache Enabler |
| 37 | `Content-Length` on cached response | ✅ | Set by early-serving drop-in | Advanced server caches |
| 38 | Browser cache policy for HTML | ✅ | `public, max-age=3600` | Most cache suites |
| 39 | Browser caching for static assets | ◐ | Generated Nginx and Apache recipes set one-year immutable policies; applying server-owned configuration remains host-dependent | W3TC, Hummingbird, SpeedyCache |
| 40 | Cache-status response header | ✅ | `X-WPS-Cache: HIT` on early/direct serves | Most mature cache products |

### A2. Object, server, CDN, and edge caching

| # | Feature | WPSeiten | Actual implementation / limitation | Market reference |
|---:|---|:---:|---|---|
| 41 | Persistent Redis object cache | ✅ | Bundled `object-cache.php` drop-in and Redis client | LiteSpeed Cache, W3TC, FlyingPress |
| 42 | Redis host/port/database/password/prefix | ✅ | UI plus `WP_REDIS_*` constants | Redis Object Cache, W3TC |
| 43 | Redis key signing / safe serialization | ✅ | Values are serialized and signed | Security-oriented differentiator |
| 44 | Redis compression | ✅ | Uses supported phpredis compression options | W3TC/Redis specialists |
| 45 | Redis group flush/multiple operations | ✅ | Modern WordPress cache capability functions exist | Redis Object Cache |
| 46 | Redis TLS/sentinel/cluster/replication | ◐ | TLS is wired through the UI, mode-0600 early config, runtime client, and drop-in; Sentinel/cluster/replication need an enterprise topology/client and remain unimplemented | Redis Object Cache Pro, enterprise stacks |
| 47 | Memcached object cache | — | Redis only | LiteSpeed Cache, W3TC, SiteGround |
| 48 | Separate database-query cache | ◐ | Redis-backed WordPress object cache, fragment API, and REST response cache cover safe application queries; raw SQL interception is deliberately not attempted | W3TC |
| 49 | Varnish integration | ✅ | Adds cache tags/control headers and sends async PURGE | W3TC, WP Rocket |
| 50 | Nginx FastCGI-cache integration | ✅ | Generated FastCGI/static recipe plus configurable targeted/full PURGE endpoint integration | Nginx Helper, host plugins |
| 51 | Static CDN URL rewriting | ✅ | Rewrites `src`, `href`, `srcset`, and lazy-load attributes for selected extensions | WP Rocket, Perfmatters, Autoptimize |
| 52 | Multiple CDN hostnames by asset type | ✅ | Optional CSS, JavaScript, and media origins fall back to the common CDN URL | WP Rocket, W3TC |
| 53 | Built-in CDN | — | User supplies CDN URL | NitroPack, FlyingCDN, QUIC.cloud |
| 54 | Cloudflare API authentication | ✅ | Zone ID plus API token | WP Rocket, Hummingbird, Super Page Cache |
| 55 | Cloudflare purge on local clear | ✅ | Purges the entire Cloudflare zone cache | Many Cloudflare integrations |
| 56 | Granular Cloudflare URL purge | ✅ | Targeted invalidation uses Cloudflare's file URL purge payload; full purge remains available | FlyingPress, Super Page Cache |
| 57 | Configure Cloudflare full-page edge cache | ✅ | Explicit opt-in synchronizes one identified anonymous HTML Cache Rule without replacing unrelated rules | FlyingPress, Super Page Cache, APO |
| 58 | Full-page edge CDN | ◐ | Cloudflare full-page rule integration is complete, but the global network/account is external infrastructure and cannot be bundled in a local plugin | NitroPack, FlyingCDN, QUIC.cloud, APO |
| 59 | CDN cache metrics | — | No edge hit/miss/bandwidth reporting | NitroPack, Cloudflare, CDN services |

### A3. CSS, JavaScript, HTML, and navigation

| # | Feature | WPSeiten | Actual implementation / limitation | Market reference |
|---:|---|:---:|---|---|
| 60 | CSS minification | ✅ | Local enqueued files up to 1 MB; skips already-minified/external files | Most optimizer suites |
| 61 | CSS minification exclusions | ✅ | Handles/filename/path patterns | Most optimizer suites |
| 62 | CSS combination | ✅ | Opt-in local aggregation combines simple enqueued handles while leaving inline/conditional/complex handles untouched for compatibility | LiteSpeed Cache, W3TC, Autoptimize |
| 63 | Remove unused CSS from linked stylesheets | — | Linked `.css` files are not analyzed | WP Rocket, FlyingPress, Perfmatters |
| 64 | Remove unused CSS from inline styles | ◐ | Server-side selector pruning of `<style>` blocks against current DOM | Rare local/no-SaaS differentiator, but risky/narrow |
| 65 | CSS safelist | ✅ | Built-in and user wildcard safelist | WP Rocket, Perfmatters, LiteSpeed Cache |
| 66 | True per-page critical CSS generation | — | Class name says CriticalCSS, but implementation is inline tree-shaking, not above-the-fold rendering analysis | WP Rocket, LiteSpeed/QUIC.cloud, NitroPack |
| 67 | Async/deferred non-critical CSS | ✅ | Opt-in preload/onload delivery preserves critical/media styles and adds a noscript fallback | LiteSpeed Cache, Autoptimize, W3TC Pro |
| 68 | CSS delivery test/safe mode | ✅ | Signed preview-only safe mode, presets, ten-version configuration history, and rollback are implemented | Hummingbird Safe Mode, NitroPack Test Mode |
| 69 | JavaScript minification | ✅ | Local enqueued files up to 1 MB; conservative tokenizer | Most optimizer suites |
| 70 | JS minification exclusions | ✅ | Handle/path/filename patterns | Most optimizer suites |
| 71 | JavaScript combination | ✅ | Opt-in local aggregation skips scripts with inline data, conditionals, or external sources | W3TC, LiteSpeed Cache, Autoptimize |
| 72 | Defer external JavaScript | ✅ | Adds `defer`; excludes critical scripts; lowers fetch priority | WP Rocket, Perfmatters |
| 73 | Defer inline JavaScript | ✅ | Eligible inline blocks can execute in order after DOMContentLoaded | Perfmatters, Autoptimize |
| 74 | Delay external JavaScript until interaction | ✅ | Replaces type/source and restores sequentially | WP Rocket, FlyingPress, Perfmatters |
| 75 | Delay inline JavaScript until interaction | ✅ | Inline blocks of 100+ bytes; 8-second fallback | WP Rocket, Perfmatters |
| 76 | Delay/defer exclusions | ✅ | Built-in critical list plus user patterns | Most commercial optimizers |
| 77 | Delay execution timeout | ✅ | 0–30,000 ms admin control is injected into the interaction bootloader | Perfmatters |
| 78 | Remove unused JavaScript/assets per page | ✅ | Rule-based Script Manager dequeues script/style handles by URL wildcard and visitor role; WooCommerce rules have dedicated toggles | Perfmatters, Hummingbird, Super Page Cache |
| 79 | HTML minification | ✅ | Conservative processor removes comments/inter-tag whitespace while preserving raw-text and preformatted nodes | NitroPack, W3TC, Autoptimize, Breeze |
| 80 | Self-host external CSS/JS | ✅ | Exact administrator-approved CSS/JS URLs are downloaded, CSS-relative paths normalized, and cached locally for seven days | FlyingPress |
| 81 | Lazy-render below-fold HTML/elements | ✅ | Validated selectors receive `content-visibility:auto` and intrinsic-size containment | FlyingPress, Perfmatters, SpeedyCache |
| 82 | Speculation Rules prerender | ✅ | Same-origin moderate prerender with sensitive-path exclusions | Perfmatters, newer performance plugins |
| 83 | Hover/intersection prefetch fallback | ✅ | Legacy-browser fallback uses prefetch | WP Rocket Preload Links, FlyingPress |
| 84 | DNS prefetch UI | ✅ | Capability-focused Web Experience screen emits validated absolute origins | W3TC, SpeedyCache, SiteGround |
| 85 | Preconnect UI | ✅ | User-defined origins receive crossorigin preconnect hints | Perfmatters, SpeedyCache |
| 86 | Generic resource preload UI | ✅ | Images, CSS, JS, fonts, and fetch resources receive inferred `as` attributes | Perfmatters, Hummingbird, SpeedyCache |

### A4. Images, iframes, video, and fonts

| # | Feature | WPSeiten | Actual implementation / limitation | Market reference |
|---:|---|:---:|---|---|
| 87 | Image lossy compression | ✅ | Local Imagick/GD pipeline provides quality control and size-aware quality adjustment | ShortPixel, Imagify, EWWW, Smush |
| 88 | Image lossless compression | ◐ | Lossless-oriented PNG/high-fidelity mode is local; truly lossless JPEG recompression still needs a server jpegtran-style codec | ShortPixel, Imagify, EWWW, TinyPNG |
| 89 | Smart/content-aware compression | ◐ | Pixel-count-aware quality and focal-coordinate provider exist; ML/vision-aware quality requires an optional provider | Optimole, Imagify, TinyPNG |
| 90 | Automatic optimization on upload | ✅ | Runs after WordPress metadata generation and updates resized full-image dimensions | All dedicated image optimizers |
| 91 | Bulk optimization of existing library | ✅ | Resumable 100-item media-library batches expose progress and safely pause when the tab closes | All dedicated image optimizers |
| 92 | Original-image backup and restore | ✅ | Sidecar originals are created once; per-attachment restore updates metadata dimensions | ShortPixel, Imagify, EWWW, reSmush.it |
| 93 | Resize oversized uploads | ✅ | Configurable width/height bounds use proportional resize or opt-in focal crop | ShortPixel, Imagify, TinyPNG, SiteGround |
| 94 | Optimize thumbnails/image sizes selectively | ✅ | Empty means all sizes; explicit WordPress size-name allowlist narrows processing | ShortPixel, LiteSpeed Cache, TinyPNG |
| 95 | Optimize custom folders | ✅ | Validated custom roots are available to the public API and recursive WP-CLI `--path` processing | ShortPixel, EWWW, Converter for Media |
| 96 | PDF optimization | ◐ | PDF files enter the backup/provider pipeline, but built-in raster rewrite is refused because it would destroy vector text; a preserving provider must implement `wpsc_optimize_image_file` | ShortPixel, Imagify, EWWW Pro |
| 97 | Animated GIF optimization | ◐ | Imagick coalesces, optimizes, and reconstructs multi-frame GIF/APNG files; unavailable without Imagick | ShortPixel, EWWW, NitroPack |
| 98 | EXIF preserve/strip control | ✅ | Metadata preservation is explicit; default strips profiles/metadata for size | ShortPixel, WP-Optimize, TinyPNG, reSmush.it |
| 99 | Generate WebP files | ◐ | Generates sidecar files with Imagick or GD and reports real codec availability in admin | Most image optimizers |
| 100 | Generate AVIF files | ◐ | Generates sidecar files when Imagick/GD provides AVIF; unavailable codec is reported instead of hidden | LiteSpeed, Imagify, EWWW, Smush, Optimole |
| 101 | Browser-aware next-gen fallback | ✅ | Local variants are wrapped in ordered AVIF/WebP `<picture>` sources with original fallback | Dedicated image optimizers/CDNs |
| 102 | Image CDN | ◐ | Generic CDN rewrite can deliver images, but does not transform/optimize them | Optimole, EWWW Easy IO, Smush CDN |
| 103 | On-the-fly adaptive resize | — | Intentionally not provided: secure transform-at-request scale requires a dedicated image service/cache and cannot be equivalent inside a normal WordPress request | Optimole, NitroPack, Cloudinary, Cloudflare Images |
| 104 | Generate responsive `srcset`/`sizes` | ✅ | Missing attributes are generated from WordPress attachment metadata and work with CDN/next-gen delivery | FlyingPress, Optimole, Smush |
| 105 | Image lazy loading | ✅ | Native `loading="lazy"` plus `decoding="async"` | Nearly all frontend optimizers |
| 106 | CSS background-image lazy loading | ◐ | Inline background URLs use IntersectionObserver; linked-stylesheet background discovery remains a browser/CSS-analysis task | WP Rocket, Perfmatters, Optimole |
| 107 | Iframe lazy loading | ✅ | Native `loading="lazy"` | WP Rocket, FlyingPress, Smush |
| 108 | Video lazy loading | ✅ | Native video/source URLs move to data attributes until an observer approaches the viewport | FlyingPress, Cloudinary |
| 109 | YouTube facade | ✅ | Replaces iframe with thumbnail/player bootloader | WP Rocket, FlyingPress, Perfmatters |
| 110 | Vimeo/Google Maps facade | ✅ | Both use a lightweight click-to-load surface; YouTube retains its thumbnail-specific facade | Perfmatters, W3TC Pro |
| 111 | Missing image dimensions | ✅ | Local physical images use cached dimensions in the independent frontend pipeline, regardless of page caching | WP Rocket, FlyingPress, Perfmatters |
| 112 | Automatically detect above-fold/LCP image | ✅ | Anonymous sampled browser LCP observations teach a per-path image map; leading-image fallback covers unsampled/new pages | FlyingPress, NitroPack, WP Rocket |
| 113 | Configurable leading-image lazy-load exclusion | ✅ | Numeric first-image count, default three | Perfmatters |
| 114 | LCP image preload | ✅ | Emits a real high-priority image preload for learned or leading LCP candidates | FlyingPress, NitroPack, Smush |
| 115 | LQIP/blur placeholder | ✅ | Image jobs create tiny low-quality JPEG previews and lazy markup uses them as background placeholders, with a neutral fallback | LiteSpeed/QUIC.cloud, Optimole, Smush |
| 116 | Smart crop/focal subject | ◐ | Opt-in focal crop plus `wpsc_image_crop_focus` provider filter; automatic vision subject detection needs a provider | ShortPixel, Optimole, Cloudinary |
| 117 | Retina/DPR-aware delivery | ✅ | WordPress responsive candidates and generated `srcset/sizes` provide DPR-aware browser selection | Optimole, EWWW, Cloudinary |
| 118 | Watermarking | ◐ | Attachment-ID watermark compositing is implemented when Imagick is available | Optimole, EWWW Pro |
| 119 | Media offload/cloud library | — | None | Optimole, Cloudinary |
| 120 | AI alt text/captioning | ◐ | Safe opt-in provider filter writes only missing alt text; no third-party AI service or data transfer is bundled | ShortPixel (beta), media/DAM products |
| 121 | Localize Google Fonts | ✅ | Downloads Google CSS and font files to local cache | Perfmatters, FlyingPress, WP Rocket |
| 122 | `font-display: swap` | ✅ | Rewrites inline/downloaded `@font-face` rules | Most frontend optimizers |
| 123 | Font preload | ✅ | Manual font URLs receive preload, inferred font type, and crossorigin attributes | WP Rocket, FlyingPress, Perfmatters |
| 124 | Font subsetting | — | None | NitroPack |
| 125 | Font conversion/compression to WOFF2 | — | None | NitroPack |
| 126 | System-font-first strategy | ✅ | Opt-in system stack is injected through a dedicated CSS variable/style | FlyingPress |
| 127 | Local Gravatar cache | ✅ | Official-host avatar responses are validated and cached locally for seven days | FlyingPress, LiteSpeed Cache, SpeedyCache |

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
| 135 | Orphan metadata/table analysis | ✅ | Reports and selectively removes orphan post, comment, term, and user metadata with join-based queries | WP-Optimize Premium |
| 136 | Scheduled database cleanup | ✅ | Disabled/hourly/daily/weekly controls | WP-Optimize, WP Rocket |
| 137 | Disable emojis | ✅ | Frontend/admin/feed/mail hooks | Perfmatters, LiteSpeed Cache |
| 138 | Disable embeds | ✅ | Discovery, host JS, TinyMCE, rewrite rules | Perfmatters, SpeedyCache |
| 139 | Disable XML-RPC | ✅ | Filter plus Apache file block/header removal | Perfmatters, SpeedyCache |
| 140 | Block user enumeration | ✅ | Author redirects plus REST endpoint removal plus Apache query rule | Security/performance overlap |
| 141 | Hide WordPress version | ✅ | Generator removal | Perfmatters/security plugins |
| 142 | Remove WLW/RSD links | ✅ | Head-link removal | Perfmatters |
| 143 | Remove shortlink | ✅ | Head-link removal | Perfmatters |
| 144 | Disable RSS feeds | ✅ | Visible Tweaks toggle removes discovery links and disables feed behavior | Perfmatters, SpeedyCache |
| 145 | Disable self-pingbacks | ✅ | Removes same-site URLs before ping | Perfmatters |
| 146 | Remove jQuery Migrate | ✅ | Removes dependency for frontend jQuery | Perfmatters, SpeedyCache |
| 147 | Remove Dashicons for visitors | ✅ | Keeps them for logged-in admin-bar users | Perfmatters, SpeedyCache |
| 148 | Remove version query strings | ✅ | Removes `ver` from CSS/JS URLs | Breeze, SiteGround |
| 149 | Granular Heartbeat frequency | ✅ | 15–120 seconds | WP Rocket, Perfmatters, Breeze |
| 150 | Disable Heartbeat by area | ✅ | Admin, dashboard, editor, frontend | Perfmatters, SiteGround |
| 151 | Disable WooCommerce cart fragments | ✅ | Dedicated toggle dequeues `wc-cart-fragments` | Hummingbird, Perfmatters, SpeedyCache |
| 152 | Disable WooCommerce scripts/styles by page | ✅ | Dedicated non-commerce unload plus general URL/role Script Manager rules | Perfmatters, SpeedyCache |
| 153 | Security response headers | ✅ | HSTS on HTTPS, nosniff, frame policy, referrer and permissions policies | Unusual for cache plugins |
| 154 | Cache/Redis dashboard | ✅ | Page hits/misses/stale hits/hit ratio/bytes, cache files/size, and Redis health/hit data are recorded | W3TC stats, NitroPack dashboard |
| 155 | Configurable metrics retention | ✅ | RUM samples are age-pruned and bounded; cache traffic uses constant-size cumulative counters | Monitoring suites |
| 156 | Lab performance tests | ◐ | Authenticated HTTP loopback test reports status, total time, bytes, and cache status; full Lighthouse rendering needs a browser service/CLI | Hummingbird, SiteGround |
| 157 | Real-user Core Web Vitals | ✅ | Privacy-conscious 10% local sampling stores LCP, CLS, INP, TTFB and p75 summaries, and trains LCP image detection | FlyingPress, NitroPack/Rocket add-ons |
| 158 | Uptime monitoring | ✅ | Hourly/daily same-origin cron check records status, latency, and availability locally | Hummingbird Pro |
| 159 | Settings import/export | ✅ | Versioned JSON omits secrets on export and validates/retains secrets on import | WP Rocket, Breeze, LiteSpeed Cache |
| 160 | Presets | ✅ | Safe, balanced, and aggressive presets are explicit admin actions | LiteSpeed Cache, NitroPack |
| 161 | Safe/test mode | ✅ | Risky transformations are disabled publicly and enabled through signed preview URLs | Hummingbird, NitroPack |
| 162 | Version/config rollback | ✅ | Last ten changed configurations are timestamped and one-click restorable | WP Rocket, preset/config products |
| 163 | WP-CLI | ✅ | Purge, targeted purge, sitemap preload, image optimize/restore/custom folders, database cleanup, and status commands | W3TC, LiteSpeed Cache, image leaders |
| 164 | Multisite/network controls | ✅ | Network settings page can enable and populate a centralized profile while cache paths remain site/host isolated | W3TC, Autoptimize, image leaders |
| 165 | Public developer API/hooks | ✅ | Fragment API, lifecycle/image/provider hooks, REST controls, CLI surface, and `docs/DEVELOPER_API.md` are documented | W3TC, LiteSpeed Cache, ShortPixel |
| 166 | Automated tests | ✅ | Zero-dependency suite covers unit, integration, lifecycle, drop-in subprocess, and deterministic build behavior | Mature commercial products |
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

> **0.2.0 reconciliation:** Items 1–3 and 5–16 have been implemented locally or through explicit provider/server boundaries. Item 4 is implemented as a local Imagick/GD engine with honest codec diagnostics. The only intentionally incomplete portions are browser/SaaS/infrastructure capabilities listed in the executive conclusion and in the limitation register below.

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

### 0.2.0 limitation register

| Capability | Why it is not marked native-complete | Extension point / safe alternative |
|---|---|---|
| Memcached object-cache drop-in | WordPress permits one object-cache drop-in and this release preserves the signed/compressed Redis implementation; a daemon/client cannot be bundled | PHP Memcached is diagnosed; a future selectable drop-in backend can implement the same lifecycle |
| Redis Sentinel/cluster/replication | Requires a topology-aware client and operator endpoints/credentials | Redis TLS is complete; constants and provider boundaries remain available for managed Redis |
| Bundled/global CDN and transforming image CDN | Requires global infrastructure, billing, origin controls, and abuse protection | Multi-origin static CDN plus Cloudflare full-page rule/purge integration |
| Linked used CSS and true critical CSS | Accurate output requires running the final page in a browser at multiple viewport/state combinations | Inline pruning is accurately labeled; async CSS, safelist, signed test mode, and rollback reduce risk |
| Media offload/cloud library | Needs remote storage credentials, lifecycle policy, and URL ownership | `wpsc_*` provider hooks and multi-CDN origins |
| Font subsetting/conversion | Safe subsetting requires shaping/font binaries and script/language coverage | Local Google fonts, font-display, manual preloads, system stack, and provider-ready architecture |
| Bundled AI alt text | Would transmit media to a third party and require a model/account/privacy agreement | Opt-in `wpsc_generate_image_alt_text` filter writes only missing alt text |
| Full Lighthouse/PageSpeed lab | Needs Chromium or a remote lab service | Authenticated origin HTTP lab plus local Core Web Vitals RUM/p75 |
| External uptime observer | Origin cron cannot detect a total origin outage while it is down | Local scheduled availability history; external monitors can consume the public site independently |

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
