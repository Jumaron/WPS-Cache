## 2024-05-23 - [Batch Processing for Cache Preloader]
**Learning:** Sequential AJAX requests for cache warming are inefficient and slow due to round-trip latency. Browsers and servers can handle multiple concurrent connections.
**Action:** Implemented a concurrent queue processor in `admin.js` with a concurrency limit of 3. This significantly speeds up the preloading process by utilizing available network bandwidth and server capacity more effectively.

## 2024-05-24 - [Avoid File I/O for Cache Checks]
**Learning:** Reading file content to generate a cache key (e.g., via MD5 of content) is a significant performance bottleneck on every request.
**Action:** Use file metadata (path, mtime, size) to generate cache keys instead. This avoids reading the file content unless a cache miss occurs. Implemented this in `MinifyJS.php` to match the efficient strategy in `MinifyCSS.php`.

## 2024-05-30 - [Avoid Global Object Cache Flush on Content Updates]
**Learning:** `wp_cache_flush()` wipes the entire persistent object cache (Redis/Memcached), causing cache stampedes. Calling it on frequent events like `save_post` defeats the purpose of persistent caching.
**Action:** Removed `wp_cache_flush()` from `clearContentCaches` in `CacheManager.php`. WordPress natively handles granular invalidation (`clean_post_cache`). Only perform full flushes during system updates (e.g., theme switch).

## 2024-05-30 - [Efficient String Tokenization]
**Learning:** Parsing strings character-by-character in PHP is slow due to opcode overhead.
**Action:** Replaced manual loops in `MinifyJS::tokenize` with `strcspn()` to skip over chunks of safe characters. This utilizes C-level performance for scanning, only dropping back to PHP for delimiters. This matches the optimization already present in `MinifyCSS`.

## 2024-05-30 - [Regex over Iterative Search]
**Learning:** Repeating `array_merge` and `stripos` loops for every tag (script/style) creates O(N*M) complexity in hot paths.
**Action:** Pre-compile exclusion lists into a single Regex in the constructor. This reduces per-tag checks to a single O(1) (amortized) `preg_match` call, significantly reducing CPU overhead on pages with many assets.
