# WPS-Cache 0.3.0 manual test checklist

Run this checklist on a disposable staging copy, not directly on production.
Keep a database/files backup, browser developer tools, a logged-out/incognito
window, and filesystem/SSH access available. Tests marked **conditional** need
the named server extension, daemon, proxy, or provider account.

Record the environment before testing:

- WordPress/PHP/server versions:
- Single site or multisite:
- Active theme and WooCommerce version:
- Imagick formats (`WEBP`, `AVIF`, `PDF`) and/or GD formats:
- Redis/Memcached/Varnish/Nginx/Cloudflare availability:
- jpegtran/Ghostscript/pyftsubset paths and versions:
- Optional S3-compatible/PageSpeed/OpenAI/dead-man test accounts:
- Test date and tester:

## 1. Install, upgrade, navigation, and build

- [ ] Run `php tools/lint.php`; every shipped PHP file passes.
- [ ] Run `php tests/run.php`; all tests pass.
- [ ] Run `php tools/build.php` twice; the SHA-256 is identical both times.
- [ ] Install `dist/wps-cache-0.3.0.zip` on a clean staging site and activate it without a PHP error or unexpected `.htaccess` edit.
- [ ] Upgrade an existing 0.1.x install; owned drop-ins update, settings remain, and third-party drop-ins are not overwritten.
- [ ] Confirm the sidebar is grouped into Overview, Caching, Frontend, Infrastructure, and Operations.
- [ ] Open every tab: Dashboard, Cache Rules, Delivery Rules, File Optimization, Web Experience, Media Delivery, Image Engine & Offload, CDN & Edge, Database, Monitoring, Tweaks, and Tools & Diagnostics.
- [ ] Resize the admin viewport below 960 px; navigation remains usable and the active tab scrolls into view.
- [ ] Save a setting on each new screen; the button enters a loading state and the saved value survives reload.
- [ ] Confirm Tools & Diagnostics accurately reports writable cache, Redis, Memcached, image encoder, Brotli, WP-CLI, and multisite capabilities.

## 2. Page-cache identity and early delivery

- [ ] Enable Page Caching, set TTL to 120 seconds, request a logged-out page twice, and confirm `MISS` then `HIT` in `X-WPS-Cache`.
- [ ] Inspect `wp-content/cache/wps-cache/runtime.php`; `ttl` is 120 and no Cloudflare/Redis secret is present.
- [ ] Confirm the cached response has the configured `Cache-Control`, `ETag`, `Content-Length`, security headers, `Vary`, and correct content type.
- [ ] Send the returned ETag as `If-None-Match`; confirm a 304 response.
- [ ] Request with gzip support; confirm the precomputed `.gz` file is used. **Conditional:** repeat for Brotli when diagnosed available.
- [ ] Confirm `page-metrics.json` increments misses on generation and hits/bytes on early delivery; Dashboard shows page hit ratio.
- [ ] Set device mode to Shared; desktop, phone, and tablet use one cache filename.
- [ ] Set device mode to Mobile + desktop; phone uses `index-mobile.html`, desktop uses `index.html`.
- [ ] Set device mode to Tablet + mobile + desktop; iPad uses `index-tablet.html`, phone uses `index-mobile.html`, desktop uses `index.html`.
- [ ] In Variants mode request `?color=blue&page=2` in different parameter orders; confirm one canonical hashed variant.
- [ ] Request with `utm_source`, `utm_campaign`, `fbclid`, and `gclid`; confirm the normal canonical cache entry is used and no tracking-specific files appear.
- [ ] Add a wildcard ignored parameter such as `campaign_*`; confirm it is removed from cache identity.
- [ ] Add a denied parameter; confirm a matching request bypasses both early read and runtime write.
- [ ] Switch to Allowlist mode; allowed parameters cache, ignored tracking parameters collapse, and unknown parameters bypass.
- [ ] Switch to Ignore-all mode; benign query parameters share the no-query entry while denied parameters still bypass.
- [ ] Add a custom bypass cookie fragment and send that cookie; confirm no early hit and no cache write.
- [ ] Add a user-agent exclusion and send the matching agent; confirm bypass at early and runtime layers.
- [ ] Add a URL exclusion; confirm path and subpath requests bypass.
- [ ] Confirm logged-in administrators bypass public early cache.
- [ ] Define `WPSC_PRIVATE_CACHE_DIR` outside the public web root, add `subscriber` to logged-in roles, visit as two subscribers, and confirm distinct `user-<id>-role-subscriber` runtime variants are stored there while the public early drop-in never serves them. Remove the constant and confirm logged-in caching safely disables.
- [ ] Enable Search cache, request two distinct searches, and confirm canonical variants; disable it and confirm search bypass.
- [ ] Enable Feed cache and request `/feed/`; confirm it can be cached and early hits use `application/rss+xml`. Disable and confirm bypass.

