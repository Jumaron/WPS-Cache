## 2026-01-08 - [N+1 Query Optimization in Media Processing]
**Learning:** Checking caching layers (like `get_transient`) sequentially inside a loop (or regex callback) creates an N+1 query problem, especially when the object cache is backed by the database.
**Action:** Implemented a `primeDimensionCache` method in `MediaOptimizer` that scans the HTML for all image sources upfront and performs a single batch fetch (via `wp_cache_get_multiple` or `WHERE IN` SQL query). This reduces database round-trips from N to 1.

## 2026-01-08 - [Extension Availability Checks in Core Drivers]
**Learning:** When adding support for optional PHP extensions (like `igbinary`) in core drivers, checking `extension_loaded` in the constructor is crucial to avoid fatal errors. Additionally, ensuring a graceful fallback for data retrieval (e.g., checking function existence before unserialization) prevents data loss or crashes if the extension is disabled after data has been cached.
**Action:** Implemented `igbinary` support in `RedisCache` with strict checks: `$this->useIgbinary` flags availability for writing, and `function_exists` guards reading `I:` prefixed keys.

## 2026-01-09 - [Consolidating DB Queries vs. Code Separation]
**Learning:** While splitting queries (e.g., local vs. site transients) makes code semantically clearer, it incurs unnecessary network latency in the database layer. Consolidating logic into a single query using `OR` allows for reduced round-trips without sacrificing index usage, provided the `WHERE` clauses target the same indexed column (range scan).
**Action:** Combined 4 separate transient count queries into 2 queries in `DatabaseOptimizer`, reducing database round-trips by 50% while maintaining Index Only Scans for the "Total" counts.

## 2026-01-14 - [Function Call Overhead in Tight Loops]
**Learning:** In PHP, function calls have measurable overhead when executed inside tight loops (like minification tokenizers running thousands of times). Even if the function starts with a "fast return" guard clause, the call frame creation still costs CPU cycles.
**Action:** Inlined the guard clause checks (`$val[0] === '#'`) directly into the loop in `MinifyCSS`, avoiding the function call entirely for the 99% of tokens that don't need processing.

## 2026-01-20 - [Optimizing Text Parsers with Native C Functions]
**Learning:** In PHP, iterating over strings character-by-character in a loop is significantly slower than using native functions like `strcspn` or `strpos`, which are implemented in C. This is critical for tokenizers processing large files.
**Action:** Replaced manual `while` loops for comment scanning in `MinifyJS::tokenize` with `strcspn` (for single-line) and `strpos` (for multi-line), reducing the complexity from O(N) script operations to a single internal call.
