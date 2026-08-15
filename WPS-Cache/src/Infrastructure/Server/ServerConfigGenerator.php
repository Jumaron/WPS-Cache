<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\Server;

use WPSCache\Config\Settings;

/** Generates copyable server recipes; it never edits server-owned files. */
final class ServerConfigGenerator
{
    public function nginx(Settings $settings): string
    {
        $ttl = max(60, min(31536000, $settings->integer('cache_lifetime')));
        $cookies = array_merge(['wordpress_logged_in_', 'woocommerce_items_in_cart', 'wp_woocommerce_session_'], $settings->strings('cache_bypass_cookies'));
        $cookiePattern = implode('|', array_map(static fn(string $cookie): string => preg_quote($cookie, '~'), $cookies));
        return <<<NGINX
# WPS Cache — review paths with your host before enabling.
fastcgi_cache_path /var/cache/nginx/wps levels=1:2 keys_zone=WPSC:100m inactive={$ttl}s;
map \$http_cookie \$wpsc_skip_cookie { default 0; ~*"({$cookiePattern})" 1; }
map \$request_method \$wpsc_skip_method { default 1; GET 0; HEAD 0; }

# Add inside the PHP location serving WordPress:
fastcgi_cache WPSC;
fastcgi_cache_valid 200 {$ttl}s;
fastcgi_cache_bypass \$wpsc_skip_cookie \$wpsc_skip_method;
fastcgi_no_cache \$wpsc_skip_cookie \$wpsc_skip_method;
add_header X-WPS-FastCGI-Cache \$upstream_cache_status always;

# Static asset browser policy:
location ~* \\.(?:css|js|jpg|jpeg|png|gif|svg|webp|avif|woff2?|ttf|otf)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    try_files \$uri =404;
}
NGINX;
    }

    public function apacheStaticAssets(): string
    {
        return <<<'APACHE'
# Optional WPS Cache static-asset browser policy.
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType text/css "access plus 1 year"
  ExpiresByType application/javascript "access plus 1 year"
  ExpiresByType image/webp "access plus 1 year"
  ExpiresByType image/avif "access plus 1 year"
  ExpiresByType font/woff2 "access plus 1 year"
</IfModule>
<IfModule mod_headers.c>
  <FilesMatch "\.(css|js|jpe?g|png|gif|svg|webp|avif|woff2?|ttf|otf)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
APACHE;
    }
}
