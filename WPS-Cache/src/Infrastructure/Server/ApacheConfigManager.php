<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\Server;

use WPSCache\Infrastructure\Filesystem\AtomicFileWriter;

/**
 * Removes legacy plugin-owned .htaccess rules and sends response headers.
 *
 * Page-cache hits are served by the advanced-cache.php drop-in. WPS Cache does
 * not add directives to the site's .htaccess file: SERVER_SOFTWARE tells us
 * which server is running, but not which directives a shared host permits via
 * AllowOverride/AllowOverrideList. Writing unverified directives there can
 * make the entire site return HTTP 500.
 */
final class ApacheConfigManager
{
    private string $htaccessPath;

    public function __construct(?string $htaccessPath = null)
    {
        $this->htaccessPath = $htaccessPath ?? ABSPATH . '.htaccess';
    }

    /**
     * Kept as a lifecycle reconciliation entry point for upgrades. It only
     * removes rules written by older plugin releases.
     */
    public function applyConfiguration(): void
    {
        $this->cleanHtaccess();
    }

    public function removeConfiguration(): void
    {
        $this->cleanHtaccess();
    }

    /**
     * Sends security headers for dynamic PHP responses.
     */
    public function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        if (is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000');
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self'");
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header(
            'Permissions-Policy: camera=(), microphone=(), payment=(), geolocation=(), browsing-topics=(), interest-cohort=(), magnetometer=(), gyroscope=(), usb=(), bluetooth=(), serial=(), midi=(), picture-in-picture=()',
        );
    }

    private function cleanHtaccess(): void
    {
        if (!is_file($this->htaccessPath) || !is_writable($this->htaccessPath)) {
            return;
        }

        $content = file_get_contents($this->htaccessPath);
        if (!is_string($content)) {
            return;
        }

        $newContent = preg_replace(
            '/^[ \t]*# BEGIN WPS Cache[^\r\n]*\R.*?^[ \t]*# END WPS Cache[^\r\n]*(?:\R)?/ms',
            '',
            $content,
        );

        if (is_string($newContent) && $newContent !== $content) {
            if (!AtomicFileWriter::replace($this->htaccessPath, $newContent)) {
                error_log('[WPS-Cache] Could not remove legacy .htaccess rules.');
            }
        }
    }
}
