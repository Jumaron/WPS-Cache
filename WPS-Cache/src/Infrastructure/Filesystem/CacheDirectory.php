<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\Filesystem;

final class CacheDirectory
{
    /** Rules written by releases before 0.1.1. Only this exact file is removed. */
    private const LEGACY_ACCESS_RULES = <<<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order Deny,Allow
    Deny from all
</IfModule>
<FilesMatch "\.(css|js|html|xml|txt|map|woff|woff2|ttf|otf|eot|svg|webp|png|jpg|jpeg|gif|avif)(\.gz|\.br)?$">
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
    <IfModule !mod_authz_core.c>
        Allow from all
    </IfModule>
</FilesMatch>
HTACCESS;

    public function __construct(private readonly string $root)
    {
    }

    public function prepare(): bool
    {
        $directories = [
            $this->root,
            $this->root . 'html',
            $this->root . 'css',
            $this->root . 'js',
            $this->root . 'fonts',
        ];

        foreach ($directories as $directory) {
            if (!$this->ensure($directory)) {
                return false;
            }
        }

        return $this->removeLegacyHtaccess();
    }

    private function ensure(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        $index = rtrim($directory, '/\\') . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, '<?php // Silence is golden');
        }

        return is_writable($directory);
    }

    private function removeLegacyHtaccess(): bool
    {
        $file = $this->root . '.htaccess';
        if (!is_file($file)) {
            return true;
        }

        $content = file_get_contents($file);
        if (!is_string($content) || $this->normalize($content) !== $this->normalize(self::LEGACY_ACCESS_RULES)) {
            return true;
        }

        return @unlink($file) || !is_file($file);
    }

    private function normalize(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", trim($content));
    }
}
