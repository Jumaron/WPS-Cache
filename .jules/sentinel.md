## 2024-05-23 - Hardcoded Fallback Salt in Cache Drivers
**Vulnerability:** The `RedisCache` driver and `object-cache.php` drop-in used a hardcoded string or `uniqid` as a fallback salt when standard WordPress salt constants were missing.
**Learning:** `wp_salt()` is defined in `pluggable.php`, which is not loaded when `object-cache.php` or early-initialized plugins run. Relying on it leads to the fallback path being taken more often than expected. The hardcoded fallback was public knowledge, allowing signature forgery (PHP Object Injection). The `uniqid` fallback in `object-cache.php` broke persistence.
**Prevention:** Always derive fallback secrets from consistent, site-specific environment data available early in the lifecycle, such as `DB_PASSWORD` and `DB_NAME` from `wp-config.php`, rather than using static strings or runtime-generated IDs.

## 2024-05-24 - Local File Inclusion in Minify Drivers
**Vulnerability:** The `MinifyJS` and `MinifyCSS` drivers used simple string replacement to map URLs to local paths. This allowed path traversal (`..`) to read files outside the intended directories, and lacked extension validation, permitting reading of sensitive PHP files (like `wp-config.php`) as text.
**Learning:** Never trust URL-to-Path mapping without strict validation. `str_replace` is insufficient for sanitization.
**Prevention:** Always use `realpath()` to resolve the final path and verify it against a trusted root (`ABSPATH`). Additionally, enforce strict file extensions (e.g., `.js`, `.css`) to prevent source code disclosure of dynamic files.

## 2024-05-25 - Directory Traversal via Host Header in HTML Cache
**Vulnerability:** The `HTMLCache` driver sanitized the `HTTP_HOST` header using a regex that allowed dots (`[^a-zA-Z0-9\-\.]`). This permitted attackers to send a Host header containing `..` sequences (e.g., `Host: ..`), causing the plugin to write cache files outside the intended `html` directory (e.g., into the parent `wps-cache` directory).
**Learning:** Regex character classes like `[\.]` treat dots literally but do not prevent sequences like `..`. User input used in file paths (like `HTTP_HOST` or `REQUEST_URI`) must be strictly validated against directory traversal attacks.
**Prevention:** explicitly strip `..` sequences, enforce strict hostname formats (alphanumeric + single dots), and validate the final resolved path against the intended cache root.

## 2024-05-26 - Sensitive Data Exposure in Settings Export
**Vulnerability:** The settings export functionality (`ImportExportTools::exportSettings`) dumped the entire `wpsc_settings` array, including the `redis_password` field, to a JSON file. This could lead to accidental leakage of database credentials if the export file was shared (e.g., for support or debugging).
**Learning:** Generic "export all settings" functions often overlook sensitive fields. Developers tend to trust "admin-only" features, but data often leaves the secure boundary (the server) via files.
**Prevention:** Implement an explicit "allowlist" for exportable fields or a "denylist" for sensitive ones. Always scrub sensitive data (passwords, keys, salts) from export payloads unless there is a specific, secure mechanism to handle them (e.g., encryption).
