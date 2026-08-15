<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Contracts\HtmlProcessor;

/**
 * Handles Image and Video Optimization.
 *
 * Features:
 * 1. Native Lazy Loading (Images & Iframes).
 * 2. Add Missing Dimensions (Prevents CLS).
 * 3. YouTube Facade (Replaces heavy player with static thumbnail).
 */
final class MediaOptimizer implements HtmlProcessor
{
    private array $settings;
    private int $imageCount = 0;
    private string $siteUrl;
    private array $dimensionCache = [];
    private ?string $regexPattern = null;
    private array $checkedTransients = [];
    private ?string $lcpUrl = null;
    private ?string $detectedLcpPath = null;
    /** @var list<string> */
    private array $detectedAboveFoldPaths = [];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->siteUrl = site_url();
        $lcpMap = get_option('wpsc_lcp_images', []);
        $requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        if (is_array($lcpMap) && is_array($lcpMap[$requestPath] ?? null)) {
            $this->detectedLcpPath = (string) ($lcpMap[$requestPath]['image'] ?? '');
        }
        $aboveFoldMap = get_option('wpsc_above_fold_images', []);
        if (is_array($aboveFoldMap) && is_array($aboveFoldMap[$requestPath] ?? null)) {
            $this->detectedAboveFoldPaths = array_values(array_filter(array_map('strval', (array) ($aboveFoldMap[$requestPath]['images'] ?? []))));
        }

        $tags = [];
        $processImages = !empty($this->settings["media_lazy_load"]) || !empty($this->settings["media_add_dimensions"]);
        $processIframes = !empty($this->settings["media_lazy_load_iframes"]) || !empty($this->settings["media_youtube_facade"]);
        $processIframes = $processIframes || !empty($this->settings['media_vimeo_facade']) || !empty($this->settings['media_maps_facade']);

        if ($processImages) {
            $tags[] = "img";
        }
        if ($processIframes) {
            $tags[] = "iframe";
        }

