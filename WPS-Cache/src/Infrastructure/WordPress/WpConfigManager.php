<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

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

    private function update(bool $enable): bool
    {
        if (!is_file($this->file) || !is_writable($this->file)) {
            return false;
        }

        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            rewind($handle);
            $content = stream_get_contents($handle);
            if ($content === false) {
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

            if ($updated === $content) {
                return true;
            }

            ftruncate($handle, 0);
            rewind($handle);

            return fwrite($handle, $updated) === strlen($updated);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