## 3. Purge, rebuild locking, and warmup

- [ ] Generate a post, home, post-type archive, and term archive cache; update the post and confirm only the related URL directories are invalidated.
- [ ] Use Delivery Rules → Targeted purge for a same-origin URL; confirm its desktop/mobile/tablet/query variants disappear.
- [ ] Submit an external URL to Targeted purge; confirm it is rejected.
- [ ] Use toolbar/admin Purge All; confirm HTML, Redis (if active), Varnish/Nginx/Cloudflare (if active), transients, and owned opcode entries are handled.
- [ ] Let an entry expire inside the stale window and issue concurrent requests; exactly one falls through to regenerate while the others receive `X-WPS-Cache: STALE`.
- [ ] Leave a stale `.lock` older than the configured lock TTL; confirm it is discarded and regeneration resumes.
- [ ] Set stale TTL to zero; expired entries synchronously fall through instead of serving stale.
- [ ] Start manual preload; confirm sitemap URLs are gathered beyond the old 200-item limit and progress honors the configured concurrency.
- [ ] With Tablet mode, confirm manual preload requests desktop, mobile, and tablet variants.
- [ ] Trigger scheduled preload and inspect `wpsc_preload_queue`; batches advance by the configured size and schedule the next worker until complete.
- [ ] Use Sitemap discovery with nested WordPress sitemap indexes; confirm same-origin child sitemaps are followed.
- [ ] Break the sitemap endpoint; confirm the WordPress database fallback still discovers published URLs.
- [ ] Put an external `<loc>` into a sitemap; confirm the URL guard refuses it.

## 4. REST and fragment cache APIs

- [ ] Enable REST cache with `/wp/v2/posts*`, request it anonymously twice, and confirm `X-WPS-REST-Cache: MISS` then `HIT`.
- [ ] Confirm authenticated REST requests, non-GET mutations, error responses, and routes outside the allowlist never cache.
- [ ] Leave the route list empty and confirm `/wp/v2/users` remains excluded by default.
- [ ] Purge all and confirm indexed REST transients and their key option are removed.
- [ ] In a staging theme call `FragmentCache::remember()` twice around an incrementing producer; confirm the producer runs once until TTL expiry.
- [ ] Call `FragmentCache::forget()` and confirm the producer runs again.
- [ ] Repeat with Redis drop-in active and confirm the fragment group persists across requests.
- [ ] Enable Fragment hole punching, add `FragmentCache::placeholder('account-summary', 'Loading…')` to a cached public template, and provide `wpsc_render_fragment_account-summary`; confirm each browser fetch receives current markup while the surrounding page remains a cache HIT.
- [ ] Inspect the fragment request: it is same-origin and signed, and the response sends `Cache-Control: private, no-store`.
- [ ] Alter the fragment key/token and confirm 403; exceed the bounded request rate on staging and confirm 429.
- [ ] Disable hole punching; confirm no loader is printed and the fallback markup remains.

## 5. Redis, Memcached, reverse proxies, and server recipes

- [ ] Enable Redis in Cache Rules; confirm the owned `object-cache.php` and mode-restricted `object-runtime.php` are installed automatically.
- [ ] Confirm host, port, database, password, prefix, and TLS values in `object-runtime.php` match the UI and PHP constants still override them.
- [ ] **Conditional:** connect to normal Redis and confirm Dashboard health/hit ratio.
- [ ] **Conditional:** enable TLS against a TLS Redis endpoint and confirm both runtime health and WordPress object-cache operations work.
- [ ] Disable Redis; confirm the owned drop-in and secret runtime file are removed, but a foreign drop-in would be preserved.
- [ ] Enable Memcached; confirm the selectable Memcached `object-cache.php` and mode-restricted runtime configuration are installed and Redis is automatically disabled.
- [ ] **Conditional:** connect to Memcached and exercise add/get/set/delete/increment/decrement, multi-get/set/delete, global groups, non-persistent groups, runtime flush, group flush, and full namespace invalidation.
- [ ] Confirm the configured host, port, prefix, and persistent ID appear in the early runtime file and Dashboard health/stats identify Memcached rather than Redis.
- [ ] Switch from Memcached to Redis and back; only the owned drop-in changes, cached keys from other WordPress sites sharing the daemon are not globally flushed, and a foreign drop-in is never overwritten.
- [ ] **Conditional:** enable Varnish, update a categorized post, and confirm post/archive/term PURGE tags plus the configured TTL headers.
- [ ] Enable Nginx purge against a test receiver; confirm URL purges include the encoded path and full purge uses `/*`.
- [ ] Copy the generated Nginx recipe; confirm TTL, custom bypass cookies, FastCGI bypass rules, cache-status header, and static-asset policy reflect settings.
- [ ] Copy the Apache static-asset recipe and validate it with the staging server before applying it manually.
- [ ] Confirm WPS-Cache itself never writes these generated recipes into Nginx/Apache configuration.

