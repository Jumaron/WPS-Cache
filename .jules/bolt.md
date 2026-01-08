## 2026-01-08 - [N+1 Query Optimization in Media Processing]
**Learning:** Checking caching layers (like `get_transient`) sequentially inside a loop (or regex callback) creates an N+1 query problem, especially when the object cache is backed by the database.
**Action:** Implemented a `primeDimensionCache` method in `MediaOptimizer` that scans the HTML for all image sources upfront and performs a single batch fetch (via `wp_cache_get_multiple` or `WHERE IN` SQL query). This reduces database round-trips from N to 1.
