<?php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

/**
 * Optimized HTML cache implementation
 */
final class HTMLCache extends AbstractCacheDriver {
    private const COMPRESSION_PATTERN = '/<(?<script>script).*?<\/script\s*>|<(?<style>style).*?<\/style\s*>|<!(?<comment>--).*?-->|<(?<tag>[\/\w.:-]*)(?:".*?"|\'.*?\'|[^\'">]+)*>|(?<text>((<[^!\/\w.:-])?[^<]*)+)|/si';
    private string $cache_dir;
    private array $settings;
    private bool $compress_css = true;
    private bool $compress_js = true;
    private bool $remove_comments = true;
    
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
        if ( ! function_exists('WP_Filesystem') ) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        WP_Filesystem();
        global $wp_filesystem;
        return $wp_filesystem->is_writable($this->cache_dir);
    }

    public function get(string $key): mixed {
        $file = $this->getCacheFile($key);
        if (!is_readable($file)) {
            return null;
        }

        // Check expiration before reading file
        $lifetime = $this->settings['cache_lifetime'] ?? 3600;
        if ((time() - filemtime($file)) > $lifetime) {
            wp_delete_file($file);
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
        if (file_exists($file) && !wp_delete_file($file)) {
            $this->logError("Failed to delete cache file: $file");
        }
    }

    public function clear(): void {
        $files = glob($this->cache_dir . '*.html');
        if (!is_array($files)) {
            return;
        }
        
        foreach ($files as $file) {
            if (is_file($file) && !wp_delete_file($file)) {
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

        try {
            $minified = $this->minifyHTML($content);
            
            if ($minified === false) {
                return $content;
            }

            // Add cache metadata
            $minified .= $this->getCacheComment($content, $minified);
            
            // Sanitize the REQUEST_URI before generating the cache key
            $request_uri = isset($_SERVER['REQUEST_URI'])
                ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
                : '';
            $key = $this->generateCacheKey($request_uri);
            $this->set($key, $minified);
            
            return $minified;
        } catch (\Throwable $e) {
            $this->logError('HTML processing failed', $e);
            return $content;
        }
    }

    private function minifyHTML(string $html): string|false {
        if (empty($html)) {
            return false;
        }

        $matches = [];
        if (!preg_match_all(self::COMPRESSION_PATTERN, $html, $matches, PREG_SET_ORDER)) {
            return false;
        }

        $overriding = false;  // For no compression blocks
        $raw_tag = false;     // For pre/textarea tags
        $compressed = '';

        foreach ($matches as $token) {
            $tag = (isset($token['tag'])) ? strtolower($token['tag']) : null;
            $content = $token[0];

            if (is_null($tag)) {
                if (!empty($token['script'])) {
                    $strip = $this->compress_js;
                } elseif (!empty($token['style'])) {
                    $strip = $this->compress_css;
                } elseif ($content == '<!--wp-html-compression no compression-->') {
                    $overriding = !$overriding;
                    continue;
                } elseif ($this->remove_comments) {
                    if (!$overriding && $raw_tag != 'textarea') {
                        // Remove HTML comments except MSIE conditional comments
                        $content = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $content);
                    }
                }
            } else {
                if ($tag == 'pre' || $tag == 'textarea') {
                    $raw_tag = $tag;
                } elseif ($tag == '/pre' || $tag == '/textarea') {
                    $raw_tag = false;
                } else {
                    if (!$raw_tag && !$overriding) {
                        // Remove empty attributes, except: action, alt, content, src
                        $content = preg_replace('/(\s+)(\w++(?<!\baction|\balt|\bcontent|\bsrc)="")/', '$1', $content);
                        // Remove space before self-closing XHTML tags
                        $content = str_replace(' />', '/>', $content);
                    }
                }
            }

            if (!$raw_tag && !$overriding) {
                $content = $this->removeWhitespace($content);
            }

            $compressed .= $content;
        }

        return $compressed;
    }

    private function removeWhitespace(string $str): string {
        $str = str_replace("\t", ' ', $str);
        $str = str_replace("\n", '', $str);
        $str = str_replace("\r", '', $str);
        while (strpos($str, '  ') !== false) {
            $str = str_replace('  ', ' ', $str);
        }
        return trim($str);
    }

    private function getCacheComment(string $raw, string $compressed): string {
        $raw_size = strlen($raw);
        $compressed_size = strlen($compressed);
        $savings = ($raw_size - $compressed_size) / $raw_size * 100;
        
        return sprintf(
            "\n<!-- Page cached by WPS-Cache on %s. Size saved %.2f%%. From %d bytes to %d bytes -->",
            gmdate('Y-m-d H:i:s'),
            round($savings, 2),
            $raw_size,
            $compressed_size
        );
    }

    /**
     * Determines if the current page should be cached.
     *
     * To address the warning about processing form data without nonce verification,
     * we now explicitly sanitize GET data using filter_input_array().
     */
    private function shouldCache(): bool {
        $get_data = filter_input_array(INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: [];
        if (is_admin() || is_user_logged_in() || !empty($get_data)) {
            return false;
        }
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }
        return !$this->isPageCached() && !$this->isExcludedUrl();
    }

    private function isExcludedUrl(): bool {
        $current_url = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '';
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