## 6. CDN and Cloudflare

- [ ] Enable CDN rewrite with only a common origin; local CSS, JS, fonts, images, and video URLs rewrite, while admin/preview/external URLs do not.
- [ ] Configure separate CSS, JS, and media origins; each extension class uses the correct hostname.
- [ ] Confirm `srcset`, lazy `data-src`, and query/fragment suffixes remain valid after rewriting.
- [ ] **Conditional:** configure a least-privilege Cloudflare token/zone and purge one URL; Cloudflare receives a `files` purge, not `purge_everything`.
- [ ] **Conditional:** Purge All sends the full-zone purge.
- [ ] **Conditional:** opt into the full-page Cache Rule and save; one rule with ref `wps_cache_full_page` is created/updated without deleting unrelated rules.
- [ ] Disable the Cloudflare option and confirm no API requests occur on local purges.

## 7. CSS, JavaScript, HTML, and safe mode

- [ ] Enable simple CSS combination with at least two local styles; one deterministic bundle appears and complex/inline/conditional handles stay separate.
- [ ] Modify a source stylesheet; confirm a new bundle hash is produced.
- [ ] Enable rendered CSS profiles and open the Tools signed-preview URL as a logged-in administrator once on desktop, mobile, and tablet; confirm bounded per-URL/device profiles are written below the WPS cache directory through the authenticated, signed same-origin endpoint. A logged-out visitor must not receive the capture script.
- [ ] Alter the CSS-profile signature/path or post an oversized payload; confirm it is rejected and cannot write outside the profile directory.
- [ ] Enable true rendered critical CSS; on the next visit confirm recorded above-fold rules are inlined for the matching URL/device profile and the page cache was purged after the first profile arrived.
- [ ] Enable linked-stylesheet used-CSS replacement; confirm only recorded same-origin styles receive generated used-CSS files, with async loading and a noscript fallback. External or unprofiled links remain untouched.
- [ ] Confirm CSS profiles older than the configured retention are pruned and desktop/mobile/tablet profiles do not leak into one another.
- [ ] Enable async CSS; eligible links become preload/onload links with an unchanged `<noscript>` fallback. Critical/media links remain blocking.
- [ ] Enable simple JavaScript combination; eligible local scripts bundle, while external scripts and handles with inline data/conditionals remain untouched.
- [ ] Enable Defer inline JavaScript; a 100+ byte block executes after DOMContentLoaded and excluded/tiny/JSON-LD blocks remain unchanged.
- [ ] Set interaction delay timeout to 0, 5000, and 30000; inspect the bootloader and confirm the selected value.
- [ ] Verify delayed external and inline scripts restore in source order on mouse, key, scroll, or touch interaction.
- [ ] Select Idle strategy; scripts start through `requestIdleCallback` (or its timeout fallback) without interaction.
- [ ] Select Consent strategy; scripts stay delayed until `window.dispatchEvent(new Event('wpsc:consent'))`, then restore in source order. Verify the optional timeout still acts as a deliberate fallback and zero means no timeout.
- [ ] Add a delay/defer exclusion and confirm matching source/content is untouched.
- [ ] Enable HTML minification; comments/inter-tag whitespace shrink while `<pre>`, `<textarea>`, `<script>`, `<style>`, and `<template>` contents remain exact.
- [ ] Add lazy-render selectors; matching below-fold elements receive the generated `content-visibility` rule and retain layout with intrinsic sizing.
- [ ] Add Script Manager rules for script/style, URL wildcard, visitor, and a logged-in role; only matching handles dequeue and `wpsc_asset_unloaded` fires.
- [ ] Enable WooCommerce asset unloading; shop/cart/checkout/account retain assets while unrelated pages dequeue common handles.
- [ ] Enable cart-fragment removal; `wc-cart-fragments` is absent.
- [ ] Enable safe/test mode; a normal visitor receives none of the risky transformations.
- [ ] Open the signed preview button; the preview receives transformations. Alter/remove the nonce and confirm it does not.
- [ ] Apply Safe, Balanced, and Aggressive presets one at a time; verify their documented optimization choices.
- [ ] Roll back after a preset/save; the previous configuration returns. Repeat until the ten-snapshot bound is exercised.

