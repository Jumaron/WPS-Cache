<?php
// src/Cache/Drivers/HTMLCache.php
declare(strict_types=1);

namespace WPSCache\Cache\Drivers;

use WPSCache\Cache\Abstracts\AbstractCacheDriver;

final class HTMLCache extends AbstractCacheDriver {
    /** @var array<string, string> */
    private array $preserved_content = [];

    protected function getFileExtension(): string {
        return '.html';
    }

    protected function doInitialize(): void {
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
            !$this->isExcludedUrl($_SERVER['REQUEST_URI'] ?? '');
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
        
        $content = $this->preserveContent($content);
        $content = $this->minifyHTML($content);
        $content = $this->restoreContent($content);
        $content = $this->addCacheSignature($content, $original_size);

        $key = md5($_SERVER['REQUEST_URI'] ?? uniqid());
        $this->set($key, $content);

        return $content;
    }

    private function preserveContent(string $content): string {
        $preservation_patterns = [
            'conditional_comments' => '/<!--\[if[^\]]*]>.*?<!\[endif]-->/is',
            'special_scripts' => '/<script[^>]*(?:type=["\'](?:text\/template|text\/x-template)["\'])[^>]*>.*?<\/script>/is',
            'special_styles' => '/<style[^>]*data-nominify[^>]*>.*?<\/style>/is'
        ];

        foreach ($preservation_patterns as $type => $pattern) {
            $content = preg_replace_callback($pattern, function($match) use ($type) {
                $key = sprintf('___PRESERVED_%s_%d___', strtoupper($type), count($this->preserved_content));
                $this->preserved_content[$key] = $match[0];
                return $key;
            }, $content);
        }

        return $content;
    }

    private function restoreContent(string $content): string {
        return strtr($content, $this->preserved_content);
    }

    private function minifyHTML(string $content): string {
        // Process scripts
        $content = preg_replace_callback('/<script[^>]*>(.*?)<\/script>/is', function($matches) {
            if (!preg_match('/type=["\'](?:text\/template|text\/x-template)["\']/', $matches[0])) {
                return $this->minifyJS($matches[0]);
            }
            return $matches[0];
        }, $content);

        // Process styles
        $content = preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function($matches) {
            if (strpos($matches[0], 'data-nominify') === false) {
                return $this->minifyCSS($matches[0]);
            }
            return $matches[0];
        }, $content);

        // Remove comments except IE conditionals
        $content = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->)[\s\S])*-->/s', '', $content);

        // Minify HTML
        $replacements = [
            '/\s+/' => ' ',
            '/>\s+</' => '><',
            '/\s+>/' => '>',
            '/>\s+/' => '>',
            '/\s+(<\/?(?:img|input|br|hr|meta|link|source|area)(?:\s+[^>]*)?>)\s+/' => '$1'
        ];

        return trim(preg_replace(array_keys($replacements), array_values($replacements), $content));
    }

    private function minifyJS(string $js): string {
        // Preserve strings
        $strings = [];
        $js = preg_replace_callback('/([\'"`])(?:(?!\1)[^\\\\]|\\\\.)*\1/', function($match) use (&$strings) {
            $key = '___STRING_' . count($strings) . '___';
            $strings[$key] = $match[0];
            return $key;
        }, $js);

        // Remove comments and minimize
        $js = preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/', '', $js);
        $js = preg_replace(['/\s+/', '/\s*([:;{},=\(\)\[\]])\s*/'], [' ', '$1'], $js);

        // Restore strings
        return strtr($js, $strings);
    }

    private function minifyCSS(string $css): string {
        // Preserve strings and important comments
        $preserved = [];
        $css = preg_replace_callback('/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\/\*![\s\S]*?\*\/)/', 
            function($match) use (&$preserved) {
                $key = '___PRESERVATION_' . count($preserved) . '___';
                $preserved[$key] = $match[0];
                return $key;
            }, 
            $css
        );

        // Remove comments and minimize
        $css = preg_replace('/\/\*(?!!)[^*]*\*+([^\/][^*]*\*+)*\//', '', $css);
        $css = preg_replace([
            '/\s+/',
            '/\s*([:;{},])\s*/',
            '/;}/',
            '/(\d+)\.0+(?:px|em|rem|%)/i',
            '/(:| )0(?:px|em|rem|%)/i'
        ], [
            ' ',
            '$1',
            '}',
            '$1$2',
            '${1}0'
        ], $css);

        // Restore preserved content
        return strtr(trim($css), $preserved);
    }

    private function addCacheSignature(string $content, int $original_size): string {
        $minified_size = strlen($content);
        $savings = round(($original_size - $minified_size) / $original_size * 100, 2);
        
        $signature = sprintf(
            "\n<!-- Page cached by WPS-Cache on %s. Savings: %.2f%% -->",
            date('Y-m-d H:i:s'),
            $savings
        );

        return str_contains($content, '</body>')
            ? preg_replace('/<\/body>/i', $signature . '</body>', $content, 1)
            : $content . $signature;
    }

    private function isPageCached(): bool {
        return isset($_SERVER['HTTP_X_WPS_CACHE']) && $_SERVER['HTTP_X_WPS_CACHE'] === 'HIT';
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

        file_put_contents($this->getCacheFile($key), $value);
    }

    public function delete(string $key): void {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function clear(): void {
        array_map('unlink', glob($this->cache_dir . '*' . $this->getFileExtension()) ?: []);
    }
}