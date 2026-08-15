<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Media;

use Imagick;
use Throwable;
use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

/** Local Imagick/GD image pipeline. External/cloud processors can use its filters. */
final class ImageOptimizer implements Module
{
    private const STATS_OPTION = 'wpsc_image_stats';
    private const BACKUP_HEADER = "<?php exit; __halt_compiler(); ?>\n";

    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'images';
    }

    public function boot(): void
    {
        if ($this->settings->enabled('image_optimize_upload')) {
            add_filter('wp_generate_attachment_metadata', [$this, 'optimizeAttachment'], 30, 2);
        }
        add_filter('wpsc_image_optimizer_capabilities', [$this, 'capabilities']);
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    public function optimizeAttachment(array $metadata, int $attachmentId): array
    {
        $original = function_exists('get_attached_file') ? get_attached_file($attachmentId) : false;
        if (is_string($original) && $original !== '') {
            $this->optimizeFile($original, $attachmentId);
            $dimensions = @getimagesize($original);
            if (is_array($dimensions)) {
                $metadata['width'] = (int) ($dimensions[0] ?? ($metadata['width'] ?? 0));
                $metadata['height'] = (int) ($dimensions[1] ?? ($metadata['height'] ?? 0));
            }
        }
        $directory = is_string($original) ? dirname($original) : '';
        $selectedSizes = $this->settings->strings('image_optimize_sizes');
        foreach ((array) ($metadata['sizes'] ?? []) as $size => $data) {
            if ($selectedSizes !== [] && !in_array((string) $size, $selectedSizes, true)) {
                continue;
            }
            $file = is_array($data) ? (string) ($data['file'] ?? '') : '';
            if ($directory !== '' && $file !== '') {
                $this->optimizeFile($directory . DIRECTORY_SEPARATOR . basename($file), $attachmentId, false);
            }
        }
        return $metadata;
    }

    /** @return array{success: bool, saved: int, variants: list<string>, message: string} */
    public function optimizeFile(string $file, int $attachmentId = 0, bool $backup = true): array
    {
        $before = is_file($file) ? (int) filesize($file) : 0;
        if ($before <= 0 || !$this->allowed($file)) {
            return ['success' => false, 'saved' => 0, 'variants' => [], 'message' => 'File is missing or excluded.'];
        }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $supported = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'pdf'];
        if (!in_array($extension, $supported, true)) {
            return ['success' => false, 'saved' => 0, 'variants' => [], 'message' => 'Unsupported local format.'];
        }
        if ($backup && $this->settings->enabled('image_backup_originals')) {
            $this->backup($file);
        }

        $result = apply_filters('wpsc_optimize_image_file', null, $file, $this->settings, $attachmentId);
        if (!is_array($result)) {
            $result = $extension === 'pdf'
                ? ['success' => false, 'message' => 'PDF optimization requires a provider that preserves document vectors/text.']
                : ($this->imagickAvailable()
                ? $this->optimizeWithImagick($file, $extension)
                : $this->optimizeWithGd($file, $extension));
        }
        $variants = $this->createVariants($file, $extension);
        clearstatcache(true, $file);
        $after = is_file($file) ? (int) filesize($file) : $before;
        $saved = max(0, $before - $after);
        $success = !empty($result['success']);
        if ($success) {
            $this->recordStats($saved, count($variants));
            do_action('wpsc_image_optimized', $file, $saved, $variants, $attachmentId);
        }
        return [
            'success' => $success,
            'saved' => $saved,
            'variants' => $variants,
            'message' => (string) ($result['message'] ?? ($success ? 'Optimized locally.' : 'No compatible encoder is available.')),
        ];
    }

    public function restore(string $file): bool
    {
        $backup = $this->backupPath($file);
        if (!is_file($backup) || !$this->allowed($file)) {
            return false;
        }
        $temporary = $file . '.wpsc-restore-' . bin2hex(random_bytes(4));
        $input = @fopen($backup, 'rb');
        $output = @fopen($temporary, 'wb');
        if (!is_resource($input) || !is_resource($output)) {
            is_resource($input) && fclose($input);
            is_resource($output) && fclose($output);
            @unlink($temporary);
            return false;
        }
        fseek($input, strlen(self::BACKUP_HEADER));
        $copied = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        if ($copied === false) {
            @unlink($temporary);
            return false;
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return false;
        }
        do_action('wpsc_image_restored', $file);
        return true;
    }

    /** @return array<string, bool> */
    public function capabilities(array $capabilities = []): array
    {
        $formats = class_exists(Imagick::class) ? array_map('strtoupper', Imagick::queryFormats()) : [];
        return $capabilities + [
            'imagick' => $this->imagickAvailable(),
            'gd' => extension_loaded('gd'),
            'webp' => in_array('WEBP', $formats, true) || function_exists('imagewebp'),
            'avif' => in_array('AVIF', $formats, true) || function_exists('imageavif'),
            'animated_gif' => $this->imagickAvailable(),
            'exif' => extension_loaded('exif'),
        ];
    }

    /** @return array{success: bool, message: string} */
    private function optimizeWithImagick(string $file, string $extension): array
    {
        try {
            $image = new Imagick($file);
            if (in_array($extension, ['gif', 'png'], true) && $image->getNumberImages() > 1) {
                $image = $image->coalesceImages();
                foreach ($image as $frame) {
                    $this->configureImagick($frame);
                }
                $image = $image->deconstructImages();
                $image->writeImages($file, true);
            } else {
                if (method_exists($image, 'autoOrient')) {
                    $image->autoOrient();
                }
                $this->resizeImagick($image);
                $this->configureImagick($image);
                $image->writeImage($file);
            }
            $image->clear();
            $image->destroy();
            return ['success' => true, 'message' => 'Optimized with Imagick.'];
        } catch (Throwable $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    private function configureImagick(Imagick $image): void
    {
        if (!$this->settings->enabled('image_preserve_exif')) {
            $image->stripImage();
        }
        $quality = $this->settings->enabled('image_lossless') ? 100 : $this->smartQuality($image->getImageWidth(), $image->getImageHeight());
        $image->setImageCompressionQuality($quality);
        if ($image->getImageFormat() === 'PNG') {
            $image->setOption('png:compression-level', $this->settings->enabled('image_lossless') ? '9' : '7');
        } else {
            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        }
        $this->applyWatermark($image);
    }

    private function resizeImagick(Imagick $image): void
    {
        $maxWidth = $this->settings->integer('image_max_width');
        $maxHeight = $this->settings->integer('image_max_height');
        if ($maxWidth > 0 && $maxHeight > 0 && ($image->getImageWidth() > $maxWidth || $image->getImageHeight() > $maxHeight)) {
            if ($this->settings->enabled('image_smart_crop')) {
                $focus = apply_filters('wpsc_image_crop_focus', ['x' => 0.5, 'y' => 0.5], $image->getImageWidth(), $image->getImageHeight());
                $focus = is_array($focus) ? $focus : ['x' => 0.5, 'y' => 0.5];
                $x = max(0.0, min(1.0, (float) ($focus['x'] ?? 0.5)));
                $y = max(0.0, min(1.0, (float) ($focus['y'] ?? 0.5)));
                $sourceWidth = $image->getImageWidth();
                $sourceHeight = $image->getImageHeight();
                $targetRatio = $maxWidth / $maxHeight;
                $sourceRatio = $sourceWidth / max(1, $sourceHeight);
                $cropWidth = $sourceRatio > $targetRatio ? (int) round($sourceHeight * $targetRatio) : $sourceWidth;
                $cropHeight = $sourceRatio > $targetRatio ? $sourceHeight : (int) round($sourceWidth / $targetRatio);
                $image->cropImage($cropWidth, $cropHeight, (int) round(($sourceWidth - $cropWidth) * $x), (int) round(($sourceHeight - $cropHeight) * $y));
                $image->thumbnailImage($maxWidth, $maxHeight, true, true);
            } else {
                $image->thumbnailImage($maxWidth, $maxHeight, true, true);
            }
        }
    }

    /** @return array{success: bool, message: string} */
    private function optimizeWithGd(string $file, string $extension): array
    {
        if (!extension_loaded('gd') || $extension === 'gif' || $extension === 'avif') {
            return ['success' => false, 'message' => 'Imagick or a compatible GD encoder is required.'];
        }
        $create = match ($extension) {
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'webp' => 'imagecreatefromwebp',
            default => null,
        };
        if ($create === null || !function_exists($create)) {
            return ['success' => false, 'message' => 'The GD decoder is unavailable.'];
        }
        $source = @$create($file);
        if ($source === false) {
            return ['success' => false, 'message' => 'The image could not be decoded.'];
        }
        $source = $this->resizeGd($source);
        $success = match ($extension) {
            'jpg', 'jpeg' => imagejpeg($source, $file, $this->settings->enabled('image_lossless') ? 100 : $this->smartQuality(imagesx($source), imagesy($source))),
            'png' => imagepng($source, $file, $this->settings->enabled('image_lossless') ? 9 : 7),
            'webp' => imagewebp($source, $file, $this->settings->integer('image_quality')),
            default => false,
        };
        imagedestroy($source);
        return ['success' => $success, 'message' => $success ? 'Optimized with GD.' : 'GD could not write the image.'];
    }

    private function resizeGd(\GdImage $source): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $maxWidth = $this->settings->integer('image_max_width');
        $maxHeight = $this->settings->integer('image_max_height');
        if ($maxWidth <= 0 || $maxHeight <= 0 || ($width <= $maxWidth && $height <= $maxHeight)) {
            return $source;
        }
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $target = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
        imagedestroy($source);
        return $target;
    }

    /** @return list<string> */
    private function createVariants(string $file, string $extension): array
    {
        if (in_array($extension, ['gif', 'webp', 'avif', 'pdf'], true)) {
            return [];
        }
        if ($this->imagickAvailable()) {
            try {
                $probe = new Imagick($file);
                $frames = $probe->getNumberImages();
                $probe->clear();
                if ($frames > 1) {
                    return [];
                }
            } catch (Throwable) {
            }
        }
        $variants = [];
        foreach (['webp' => 'image_generate_webp', 'avif' => 'image_generate_avif'] as $format => $setting) {
            if (!$this->settings->enabled($setting)) {
                continue;
            }
            $target = preg_replace('/\.[^.]+$/', '.' . $format, $file);
            if (!is_string($target) || !$this->encodeVariant($file, $target, $format)) {
                continue;
            }
            $variants[] = $target;
        }
        if ($this->settings->enabled('media_lqip')) {
            $target = preg_replace('/\.[^.]+$/', '.wps-lqip.jpg', $file);
            if (is_string($target) && $this->encodeLqip($file, $target)) {
                $variants[] = $target;
            }
        }
        return $variants;
    }

    private function encodeLqip(string $source, string $target): bool
    {
        if ($this->imagickAvailable()) {
            try {
                $image = new Imagick($source);
                $image->thumbnailImage(32, 32, true, true);
                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality(25);
                $success = $image->writeImage($target);
                $image->clear();
                return $success;
            } catch (Throwable) {
                return false;
            }
        }
        if (!extension_loaded('gd')) {
            return false;
        }
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $create = in_array($extension, ['jpg', 'jpeg'], true) ? 'imagecreatefromjpeg' : ($extension === 'png' ? 'imagecreatefrompng' : null);
        if ($create === null || !function_exists($create)) {
            return false;
        }
        $image = @$create($source);
        if ($image === false) {
            return false;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = min(32 / max(1, $width), 32 / max(1, $height));
        $preview = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
        imagecopyresampled($preview, $image, 0, 0, 0, 0, imagesx($preview), imagesy($preview), $width, $height);
        $success = imagejpeg($preview, $target, 25);
        imagedestroy($preview);
        imagedestroy($image);
        return $success;
    }

    private function encodeVariant(string $source, string $target, string $format): bool
    {
        if ($this->imagickAvailable()) {
            try {
                $image = new Imagick($source);
                $image->setImageFormat($format);
                $image->setImageCompressionQuality($this->settings->integer('image_quality'));
                $success = $image->writeImage($target);
                $image->clear();
                return $success;
            } catch (Throwable) {
                return false;
            }
        }
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $create = in_array($extension, ['jpg', 'jpeg'], true) ? 'imagecreatefromjpeg' : ($extension === 'png' ? 'imagecreatefrompng' : null);
        if ($create === null || !function_exists($create)) {
            return false;
        }
        $image = @$create($source);
        if ($image === false) {
            return false;
        }
        $success = $format === 'webp' && function_exists('imagewebp')
            ? imagewebp($image, $target, $this->settings->integer('image_quality'))
            : ($format === 'avif' && function_exists('imageavif') ? imageavif($image, $target, $this->settings->integer('image_quality')) : false);
        imagedestroy($image);
        return $success;
    }

    private function applyWatermark(Imagick $image): void
    {
        $id = $this->settings->integer('image_watermark_id');
        if ($id <= 0 || !function_exists('get_attached_file')) {
            return;
        }
        $file = get_attached_file($id);
        if (!is_string($file) || !is_file($file)) {
            return;
        }
        try {
            $watermark = new Imagick($file);
            $maxWidth = max(1, (int) round($image->getImageWidth() * 0.2));
            $watermark->thumbnailImage($maxWidth, 0);
            $image->compositeImage($watermark, Imagick::COMPOSITE_OVER, $image->getImageWidth() - $watermark->getImageWidth() - 20, $image->getImageHeight() - $watermark->getImageHeight() - 20);
            $watermark->clear();
        } catch (Throwable) {
        }
    }

    private function backup(string $file): void
    {
        $backup = $this->backupPath($file);
        if (!is_file($backup)) {
            $temporary = $backup . '.tmp-' . bin2hex(random_bytes(4));
            $input = @fopen($file, 'rb');
            $output = @fopen($temporary, 'wb');
            if (is_resource($input) && is_resource($output)) {
                fwrite($output, self::BACKUP_HEADER);
                $copied = stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if ($copied !== false && @rename($temporary, $backup)) {
                    @chmod($backup, 0600);
                    return;
                }
            } else {
                is_resource($input) && fclose($input);
                is_resource($output) && fclose($output);
            }
            @unlink($temporary);
        }
    }

    private function backupPath(string $file): string
    {
        return $file . '.wps-original.php';
    }

    private function smartQuality(int $width, int $height): int
    {
        $quality = max(1, min(100, $this->settings->integer('image_quality')));
        return $width * $height > 4000000 ? max(65, $quality - 5) : $quality;
    }

    private function imagickAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    private function allowed(string $file): bool
    {
        $real = realpath($file);
        if ($real === false || !is_file($real)) {
            return false;
        }
        foreach ($this->settings->strings('image_exclusions') as $pattern) {
            if ($pattern !== '' && str_contains(str_replace('\\', '/', $real), str_replace('\\', '/', $pattern))) {
                return false;
            }
        }
        $roots = [realpath(WP_CONTENT_DIR)];
        foreach ($this->settings->strings('image_custom_folders') as $folder) {
            $roots[] = realpath($folder);
        }
        foreach (array_filter($roots, 'is_string') as $root) {
            if ($real === $root || str_starts_with($real, rtrim($root, '/\\') . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }
        return false;
    }

    private function recordStats(int $saved, int $variants): void
    {
        $stats = get_option(self::STATS_OPTION, []);
        $stats = is_array($stats) ? $stats : [];
        $stats['processed'] = (int) ($stats['processed'] ?? 0) + 1;
        $stats['bytes_saved'] = (int) ($stats['bytes_saved'] ?? 0) + $saved;
        $stats['variants'] = (int) ($stats['variants'] ?? 0) + $variants;
        $stats['last_run'] = current_time('mysql');
        update_option(self::STATS_OPTION, $stats, false);
    }
}
