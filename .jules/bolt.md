## 2024-05-23 - [Batch Processing for Cache Preloader]
**Learning:** Sequential AJAX requests for cache warming are inefficient and slow due to round-trip latency. Browsers and servers can handle multiple concurrent connections.
**Action:** Implemented a concurrent queue processor in `admin.js` with a concurrency limit of 3. This significantly speeds up the preloading process by utilizing available network bandwidth and server capacity more effectively.

## 2024-05-24 - [Avoid File I/O for Cache Checks]
**Learning:** Reading file content to generate a cache key (e.g., via MD5 of content) is a significant performance bottleneck on every request.
**Action:** Use file metadata (path, mtime, size) to generate cache keys instead. This avoids reading the file content unless a cache miss occurs. Implemented this in `MinifyJS.php` to match the efficient strategy in `MinifyCSS.php`.
