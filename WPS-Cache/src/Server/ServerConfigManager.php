<?php

declare(strict_types=1);

namespace WPSCache\Server;

/**
 * Manages Server Configuration for Cache Bypassing.
 */
class ServerConfigManager
{
    private string $cache_path_rel = '/wp-content/cache/wps-cache/html/';

    public function applyConfiguration(): void
    {
        if ($this->isApache() || $this->isLiteSpeed()) {
            $this->writeHtaccess();
        }
    }

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

    private function writeHtaccess(): void
    {
        $htaccess_path = ABSPATH . '.htaccess';
        if (!file_exists($htaccess_path) || !is_writable($htaccess_path)) return;

        $rules = $this->getApacheRules();
        $content = file_get_contents($htaccess_path);
        $content = preg_replace('/# BEGIN WPS Cache.*?# END WPS Cache/s', '', $content);
        $content = $rules . "\n" . trim($content);
        @file_put_contents($htaccess_path, $content);
    }

    private function removeHtaccessRules(): void
    {
        $htaccess_path = ABSPATH . '.htaccess';
        if (!file_exists($htaccess_path) || !is_writable($htaccess_path)) return;

        $content = file_get_contents($htaccess_path);
        $content = preg_replace('/# BEGIN WPS Cache.*?# END WPS Cache\s*/s', '', $content);
        @file_put_contents($htaccess_path, $content);
    }

    public function getApacheRules(): string
    {
        return <<<EOT
# BEGIN WPS Cache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_METHOD} !GET
RewriteCond %{QUERY_STRING} !^$
RewriteCond %{REQUEST_URI} !^(/wp-admin|/xmlrpc.php|/wp-(app|cron|login|register|mail).php|/wp-includes|/wp-content) [NC]
RewriteCond %{HTTP_COOKIE} !(wp-postpass|wordpress_logged_in|comment_author)_ [NC]
RewriteCond %{DOCUMENT_ROOT}{$this->cache_path_rel}%{HTTP_HOST}%{REQUEST_URI}index.html -f
RewriteRule ^(.*)$ {$this->cache_path_rel}%{HTTP_HOST}/$1/index.html [L]
</IfModule>
# END WPS Cache
EOT;
    }

    /**
     * FrankenPHP / Caddy Snippet
     * Ensure this matches the regex hostname sanitization logic ({host} in caddy maps to HTTP_HOST)
     */
    public function getFrankenPhpConfig(): string
    {
        return <<<EOT
# WPS Cache - Caddy/FrankenPHP Configuration
# Place this in your Caddyfile

@wps_cache {
    method GET
    not header_regexp Cookie "comment_author|wordpress_[a-f0-9]+|wp-postpass|wordpress_no_cache|wordpress_logged_in"
    not path_regexp "^/(wp-admin|xmlrpc.php|wp-(app|cron|login|register|mail).php|wp-.*.php)"
    query ""
}

handle @wps_cache {
    # {host} matches the hostname. We rely on Caddy to match the folder structure created by PHP.
    # Note: If your PHP code strips ports (localhost:8000 -> localhost8000), 
    # Caddy {host} might include the port if not careful. 
    # Standard Caddy {host} is usually hostname only (without port).
    try_files {$this->cache_path_rel}{host}{path}/index.html {path} {path}/ /index.php?{query}
}
EOT;
    }
}
