## 2024-05-31 - Unsafe File Extension Handling in Font Downloader
**Vulnerability:** The `FontOptimizer::downloadFontFile` method naively relied on `pathinfo()` to extract the file extension from remote URLs. If a malicious upstream server (or a spoofed URL) served a file ending in `.php`, the plugin would write an executable PHP file to the public cache directory.
**Learning:** Never trust file extensions derived from external URLs when writing to the local filesystem. Always validate against a strict allowlist of safe extensions.
**Prevention:** Implemented a strict whitelist check (`woff`, `woff2`, `ttf`, `otf`, `eot`) and a safe default fallback (`woff2`) to prevent writing dangerous file types.

## 2024-05-31 - Overly Restrictive .htaccess Blocking Static Assets
**Vulnerability:** The plugin's default `.htaccess` rule was `Deny from all`, which correctly blocked PHP execution but inadvertently blocked *all* access to cached static assets (fonts, CSS, JS), breaking the functionality of the Font Optimizer and Minification features.
**Learning:** Security controls must be precise. A blanket `Deny from all` is too broad for a public cache directory.
**Prevention:** Updated `.htaccess` to use `<FilesMatch>` with an explicit whitelist of safe extensions, allowing legitimate assets to be served while maintaining a strict block on everything else (especially PHP).