        if (!empty($tags)) {
            // Optimization: Combine Regex passes into one O(N) scan
            $this->regexPattern = "/<(" . implode("|", $tags) . ")\s+([^>]+)>/i";
        }
    }

    public function process(string $html): string
    {
        if ($this->regexPattern === null) {
            // Even if no tags to process, we might need to inject facade assets if they were added previously?
            // Original logic only injected assets if "wpsc-youtube-wrapper" existed.
            // That wrapper is added by processIframe.
            // If processIframes is false, processIframe is never called, so wrapper never exists.
            // So we can safely return early.
            return $html;
        }

        // Optimization: Prime cache for image dimensions to prevent N+1 DB queries
        $this->primeDimensionCache($html);

        $html = preg_replace_callback(
            $this->regexPattern,
            function ($matches) {
                // $matches[0] = full tag
                // $matches[1] = tag name (img|iframe)
                // $matches[2] = attributes

                $tagName = strtolower($matches[1]);
                $subMatches = [$matches[0], $matches[2]]; // [Tag, Attributes]

                if ($tagName === "img") {
                    return $this->processImage($subMatches);
                }
                if ($tagName === "iframe") {
                    return $this->processIframe($subMatches);
                }
                return $matches[0];
            },
            $html,
        );

        if (!empty($this->settings['media_lazy_load_video'])) {
            $html = $this->lazyLoadVideos($html);
        }
        if (!empty($this->settings['media_lazy_backgrounds'])) {
            $html = $this->lazyLoadBackgrounds($html);
        }

        // 3. Inject YouTube Facade CSS/JS if needed
        if (
            !empty($this->settings["media_youtube_facade"]) &&
            strpos($html, "wpsc-youtube-wrapper") !== false
        ) {
            $html = str_replace(
                "</body>",
                $this->getFacadeAssets() . "</body>",
                $html,
            );
        }

        if (str_contains($html, 'wpsc-embed-facade')) {
            $html = str_replace('</body>', $this->getGenericFacadeAssets() . '</body>', $html);
        }
        if (!empty($this->settings['media_lcp_preload']) && $this->lcpUrl !== null) {
            $preload = '<link rel="preload" as="image" href="' . esc_url($this->lcpUrl) . '" fetchpriority="high">';
            $html = str_replace('</head>', $preload . '</head>', $html);
        }

        return $html;
    }

    private function processImage(array $matches): string
    {
        $tag = $matches[0];
        $attrs = $matches[1];

        $this->imageCount++;

        // Skip the first X images to protect LCP (Largest Contentful Paint)
        // Usually the Logo and the Hero Image.
        $skipCount =
            (int) ($this->settings["media_lazy_load_exclude_count"] ?? 3);
        $isAboveFold = $this->imageCount <= $skipCount;

        $sourceUrl = null;
        if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $attrs, $source) === 1) {
            $sourceUrl = html_entity_decode($source[1], ENT_QUOTES | ENT_HTML5);
            $sourcePath = (string) (parse_url($sourceUrl, PHP_URL_PATH) ?: '');
            if (in_array($sourcePath, $this->detectedAboveFoldPaths, true)) {
                $isAboveFold = true;
            }
            if ($this->detectedLcpPath !== null && $sourcePath === $this->detectedLcpPath) {
                $isAboveFold = true;
                $this->lcpUrl = $sourceUrl;
            }
        }

        if ($isAboveFold && $this->lcpUrl === null && $sourceUrl !== null) {
            $this->lcpUrl = $sourceUrl;
        }

        // 1. Add Missing Dimensions
        if (!empty($this->settings["media_add_dimensions"])) {
            if (
                stripos($attrs, "width=") === false ||
                stripos($attrs, "height=") === false
            ) {
                $tag = $this->addDimensions($tag, $attrs);
            }
        }

        // 2. Native Lazy Loading
        if (!empty($this->settings["media_lazy_load"]) && !$isAboveFold) {
            // Check exclusions
            if (
                stripos($attrs, "data-no-lazy") !== false ||
                stripos($attrs, "loading=") !== false
            ) {
                return $tag;
            }

            // Add loading="lazy" and decoding="async"
            $tag = str_replace(
                "<img ",
                '<img loading="lazy" decoding="async" ',
                $tag,
            );
            if (!empty($this->settings['media_lqip']) && stripos($tag, 'style=') === false) {
                $placeholder = 'background:#f0f2f5;background-size:cover;background-position:center;';
                if ($sourceUrl !== null) {
                    $sourcePath = $this->urlToPath($sourceUrl);
                    $lqipPath = preg_replace('/\.[^.]+$/', '.wps-lqip.jpg', $sourcePath);
                    $lqipUrl = preg_replace('/\.[^.]+(?=\?.*|$)/', '.wps-lqip.jpg', $sourceUrl);
                    if (is_string($lqipPath) && is_file($lqipPath) && is_string($lqipUrl)) {
                        $placeholder = 'background-image:url(&quot;' . esc_url($lqipUrl) . '&quot;);background-size:cover;background-position:center;';
                    }
                }
                $tag = str_replace('<img ', '<img style="' . $placeholder . '" ', $tag);
            }
        } elseif ($isAboveFold) {
            // SOTA: Explicitly mark LCP candidates as eager
            $tag = str_replace(
                "<img ",
                '<img loading="eager" fetchpriority="high" ',
                $tag,
            );
        }


        if (!empty($this->settings['media_responsive_images']) && stripos($tag, 'srcset=') === false && function_exists('attachment_url_to_postid')) {
            if (preg_match('/\bsrc=["\']([^"\']+)["\']/i', $tag, $source) === 1) {
                $attachmentId = attachment_url_to_postid(html_entity_decode($source[1], ENT_QUOTES | ENT_HTML5));
                if ($attachmentId > 0) {
                    $srcset = wp_get_attachment_image_srcset($attachmentId, 'full');
                    $sizes = wp_get_attachment_image_sizes($attachmentId, 'full');
                    if (is_string($srcset) && $srcset !== '') {
                        $attributes = ' srcset="' . esc_attr($srcset) . '"';
                        if (is_string($sizes) && $sizes !== '') {
                            $attributes .= ' sizes="' . esc_attr($sizes) . '"';
                        }
                        $tag = preg_replace('/\s*\/?>$/', $attributes . '$0', $tag, 1) ?? $tag;
                    }
                }
            }
        }

        return $tag;
    }

    private function processIframe(array $matches): string
    {
        $tag = $matches[0];
        $attrs = $matches[1];

        // 1. YouTube Facade
        if (!empty($this->settings["media_youtube_facade"])) {
            // Check if it's YouTube
            if (
                preg_match(
                    '/src=["\'](https?:)?\/\/(www\.)?(youtube\.com\/embed\/|youtu\.be\/)([^"\?]+)([^"\']*)["\']/i',
                    $attrs,
                    $ytMatches,
                )
            ) {
                $videoId = $ytMatches[4];
                $params = $ytMatches[5]; // query params
                return $this->createYouTubeFacade($videoId, $params, $attrs);
            }
        }

        if (
            (!empty($this->settings['media_vimeo_facade']) && stripos($attrs, 'vimeo.com') !== false) ||
            (!empty($this->settings['media_maps_facade']) && (stripos($attrs, 'google.com/maps') !== false || stripos($attrs, 'maps.google') !== false))
        ) {
            if (preg_match('/src=["\']([^"\']+)["\']/i', $attrs, $source) === 1) {
                return '<button type="button" class="wpsc-embed-facade" data-wpsc-src="' . esc_attr($source[1]) . '"><span>Load embedded content</span></button>';
            }
        }

        // 2. Lazy Load Generic Iframes
        if (!empty($this->settings["media_lazy_load_iframes"])) {
            if (stripos($attrs, "loading=") === false) {
                $tag = str_replace("<iframe ", '<iframe loading="lazy" ', $tag);
            }
        }

        return $tag;
    }

    private function lazyLoadVideos(string $html): string
    {
        $changed = false;
        $html = preg_replace_callback('~<video\b([^>]*)>(.*?)</video>~is', static function (array $match) use (&$changed): string {
            $changed = true;
            $attributes = preg_replace('/\bpreload=["\'][^"\']*["\']/i', '', $match[1]) ?? $match[1];
            $attributes = preg_replace('/\bsrc=(["\'])([^"\']+)\1/i', 'data-wpsc-src=$1$2$1', $attributes) ?? $attributes;
            $content = preg_replace('/\bsrc=(["\'])([^"\']+)\1/i', 'data-wpsc-src=$1$2$1', $match[2]) ?? $match[2];
            return '<video preload="none" data-wpsc-lazy-video' . $attributes . '>' . $content . '</video>';
        }, $html) ?? $html;
        if ($changed) {
            $script = '<script id="wpsc-video-lazy">var wpscVideoObserver=new IntersectionObserver((e,o)=>e.forEach(x=>{if(x.isIntersecting){if(x.target.dataset.wpscSrc){x.target.src=x.target.dataset.wpscSrc;x.target.removeAttribute("data-wpsc-src")}x.target.querySelectorAll("[data-wpsc-src]").forEach(s=>{s.src=s.dataset.wpscSrc;s.removeAttribute("data-wpsc-src")});x.target.load();o.unobserve(x.target)}}),{rootMargin:"300px"});document.querySelectorAll("[data-wpsc-lazy-video]").forEach(v=>wpscVideoObserver.observe(v));</script>';
            $html = str_replace('</body>', $script . '</body>', $html);
        }
        return $html;
    }

    private function lazyLoadBackgrounds(string $html): string
    {
        $changed = false;
        $html = preg_replace_callback('/style=(["\'])([^"\']*background(?:-image)?\s*:\s*url\(([^)]+)\)[^"\']*)\1/i', static function (array $match) use (&$changed): string {
            $changed = true;
            $style = preg_replace('/background(?:-image)?\s*:\s*url\([^)]+\)/i', 'background-image:none', $match[2], 1) ?? $match[2];
            return 'style=' . $match[1] . $style . $match[1] . ' data-wpsc-bg=' . $match[1] . trim($match[3], " \t\n\r\0\x0B\"'") . $match[1];
        }, $html) ?? $html;
        if ($changed) {
            $script = '<script id="wpsc-bg-lazy">document.querySelectorAll("[data-wpsc-bg]").forEach(el=>new IntersectionObserver((e,o)=>e.forEach(x=>{if(x.isIntersecting){x.target.style.backgroundImage="url(\""+x.target.dataset.wpscBg+"\")";x.target.removeAttribute("data-wpsc-bg");o.disconnect()}}),{rootMargin:"300px"}).observe(el));</script>';
            $html = str_replace('</body>', $script . '</body>', $html);
        }
        return $html;
    }

    private function getGenericFacadeAssets(): string
    {
        return '<style>.wpsc-embed-facade{display:grid;place-items:center;width:100%;min-height:240px;border:0;background:#151922;color:#fff;cursor:pointer}.wpsc-embed-facade span{padding:1rem 1.5rem;background:#fff;color:#111;border-radius:999px}</style><script>document.addEventListener("click",function(e){var b=e.target.closest(".wpsc-embed-facade");if(!b)return;var f=document.createElement("iframe");f.src=b.dataset.wpscSrc;f.loading="lazy";f.allowFullscreen=true;f.style.cssText="width:100%;min-height:360px;border:0";b.replaceWith(f)});</script>';
    }

    private function addDimensions(string $tag, string $attrs): string
    {
        // Extract SRC
        if (!preg_match('/src=["\']([^"\']+)["\']/i', $attrs, $srcMatch)) {
            return $tag;
        }
        $url = $srcMatch[1];

        // Only handle local images
        if (strpos($url, $this->siteUrl) !== 0 && strpos($url, "/") !== 0) {
            return $tag;
        }

        // Convert URL to Path (using shared helper)
        $path = $this->urlToPath($url);

        // Cache Key from raw path (Avoids realpath I/O on hit)
        $cacheKey = "wpsc_dim_" . md5($path);
        $dims = false;

        // 1. Check Memory Cache
        if (isset($this->dimensionCache[$path])) {
            $dims = $this->dimensionCache[$path];
        } else {
            // 2. Check Transient Cache
            // Optimization: Check if we already looked this up in primeDimensionCache
            if (array_key_exists($cacheKey, $this->checkedTransients)) {
                $dims = $this->checkedTransients[$cacheKey];
            } else {
                // Fallback for edge cases (e.g. regex miss)
                $dims = get_transient($cacheKey);
            }

            if ($dims === false) {
                // 3. Cache Miss: Fallback to File System (Heavy I/O)

                // Sentinel Fix: Prevent Path Traversal & Source Code Disclosure
                $realPath = realpath($path);

                if ($realPath !== false && str_starts_with($realPath, ABSPATH)) {
                    // Sentinel Fix: Ensure strictly Image extension
                    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

                    if (in_array($ext, ["jpg", "jpeg", "png", "gif", "webp", "avif", "svg"], true)) {
                        $dims = @getimagesize($realPath);
                        if ($dims) {
                            set_transient($cacheKey, $dims, MONTH_IN_SECONDS);
                        }
                    }
                }
            }
            // Update Memory Cache (stores false on failure to avoid re-check)
            $this->dimensionCache[$path] = $dims;
        }

        if ($dims) {
            $tag = str_replace(
                "<img ",
                sprintf('<img width="%d" height="%d" ', $dims[0], $dims[1]),
                $tag,
            );
        }

        return $tag;
    }

    private function urlToPath(string $url): string
    {
        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        $contentPath = rtrim((string) parse_url(content_url('/'), PHP_URL_PATH), '/');
        if ($contentPath !== '' && ($path === $contentPath || str_starts_with($path, $contentPath . '/'))) {
            return rtrim(WP_CONTENT_DIR, '/\\') . DIRECTORY_SEPARATOR . ltrim(substr($path, strlen($contentPath)), '/');
        }
        return rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/');
    }

    private function primeDimensionCache(string $html): void
    {
        if (empty($this->settings["media_add_dimensions"])) {
            return;
        }

        // Quick scan for local image sources
        if (!preg_match_all('/<img\s+[^>]*src=["\']([^"\']+)["\']/i', $html, $matches)) {
            return;
        }

        $urls = array_unique($matches[1]);
        $keysToFetch = [];
        $map = []; // Map cacheKey -> path (unused for logic but good for debugging context if needed)

        foreach ($urls as $url) {
            // Filter local images only
            if (strpos($url, $this->siteUrl) !== 0 && strpos($url, "/") !== 0) {
                continue;
            }

            $path = $this->urlToPath($url);

            // Optimization: Skip if already in memory cache
            if (isset($this->dimensionCache[$path])) {
                continue;
            }

            $key = "wpsc_dim_" . md5($path);

            // Optimization: Skip if already checked (e.g. repeated call)
            if (array_key_exists($key, $this->checkedTransients)) {
                continue;
            }

            $keysToFetch[] = $key;
            // Initialize as false (missing)
            $this->checkedTransients[$key] = false;
        }

        if (empty($keysToFetch)) {
            return;
        }

        // Batch Fetch Logic
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_get_multiple')) {
            // Object Cache: Fast multi-get
            $results = wp_cache_get_multiple($keysToFetch, 'transient');
            foreach ($results as $key => $value) {
                if ($value !== false) {
                    $this->checkedTransients[$key] = $value;
                }
            }
        } else {
            // Database: Reduce N queries to 1
            global $wpdb;

            $escaped_keys = [];
            foreach ($keysToFetch as $key) {
                $escaped_keys[] = "'" . esc_sql('_transient_' . $key) . "'";
            }

            $in_clause = implode(',', $escaped_keys);

            // Fetch values directly.
            // Note: We ignore timeout check here for dimensions as they are effectively static for a given path MD5.
            // If the file changes, the path MD5 stays same, so technically we might serve stale dimensions if we don't check timeout.
            // However, typical behavior for image dimensions is they don't change.
            // Also, strictly checking timeout requires joining on timeout keys which complicates the query.
            // Given the performance benefit (1 query vs 50), this trade-off is acceptable for dimensions.
            $rows = $wpdb->get_results("SELECT option_name, option_value FROM $wpdb->options WHERE option_name IN ($in_clause)");

            foreach ($rows as $row) {
                $key = str_replace('_transient_', '', $row->option_name);
                $this->checkedTransients[$key] = maybe_unserialize($row->option_value);
            }
        }
    }

    private function createYouTubeFacade(
        string $id,
        string $params,
        string $attrs,
    ): string {
        // Extract width/height if present
        $width = "100%";
        $height = "56.25%"; // 16:9 ratio default

        if (preg_match('/width=["\']([^"\']+)["\']/', $attrs, $w)) {
            $width = $w[1];
        }
        if (preg_match('/height=["\']([^"\']+)["\']/', $attrs, $h)) {
            $height = $h[1];
        }

        // Ensure width has unit if number
        if (is_numeric($width)) {
            $width .= "px";
        }
        if (is_numeric($height)) {
            $height .= "px";
        }

        // High-res thumbnail
        $thumb = "https://i.ytimg.com/vi/{$id}/maxresdefault.jpg";

        // The Facade HTML
        return sprintf(
            '<div class="wpsc-youtube-wrapper" data-id="%s" data-params="%s" style="width:%s; height:%s; background-image:url(\'%s\');">' .
                '<button class="wpsc-yt-play" aria-label="Play Video"></button>' .
                "</div>",
            esc_attr($id),
            esc_attr($params),
            esc_attr($width),
            esc_attr($height),
            esc_url($thumb),
        );
    }

    private function getFacadeAssets(): string
    {
        return <<<'HTML'
        <style>
        .wpsc-youtube-wrapper {
            position: relative;
            background-size: cover;
            background-position: center;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .wpsc-yt-play {
            width: 68px;
            height: 48px;
            background-color: #212121;
            z-index: 1;
            opacity: 0.8;
            border-radius: 10px;
            transition: all .2s cubic-bezier(0,0,.2,1);
            border: none;
            cursor: pointer;
            position: relative;
        }
        .wpsc-yt-play:before {
            content: "";
            border-style: solid;
            border-width: 11px 0 11px 19px;
            border-color: transparent transparent transparent #fff;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        .wpsc-youtube-wrapper:hover .wpsc-yt-play {
            background-color: #f00;
            opacity: 1;
        }
        </style>
        <script>
        document.querySelectorAll('.wpsc-youtube-wrapper').forEach(wrapper => {
            wrapper.addEventListener('click', function() {
                const id = this.dataset.id;
                const params = this.dataset.params || '';
                // Add autoplay
                const src = `https://www.youtube.com/embed/${id}${params}${params.includes('?') ? '&' : '?'}autoplay=1`;

                const iframe = document.createElement('iframe');
                iframe.src = src;
                iframe.width = "100%";
                iframe.height = "100%";
                iframe.frameBorder = "0";
                iframe.allow = "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture";
                iframe.allowFullscreen = true;

                this.innerHTML = '';
                this.style.backgroundImage = 'none';
                this.appendChild(iframe);
            });
        });
        </script>
        HTML;
    }
}
