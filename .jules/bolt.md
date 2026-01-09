## 2026-01-08 - [N+1 Query Optimization in Media Processing]
**Learning:** Checking caching layers (like `get_transient`) sequentially inside a loop (or regex callback) creates an N+1 query problem, especially when the object cache is backed by the database.
**Action:** Implemented a `primeDimensionCache` method in `MediaOptimizer` that scans the HTML for all image sources upfront and performs a single batch fetch (via `wp_cache_get_multiple` or `WHERE IN` SQL query). This reduces database round-trips from N to 1.

## 2026-01-08 - [Extension Availability Checks in Core Drivers]
**Learning:** When adding support for optional PHP extensions (like `igbinary`) in core drivers, checking `extension_loaded` in the constructor is crucial to avoid fatal errors. Additionally, ensuring a graceful fallback for data retrieval (e.g., checking function existence before unserialization) prevents data loss or crashes if the extension is disabled after data has been cached.
**Action:** Implemented `igbinary` support in `RedisCache` with strict checks: `$this->useIgbinary` flags availability for writing, and `function_exists` guards reading `I:` prefixed keys.
