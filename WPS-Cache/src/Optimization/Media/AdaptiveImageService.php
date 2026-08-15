<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Media;

use Imagick;
use Throwable;
use WPSCache\Config\Settings;
use WPSCache\Contracts\HtmlProcessor;
use WPSCache\Contracts\Module;

/** Signed, cached, same-origin image resizing used by responsive browser delivery. */
final class AdaptiveImageService implements HtmlProcessor, Module
{
    /** @var array<string, mixed> */
    private array $settings;
    private string $root;
    private string $baseUrl;

    /** @param Settings|array<string, mixed> $settings */
    public function __construct(Settings|array $settings, ?string $root = null, ?string $baseUrl = null)
    {
        $this->settings = $settings instanceof Settings ? $settings->all() : $settings;
        $this->root = rtrim($root ?? WPSC_CACHE_DIR . 'adaptive-images', '/\\') . DIRECTORY_SEPARATOR;
        $originUrl = content_url('cache/wps-cache/adaptive-images');
        $cdn = !empty($this->settings['cdn_enable']) ? (string) ($this->settings['cdn_media_url'] ?: ($this->settings['cdn_url'] ?? '')) : '';
        $cdnUrl = $cdn !== '' ? rtrim($cdn, '/') . (string) parse_url($originUrl, PHP_URL_PATH) : $originUrl;
        $this->baseUrl = rtrim($baseUrl ?? $cdnUrl, '/');
    }

    public function id(): string
    {
        return 'adaptive-images';
    }

    public function boot(): void
    {
        if (empty($this->settings['image_adaptive_delivery'])) {
            return;
        }
        add_action('wp_ajax_nopriv_wpsc_adaptive_image', [$this, 'serve']);
        add_action('wp_ajax_wpsc_adaptive_image', [$this, 'serve']);
    }

    public function process(string $html): string
    {
        if (empty($this->settings['image_adaptive_delivery'])) {
            return $html;
        }
        return preg_replace_callback('~<img\b([^>]*\bsrc=["\']([^"\']+)["\'][^>]*)>~i', function (array $match): string {
            $source = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
            $path = $this->uploadPath($source);
            if ($path === null || !is_file($path)) {
                return $match[0];
            }
            $dimensions = @getimagesize($path);
            $originalWidth = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0;
            if ($originalWidth < 2 || $this->animated($path) || !$this->canTransform($path)) {
                return $match[0];
            }
            $maximum = max(1, min($originalWidth, (int) ($this->settings['image_adaptive_max_width'] ?? 2560)));
            $widths = array_values(array_unique(array_filter([320, 480, 640, 768, 1024, 1280, 1600, 1920, $maximum], static fn(int $width): bool => $width <= $maximum)));
            sort($widths);
            $srcset = [];
            foreach ($widths as $width) {
                $srcset[] = esc_url($this->transformUrl($source, $width)) . ' ' . $width . 'w';
            }
            $fallbackWidth = min($maximum, max(1, $originalWidth));
            $attributes = preg_replace('~\bsrc=["\'][^"\']+["\']~i', 'src="' . esc_url($this->transformUrl($source, $fallbackWidth)) . '"', $match[1], 1) ?? $match[1];
            $attributes = preg_replace('~\s+srcset=["\'][^"\']*["\']~i', '', $attributes) ?? $attributes;
            $attributes = preg_replace('~\s+sizes=["\'][^"\']*["\']~i', '', $attributes) ?? $attributes;
            return '<img' . $attributes . ' srcset="' . esc_attr(implode(', ', $srcset)) . '" sizes="(max-width: ' . $maximum . 'px) 100vw, ' . $maximum . 'px" data-wpsc-adaptive="1">';
        }, $html) ?? $html;
    }

    public function transformUrl(string $source, int $width): string
    {
        $relative = $this->uploadRelative($source);
        $width = max(1, min(12000, $width));
        $quality = max(1, min(100, (int) ($this->settings['image_adaptive_quality'] ?? 82)));
        $payload = $relative . '|' . $width . '|' . $quality;
        return add_query_arg([
            'action' => 'wpsc_adaptive_image',
            'image' => $this->base64UrlEncode($relative),
            'width' => $width,
            'quality' => $quality,
            'signature' => hash_hmac('sha256', $payload, $this->signingKey()),
        ], admin_url('admin-ajax.php'));
    }

