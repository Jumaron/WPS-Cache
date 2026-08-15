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
    private const BACKGROUND_CURSOR_OPTION = 'wpsc_image_background_cursor';
    public const BACKGROUND_HOOK = 'wpsc_image_background_optimize';
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
        add_action(self::BACKGROUND_HOOK, [$this, 'optimizeBackgroundBatch']);
        add_action('wpscac_settings_updated', [$this, 'updateBackgroundSchedule'], 35, 1);
        if ($this->settings->enabled('image_background_optimization')) {
            if (get_option(self::BACKGROUND_CURSOR_OPTION, null) === null) {
                update_option(self::BACKGROUND_CURSOR_OPTION, 0, false);
            }
            if ((int) get_option(self::BACKGROUND_CURSOR_OPTION, 0) >= 0 && !wp_next_scheduled(self::BACKGROUND_HOOK)) {
                wp_schedule_event(time() + 300, 'hourly', self::BACKGROUND_HOOK);
            }
        }
    }

    /** @param array<string, mixed> $settings */
    public function updateBackgroundSchedule(array $settings): void
    {
        wp_clear_scheduled_hook(self::BACKGROUND_HOOK);
        if (!empty($settings['image_background_optimization'])) {
            if (get_option(self::BACKGROUND_CURSOR_OPTION, null) === null) {
                update_option(self::BACKGROUND_CURSOR_OPTION, 0, false);
            }
            if ((int) get_option(self::BACKGROUND_CURSOR_OPTION, 0) >= 0) {
                wp_schedule_event(time() + 300, 'hourly', self::BACKGROUND_HOOK);
            }
        } else {
            update_option(self::BACKGROUND_CURSOR_OPTION, 0, false);
        }
    }

    public function optimizeBackgroundBatch(): void
    {
        if (!$this->settings->enabled('image_background_optimization')) {
            return;
        }
        $offset = (int) get_option(self::BACKGROUND_CURSOR_OPTION, 0);
        if ($offset < 0 || !class_exists('WP_Query')) {
            return;
        }
        $batchSize = max(1, min(100, $this->settings->integer('image_background_batch_size')));
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/pdf'],
            'posts_per_page' => $batchSize,
            'offset' => $offset,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);
        foreach (array_map('intval', (array) $query->posts) as $attachmentId) {
            $metadata = wp_get_attachment_metadata($attachmentId);
            if (is_array($metadata)) {
                wp_update_attachment_metadata($attachmentId, $this->optimizeAttachment($metadata, $attachmentId));
            }
        }
        $count = count((array) $query->posts);
        if ($count < $batchSize) {
            update_option(self::BACKGROUND_CURSOR_OPTION, -1, false);
            wp_clear_scheduled_hook(self::BACKGROUND_HOOK);
        } else {
            update_option(self::BACKGROUND_CURSOR_OPTION, $offset + $count, false);
        }
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
        if ($this->settings->enabled('image_preserve_original_file') && !in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return ['success' => false, 'saved' => 0, 'variants' => [], 'message' => 'Variant-only mode supports JPEG and PNG sources.'];
        }
        if ($backup && $this->settings->enabled('image_backup_originals') && !$this->settings->enabled('image_preserve_original_file')) {
            $this->backup($file);
        }

        $result = $this->settings->enabled('image_preserve_original_file')
            ? ['success' => true, 'message' => 'Original preserved; only delivery variants were generated.']
            : apply_filters('wpsc_optimize_image_file', null, $file, $this->settings, $attachmentId);
        if (!is_array($result)) {
            $result = $this->settings->enabled('image_lossless') && in_array($extension, ['jpg', 'jpeg'], true)
                ? $this->optimizeLosslessJpeg($file)
                : ($extension === 'pdf'
                ? $this->optimizePdf($file)
                : ($this->imagickAvailable()
                ? $this->optimizeWithImagick($file, $extension)
                : $this->optimizeWithGd($file, $extension)));
        }
        $variants = $this->createVariants($file, $extension);
        clearstatcache(true, $file);
        $after = is_file($file) ? (int) filesize($file) : $before;
        $saved = max(0, $before - $after);
        $success = !empty($result['success']);
        if ($success && ($saved > 0 || $variants !== [] || !$this->settings->enabled('image_preserve_original_file'))) {
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
            'pdf' => $this->ghostscriptBinary() !== null,
            'lossless_jpeg' => $this->jpegtranBinary() !== null,
        ];
    }

    /** @return array{success: bool, message: string} */
    private function optimizeLosslessJpeg(string $file): array
    {
        $binary = $this->jpegtranBinary();
        if ($binary === null || !function_exists('proc_open')) {
            return ['success' => false, 'message' => 'A trusted jpegtran executable is required for mathematically lossless JPEG optimization.'];
        }
        $temporary = $file . '.wpsc-jpeg-' . bin2hex(random_bytes(4)) . '.jpg';
        $copy = $this->settings->enabled('image_preserve_exif') ? 'all' : 'none';
        $process = @proc_open([$binary, '-copy', $copy, '-optimize', '-progressive', '-outfile', $temporary, $file], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['success' => false, 'message' => 'jpegtran could not start.'];
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
        if ($status !== 0 || !is_file($temporary) || filesize($temporary) < 2 || (string) file_get_contents($temporary, false, null, 0, 2) !== "\xFF\xD8") {
            @unlink($temporary);
            return ['success' => false, 'message' => 'jpegtran did not produce a valid JPEG.'];
        }
        if ((int) filesize($temporary) >= (int) filesize($file)) {
            @unlink($temporary);
            return ['success' => true, 'message' => 'The JPEG was already losslessly optimized.'];
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return ['success' => false, 'message' => 'The optimized JPEG could not replace the source.'];
        }
        return ['success' => true, 'message' => 'Optimized losslessly with trusted jpegtran.'];
    }

    /** @return array{success: bool, message: string} */
    private function optimizePdf(string $file): array
    {
        $binary = $this->ghostscriptBinary();
        if ($binary === null || !function_exists('proc_open')) {
            return ['success' => false, 'message' => 'Define WPSC_GHOSTSCRIPT_BINARY to a trusted Ghostscript executable for vector-preserving PDF optimization.'];
        }
        $temporary = $file . '.wpsc-pdf-' . bin2hex(random_bytes(4)) . '.pdf';
        $command = [$binary, '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.6', '-dPDFSETTINGS=/ebook', '-dNOPAUSE', '-dQUIET', '-dBATCH', '-dSAFER', '-sOutputFile=' . $temporary, $file];
        $pipes = [];
        $process = @proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['success' => false, 'message' => 'Ghostscript could not start.'];
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
        $valid = $status === 0 && is_file($temporary) && filesize($temporary) > 8 && str_starts_with((string) file_get_contents($temporary, false, null, 0, 5), '%PDF-');
        if (!$valid) {
            @unlink($temporary);
            return ['success' => false, 'message' => 'Ghostscript did not produce a valid PDF.'];
        }
        if ((int) filesize($temporary) >= (int) filesize($file)) {
            @unlink($temporary);
            return ['success' => true, 'message' => 'The PDF was already smaller than the optimized output.'];
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return ['success' => false, 'message' => 'The optimized PDF could not replace the source.'];
        }
        return ['success' => true, 'message' => 'Optimized with trusted Ghostscript while preserving vector/text content.'];
    }

    private function ghostscriptBinary(): ?string
    {
        if (!defined('WPSC_GHOSTSCRIPT_BINARY')) {
            return null;
        }
        $binary = realpath((string) WPSC_GHOSTSCRIPT_BINARY);
        if ($binary === false || !is_file($binary)) {
            return null;
        }
        $name = strtolower(basename($binary));
        return in_array($name, ['gs', 'gs.exe', 'gswin32c.exe', 'gswin64c.exe'], true) ? $binary : null;
    }

    private function jpegtranBinary(): ?string
    {
        if (!defined('WPSC_JPEGTRAN_BINARY')) {
            return null;
        }
        $binary = realpath((string) WPSC_JPEGTRAN_BINARY);
        if ($binary === false || !is_file($binary)) {
            return null;
        }
        return in_array(strtolower(basename($binary)), ['jpegtran', 'jpegtran.exe'], true) ? $binary : null;
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
        $quality = $this->settings->enabled('image_lossless') ? 100 : $this->smartImagickQuality($image);
        $image->setImageCompressionQuality($quality);
        $format = strtoupper($image->getImageFormat());
        if ($format === 'PNG') {
            $image->setOption('png:compression-level', $this->settings->enabled('image_lossless') ? '9' : '7');
        } elseif (in_array($format, ['JPEG', 'JPG'], true)) {
            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        } elseif ($format === 'GIF') {
            $image->setImageCompression(Imagick::COMPRESSION_LZW);
        }
        $this->applyWatermark($image);
    }

    private function resizeImagick(Imagick $image): void
    {
        $maxWidth = $this->settings->integer('image_max_width');
        $maxHeight = $this->settings->integer('image_max_height');
        if ($maxWidth > 0 && $maxHeight > 0 && ($image->getImageWidth() > $maxWidth || $image->getImageHeight() > $maxHeight)) {
            if ($this->settings->enabled('image_smart_crop')) {
                $focus = apply_filters('wpsc_image_crop_focus', $this->detectFocus($image), $image->getImageWidth(), $image->getImageHeight());
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
        $source = $this->resizeGd($source, $extension);
        $success = match ($extension) {
            'jpg', 'jpeg' => imagejpeg($source, $file, $this->settings->enabled('image_lossless') ? 100 : $this->smartQuality(imagesx($source), imagesy($source))),
            'png' => imagepng($source, $file, $this->settings->enabled('image_lossless') ? 9 : 7),
            'webp' => imagewebp($source, $file, $this->settings->integer('image_quality')),
            default => false,
        };
        imagedestroy($source);
        return ['success' => $success, 'message' => $success ? 'Optimized with GD.' : 'GD could not write the image.'];
    }

    private function resizeGd(\GdImage $source, string $extension): \GdImage
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
        $background = in_array($extension, ['png', 'webp'], true)
            ? imagecolorallocatealpha($target, 0, 0, 0, 127)
            : imagecolorallocate($target, 255, 255, 255);
        imagefilledrectangle($target, 0, 0, imagesx($target) - 1, imagesy($target) - 1, $background);
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

    private function smartImagickQuality(Imagick $image): int
    {
        $quality = $this->smartQuality($image->getImageWidth(), $image->getImageHeight());
        try {
            $sample = clone $image;
            $sample->setIteratorIndex(0);
            $sample->thumbnailImage(64, 64, true, true);
            $colors = $sample->getImageColors();
            $sample->clear();
            if ($colors < 256) {
                return max(60, $quality - 4);
            }
            if ($colors > 2000) {
                return min(95, $quality + 2);
            }
        } catch (Throwable) {
        }
        return $quality;
    }

    /** @return array{x: float, y: float} */
    private function detectFocus(Imagick $image): array
    {
        try {
            $probe = clone $image;
            $probe->setIteratorIndex(0);
            $probe->thumbnailImage(12, 12, true, true);
            $probe->setImageColorspace(Imagick::COLORSPACE_GRAY);
            $probe->edgeImage(1.0);
            $width = max(1, $probe->getImageWidth());
            $height = max(1, $probe->getImageHeight());
            $best = -1.0;
            $bestX = 0.5;
            $bestY = 0.5;
            foreach ($probe->getPixelIterator() as $y => $row) {
                foreach ($row as $x => $pixel) {
                    $edge = (float) $pixel->getColorValue(Imagick::COLOR_GRAY);
                    $centerWeight = 1.0 - 0.2 * (abs(($x / max(1, $width - 1)) - 0.5) + abs(($y / max(1, $height - 1)) - 0.5));
                    $score = $edge * $centerWeight;
                    if ($score > $best) {
                        $best = $score;
                        $bestX = $x / max(1, $width - 1);
                        $bestY = $y / max(1, $height - 1);
                    }
                }
            }
            $probe->clear();
            return ['x' => max(0.0, min(1.0, $bestX)), 'y' => max(0.0, min(1.0, $bestY))];
        } catch (Throwable) {
            return ['x' => 0.5, 'y' => 0.5];
        }
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
