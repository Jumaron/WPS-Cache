## 2024-05-23 - [Batch Processing for Cache Preloader]
**Learning:** Sequential AJAX requests for cache warming are inefficient and slow due to round-trip latency. Browsers and servers can handle multiple concurrent connections.
**Action:** Implemented a concurrent queue processor in `admin.js` with a concurrency limit of 3. This significantly speeds up the preloading process by utilizing available network bandwidth and server capacity more effectively.

## 2024-05-24 - [OpCache Reset on Content Updates]
**Learning:** Resetting OpCache (`opcache_reset()`) on every post save (`save_post`) or comment causes a massive performance hit because it forces PHP to re-compile all scripts on the server. Content updates (DB changes) rarely require code recompilation.
**Action:** Split cache clearing logic into `clearContentCaches` (for content updates) and `clearAllCaches` (for system updates like theme/plugin switches). Only `clearAllCaches` should reset OpCache.
