<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Simple whitespace-only JavaScript minification implementation
 */
final class MinifyJS extends AbstractCacheDriver {
    private const MAX_FILE_SIZE = 500_000; // 500KB
    private const CACHE_SUBDIR = 'js/';

    private readonly string $cacheDir;

    public function __construct() {
        $this->cacheDir = WPSC_CACHE_DIR . self::CACHE_SUBDIR;
        $this->ensureCacheDirectory($this->cacheDir);
    }

    public function initialize(): void {
        if (!$this->initialized && !is_admin() && ($this->getSettings()['js_minify'] ?? false)) {
            add_action('wp_enqueue_scripts', $this->processScripts(...), 100);
            $this->initialized = true;
        }
    }

    public function isConnected(): bool {
        return is_writable($this->cacheDir);
    }

    public function get(string $key): ?string {
        $file = $this->getCacheFile($key);
        return is_readable($file) ? file_get_contents($file) : null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {
        if (!is_string($value) || empty(trim($value))) {
            return;
        }

        file_put_contents($this->getCacheFile($key), $value);
    }

    public function delete(string $key): void {
        @unlink($this->getCacheFile($key));
    }

    public function clear(): void {
        array_map('unlink', glob($this->cacheDir . '*.js') ?: []);
    }

    /**
     * Simple minification that only removes unnecessary whitespace
     */
    private function minifyJS(string $js): string|false {
        try {
            // Normalize line endings
            $js = str_replace(["\r\n", "\r"], "\n", $js);
            
            // Split into lines
            $lines = explode("\n", $js);
            
            // Process each line
            $minified = [];
            foreach ($lines as $line) {
                // Trim whitespace from start and end of line
                $line = trim($line);
                
                // Skip empty lines
                if ($line !== '') {
                    $minified[] = $line;
                }
            }
            
            // Join lines and ensure there's a semicolon between them
            return implode("\n", $minified);
            
        } catch (\Throwable $e) {
            $this->logError("Minification failed: " . $e->getMessage(), $e);
            return false;
        }
    }

    public function processScripts(): void {
        global $wp_scripts;
        
        if (empty($wp_scripts->queue)) {
            return;
        }

        $excludedJs = $this->getSettings()['excluded_js'] ?? [];
        
        foreach ($wp_scripts->queue as $handle) {
            $this->processScript($handle, $wp_scripts, $excludedJs);
        }
    }

    private function processScript(string $handle, \WP_Scripts $wp_scripts, array $excludedJs): void {
        $script = $wp_scripts->registered[$handle] ?? null;
        
        if (!$script || !$this->shouldProcessScript($script, $handle, $excludedJs)) {
            return;
        }

        $source = $this->getScriptPath($script->src);
        
        if (!$this->isValidSource($source)) {
            return;
        }

        $content = file_get_contents($source);
        if (!$content) {
            return;
        }

        $cacheKey = $this->generateCacheKey($handle . $content . filemtime($source));
        $cacheFile = $this->getCacheFile($cacheKey);

        if (!file_exists($cacheFile)) {
            if ($minified = $this->minifyJS($content)) {
                $this->set($cacheKey, $minified);
            }
        }

        if (file_exists($cacheFile)) {
            $this->updateScriptUrl($script, $cacheFile);
        }
    }

    private function shouldProcessScript(object $script, string $handle, array $excludedJs): bool {
        return $script->src 
            && !str_contains($script->src, '.min.js')
            && !str_starts_with($script->src, '//')
            && str_contains($script->src, site_url())
            && !in_array($handle, $excludedJs, true);
    }

    private function getScriptPath(string $src): string {
        return str_replace(
            [site_url(), 'wp-content'],
            [ABSPATH, 'wp-content'],
            $src
        );
    }

    private function isValidSource(string $source): bool {
        return is_readable($source) && filesize($source) <= self::MAX_FILE_SIZE;
    }

    private function updateScriptUrl(object $script, string $cacheFile): void {
        $script->src = str_replace(ABSPATH, site_url('/'), $cacheFile);
        $script->ver = filemtime($cacheFile);
    }

    private function getSettings(): array {
        return get_option('wpsc_settings', []);
    }

    private function getCacheFile(string $key): string {
        return $this->cacheDir . $key . '.js';
    }
}