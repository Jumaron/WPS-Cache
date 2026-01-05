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
**Action:** Pre-compiled exclusion lists into a single Regex in the constructor. This reduces per-tag checks to a single O(1) (amortized) `preg_match` call, significantly reducing CPU overhead on pages with many assets.

## 2024-05-30 - [Regex over Iterative Search in HTMLCache]
**Learning:** Checking excluded URLs using a loop with `str_contains` is O(N) and runs on every request.
**Action:** Pre-compiled exclusions into a single Regex in `HTMLCache` constructor. This matches the optimization in `JSOptimizer` and provides O(1) (amortized) lookup performance for URL exclusions.

## 2024-05-31 - [Avoid Stat Calls for Cached Images]
**Learning:** `filesize($path)` and `file_exists($path)` are stat calls that hit the filesystem. Using them to generate a cache key for image dimensions means we pay the I/O cost on every request, even if the result is cached.
**Action:** Changed the cache key to depend only on the file path. The cache lookup now happens *before* any file system check. This saves N*2 stat calls per page (where N is the number of local images), significantly reducing I/O overhead.

## 2026-01-03 - [PHP Memoization]
**Learning:** Memoization is powerful in PHP's shared-nothing architecture, especially for repetitive tasks within a single request (like processing a list of posts).
**Action:** Always look for loops or repeated function calls (like `processImage`) that might access the same data. Adding a simple array property for memoization reduces external calls (DB/Cache/Filesystem) to O(1) after the first hit.

## 2026-01-04 - [Optimize Orphan Cleanup]
**Learning:** `DELETE FROM table WHERE id NOT IN (SELECT id FROM other_table)` is often inefficient (O(N*M) or O(N log M)) compared to `DELETE T1 FROM T1 LEFT JOIN T2 ON T1.id = T2.id WHERE T2.id IS NULL`, which leverages indexes for a faster merge (O(N)).
**Action:** Replaced `NOT IN` subqueries with `LEFT JOIN` deletes for orphaned comment meta cleanup. Also batched multiple cleanup flags to run this expensive operation only once per request.

## 2026-01-05 - [DOMNodeList Iteration]
**Learning:** Accessing `DOMNodeList` items via `item($i)` in a `for` loop is O(N^2). Additionally, `DOMNodeList` is "live", meaning modifications during iteration can cause elements to be skipped.
**Action:** Use `iterator_to_array($nodeList)` to create a static snapshot, then iterate via `foreach`. This ensures O(N) performance and safe traversal during DOM manipulation.

## 2026-01-05 - [Tokenization Lookup Optimization]
**Learning:** Sequential `if` checks or `switch` statements inside a tight tokenization loop (running millions of times) can be slower than a single `isset()` lookup on a hash map, especially when the number of tokens grows.
**Action:** Replaced sequential `if` checks for single-character tokens in `MinifyCSS` with a `TOKEN_MAP` constant and `isset()` lookup. This reduces branch misprediction penalties and provides O(1) lookup performance, matching the optimization in `MinifyJS`.