    public function serve(): never
    {
        $relative = $this->base64UrlDecode(isset($_GET['image']) && is_string($_GET['image']) ? $_GET['image'] : '');
        $width = max(1, min(12000, absint($_GET['width'] ?? 0)));
        $quality = max(1, min(100, absint($_GET['quality'] ?? 0)));
        $signature = isset($_GET['signature']) && is_string($_GET['signature']) ? $_GET['signature'] : '';
        $payload = $relative . '|' . $width . '|' . $quality;
        if ($relative === '' || !hash_equals(hash_hmac('sha256', $payload, $this->signingKey()), $signature)) {
            status_header(403);
            exit;
        }
        $uploads = wp_get_upload_dir();
        $base = realpath((string) ($uploads['basedir'] ?? ''));
        $source = $base !== false ? realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)) : false;
        if ($source === false || $base === false || (!str_starts_with($source, rtrim($base, '/\\') . DIRECTORY_SEPARATOR) && $source !== $base) || !is_file($source) || $this->animated($source)) {
            status_header(404);
            exit;
        }
        $format = $this->preferredFormat((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), strtolower(pathinfo($source, PATHINFO_EXTENSION)));
        $target = $this->generate($source, $width, $quality, $format);
        if ($target === null) {
            status_header(415);
            exit;
        }
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Vary: Accept');
        header('Location: ' . $this->baseUrl . '/' . rawurlencode(basename($target)), true, 302);
        exit;
    }

    public function generate(string $source, int $width, int $quality, string $format): ?string
    {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'], true)) {
            return null;
        }
        if (!$this->prepareDirectory()) {
            return null;
        }
        $format = in_array($format, ['avif', 'webp', 'jpg', 'png'], true) ? $format : ($extension === 'png' ? 'png' : 'jpg');
        $key = hash('sha256', $source . '|' . (int) filemtime($source) . '|' . $width . '|' . $quality . '|' . $format);
        $target = $this->root . $key . '.' . $format;
        if (is_file($target) && filesize($target) > 0) {
            return $target;
        }
        $dimensions = @getimagesize($source);
        $sourceWidth = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0;
        $width = min($width, max(1, $sourceWidth));
        $success = $this->generateImagick($source, $target, $width, $quality, $format)
            || $this->generateGd($source, $target, $width, $quality, $format);
        return $success ? $target : null;
    }

    private function generateImagick(string $source, string $target, int $width, int $quality, string $format): bool
    {
        if (!extension_loaded('imagick') || !class_exists(Imagick::class)) {
            return false;
        }
        try {
            $image = new Imagick($source);
            $image->setIteratorIndex(0);
            $image->thumbnailImage($width, 0, true, true);
            $image->setImageFormat($format === 'jpg' ? 'jpeg' : $format);
            $image->setImageCompressionQuality($quality);
            $success = $image->writeImage($target);
            $image->clear();
            return $success;
        } catch (Throwable) {
            return false;
        }
    }

    private function generateGd(string $source, string $target, int $width, int $quality, string $format): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $create = match ($extension) {
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'webp' => 'imagecreatefromwebp',
            'avif' => 'imagecreatefromavif',
            'gif' => 'imagecreatefromgif',
            default => null,
        };
        if ($create === null || !function_exists($create)) {
            return false;
        }
        $image = @$create($source);
        if ($image === false) {
            return false;
        }
        $height = max(1, (int) round(imagesy($image) * ($width / max(1, imagesx($image)))));
        $targetImage = imagecreatetruecolor($width, $height);
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $background = in_array($format, ['png', 'webp', 'avif'], true)
            ? imagecolorallocatealpha($targetImage, 0, 0, 0, 127)
            : imagecolorallocate($targetImage, 255, 255, 255);
        imagefilledrectangle($targetImage, 0, 0, $width - 1, $height - 1, $background);
        imagecopyresampled($targetImage, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        $success = match ($format) {
            'webp' => function_exists('imagewebp') && imagewebp($targetImage, $target, $quality),
            'avif' => function_exists('imageavif') && imageavif($targetImage, $target, $quality),
            'png' => imagepng($targetImage, $target, max(0, min(9, (int) round((100 - $quality) / 11.1)))),
            default => imagejpeg($targetImage, $target, $quality),
        };
        imagedestroy($targetImage);
        imagedestroy($image);
        return $success;
    }

    private function preferredFormat(string $accept, string $sourceExtension): string
    {
        if (str_contains($accept, 'image/avif') && (function_exists('imageavif') || $this->imagickFormat('AVIF'))) {
            return 'avif';
        }
        if (str_contains($accept, 'image/webp') && (function_exists('imagewebp') || $this->imagickFormat('WEBP'))) {
            return 'webp';
        }
        return $sourceExtension === 'png' ? 'png' : 'jpg';
    }

    private function imagickFormat(string $format): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class) && in_array($format, array_map('strtoupper', Imagick::queryFormats($format)), true);
    }

    private function canTransform(string $source): bool
    {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'], true)) {
            return false;
        }
        $imagickFormat = match ($extension) {
            'jpg', 'jpeg' => 'JPEG',
            default => strtoupper($extension),
        };
        if ($this->imagickFormat($imagickFormat)) {
            return true;
        }
        if (!extension_loaded('gd') || !function_exists('imagejpeg')) {
            return false;
        }
        $decoder = match ($extension) {
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'webp' => 'imagecreatefromwebp',
            'avif' => 'imagecreatefromavif',
            'gif' => 'imagecreatefromgif',
            default => '',
        };
        return $decoder !== '' && function_exists($decoder) && ($extension !== 'png' || function_exists('imagepng'));
    }

    private function uploadPath(string $url): ?string
    {
        $relative = $this->uploadRelative($url);
        if ($relative === '') {
            return null;
        }
        $uploads = wp_get_upload_dir();
        $base = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        return $base !== '' ? $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative) : null;
    }

    private function uploadRelative(string $url): string
    {
        $uploads = wp_get_upload_dir();
        $baseUrl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
        $clean = strtok($url, '?') ?: $url;
        if ($baseUrl === '' || !str_starts_with($clean, $baseUrl . '/')) {
            return '';
        }
        $relative = rawurldecode(substr($clean, strlen($baseUrl) + 1));
        return str_contains($relative, '..') ? '' : str_replace('\\', '/', $relative);
    }

    private function animated(string $file): bool
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($extension === 'gif') {
            $content = @file_get_contents($file, false, null, 0, 1048576);
            return is_string($content) && preg_match_all('/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $content) > 1;
        }
        return false;
    }

    private function signingKey(): string
    {
        return wp_salt('auth') . '|wps-cache-adaptive-image';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return '';
        }
        $encoded = strtr($value, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        return is_string($decoded) && !str_contains($decoded, '..') ? $decoded : '';
    }

    private function prepareDirectory(): bool
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0755, true) && !is_dir($this->root)) {
            return false;
        }
        if (!is_file($this->root . 'index.php')) {
            @file_put_contents($this->root . 'index.php', "<?php\n// Silence is golden.\n", LOCK_EX);
        }
        return is_writable($this->root);
    }
}
