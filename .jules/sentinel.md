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