## 8. Resource hints, fonts, navigation, and external assets

- [ ] Add DNS-prefetch and preconnect origins; validate generated links and `crossorigin` on preconnect.
- [ ] Add image, CSS, JS, font, and other preload URLs; verify inferred `as` values and font crossorigin.
- [ ] Add explicit font preloads; verify they render independently of page cache.
- [ ] Configure an exact external CSS URL for self-hosting; it caches under `external-assets`, rewrites relative `url()` values to absolute, and reuses the file for seven days.
- [ ] Configure an exact external JS URL; it is localized. A URL not listed by the administrator remains external.
- [ ] Keep Google Fonts localization on; CSS/font files cache locally and `font-display:swap` is enforced.
- [ ] Enter a representative brand glyph set in Google Fonts subset text; the Google request includes `text=` and the localized WOFF2 contains the required characters.
- [ ] Enable automatic localized-font preload; up to four actual localized files receive deduplicated preload links without manually copying URLs.
- [ ] **Conditional FontTools:** define `WPSC_PYFTSUBSET_BINARY` as a trusted absolute pyftsubset path, localize a font with subset text, and verify a valid `wOF2` output is produced with requested glyphs. An invalid/missing binary leaves the original font valid and reports unavailable diagnostics.
- [ ] Enable system-font-first; the dedicated system-stack style appears and can be disabled cleanly.
- [ ] Enable local Gravatars; an official Gravatar image caches locally for seven days. A non-Gravatar host is never downloaded by this feature.
- [ ] Enable Speculation Rules in a modern browser; sensitive/admin/cart/account paths are excluded.
- [ ] Test a browser without Speculation Rules (or force fallback); hover/intersection prefetch works.

## 9. Responsive media and facades

- [ ] Render a WordPress attachment without `srcset/sizes`; responsive attributes are generated from attachment metadata.
- [ ] Confirm externally hosted/non-attachment images are left unchanged by responsive generation.
- [ ] Confirm missing local width/height is added even when page caching is disabled.
- [ ] Enable RUM and gather an LCP sample; `wpsc_lcp_images` stores only page/image paths, and the learned image is eager/high-priority on the next render.
- [ ] Scroll several images through the initial viewport with RUM active; `wpsc_above_fold_images` learns the actual above-fold set and all learned images are excluded from lazy loading on the next render.
- [ ] On a new/unsampled page, the configured leading-image count remains the fallback.
- [ ] Confirm a real `<link rel="preload" as="image" fetchpriority="high">` is emitted for the learned/leading LCP candidate.
- [ ] Enable lightweight placeholders, reprocess images, and confirm `.wps-lqip.jpg` previews are generated and used behind below-fold lazy images; unprocessed images use the neutral fallback.
- [ ] Enable lazy CSS backgrounds; other inline style declarations remain, the URL moves to `data-wpsc-bg`, and loads near viewport.
- [ ] Generate AVIF/WebP sidecars for an inline CSS JPEG/PNG background; confirm it becomes browser-native `image-set()` with AVIF/WebP/original type candidates and preserves other declarations.
- [ ] Enable native video lazy load; `<video src>` and nested `<source>` URLs load only near viewport.
- [ ] Confirm image and iframe native lazy load exclusions still work.
- [ ] Enable YouTube facade; thumbnail/button replaces the player and click loads the embed.
- [ ] Enable Vimeo and Google Maps facades; lightweight click surfaces replace their iframes and load the original URL on click.
- [ ] Enable adaptive delivery and open a local attachment in browsers with AVIF, WebP, and original-only support; signed width-descriptor srcsets negotiate the best locally supported format and never upscale past the source.
- [ ] Request several adaptive widths/qualities twice; the first request generates a derivative, later requests redirect to the immutable cached asset, invalid signatures return 403, and traversal/foreign paths return 404.
- [ ] Verify PNG alpha remains transparent in PNG/WebP/AVIF derivatives and animated GIFs are deliberately left on their original delivery path.
- [ ] Configure a media CDN origin; generated adaptive asset URLs use that origin while the signed transformer remains same-origin.

