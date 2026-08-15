<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Contracts\HtmlProcessor;

/**
 * Font Optimization.
 *
 * Features:
 * 1. Localize Google Fonts (v1 and v2) — downloads & caches WOFF2 files locally.
 * 2. Enforces 'font-display: swap' on ALL @font-face rules.
 * 3. Handles Unicode Ranges correctly (prevents duplicates).
 * 4. Canonicalizes URLs to prevent cache bloat from ?ver= parameters.
 * 5. Adds preconnect hints for fonts still pending download.
 */
final class FontOptimizer implements HtmlProcessor
{
    private array $settings;
    private string $fontCacheDir;
    private string $fontCacheUrl;
    private array $cssCache = [];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->fontCacheDir = WPSC_CACHE_DIR . 'fonts/';
        $this->fontCacheUrl = content_url('cache/wps-cache/fonts/');
    }

    public function process(string $html): string
    {
        // 1. Localize Google Fonts (supports both v1 /css and v2 /css2)
        if (!empty($this->settings['font_localize_google'])) {
            // Fast fail: skip regex engine if no Google Fonts domain is present
            if (stripos($html, 'fonts.googleapis.com') !== false) {
                $html = preg_replace_callback(
                    '/<link[^>]*href=[\'\"](https?:\/\/fonts\.googleapis\.com\/css2?[^"\']*)[\'"][^>]*>/i',
                    [$this, 'localizeGoogleFont'],
                    $html,
                );

                // Add preconnect hint for fonts.gstatic.com (the font file CDN)
                // Only if we haven't already localized all fonts
                if (stripos($html, 'fonts.gstatic.com') !== false) {
                    $preconnect = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
                    if (!str_contains($html, 'fonts.gstatic.com" crossorigin')) {
                        $html = str_replace('</head>', $preconnect . "\n</head>", $html);
                    }
                }
            }
        }

        // 2. Force 'font-display: swap' in <style> blocks
        if (!empty($this->settings['font_display_swap'])) {
            // Fast fail: skip if no @font-face in the entire HTML
            if (stripos($html, '@font-face') !== false) {
                // Only scan <style> blocks — avoids modifying text content in the body
                $html = preg_replace_callback(
                    '/(<style[^>]*>)(.*?)(<\/style>)/is',
                    function (array $styleMatches): string {
                        $open = $styleMatches[1];
                        $content = $styleMatches[2];
                        $close = $styleMatches[3];

                        // Fast fail: skip style blocks without @font-face
                        if (stripos($content, '@font-face') === false) {
                            return $styleMatches[0];
                        }

                        $content = preg_replace_callback(
                            '/@font-face\s*\{([^}]+)\}/i',
                            function (array $matches): string {
                                $body = $matches[1];
                                // Replace existing font-display value with swap
                                if (stripos($body, 'font-display') !== false) {
                                    $body = preg_replace('/font-display\s*:\s*[a-zA-Z-]+/i', 'font-display: swap', $body);
                                    return '@font-face{' . $body . '}';
                                }
                                // Trim trailing whitespace/semicolons, then append cleanly
                                $body = rtrim($body, " \t\n\r;");
                                return '@font-face{' . $body . ';font-display:swap}';
                            },
                            $content,
                        );

                        return $open . $content . $close;
                    },
                    $html,
                );
            }
        }

        if (!empty($this->settings['font_system_stack'])) {
            $style = '<style id="wpsc-system-font">:root{--wpsc-system-font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}body{font-family:var(--wpsc-system-font)}</style>';
            $html = str_replace('</head>', $style . '</head>', $html);
        }

        return $html;
    }

    /**
     * Downloads Google Fonts CSS, parses it, downloads WOFF2 files, and returns an inline <style>.
     */
    private function localizeGoogleFont(array $matches): string
    {
        $originalTag = $matches[0];
        $rawUrl = html_entity_decode($matches[1]);

        // Google Fonts' text parameter produces a real glyph-subsetted WOFF2
        // response. The configured text stays local until the administrator
        // explicitly enables Google font localization.
        $subsetText = trim((string) ($this->settings['font_subset_text'] ?? ''));
        if ($subsetText !== '') {
            $rawUrl = add_query_arg('text', substr($subsetText, 0, 512), $rawUrl);
        }

        // Canonicalize URL to prevent duplicates (remove ver, sort params)
        $url = $this->canonicalizeUrl($rawUrl);

        // Create a cache ID based on the clean URL
        $cacheFilename = md5($url) . '.css';
        $cacheKey = 'wpsc_font_css_' . md5($url);

        // 1. Check runtime memory cache
        if (isset($this->cssCache[$cacheKey])) {
            return $this->formatCss($this->cssCache[$cacheKey], $cacheFilename);
        }

        // 2. Check object cache (transient)
        $css = get_transient($cacheKey);

        if ($css === false) {
            $cacheFile = $this->fontCacheDir . $cacheFilename;

            // 3. Fall back to file system
            if (file_exists($cacheFile)) {
                $css = @file_get_contents($cacheFile);
                if ($css !== false && $css !== '') {
                    set_transient($cacheKey, $css, MONTH_IN_SECONDS);
                } else {
                    $css = false;
                }
            }

            if ($css === false) {
                // 4. Download and process
                $css = $this->downloadAndProcessFont($url);
                if ($css === null) {
                    return $originalTag;
                }
                $this->ensureFontDir();
                $this->atomicWriteFile($cacheFile, $css);
                set_transient($cacheKey, $css, MONTH_IN_SECONDS);
            }
        }

        $this->cssCache[$cacheKey] = $css;

        return $this->formatCss($css, $cacheFilename);
    }

    private function formatCss(string $css, string $filename): string
    {
        $style = sprintf(
            '<style id="wpsc-local-font-%s">%s</style>',
            substr($filename, 0, 8),
            $css,
        );
        if (empty($this->settings['font_auto_preload_localized'])) {
            return $style;
        }
        preg_match_all('~url\((?:["\']?)([^)"\']+\.(?:woff2?|ttf|otf))(?:["\']?)\)~i', $css, $matches);
        $preloads = '';
        foreach (array_slice(array_values(array_unique($matches[1] ?? [])), 0, 4) as $url) {
            $extension = strtolower(pathinfo((string) parse_url((string) $url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $preloads .= '<link rel="preload" as="font" type="font/' . esc_attr($extension) . '" href="' . esc_url((string) $url) . '" crossorigin>';
        }
        return $preloads . $style;
    }

    /**
     * Downloads Google Fonts CSS, parses it, downloads WOFF2 files locally.
     */
    private function downloadAndProcessFont(string $apiUrl): ?string
    {
        // Fetch CSS masquerading as Chrome to get WOFF2 (modern format)
        $response = wp_safe_remote_get($apiUrl, [
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        $css = wp_remote_retrieve_body($response);
        if (empty($css)) {
            return null;
        }

        // Extract and download font file URLs
        $css = preg_replace_callback(
            '/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/',
            function (array $m): string {
                $remoteFontUrl = $m[1];
                return 'url(' . $this->downloadFontFile($remoteFontUrl) . ')';
            },
            $css,
        );

        // Ensure font-display: swap in downloaded CSS
        if (!empty($this->settings['font_display_swap'])) {
            $css = preg_replace_callback(
                '/@font-face\s*\{([^}]+)\}/i',
                function (array $m): string {
                    $body = $m[1];
                    // Replace existing font-display value with swap
                    if (stripos($body, 'font-display') !== false) {
                        $body = preg_replace('/font-display\s*:\s*[a-zA-Z-]+/i', 'font-display: swap', $body);
                        return '@font-face{' . $body . '}';
                    }
                    $body = rtrim($body, " \t\n\r;");
                    return '@font-face{' . $body . ';font-display:swap}';
                },
                $css,
            );
        }

        return $css;
    }

    /**
     * Downloads a single font file and returns its local URL.
     */
    private function downloadFontFile(string $url): string
    {
        // Use MD5 of URL for filename — handles query strings and same-basename collisions
        $path = parse_url($url, PHP_URL_PATH);
        $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        // Strict whitelist to prevent dangerous file writes (security)
        if (!in_array($ext, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true)) {
            $ext = 'woff2';
        }

        $filename = md5($url) . '.' . $ext;
        $localPath = $this->fontCacheDir . $filename;
        $localUrl = $this->fontCacheUrl . $filename;

        if (file_exists($localPath)) {
            return $localUrl;
        }

        $response = wp_safe_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            return $url; // Fallback to remote if download fails
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return $url;
        }

        $processed = $this->processWithFontTools($body, $ext);
        $processed = apply_filters('wpsc_process_local_font', $processed, $url, $this->settings);
        if (is_array($processed) && is_string($processed['body'] ?? null) && $processed['body'] !== '') {
            $body = $processed['body'];
            $processedExtension = strtolower((string) ($processed['extension'] ?? $ext));
            if (in_array($processedExtension, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true) && $processedExtension !== $ext) {
                $filename = md5($url) . '.' . $processedExtension;
                $localPath = $this->fontCacheDir . $filename;
                $localUrl = $this->fontCacheUrl . $filename;
            }
        }
        $this->ensureFontDir();
        $this->atomicWriteFile($localPath, $body);

        return $localUrl;
    }

    /** @return array{body: string, extension: string} */
    private function processWithFontTools(string $body, string $extension): array
    {
        $text = trim((string) ($this->settings['font_subset_text'] ?? ''));
        if ($text === '' || !defined('WPSC_PYFTSUBSET_BINARY') || !function_exists('proc_open')) {
            return ['body' => $body, 'extension' => $extension];
        }
        $binary = realpath((string) WPSC_PYFTSUBSET_BINARY);
        if ($binary === false || !is_file($binary) || !in_array(strtolower(basename($binary)), ['pyftsubset', 'pyftsubset.exe'], true)) {
            return ['body' => $body, 'extension' => $extension];
        }
        $this->ensureFontDir();
        $source = tempnam($this->fontCacheDir, 'wpsc_font_source_');
        $target = tempnam($this->fontCacheDir, 'wpsc_font_target_');
        if ($source === false || $target === false) {
            @unlink((string) $source);
            @unlink((string) $target);
            return ['body' => $body, 'extension' => $extension];
        }
        file_put_contents($source, $body, LOCK_EX);
        @unlink($target);
        $command = [$binary, $source, '--output-file=' . $target, '--flavor=woff2', '--text=' . substr($text, 0, 512), '--layout-features=*', '--no-hinting'];
        $process = @proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            @unlink($source);
            return ['body' => $body, 'extension' => $extension];
        }
        foreach ($pipes as $index => $pipe) {
            if (is_resource($pipe)) {
                if ($index > 0) {
                    stream_get_contents($pipe);
                }
                fclose($pipe);
            }
        }
        $status = proc_close($process);
        $converted = $status === 0 && is_file($target) ? file_get_contents($target) : false;
        @unlink($source);
        @unlink($target);
        return is_string($converted) && str_starts_with($converted, 'wOF2')
            ? ['body' => $converted, 'extension' => 'woff2']
            : ['body' => $body, 'extension' => $extension];
    }

    /**
     * Ensures the font cache directory exists.
     */
    private function ensureFontDir(): void
    {
        if (!is_dir($this->fontCacheDir)) {
            @mkdir($this->fontCacheDir, 0755, true);
            @file_put_contents(
                $this->fontCacheDir . 'index.php',
                '<?php // Silence is golden',
            );
        }
    }

    /**
     * Atomic file write using temp file + rename.
     */
    private function atomicWriteFile(string $filepath, string $content): bool
    {
        $dir = dirname($filepath);
        $temp = @tempnam($dir, 'wpsc_font_');
        if ($temp === false) {
            // Fallback to direct write
            return @file_put_contents($filepath, $content, LOCK_EX) !== false;
        }

        if (@file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            return false;
        }

        @chmod($temp, 0644);

        if (!@rename($temp, $filepath)) {
            @unlink($filepath);
            if (!@rename($temp, $filepath)) {
                @unlink($temp);
                return false;
            }
        }

        return true;
    }

    /**
     * Canonicalizes Google Font URLs to prevent duplicate cache entries.
     * Removes cache-busting params (ver, version, etc.) and sorts remaining params.
     */
    private function canonicalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $params);

        // Remove cache-busting parameters often added by WP themes
        unset(
            $params['ver'],
            $params['version'],
            $params['timestamp'],
            $params['time'],
        );

        // Sort parameters to ensure consistent cache keys
        ksort($params);

        // Rebuild URL
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '//';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        $query = http_build_query($params);

        return $scheme . $host . $path . ($query !== '' ? '?' . $query : '');
    }
}
