<?php

declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use WPSCache\Optimization\JSOptimizer;
use WPSCache\Optimization\AsyncCSS;

final class HTMLCache extends AbstractCacheDriver
{
    private string $cacheDir;
    private array $ignoredTags = [];

    private const BYPASS_PARAMS = [
        'add-to-cart',
        'wp_nonce',
        'preview',
        's'
    ];

    public function __construct()
    {
        parent::__construct();
        $this->cacheDir = defined('WPSC_CACHE_DIR') ? WPSC_CACHE_DIR . 'html/' : WP_CONTENT_DIR . '/cache/wps-cache/html/';
        $this->ensureDirectory($this->cacheDir);
    }

    public function initialize(): void
    {
        if ($this->initialized || !$this->shouldCacheRequest()) {
            return;
        }
        ob_start([$this, 'processOutput']);
        $this->initialized = true;
    }

    private function shouldCacheRequest(): bool
    {
        if (empty($this->settings['html_cache'])) return false;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
        if (is_user_logged_in() || is_admin()) return false;

        if (!empty($_GET)) {
            $keys = array_keys($_GET);
            foreach ($keys as $key) {
                if (in_array($key, self::BYPASS_PARAMS, true)) return false;
            }
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        foreach ($this->settings['excluded_urls'] ?? [] as $pattern) {
            if (!empty($pattern) && str_contains($uri, $pattern)) return false;
        }

        return true;
    }

    public function processOutput(string $buffer): string
    {
        if (empty($buffer) || http_response_code() !== 200) return $buffer;

        // 1. Safe HTML Optimization (Fix for Elementor)
        $content = $this->optimizeHTML($buffer);

        // 2. Asset Optimization
        $jsOpt = new JSOptimizer($this->settings);
        $content = $jsOpt->process($content);

        $cssOpt = new AsyncCSS($this->settings);
        $content = $cssOpt->process($content);

        $content .= sprintf("\n<!-- WPS Cache: %s -->", gmdate('Y-m-d H:i:s'));

        $this->writeCacheFile($content);

        return $content;
    }

    /**
     * SOTA "Safe" HTML Optimizer.
     * We REMOVED aggressive whitespace collapsing because it breaks
     * page builders (Elementor/Divi) and inline-block layouts.
     * We primarily focus on removing comments.
     */
    private function optimizeHTML(string $html): string
    {
        $this->ignoredTags = [];

        // 1. Protect SCRIPT, STYLE, PRE, TEXTAREA, CODE
        // Using 's' modifier for dot-matches-newline
        $html = preg_replace_callback(
            '/<(script|style|pre|textarea|code)[^>]*>.*?<\/\1>/si',
            function ($m) {
                $k = "<!--WP_P_" . count($this->ignoredTags) . "-->";
                $this->ignoredTags[$k] = $m[0];
                return $k;
            },
            $html
        );

        // 2. Remove HTML Comments (Except IE conditionals)
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);

        // 3. (REMOVED) Whitespace collapsing.
        // $html = preg_replace('/\s+/', ' ', $html); <--- THIS WAS KILLING ELEMENTOR

        // 4. Restore protected blocks
        if (!empty($this->ignoredTags)) {
            $html = strtr($html, $this->ignoredTags);
        }

        return trim($html);
    }

    private function writeCacheFile(string $content): void
    {
        $host = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_SERVER['HTTP_HOST'] ?? 'unknown');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        if (substr($path, -1) !== '/' && !preg_match('/\.[a-z0-9]{2,4}$/i', $path)) {
            $path .= '/';
        }

        $query = parse_url($uri, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $queryParams);
            ksort($queryParams);
            $filename = 'index-' . md5(http_build_query($queryParams)) . '.html';
        } else {
            $filename = 'index.html';
        }

        $fullPath = $this->cacheDir . $host . $path;
        if (substr($fullPath, -1) !== '/') $fullPath .= '/';

        $this->atomicWrite($fullPath . $filename, $content);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {}
    public function get(string $key): mixed
    {
        return null;
    }
    public function delete(string $key): void {}
    public function clear(): void
    {
        $this->recursiveDelete($this->cacheDir);
    }
}
