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

        // Sentinel Enhancement: Add HSTS header for HTTPS sites
        if (is_ssl()) {
            header("Strict-Transport-Security: max-age=31536000");
        }

        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        // Sentinel Enhancement: Add CSP frame-ancestors to prevent Clickjacking (Defense in Depth)
        header("Content-Security-Policy: frame-ancestors 'self'");
        // Sentinel Enhancement: Prevent Flash/PDF cross-domain data inclusion
        header("X-Permitted-Cross-Domain-Policies: none");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header(
            "Permissions-Policy: camera=(), microphone=(), payment=(), geolocation=(), browsing-topics=(), interest-cohort=(), magnetometer=(), gyroscope=(), usb=(), bluetooth=(), serial=(), midi=(), picture-in-picture=()",
        );
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

        // Sentinel: Apply Security Hardening based on settings
        $settings = get_option("wpsc_settings", \WPSCache\Plugin::DEFAULT_SETTINGS);
        $security_rules = "";

        if (!empty($settings["bloat_disable_xmlrpc"])) {
            $security_rules .= "\n# Sentinel: Block XML-RPC\n<Files xmlrpc.php>\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Order Deny,Allow\n        Deny from all\n    </IfModule>\n</Files>\n";
        }

        if (!empty($settings["bloat_disable_user_enumeration"])) {
            $security_rules .= "\n# Sentinel: Block User Enumeration\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{QUERY_STRING} (?:^|&)author=\\d+\nRewriteRule .* /? [L,R=301]\n</IfModule>\n";
        }

        return <<<EOT
        # BEGIN WPS Cache
        {$security_rules}
        <IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteBase {$base}

        # ── BYPASS CHECKS ──────────────────────────────────────────
        # S=7 skips all 7 cache-serving rules below when bypassing

        # 1. Bypass POST requests
        RewriteCond %{REQUEST_METHOD} POST
        RewriteRule .* - [S=7]

        # 2. Bypass if Query String exists (query-string pages use hashed filenames,
        #    which can't be resolved by mod_rewrite — PHP handles those)
        RewriteCond %{QUERY_STRING} !^$
        RewriteRule .* - [S=6]

        # 3. Bypass if logged-in or special WP cookies
        RewriteCond %{HTTP_COOKIE} (wp-postpass|wordpress_logged_in|comment_author)_ [NC]
        RewriteRule .* - [S=5]

        # ── MOBILE CACHE: Precompressed (brotli → gzip → plain) ───

        # 4a. MOBILE + BROTLI
        RewriteCond %{HTTP_USER_AGENT} "{$mobile_agents}" [NC]
        RewriteCond %{HTTP:Accept-Encoding} br
        RewriteCond %{DOCUMENT_ROOT}{$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index-mobile.html.br -f
        RewriteRule .* {$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index-mobile.html.br [L,E=WPS_ENC:br,E=WPS_HIT:1]

        # 4b. MOBILE + GZIP
        RewriteCond %{HTTP_USER_AGENT} "{$mobile_agents}" [NC]
        RewriteCond %{HTTP:Accept-Encoding} gzip
        RewriteCond %{DOCUMENT_ROOT}{$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index-mobile.html.gz -f
        RewriteRule .* {$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index-mobile.html.gz [L,E=WPS_ENC:gzip,E=WPS_HIT:1]

        # 4c. MOBILE + PLAIN
        RewriteCond %{HTTP_USER_AGENT} "{$mobile_agents}" [NC]
        RewriteCond %{DOCUMENT_ROOT}{$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index-mobile.html -f
        RewriteRule .* {$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index-mobile.html [L,E=WPS_HIT:1]

        # ── DESKTOP CACHE: Precompressed (brotli → gzip → plain) ──
        # Explicitly exclude mobile agents to prevent serving desktop to mobile on miss

        # 5a. DESKTOP + BROTLI
        RewriteCond %{HTTP_USER_AGENT} !"{$mobile_agents}" [NC]
        RewriteCond %{HTTP:Accept-Encoding} br
        RewriteCond %{DOCUMENT_ROOT}{$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html.br -f
        RewriteRule .* {$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html.br [L,E=WPS_ENC:br,E=WPS_HIT:1]

        # 5b. DESKTOP + GZIP
        RewriteCond %{HTTP_USER_AGENT} !"{$mobile_agents}" [NC]
        RewriteCond %{HTTP:Accept-Encoding} gzip
        RewriteCond %{DOCUMENT_ROOT}{$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html.gz -f
        RewriteRule .* {$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html.gz [L,E=WPS_ENC:gzip,E=WPS_HIT:1]

        # 5c. DESKTOP + PLAIN
        RewriteCond %{HTTP_USER_AGENT} !"{$mobile_agents}" [NC]
        RewriteCond %{DOCUMENT_ROOT}{$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html -f
        RewriteRule .* {$cache_path}/%{HTTP_HOST}%{REQUEST_URI}index.html [L,E=WPS_HIT:1]

        </IfModule>

        <IfModule mod_headers.c>
            # ── Headers for ALL cache-served responses ──────────────
            <FilesMatch "index(-mobile)?\.html(\.gz|\.br)?$">
                Header set Content-Type "text/html; charset=UTF-8"
                Header set Cache-Control "max-age=3600, public"
                Header set X-WPS-Cache "HIT"
                Header set Vary "Accept-Encoding, Cookie"
                Header set Strict-Transport-Security "max-age=31536000"
                Header set X-Content-Type-Options "nosniff"
                Header set X-Frame-Options "SAMEORIGIN"
                Header set Content-Security-Policy "frame-ancestors 'self'"
                Header set X-Permitted-Cross-Domain-Policies "none"
                Header set Referrer-Policy "strict-origin-when-cross-origin"
                Header set Permissions-Policy "camera=(), microphone=(), payment=(), geolocation=(), browsing-topics=(), interest-cohort=(), magnetometer=(), gyroscope=(), usb=(), bluetooth=(), serial=(), midi=(), picture-in-picture=()"
            </FilesMatch>

            # ── Content-Encoding for precompressed files ───────────
            <FilesMatch "\.html\.gz$">
                Header set Content-Encoding "gzip"
                # Prevent double-compression by mod_deflate
                Header set Content-Type "text/html; charset=UTF-8"
            </FilesMatch>
            <FilesMatch "\.html\.br$">
                Header set Content-Encoding "br"
                Header set Content-Type "text/html; charset=UTF-8"
            </FilesMatch>
        </IfModule>

        # ── Prevent mod_deflate from double-compressing precompressed files ──
        <IfModule mod_deflate.c>
            <FilesMatch "\.html\.(gz|br)$">
                SetEnv no-gzip 1
            </FilesMatch>
        </IfModule>

        # ── Correct MIME type for precompressed files ──
        <IfModule mod_mime.c>
            AddType text/html .gz
            AddType text/html .br
        </IfModule>
        # END WPS Cache
        EOT;
    }
}
