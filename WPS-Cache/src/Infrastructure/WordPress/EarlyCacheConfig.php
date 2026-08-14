<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

use WPSCache\Config\Settings;

/** Writes the dependency-free configuration consumed by advanced-cache.php. */
final class EarlyCacheConfig
{
    public function __construct(private readonly string $file)
    {
    }

    public function write(Settings $settings): bool
    {
        $cookies = [
            'wordpress_logged_in_',
            'wp-postpass_',
            'comment_author_',
        ];

        if ($settings->enabled('woo_support')) {
            $cookies[] = 'woocommerce_items_in_cart';
            $cookies[] = 'woocommerce_cart_hash';
            $cookies[] = 'wp_woocommerce_session_';
        }

        $configuration = [
            'version' => WPSC_VERSION,
            'ttl' => max(60, min(31536000, $settings->integer('cache_lifetime'))),
            'bypass_cookies' => $cookies,
            'excluded_urls' => $settings->strings('excluded_urls'),
        ];

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($configuration, true)
            . ";\n";

        if (is_file($this->file) && file_get_contents($this->file) === $content) {
            return true;
        }

        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        $temporary = tempnam($directory, 'wpsc_config_');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) === false) {
            return false;
        }

        @chmod($temporary, 0644);
        if (!@rename($temporary, $this->file)) {
            @unlink($this->file);
            if (!@rename($temporary, $this->file)) {
                @unlink($temporary);
                return false;
            }
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->file, true);
        }

        return true;
    }
}
