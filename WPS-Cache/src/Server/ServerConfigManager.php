<?php

declare(strict_types=1);

namespace WPSCache\Server;

/**
 * Manages Server Configuration for Cache Bypassing.
 * Handles .htaccess writing (Apache/LiteSpeed) and Snippet generation (Nginx/FrankenPHP).
 */
class ServerConfigManager
{
    private string $cache_path_rel = '/wp-content/cache/wps-cache/html/';

    /**
     * Applies the appropriate server configuration.
     * Currently only automates Apache/LiteSpeed (.htaccess).
     */
    public function applyConfiguration(): void
    {
        if ($this->isApache() || $this->isLiteSpeed()) {
            $this->writeHtaccess();
        }
        // Nginx/FrankenPHP require manual user intervention, so we don't auto-write.
    }

    /**
     * Removes the server configuration.
     */
    public function removeConfiguration(): void
    {
        if ($this->isApache() || $this->isLiteSpeed()) {
            $this->removeHtaccessRules();
        }
    }

    private function isApache(): bool
    {
        return isset($_SERVER['SERVER_SOFTWARE']) && strpos(strtolower($_SERVER['SERVER_SOFTWARE']), 'apache') !== false;
    }

    private function isLiteSpeed(): bool
    {
        return isset($_SERVER['SERVER_SOFTWARE']) && strpos(strtolower($_SERVER['SERVER_SOFTWARE']), 'litespeed') !== false;
    }

    /**
     * Writes rewrite rules to .htaccess
     */
    private function writeHtaccess(): void
    {
        $htaccess_path = ABSPATH . '.htaccess';
        if (!file_exists($htaccess_path) || !is_writable($htaccess_path)) {
            return;
        }

        $rules = $this->getApacheRules();
        $content = file_get_contents($htaccess_path);

        // Remove old rules if exist
        $content = preg_replace('/# BEGIN WPS Cache.*?# END WPS Cache/s', '', $content);

        // Insert new rules at the TOP of the file for priority
        $content = $rules . "\n" . trim($content);

        @file_put_contents($htaccess_path, $content);
    }

    private function removeHtaccessRules(): void
    {
        $htaccess_path = ABSPATH . '.htaccess';
        if (!file_exists($htaccess_path) || !is_writable($htaccess_path)) {
            return;
        }

        $content = file_get_contents($htaccess_path);
        $content = preg_replace('/# BEGIN WPS Cache.*?# END WPS Cache\s*/s', '', $content);
        @file_put_contents($htaccess_path, $content);
    }

    /**
     * Apache / OpenLiteSpeed Rules
     */
    public function getApacheRules(): string
    {
        return <<<EOT
# BEGIN WPS Cache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /

# 1. Bypass if Post/Put/etc
RewriteCond %{REQUEST_METHOD} !GET

# 2. Bypass query strings (handled by PHP)
RewriteCond %{QUERY_STRING} !^$

# 3. Bypass logged in users or special paths
RewriteCond %{REQUEST_URI} !^(/wp-admin|/xmlrpc.php|/wp-(app|cron|login|register|mail).php|/wp-includes|/wp-content) [NC]
RewriteCond %{HTTP_COOKIE} !(wp-postpass|wordpress_logged_in|comment_author)_ [NC]

# 4. Check if file exists
RewriteCond %{DOCUMENT_ROOT}{$this->cache_path_rel}%{HTTP_HOST}%{REQUEST_URI}index.html -f

# 5. Serve File!
RewriteRule ^(.*)$ {$this->cache_path_rel}%{HTTP_HOST}/$1/index.html [L]
</IfModule>
# END WPS Cache
EOT;
    }

    /**
     * NGINX Configuration Snippet
     */
    public function getNginxConfig(): string
    {
        return <<<EOT
# WPS Cache - NGINX Configuration
# Add this inside your server {} block, before other location blocks.

set \$cache_uri \$request_uri;

# Bypass for Query Strings
if (\$query_string != "") {
    set \$cache_uri 'null cache';
}

# Bypass for Logged in users
if (\$http_cookie ~* "comment_author|wordpress_[a-f0-9]+|wp-postpass|wordpress_no_cache|wordpress_logged_in") {
    set \$cache_uri 'null cache';
}

# Bypass for specific paths
if (\$request_uri ~* "(/wp-admin/|/xmlrpc.php|/wp-(app|cron|login|register|mail).php|wp-.*.php)") {
    set \$cache_uri 'null cache';
}

# Check file existence and serve
location / {
    try_files {$this->cache_path_rel}\$host\$cache_uri/index.html \$uri \$uri/ /index.php?\$args;
}
EOT;
    }

    /**
     * FrankenPHP / Caddy Snippet
     */
    public function getFrankenPhpConfig(): string
    {
        return <<<EOT
# WPS Cache - Caddy/FrankenPHP Configuration

@wps_cache {
    method GET
    not header_regexp Cookie "comment_author|wordpress_[a-f0-9]+|wp-postpass|wordpress_no_cache|wordpress_logged_in"
    not path_regexp "^/(wp-admin|xmlrpc.php|wp-(app|cron|login|register|mail).php|wp-.*.php)"
    query ""
}

handle @wps_cache {
    try_files {$this->cache_path_rel}{host}{path}/index.html {path} {path}/ /index.php?{query}
}
EOT;
    }
}
