<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

use WPSCache\Infrastructure\Filesystem\AtomicFileWriter;

final class WpConfigManager
{
    public function __construct(private readonly string $file)
    {
    }

    public function enableCache(): bool
    {
        return $this->update(true);
    }

    public function disableCache(): bool
    {
        return $this->update(false);
    }

    public function isWritable(): bool
    {
        return is_file($this->file) && is_writable($this->file) && is_writable(dirname($this->file));
    }

    private function update(bool $enable): bool
    {
        if (!is_file($this->file) || !is_writable($this->file)) {
            return false;
        }
        $content = file_get_contents($this->file);
        if (!is_string($content)) {
            return false;
        }

        $pattern = '/define\s*\(\s*[\'\"]WP_CACHE[\'\"]\s*,\s*(?:true|false)\s*\)\s*;\s*/i';
        if ($enable) {
            $replacement = "define('WP_CACHE', true);\n";
            $updated = preg_match($pattern, $content)
                ? preg_replace($pattern, $replacement, $content, 1)
                : preg_replace('/^<\?php\s*/', "<?php\n" . $replacement, $content, 1);
        } else {
            $updated = preg_replace($pattern, '', $content);
        }

        if (!is_string($updated)) {
            return false;
        }
        $stillDefined = preg_match($pattern, $updated) === 1;
        if (($enable && !$stillDefined) || (!$enable && $stillDefined)) {
            return false;
        }
        return $updated === $content || AtomicFileWriter::replace($this->file, $updated);
    }
}
