<?php

declare(strict_types=1);

namespace WPSCache\Server;

/**
 * Manages .htaccess rules to allow direct file serving.
 * This effectively makes WordPress run as a static site generator for cached pages.
 */
class ServerConfigManager
{
    private string $htaccessPath;

    // The relative path from document root to cache dir
    private string $cachePathRel = 'wp-content/cache/wps-cache/html/';

    public function __construct()
    {
        $this->htaccessPath = ABSPATH . '.htaccess';
    }

    public function applyConfiguration(): void
    {
        if ($this->isApacheOrLiteSpeed()) {
            $this->writeHtaccess();
        }
    }

    public function removeConfiguration(): void
    {
        if ($this->isApacheOrLiteSpeed()) {
            $this->cleanHtaccess();
        }
    }

    private function isApacheOrLiteSpeed(): bool
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? '';
        return stripos($software, 'Apache') !== false || stripos($software, 'LiteSpeed') !== false;
    }

    /**
     * Writes the Rewrite Rules within markers.
     */
    private function writeHtaccess(): void
    {
        if (!file_exists($this->htaccessPath) || !is_writable($this->htaccessPath)) {
            // If .htaccess doesn't exist, we can try to create it, but usually WP handles this.
            // Logging error is appropriate here.
            error_log('WPS Cache: .htaccess is not writable.');
            return;
        }

        $current_content = file_get_contents($this->htaccessPath);
        $rules = $this->getRules();

        // Remove old rules first
        $content = preg_replace('/# BEGIN WPS Cache.*?# END WPS Cache\s*/s', '', $current_content);

        // Insert new rules at the TOP (before WordPress default rules)
        $new_content = $rules . "\n" . trim($content);

        if ($new_content !== $current_content) {
            @file_put_contents($this->htaccessPath, $new_content, LOCK_EX);
        }
    }

    private function cleanHtaccess(): void
    {
        if (!file_exists($this->htaccessPath) || !is_writable($this->htaccessPath)) return;

        $content = file_get_contents($this->htaccessPath);
        $new_content = preg_replace('/# BEGIN WPS Cache.*?# END WPS Cache\s*/s', '', $content);

        if ($new_content !== $content) {
            @file_put_contents($this->htaccessPath, $new_content, LOCK_EX);
        }
    }

    /**
     * Generates SOTA mod_rewrite rules.
     * 1. Checks constraints (Not POST, Not Query String, Not Logged In).
     * 2. Maps %{REQUEST_URI} to the physical file on disk.
     * 3. Sets default MIME types and Headers.
     */
    private function getRules(): string
    {
        // Sanitize cache path for Regex
        $base = parse_url(get_home_url(), PHP_URL_PATH) ?? '/';
        $cache_path = '/' . trim($this->cachePathRel, '/'); // ensure leading slash

        return <<<EOT
# BEGIN WPS Cache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase {$base}

# 1. Bypass if method is POST
RewriteCond %{REQUEST_METHOD} POST
RewriteRule .* - [S=2]

# 2. Bypass if Query String exists
RewriteCond %{QUERY_STRING} !^$
RewriteRule .* - [S=1]

# 3. Bypass if logged in or special WP cookies
RewriteCond %{HTTP_COOKIE} (wp-postpass|wordpress_logged_in|comment_author)_ [NC]
RewriteRule .* - [S=1]

# 4. Check if HTML file exists
# We map: domain.com/about/ -> /cache/path/domain.com/about/index.html
RewriteCond %{DOCUMENT_ROOT}{$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html -f
RewriteRule .* {$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html [L]

</IfModule>

<IfModule mod_headers.c>
    # Serve correct headers for cached HTML
    <FilesMatch "index\.html$">
        Header set Content-Type "text/html; charset=UTF-8"
        Header set Cache-Control "max-age=3600, public"
        Header set X-WPS-Cache "HIT"
        # Sentinel: Security Headers for static content
        Header set X-Content-Type-Options "nosniff"
        Header set X-Frame-Options "SAMEORIGIN"
        Header set Referrer-Policy "strict-origin-when-cross-origin"
    </FilesMatch>
</IfModule>
# END WPS Cache
EOT;
    }
}
