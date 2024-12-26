<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Optimized HTML cache implementation with minification
 */
final class HTMLCache extends AbstractCacheDriver {
    private const PRESERVED_PATTERNS = [
        'comments' => '/<!--\[if[^\]]*]>.*?<!\[endif]-->/is',
        'scripts' => '/<script[^>]*>.*?<\/script>/is',
        'styles' => '/<style[^>]*>.*?<\/style>/is'
    ];
    
    private string $cache_dir;
    private array $settings;
    private array $preserved = [];

    public function __construct() {
        $this->cache_dir = WPSC_CACHE_DIR . 'html/';
        $this->settings = get_option('wpsc_settings', []);
        $this->ensureCacheDirectory($this->cache_dir);
    }

    public function initialize(): void {
        if (!$this->initialized && $this->shouldCache()) {
            add_action('template_redirect', [$this, 'startOutputBuffering']);
            add_action('shutdown', [$this, 'closeOutputBuffering']);
            $this->initialized = true;
        }
    }

    public function isConnected(): bool {
        return is_writable($this->cache_dir);
    }

    public function get(string $key): mixed {
        $file = $this->getCacheFile($key);
        if (!is_readable($file)) {
            return null;
        }

        // Check expiration before reading file
        $lifetime = $this->settings['cache_lifetime'] ?? 3600;
        if ((time() - filemtime($file)) > $lifetime) {
            @unlink($file);
            return null;
        }

        $content = file_get_contents($file);
        return ($content !== false) ? $content : null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {
        if (!is_string($value) || empty(trim($value))) {
            return;
        }

        $file = $this->getCacheFile($key);
        if (@file_put_contents($file, $value) === false) {
            $this->logError("Failed to write cache file: $file");
        }
    }

    public function delete(string $key): void {
        $file = $this->getCacheFile($key);
        if (file_exists($file) && !@unlink($file)) {
            $this->logError("Failed to delete cache file: $file");
        }
    }

    public function clear(): void {
        $files = glob($this->cache_dir . '*.html');
        if (!is_array($files)) {
            return;
        }
        
        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file)) {
                $this->logError("Failed to delete cache file during clear: $file");
            }
        }
    }

    public function startOutputBuffering(): void {
        ob_start([$this, 'processOutput']);
    }

    public function closeOutputBuffering(): void {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    public function processOutput(string $content): string {
        if (empty($content)) {
            return $content;
        }

        $original_size = strlen($content);
        
        try {
            $content = $this->preserveContent($content);
            $content = $this->minifyHTML($content);
            $content = $this->restoreContent($content);
            
            // Add cache metadata
            $content = $this->addCacheMetadata($content, $original_size);
            
            // Cache the processed content
            $key = $this->generateCacheKey($_SERVER['REQUEST_URI']);
            $this->set($key, $content);
            
            return $content;
        } catch (\Throwable $e) {
            $this->logError('HTML processing failed', $e);
            return $content; // Return original content on error
        }
    }

    private function preserveContent(string $content): string {
        $this->preserved = [];
        
        foreach (self::PRESERVED_PATTERNS as $type => $pattern) {
            $content = preg_replace_callback($pattern, function($match) use ($type) {
                // Skip if doesn't match additional conditions
                if ($type === 'scripts' && !preg_match('/type=["\'](text\/template|text\/x-template)["\']/', $match[0])) {
                    return $match[0];
                }
                if ($type === 'styles' && strpos($match[0], 'data-nominify') === false) {
                    return $match[0];
                }
                
                $key = sprintf('___%s_%d___', strtoupper($type), count($this->preserved));
                $this->preserved[$key] = $match[0];
                return $key;
            }, $content);
        }

        return $content;
    }

    private function restoreContent(string $content): string {
        return strtr($content, $this->preserved);
    }

    private function minifyHTML(string $html): string {
        // Process embedded content first
        $html = preg_replace_callback('/<(script|style)[^>]*>(.*?)<\/\1>/is', function($matches) {
            $content = $matches[2];
            if ($matches[1] === 'script' && !preg_match('/type=["\'](text\/template|text\/x-template)["\']/', $matches[0])) {
                $content = $this->minifyJS($content);
            } elseif ($matches[1] === 'style' && strpos($matches[0], 'data-nominify') === false) {
                $content = $this->minifyCSS($content);
            }
            return "<{$matches[1]}{$matches[0]}>{$content}</{$matches[1]}>";
        }, $html);

        // Remove comments and whitespace
        return preg_replace(
            [
                '/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', // Comments
                '/\s+/',                                                // Multiple whitespace
                '/>\s+</',                                             // Between tags
                '/\s+(>|<)/',                                          // Before/after tags
                '/\s+(<\/?(?:img|input|br|hr|meta|link|source|area)(?:\s+[^>]*)?>)\s+/' // Self-closing tags
            ],
            [
                '',
                ' ',
                '><',
                '$1',
                '$1'
            ],
            trim($html)
        );
    }

    private function minifyJS(string $js): string {
        static $patterns = [
            '/\/\*.*?\*\/|\/\/[^\n]*/' => '',           // Comments
            '/\s+/' => ' ',                             // Multiple whitespace
            '/\s*([:;{},=\(\)\[\]])\s*/' => '$1',      // Around operators
        ];

        // Preserve strings
        $strings = [];
        $js = preg_replace_callback('/([\'"`])(?:(?!\1)[^\\\\]|\\\\.)*\1/', 
            function($match) use (&$strings) {
                $key = '___JS_STR_' . count($strings) . '___';
                $strings[$key] = $match[0];
                return $key;
            }, 
            $js
        );

        // Apply minification patterns
        $js = preg_replace(array_keys($patterns), array_values($patterns), $js);

        // Restore strings
        return strtr($js, $strings);
    }

    private function minifyCSS(string $css): string {
        static $patterns = [
            '/\/\*(?!!)[^*]*\*+([^\/][^*]*\*+)*\//' => '',  // Comments except important ones
            '/\s+/' => ' ',                                  // Multiple whitespace
            '/\s*([:;{},])\s*/' => '$1',                    // Around operators
            '/;}/' => '}',                                  // Extra semicolons
            '/(\d+)\.0+(?:px|em|rem|%)/i' => '$1$2',      // Leading zeros
            '/(:| )0(?:px|em|rem|%)/i' => '${1}0'         // Zero units
        ];

        // Preserve strings and important comments
        $preserved = [];
        $css = preg_replace_callback(
            '/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\/\*![\s\S]*?\*\/)/',
            function($match) use (&$preserved) {
                $key = '___CSS_PRESERVED_' . count($preserved) . '___';
                $preserved[$key] = $match[0];
                return $key;
            },
            $css
        );

        // Apply minification patterns
        $css = preg_replace(array_keys($patterns), array_values($patterns), $css);

        // Restore preserved content
        return strtr(trim($css), $preserved);
    }

    private function addCacheMetadata(string $content, int $original_size): string {
        $minified_size = strlen($content);
        $savings = round(($original_size - $minified_size) / $original_size * 100, 2);
        
        $signature = sprintf(
            "\n<!-- Page cached by WPS-Cache on %s. Savings: %.2f%% -->",
            date('Y-m-d H:i:s'),
            $savings
        );

        // Add before closing body or at the end
        return (stripos($content, '</body>') !== false)
            ? preg_replace('/<\/body>/i', $signature . '</body>', $content, 1)
            : $content . $signature;
    }

    private function shouldCache(): bool {
        return !is_admin() && 
               !$this->isPageCached() && 
               !is_user_logged_in() && 
               $_SERVER['REQUEST_METHOD'] === 'GET' && 
               empty($_GET) &&
               !$this->isExcludedUrl();
    }

    private function isExcludedUrl(): bool {
        $current_url = $_SERVER['REQUEST_URI'];
        $excluded_urls = $this->settings['excluded_urls'] ?? [];
        
        foreach ($excluded_urls as $pattern) {
            if (fnmatch($pattern, $current_url)) {
                return true;
            }
        }
        
        return false;
    }

    private function getCacheFile(string $key): string {
        return $this->cache_dir . $this->generateCacheKey($key) . '.html';
    }

    private function isPageCached(): bool {
        return isset($_SERVER['HTTP_X_WPS_CACHE']) && $_SERVER['HTTP_X_WPS_CACHE'] === 'HIT';
    }
}