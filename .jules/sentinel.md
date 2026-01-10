## 2024-05-31 - Unsafe File Extension Handling in Font Downloader
**Vulnerability:** The `FontOptimizer::downloadFontFile` method naively relied on `pathinfo()` to extract the file extension from remote URLs. If a malicious upstream server (or a spoofed URL) served a file ending in `.php`, the plugin would write an executable PHP file to the public cache directory.
**Learning:** Never trust file extensions derived from external URLs when writing to the local filesystem. Always validate against a strict allowlist of safe extensions.
**Prevention:** Implemented a strict whitelist check (`woff`, `woff2`, `ttf`, `otf`, `eot`) and a safe default fallback (`woff2`) to prevent writing dangerous file types.

## 2024-05-31 - Overly Restrictive .htaccess Blocking Static Assets
**Vulnerability:** The plugin's default `.htaccess` rule was `Deny from all`, which correctly blocked PHP execution but inadvertently blocked *all* access to cached static assets (fonts, CSS, JS), breaking the functionality of the Font Optimizer and Minification features.
**Learning:** Security controls must be precise. A blanket `Deny from all` is too broad for a public cache directory.
**Prevention:** Updated `.htaccess` to use `<FilesMatch>` with an explicit whitelist of safe extensions, allowing legitimate assets to be served while maintaining a strict block on everything else (especially PHP).

## 2024-05-23 - SQL LIKE Wildcard Escaping
**Vulnerability:** Unescaped underscores in SQL `LIKE` clauses (`LIKE '_transient_%'`) allowed matching any character in that position, potentially leading to unintended data deletion (e.g., `atransient_setting` would be deleted).
**Learning:** SQL `LIKE` wildcards (`_` and `%`) must be escaped (`\_`, `\%`) even when the input pattern is a hardcoded string if the intention is to match the literal characters. This is a common oversight when manually constructing SQL queries outside of `$wpdb->prepare()`.
**Prevention:** Always verify if `_` or `%` are intended as literals in `LIKE` clauses and escape them accordingly. Use `$wpdb->esc_like()` for dynamic inputs, but remember to manually escape hardcoded literals in raw SQL.

## 2024-06-05 - Predictable Secrets in Containerized Environments
**Vulnerability:** The Redis cache driver implemented a fallback salt generation mechanism for HMAC signing that relied on standard environment variables (`DB_NAME`, `DB_USER`, `DOCUMENT_ROOT`) when security keys were missing. In standardized containerized environments (e.g., Docker, Kubernetes), these values are often identical across installations (e.g., `wordpress`, `root`, `/var/www/html`), making the "secret" salt predictable and allowing attackers to forge signatures and exploit PHP Object Injection.
**Learning:** Security secrets must never be derived solely from configuration values that are common defaults or widely known patterns. Entropy must come from instance-specific state that is hard to guess remotely.
**Prevention:** Enhanced the salt generation to include filesystem metadata (`filemtime` and `fileinode` of the plugin file), which varies based on the specific installation time and filesystem allocation, providing significantly higher entropy even in default configurations.

## 2024-06-15 - Deprecated Security Headers (XS-Leak Risk)
**Vulnerability:** The `X-XSS-Protection` header, once recommended, is now deprecated and can introduce Cross-Site Leak (XS-Leak) vulnerabilities in older browsers by allowing attackers to detect if a specific script was executed or blocked. Modern browsers like Chrome and Edge have removed their XSS Auditor entirely.
**Learning:** Security best practices evolve. Headers that were once protective can become liabilities. Always consult modern resources (MDN, OWASP) rather than copying legacy configs.
**Prevention:** Removed `X-XSS-Protection` and `X-Download-Options` (IE8 specific) headers. Reliance should be placed on Content Security Policy (CSP) for robust XSS protection.

## 2024-06-16 - Broken JS Array Serialization Affecting Availability
**Vulnerability:** The JavaScript `URLSearchParams` constructor incorrectly stringified the array of database cleanup items (e.g., `items[]=a,b`), causing the backend PHP logic to receive malformed data and fail silently. This prevented critical maintenance tasks (expired transient cleanup) from running.
**Learning:** PHP's array handling for query parameters (e.g., `key[]=value&key[]=value2`) is not automatically supported by JS `URLSearchParams` when passing an array directly. It converts the array to a single comma-separated string, which PHP treats as a single value.
**Prevention:** Always iterate over arrays and use `params.append('key[]', value)` explicitly when constructing POST bodies for PHP backends.

## 2024-10-24 - [Redis Password Sanitization]
**Vulnerability:** `sanitize_text_field` was used for `redis_password`, which strips HTML tags. This corrupts complex passwords containing characters like `<` or `>`, forcing users to use weaker passwords or causing authentication failures.
**Learning:** Standard WordPress sanitization functions like `sanitize_text_field` are not suitable for raw secrets or passwords where character fidelity is critical.
**Prevention:** Use `trim()` and remove null bytes for password fields instead of aggressive tag stripping functions, while ensuring output is properly escaped with `esc_attr`.

## 2024-06-25 - [Incomplete User Enumeration Protection]
**Vulnerability:** The logic to block user enumeration missed two critical vectors: 1) Author Archives used `isset($_GET['author'])` which fails on pretty permalinks (e.g., `/author/admin`), and 2) REST API filtering only unset the exact default route `/wp/v2/users`, failing to block variations or sub-routes.
**Learning:** Security controls that rely on exact string matching or specific URL parameters are brittle in flexible frameworks like WordPress.
**Prevention:** Use robust pattern matching (e.g., `str_starts_with`) to cover all route variations and use high-level checks (e.g., `is_author()`) that work regardless of the URL structure.