## 10. Local image engine

- [ ] Open Image Engine and compare reported Imagick/GD/WebP/AVIF/GIF/EXIF capabilities with `phpinfo()`/server tools.
- [ ] Enable upload automation and upload JPEG and PNG files; originals and selected thumbnail sizes process once.
- [ ] Verify a PHP-guarded `.wps-original.php` backup is created before resize/re-encode, returns no image bytes when requested directly, and is not overwritten by a second optimization.
- [ ] Use lossy quality settings on a large photo; file size/stat counters change and the image remains visually acceptable.
- [ ] Compare simple and high-detail photos at one quality; smart content complexity preserves more quality for detailed imagery while keeping outputs valid.
- [ ] Enable variant-only mode; hash the source before/after and confirm it stays byte-for-byte identical while WebP/AVIF/LQIP sidecars are created.
- [ ] Enable lossless/high-fidelity mode; PNG is processed losslessly. **Conditional jpegtran:** define `WPSC_JPEGTRAN_BINARY`, verify progressive lossless JPEG optimization, and confirm invalid output is rejected without replacing the source.
- [ ] Set maximum dimensions; oversized originals shrink proportionally and attachment metadata reports the new size.
- [ ] Enable focal crop; output matches target bounds. Attach `wpsc_image_crop_focus` and verify custom x/y coordinates move the crop.
- [ ] Remove the custom focus filter and use an off-center high-contrast subject; built-in edge-saliency moves the smart crop toward it instead of always centering.
- [ ] Toggle EXIF preservation; inspect metadata before/after.
- [ ] Select named thumbnail sizes; only selected sizes and the full original process. Empty allowlist processes all sizes.
- [ ] Add an exclusion path fragment; matching media is skipped.
- [ ] Run Optimize media library; progress reaches the total, individual failures do not abort later items, and reload safely pauses work.
- [ ] Enable background optimization; trigger `wpsc_image_background_optimize` repeatedly and confirm bounded batches advance the cursor, mark completion and unschedule at the end, and never overlap into an unbounded request. Disable then re-enable to reset a completed run.
- [ ] Enter an attachment ID in Restore original; exact backup content returns and full-size dimensions update.
- [ ] **Conditional WebP:** enable generation, optimize JPEG/PNG, confirm `.webp` sidecars and `<picture>` WebP source with original fallback.
- [ ] **Conditional AVIF:** repeat for AVIF and confirm AVIF precedes WebP in `<picture>`.
- [ ] In a browser without AVIF/WebP support, confirm the original image still renders.
- [ ] **Conditional Imagick:** optimize animated GIF/APNG and confirm animation/frame count survives.
- [ ] Submit a PDF without Ghostscript/provider; confirm destructive Imagick raster rewrite is refused. **Conditional Ghostscript:** define `WPSC_GHOSTSCRIPT_BINARY`, optimize a multi-page text/vector PDF, verify page count/selectable text/vectors remain, and confirm invalid output is rejected before replacement.
- [ ] **Conditional Imagick:** set a watermark attachment ID and confirm bottom-right compositing; zero disables it.
- [ ] Configure a custom folder below `wp-content`; run `wp wps-cache images --path=<folder>` and confirm recursive supported-file processing only inside allowed roots.
- [ ] Run the same command with `--restore`; backups restore.
- [ ] Enable alt-text provider mode with the built-in OpenAI toggle off and no filter; confirm no media leaves the site and no fake alt text is written.
- [ ] Attach `wpsc_generate_image_alt_text`, return a test description, optimize an attachment with empty alt text, and confirm it is stored; existing alt text remains untouched.
- [ ] **Conditional OpenAI:** explicitly enable the built-in provider, set a staging API key/model, optimize supported JPEG/PNG/WebP and a non-animated GIF with missing alt fields, and confirm concise language-appropriate text is stored once.
- [ ] Confirm OpenAI receives no request for existing alt text, unsupported files, animated GIFs, files over 10 MiB, disabled toggles, or when the custom filter already returned text; API errors fire `wpsc_alt_text_error` without exposing the key.
- [ ] Leave the OpenAI key field blank on a later save and confirm the existing key remains; export settings and confirm it is blank. Repeat with `WPSC_OPENAI_API_KEY` and verify the constant takes precedence.

## 11. Media offload, database, monitoring, and privacy

