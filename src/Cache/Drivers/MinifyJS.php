<?php
    declare(strict_types=1);

    namespace WPSCache\Cache\Drivers;

    use WPSCache\Cache\Abstracts\AbstractCacheDriver;

    final class MinifyJS extends AbstractCacheDriver {
        protected function getFileExtension(): string {
            return '.js';
        }

        protected function doInitialize(): void {
            if (!is_admin() && ($this->settings['js_minify'] ?? false)) {
                add_action('wp_enqueue_scripts', [$this, 'processScripts'], 100);
            }
        }

        public function processScripts(): void {
            global $wp_scripts;
        
            if (empty($wp_scripts->queue)) {
                return;
            }
        }

        public function processScripts(): void {
            global $wp_scripts;
        
            if (empty($wp_scripts->queue)) {
                return;
            }

            $excluded_js = $this->settings['excluded_js'] ?? [];
        
            foreach ($wp_scripts->queue as $handle) {
                if (!isset($wp_scripts->registered[$handle])) {
                    continue;
                }
        
                $script = $wp_scripts->registered[$handle];
                
                if ($this->shouldSkipScript($script, $excluded_js)) {
                    continue;
                }

                $source_path = $this->getSourcePath($script->src);
                if (!$this->isValidSource($source_path)) {
                    continue;
                }

                $content = @file_get_contents($source_path);
                if (!$content || empty(trim($content))) {
                    continue;
                }

                try {
                    $cache_key = $this->generateCacheKey($handle, $content, $source_path);
                    $cache_file = $this->getCacheFile($cache_key);

                    if (!file_exists($cache_file)) {
                        $minified = $this->minifyJS($content);
                        if ($minified === false) {
                            continue;
                        }

                        if (@file_put_contents($cache_file, $minified) === false) {
                            continue;
                        }
                    }

                    $this->updateScriptSource($script, $cache_file);

                } catch (\Exception $e) {
                    error_log("WPS Cache JS Error: " . $e->getMessage() . " in file: {$source_path}");
                    continue;
                }
            }
        }

        private function shouldSkipScript($script, array $excluded_js): bool {
            return empty($script->src) || 
                strpos($script->src, '.min.js') !== false ||
                strpos($script->src, '//') === 0 ||
                strpos($script->src, site_url()) === false ||
                in_array($script->handle, $excluded_js) ||
                $this->isExcludedUrl($script->src);
        }

        private function isValidSource(?string $source_path): bool {
            return $source_path && 
                is_readable($source_path) && 
                filesize($source_path) <= 500000; // 500KB limit
        }

        private function getSourcePath(string $src): ?string {
            if (strpos($src, 'http') !== 0) {
                $src = site_url($src);
            }

            return str_replace(
                [site_url(), 'wp-content'],
                [ABSPATH, 'wp-content'],
                $src
            );
        }

        private function generateCacheKey(string $handle, string $content, string $source_path): string {
            return md5($handle . $content . filemtime($source_path));
        }

        private function updateScriptSource($script, string $cache_file): void {
            global $wp_scripts;
            
            $wp_scripts->registered[$script->handle]->src = str_replace(
                ABSPATH,
                site_url('/'),
                $cache_file
            );
            $wp_scripts->registered[$script->handle]->ver = filemtime($cache_file);
        }

        private function minifyJS(string $js): string|false {
            if (empty($js)) {
                return false;
            }

            try {
                // Skip tiny or already minified files
                if (strlen($js) < 500 || str_contains($js, '.min.js')) {
                    return $js;
                }

                // Preserve important comments and strings
                $preserved = [];
                
                // Preserve multi-line comments starting with /*!
                $js = preg_replace_callback('/\/\*![\s\S]*?\*\//', function($match) use (&$preserved) {
                    $key = '/*__PRESERVED_COMMENT_' . count($preserved) . '__*/';
                    $preserved[$key] = $match[0];
                    return $key;
                }, $js);

                // Preserve strings
                $js = preg_replace_callback('/([\'"`])((?:\\\\\1|(?!\1).)*)\1/', function($match) use (&$preserved) {
                    $key = '/*__PRESERVED_STRING_' . count($preserved) . '__*/';
                    $preserved[$key] = $match[0];
                    return $key;
                }, $js);

                // Remove comments
                $js = preg_replace([
                    '#^\s*//[^\n]*$#m',     // Single line comments
                    '#^\s*/\*[^*]*\*+([^/*][^*]*\*+)*/\s*#m' // Multi-line comments
                ], '', $js);
                
                if ($js === null) {
                    return false;
                }

                // Handle newlines for better regex processing
                $js = str_replace(['){', ']{', '}else{'], [")\n{", "]\n{", "}\nelse\n{"], $js);

                // Remove whitespace
                $js = preg_replace([
                    '#^\s+#m',               // Leading whitespace
                    '#\s+$#m',               // Trailing whitespace
                    '#\s*([{};,=\(\)\[\]])\s*#', // Around specific characters
                    '#\s+#'                  // Multiple spaces to single
                ], ['', '', '$1', ' '], $js);

                if ($js === null) {
                    return false;
                }

                // Minimize logic operators
                $js = preg_replace([
                    '#\s*([\+\-\*\/\%\&\|\^])\s*#',  // Arithmetic operators
                    '#\s*([<>])\s*#',                 // Comparison operators
                    '#\s*(===|!==|==|!=|<=|>=)\s*#'   // Equality operators
                ], '$1', $js);

                // Restore preserved content
                foreach ($preserved as $key => $value) {
                    $js = str_replace($key, $value, $js);
                }

                return trim($js);

            } catch (\Exception $e) {
                error_log('WPS Cache JS Minification Error: ' . $e->getMessage());
                return false;
            }
        }

        public function get(string $key): mixed {
            $file = $this->getCacheFile($key);
            return file_exists($file) ? file_get_contents($file) : null;
        }

        public function set(string $key, mixed $value, int $ttl = 3600): void {
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
