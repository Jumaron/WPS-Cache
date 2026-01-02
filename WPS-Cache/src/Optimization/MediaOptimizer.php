<?php

declare(strict_types=1);

namespace WPSCache\Optimization;

/**
 * Handles Image and Video Optimization.
 *
 * Features:
 * 1. Native Lazy Loading (Images & Iframes).
 * 2. Add Missing Dimensions (Prevents CLS).
 * 3. YouTube Facade (Replaces heavy player with static thumbnail).
 */
class MediaOptimizer
{
    private array $settings;
    private int $imageCount = 0;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function process(string $html): string
    {
        $tags = [];
        $processImages = !empty($this->settings["media_lazy_load"]) || !empty($this->settings["media_add_dimensions"]);
        $processIframes = !empty($this->settings["media_lazy_load_iframes"]) || !empty($this->settings["media_youtube_facade"]);

        if ($processImages) {
            $tags[] = "img";
        }
        if ($processIframes) {
            $tags[] = "iframe";
        }

        if (empty($tags)) {
            // Even if no tags to process, we might need to inject facade assets if they were added previously?
            // Original logic only injected assets if "wpsc-youtube-wrapper" existed.
            // That wrapper is added by processIframe.
            // If processIframes is false, processIframe is never called, so wrapper never exists.
            // So we can safely return early.
            return $html;
        }

        // Optimization: Combine Regex passes into one O(N) scan
        $pattern = "/<(" . implode("|", $tags) . ")\s+([^>]+)>/i";

        $html = preg_replace_callback(
            $pattern,
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
        } elseif ($isAboveFold) {
            // SOTA: Explicitly mark LCP candidates as eager
            $tag = str_replace(
                "<img ",
                '<img loading="eager" fetchpriority="high" ',
                $tag,
            );
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

        // 2. Lazy Load Generic Iframes
        if (!empty($this->settings["media_lazy_load_iframes"])) {
            if (stripos($attrs, "loading=") === false) {
                $tag = str_replace("<iframe ", '<iframe loading="lazy" ', $tag);
            }
        }

        return $tag;
    }

    private function addDimensions(string $tag, string $attrs): string
    {
        // Extract SRC
        if (!preg_match('/src=["\']([^"\']+)["\']/i', $attrs, $srcMatch)) {
            return $tag;
        }
        $url = $srcMatch[1];

        // Only handle local images
        if (strpos($url, site_url()) !== 0 && strpos($url, "/") !== 0) {
            return $tag;
        }

        // Convert URL to Path
        $path = str_replace(
            [site_url(), "wp-content"],
            [ABSPATH, "wp-content"],
            $url,
        );
        // Remove query strings
        $path = strtok($path, "?");

        // SOTA: Cache the result to avoid disk I/O on every request
        // Key is hashed path (invalidates via TTL or manual flush)
        $cacheKey = "wpsc_dim_" . md5($path);
        $dims = get_transient($cacheKey);

        if (!$dims) {
            if (!file_exists($path)) {
                return $tag;
            }

            $dims = @getimagesize($path);
            if ($dims) {
                set_transient($cacheKey, $dims, MONTH_IN_SECONDS);
            }
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