- [ ] Attach a test implementation to `wpsc_offload_media_file`; upload media and confirm verified HTTPS manifest entries rewrite original and srcset URLs while a failed/unverified result never deletes local data.
- [ ] **Conditional S3-compatible storage:** enable the built-in adapter with a disposable bucket, upload an original plus thumbnails, and verify signed PUT objects preserve upload-relative keys and public URLs use the configured CDN/custom origin.
- [ ] Enable delete-local only after the storage check; verified thumbnails may be removed but the original always remains local. Delete the attachment and confirm signed DELETE requests remove manifest objects.
- [ ] Confirm S3 endpoint/public URL require HTTPS, credentials survive blank saves, exports omit both keys, and `WPSC_S3_ACCESS_KEY`/`WPSC_S3_SECRET_KEY` override stored values.
- [ ] Attempt an upload outside the WordPress uploads directory and one over 64 MiB; built-in offload refuses both.

- [ ] Database tab reports orphan post/comment/term/user metadata counts.
- [ ] On a backed-up test dataset select each orphan cleanup independently; only rows whose parent is missing are deleted.
- [ ] Scheduled cleanup honors the four new orphan toggles and existing cleanup toggles.
- [ ] Enable RUM, load enough public pages for sampling, and confirm only timing values and sanitized paths are stored—no cookies, IP fields, user IDs, or query strings.
- [ ] Confirm LCP, CLS, INP, and TTFB p75 values appear through the metrics collector after samples exist.
- [ ] Set retention to one day, add an older synthetic sample, submit a new beacon, and confirm the old sample is pruned.
- [ ] Enable uptime checks hourly/daily; trigger cron and verify status/duration history and Dashboard availability calculations.
- [ ] Configure a disposable HTTPS dead-man heartbeat URL; each cron run sends status/duration JSON non-blockingly. Disable it and confirm no outbound request; export settings and confirm the private URL is omitted.
- [ ] Run the authenticated HTTP lab check without a PageSpeed key; result includes local HTTP status, total ms, bytes, and cache status.
- [ ] **Conditional PageSpeed:** configure an API key/desktop or mobile strategy, run the lab check, and confirm official Lighthouse performance score, FCP, LCP, Speed Index, TBT, CLS, and top opportunities are stored/displayed.
- [ ] Leave the PageSpeed key blank on a later save and confirm it remains; export settings and confirm it is omitted. Simulate API failure and confirm the prior/local monitoring path stays usable.
- [ ] Call the lab endpoint logged out; permission is denied.

## 12. Settings transfer, CLI, multisite, and developer hooks

- [ ] Export settings JSON; format/version/timestamp exist and Redis, Cloudflare, PageSpeed, heartbeat, S3, and OpenAI secrets are blank.
- [ ] Import a valid export with blank secrets; existing secrets are retained and other values update.
- [ ] Import invalid JSON/wrong format; it is rejected without changing settings.
- [ ] Run `wp wps-cache status`; output reports version and active cache layers.
- [ ] Run `wp wps-cache purge` and `wp wps-cache purge --url=<same-origin-url>`; verify full/target behavior.
- [ ] Run `wp wps-cache preload --limit=10`; progress completes ten same-origin URLs.
- [ ] Run `wp wps-cache images` and `--restore` on staging media.
- [ ] Run `wp wps-cache database --all` only on a backed-up disposable database; command reports completed operations.
- [ ] On multisite, open Network Admin → Settings → WPS Cache.
- [ ] Enable centralized settings and copy the current profile; two sites load the network values but keep distinct URL/cache namespaces.
- [ ] Disable centralized settings; each site returns to its local option.
- [ ] Exercise every action/filter documented in `docs/DEVELOPER_API.md` with a small must-use test plugin.

## 13. Deactivation, uninstall, and limitation checks

- [ ] Deactivate: owned drop-ins/configs and scheduled preload/maintenance jobs are removed safely; unrelated files remain.
- [ ] Uninstall on a disposable site: cache/profile/adaptive directories, runtime files, image cursors/above-fold maps, metrics, histories, queues, network options, and all WPS scheduled hooks are removed.
- [ ] Confirm a third-party `advanced-cache.php` or `object-cache.php` is never deleted.
- [ ] Review the limitation register in `WPSEITEN_MARKET_FEATURE_COMPARISON.md`; verify the UI does not claim Redis Sentinel/cluster or that a local plugin itself supplies worldwide CDN points of presence. External accounts, server binaries, codecs, and host configuration remain visibly conditional.
- [ ] Confirm conditional feature badges remain warning/unavailable when their extension/provider is absent and turn ready only when the real dependency exists.
