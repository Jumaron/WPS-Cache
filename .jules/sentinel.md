## 2024-05-23 - Hardcoded Fallback Salt in Cache Drivers
**Vulnerability:** The `RedisCache` driver and `object-cache.php` drop-in used a hardcoded string or `uniqid` as a fallback salt when standard WordPress salt constants were missing.
**Learning:** `wp_salt()` is defined in `pluggable.php`, which is not loaded when `object-cache.php` or early-initialized plugins run. Relying on it leads to the fallback path being taken more often than expected. The hardcoded fallback was public knowledge, allowing signature forgery (PHP Object Injection). The `uniqid` fallback in `object-cache.php` broke persistence.
**Prevention:** Always derive fallback secrets from consistent, site-specific environment data available early in the lifecycle, such as `DB_PASSWORD` and `DB_NAME` from `wp-config.php`, rather than using static strings or runtime-generated IDs.

## 2024-05-24 - Local File Inclusion in Minify Drivers
**Vulnerability:** The `MinifyJS` and `MinifyCSS` drivers used simple string replacement to map URLs to local paths. This allowed path traversal (`..`) to read files outside the intended directories, and lacked extension validation, permitting reading of sensitive PHP files (like `wp-config.php`) as text.
**Learning:** Never trust URL-to-Path mapping without strict validation. `str_replace` is insufficient for sanitization.
**Prevention:** Always use `realpath()` to resolve the final path and verify it against a trusted root (`ABSPATH`). Additionally, enforce strict file extensions (e.g., `.js`, `.css`) to prevent source code disclosure of dynamic files.
