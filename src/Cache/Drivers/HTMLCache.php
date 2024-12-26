<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * HTML cache implementation
 */
final class HTMLCache implements CacheDriverInterface {
    private string $cache_dir;
    private array $settings;
    private array $preserved_comments = [];
    private array $preserved_scripts = [];
    private array $preserved_styles = [];

    public function __construct() {
        $this->cache_dir = WPSC_CACHE_DIR . 'html/';
        if (!file_exists($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
        
        $this->settings = get_option('wpsc_settings', []);
    }

    public function initialize(): void {
        if ($this->shouldCache()) {
            add_action('template_redirect', [$this, 'startOutputBuffering']);
            add_action('shutdown', [$this, 'closeOutputBuffering']);
        }
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

    public function isConnected(): bool {
        return is_writable($this->cache_dir);
    }

    public function get(string $key): mixed {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Check if cache is expired
        $lifetime = $this->settings['cache_lifetime'] ?? 3600;
        if ((time() - filemtime($file)) > $lifetime) {
            unlink($file);
            return null;
        }

        return $content;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {
        if (!is_string($value)) {
            return;
        }

        $file = $this->getCacheFile($key);
        file_put_contents($file, $value);
    }

    public function delete(string $key): void {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function clear(): void {
        $files = glob($this->cache_dir . '*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
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
        
        // Preserve specific content before minification
        $content = $this->preserveContent($content);
        
        // Minify HTML
        $content = $this->minifyHTML($content);
        
        // Restore preserved content
        $content = $this->restoreContent($content);

        // Add cache signature and stats
        $minified_size = strlen($content);
        $savings = round(($original_size - $minified_size) / $original_size * 100, 2);
        
        $timestamp = date('Y-m-d H:i:s');
        $cache_signature = sprintf(
            "\n<!-- Page cached by WPS-Cache on %s. Savings: %.2f%% -->",
            $timestamp,
            $savings
        );

        // Add signature before closing body tag
        if (stripos($content, '</body>') !== false) {
            $content = preg_replace(
                '/<\/body>/i',
                $cache_signature . '</body>',
                $content,
                1
            );
        } else {
            $content .= $cache_signature;
        }

        // Cache the content
        $key = md5($_SERVER['REQUEST_URI']);
        $this->set($key, $content);

        return $content;
    }

    private function preserveContent(string $content): string {
        // Preserve conditional comments
        $content = preg_replace_callback('/<!--\[if[^\]]*]>.*?<!\[endif]-->/is', function($match) {
            $key = '___PRESERVED_COMMENT_' . count($this->preserved_comments) . '___';
            $this->preserved_comments[$key] = $match[0];
            return $key;
        }, $content);

        // Preserve scripts with special attributes
        $content = preg_replace_callback('/<script[^>]*>.*?<\/script>/is', function($match) {
            if (preg_match('/type=["\'](text\/template|text\/x-template)["\']/', $match[0])) {
                $key = '___PRESERVED_SCRIPT_' . count($this->preserved_scripts) . '___';
                $this->preserved_scripts[$key] = $match[0];
                return $key;
            }
            return $match[0];
        }, $content);

        // Preserve styles with special attributes
        $content = preg_replace_callback('/<style[^>]*>.*?<\/style>/is', function($match) {
            if (strpos($match[0], 'data-nominify') !== false) {
                $key = '___PRESERVED_STYLE_' . count($this->preserved_styles) . '___';
                $this->preserved_styles[$key] = $match[0];
                return $key;
            }
            return $match[0];
        }, $content);

        return $content;
    }

    private function restoreContent(string $content): string {
        // Restore all preserved content
        foreach ($this->preserved_comments as $key => $value) {
            $content = str_replace($key, $value, $content);
        }
        foreach ($this->preserved_scripts as $key => $value) {
            $content = str_replace($key, $value, $content);
        }
        foreach ($this->preserved_styles as $key => $value) {
            $content = str_replace($key, $value, $content);
        }

        return $content;
    }

    private function minifyHTML(string $html): string {
        // Process scripts
        $html = preg_replace_callback('/<script[^>]*>(.*?)<\/script>/is', function($matches) {
            if (!preg_match('/type=["\'](text\/template|text\/x-template)["\']/', $matches[0])) {
                return $this->minifyJS($matches[0]);
            }
            return $matches[0];
        }, $html);

        // Process styles
        $html = preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function($matches) {
            if (strpos($matches[0], 'data-nominify') === false) {
                return $this->minifyCSS($matches[0]);
            }
            return $matches[0];
        }, $html);

        // Remove comments (except IE conditionals)
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);
   

        // Remove whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        $html = preg_replace('/\s+>/', '>', $html);
        $html = preg_replace('/>\s+/', '>', $html);

        // Remove whitespace around specific tags
        $html = preg_replace('/\s+(<\/?(?:img|input|br|hr|meta|link|source|area)(?:\s+[^>]*)?>)\s+/', '$1', $html);

        return trim($html);
    }

    private function minifyJS(string $js): string {
        // Preserve strings
        $strings = [];
        $js = preg_replace_callback('/([\'"`])(?:(?!\1)[^\\\\]|\\\\.)*\1/', function($match) use (&$strings) {
            $key = '___STRING_' . count($strings) . '___';
            $strings[$key] = $match[0];
            return $key;
        }, $js);

        // Remove comments
        $js = preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/', '', $js);
        
        // Basic minification
        $js = preg_replace('/\s+/', ' ', $js);
        $js = preg_replace('/\s*([:;{},=\(\)\[\]])\s*/', '$1', $js);
        
        // Restore strings
        foreach ($strings as $key => $value) {
            $js = str_replace($key, $value, $js);
        }

        return $js;
    }

    private function minifyCSS(string $css): string {
        // Preserve strings and important comments
        $preservations = [];
        $css = preg_replace_callback('/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\/\*![\s\S]*?\*\/)/', function($match) use (&$preservations) {
            $key = '___PRESERVATION_' . count($preservations) . '___';
            $preservations[$key] = $match[0];
            return $key;
        }, $css);

        // Remove comments
        $css = preg_replace('/\/\*(?!!)[^*]*\*+([^\/][^*]*\*+)*\//', '', $css);

        // Remove whitespace
        $css = preg_replace('/\s+/', ' ', $css);
        $css = preg_replace('/\s*([:;{},])\s*/', '$1', $css);
        $css = preg_replace('/;}/', '}', $css);

        // Optimize numbers
        $css = preg_replace('/(\d+)\.0+(?:px|em|rem|%)/i', '$1$2', $css);
        $css = preg_replace('/(:| )0(?:px|em|rem|%)/i', '${1}0', $css);

        // Restore preserved content
        foreach ($preservations as $key => $value) {
            $css = str_replace($key, $value, $css);
        }

        return trim($css);
    }

    private function getCacheFile(string $key): string {
        return $this->cache_dir . $key . '.html';
    }

    private function isPageCached(): bool {
        return isset($_SERVER['HTTP_X_WPS_CACHE']) && $_SERVER['HTTP_X_WPS_CACHE'] === 'HIT';
    }
}