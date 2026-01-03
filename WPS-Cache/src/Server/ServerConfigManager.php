<?php

declare(strict_types=1);

namespace WPSCache\Server;

/**
 * Manages .htaccess rules to allow direct file serving.
 * SOTA Update: Implements Device-Aware rewriting to prevent Cache Poisoning.
 */
class ServerConfigManager
{
    private string $htaccessPath;

    // The relative path from document root to cache dir
    private string $cachePathRel = "wp-content/cache/wps-cache/html/";

    // Must match the Regex in HTMLCache.php and WPSAdvancedCache
    private const MOBILE_AGENT_REGEX = "Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi";

    public function __construct()
    {
        $this->htaccessPath = ABSPATH . ".htaccess";
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

    /**
     * Sends security headers for dynamic PHP responses.
     * Aligns with the .htaccess rules for cached files to ensure consistent security.
     */
    public function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), payment=()");
    }

    private function isApacheOrLiteSpeed(): bool
    {
        $software = $_SERVER["SERVER_SOFTWARE"] ?? "";
        return stripos($software, "Apache") !== false ||
            stripos($software, "LiteSpeed") !== false;
    }

    /**
     * Writes the Rewrite Rules within markers.
     */
    private function writeHtaccess(): void
    {
        if (
            !file_exists($this->htaccessPath) ||
            !is_writable($this->htaccessPath)
        ) {
            error_log("WPS Cache: .htaccess is not writable.");
            return;
        }

        $current_content = file_get_contents($this->htaccessPath);
        $rules = $this->getRules();

        // Remove old rules first
        $content = preg_replace(
            "/# BEGIN WPS Cache.*?# END WPS Cache\s*/s",
            "",
            $current_content,
        );

        // Insert new rules at the TOP (before WordPress default rules)
        $new_content = $rules . "\n" . trim($content);

        if ($new_content !== $current_content) {
            @file_put_contents($this->htaccessPath, $new_content, LOCK_EX);
        }
    }

    private function cleanHtaccess(): void
    {
        if (
            !file_exists($this->htaccessPath) ||
            !is_writable($this->htaccessPath)
        ) {
            return;
        }

        $content = file_get_contents($this->htaccessPath);
        $new_content = preg_replace(
            "/# BEGIN WPS Cache.*?# END WPS Cache\s*/s",
            "",
            $content,
        );

        if ($new_content !== $content) {
            @file_put_contents($this->htaccessPath, $new_content, LOCK_EX);
        }
    }

    /**
     * Generates SOTA mod_rewrite rules.
     * 1. Checks constraints.
     * 2. Tries to match Mobile Cache first.
     * 3. Tries to match Desktop Cache (EXCLUDING Mobile agents) second.
     */
    private function getRules(): string
    {
        // Sanitize cache path for Regex
        $base = parse_url(get_home_url(), PHP_URL_PATH) ?? "/";
        $cache_path = "/" . trim($this->cachePathRel, "/"); // ensure leading slash
        $mobile_agents = self::MOBILE_AGENT_REGEX;

        return <<<EOT
        # BEGIN WPS Cache
        <IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteBase {$base}

        # 1. Bypass if method is POST
        RewriteCond %{REQUEST_METHOD} POST
        RewriteRule .* - [S=3]

        # 2. Bypass if Query String exists
        RewriteCond %{QUERY_STRING} !^$
        RewriteRule .* - [S=2]

        # 3. Bypass if logged in or special WP cookies
        RewriteCond %{HTTP_COOKIE} (wp-postpass|wordpress_logged_in|comment_author)_ [NC]
        RewriteRule .* - [S=1]

        # 4. MOBILE CACHE RULE
        # Only if User-Agent matches Mobile AND index-mobile.html exists
        RewriteCond %{HTTP_USER_AGENT} "{$mobile_agents}" [NC]
        RewriteCond %{DOCUMENT_ROOT}{$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index-mobile.html -f
        RewriteRule .* {$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index-mobile.html [L]

        # 5. DESKTOP CACHE RULE
        # Critical: Explicitly EXCLUDE Mobile Agents here.
        # If we don't exclude them, a mobile user visiting an uncached page
        # (where index-mobile.html doesn't exist yet) would be served the Desktop index.html.
        RewriteCond %{HTTP_USER_AGENT} !"{$mobile_agents}" [NC]
        RewriteCond %{DOCUMENT_ROOT}{$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html -f
        RewriteRule .* {$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html [L]

        </IfModule>

        <IfModule mod_headers.c>
            # Serve correct headers for cached HTML
            <FilesMatch "index(-mobile)?\.html$">
                Header set Content-Type "text/html; charset=UTF-8"
                Header set Cache-Control "max-age=3600, public"
                Header set X-WPS-Cache "HIT"
                Header set X-Content-Type-Options "nosniff"
                Header set X-Frame-Options "SAMEORIGIN"
                Header set Referrer-Policy "strict-origin-when-cross-origin"
            </FilesMatch>
        </IfModule>
        # END WPS Cache
        EOT;
    }
}
