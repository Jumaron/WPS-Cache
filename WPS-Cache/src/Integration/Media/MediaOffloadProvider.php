<?php

declare(strict_types=1);

namespace WPSCache\Integration\Media;

use WPSCache\Config\Settings;
use WPSCache\Contracts\Module;

/** Provider-neutral media offload lifecycle with attachment URL/srcset rewriting. */
final class MediaOffloadProvider implements Module
{
    private const META_KEY = '_wpsc_offload_manifest';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function id(): string
    {
        return 'media-offload';
    }

    public function boot(): void
    {
        if (!$this->settings->enabled('media_offload_provider')) {
            return;
        }
        add_filter('wp_generate_attachment_metadata', [$this, 'offloadAttachment'], 60, 2);
        add_filter('wp_get_attachment_url', [$this, 'attachmentUrl'], 20, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'srcset'], 20, 5);
        add_action('delete_attachment', [$this, 'deleteRemote'], 10, 1);
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    public function offloadAttachment(array $metadata, int $attachmentId): array
    {
        $original = get_attached_file($attachmentId);
        if (!is_string($original) || !is_file($original)) {
            return $metadata;
        }
        $files = [$original];
        foreach ((array) ($metadata['sizes'] ?? []) as $size) {
            if (is_array($size) && is_string($size['file'] ?? null)) {
                $files[] = dirname($original) . DIRECTORY_SEPARATOR . basename($size['file']);
            }
        }
        $manifest = [];
        foreach (array_unique($files) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $result = apply_filters('wpsc_offload_media_file', null, $file, $attachmentId, $metadata);
            if (!is_array($result) && $this->settings->enabled('media_offload_s3')) {
                $result = $this->offloadS3($file);
            }
            if (!is_array($result)) {
                continue;
            }
            $url = esc_url_raw((string) ($result['url'] ?? ''));
            if ($url === '' || !str_starts_with($url, 'https://')) {
                continue;
            }
            $manifest[basename($file)] = [
                'url' => $url,
                'provider_key' => sanitize_text_field((string) ($result['provider_key'] ?? '')),
                'provider' => sanitize_key((string) ($result['provider'] ?? 'custom')),
            ];
            if ($this->settings->enabled('media_offload_delete_local') && !empty($result['verified']) && $file !== $original) {
                @unlink($file);
            }
        }
        if ($manifest !== []) {
            update_post_meta($attachmentId, self::META_KEY, $manifest);
            do_action('wpsc_media_offloaded', $attachmentId, $manifest);
        }
        return $metadata;
    }

    public function attachmentUrl(string $url, int $attachmentId): string
    {
        $manifest = $this->manifest($attachmentId);
        $entry = $manifest[basename((string) parse_url($url, PHP_URL_PATH))] ?? null;
        return is_array($entry) && is_string($entry['url'] ?? null) ? $entry['url'] : $url;
    }

    /** @param array<string, array<string, mixed>> $sources @return array<string, array<string, mixed>> */
    public function srcset(array $sources, array $sizeArray, string $imageSrc, array $imageMeta, int $attachmentId): array
    {
        $manifest = $this->manifest($attachmentId);
        foreach ($sources as &$source) {
            $basename = basename((string) parse_url((string) ($source['url'] ?? ''), PHP_URL_PATH));
            if (is_array($manifest[$basename] ?? null) && is_string($manifest[$basename]['url'] ?? null)) {
                $source['url'] = $manifest[$basename]['url'];
            }
        }
        unset($source);
        return $sources;
    }

    public function deleteRemote(int $attachmentId): void
    {
        $manifest = $this->manifest($attachmentId);
        if ($manifest !== []) {
            do_action('wpsc_delete_offloaded_media', $attachmentId, $manifest);
            if ($this->settings->enabled('media_offload_s3')) {
                foreach ($manifest as $entry) {
                    if (is_array($entry) && ($entry['provider'] ?? '') === 's3' && is_string($entry['provider_key'] ?? null) && $entry['provider_key'] !== '') {
                        $this->s3Request('DELETE', $entry['provider_key'], '', 'application/octet-stream');
                    }
                }
            }
        }
        delete_post_meta($attachmentId, self::META_KEY);
    }

    /** @return array<string, array<string, string>> */
    private function manifest(int $attachmentId): array
    {
        $manifest = get_post_meta($attachmentId, self::META_KEY, true);
        return is_array($manifest) ? $manifest : [];
    }

    /** @return array{url: string, provider_key: string, provider: string, verified: bool}|null */
    private function offloadS3(string $file): ?array
    {
        $size = (int) filesize($file);
        if ($size <= 0 || $size > 64 * 1024 * 1024) {
            return null;
        }
        $body = file_get_contents($file);
        if (!is_string($body)) {
            return null;
        }
        $uploads = wp_get_upload_dir();
        $base = realpath((string) ($uploads['basedir'] ?? ''));
        $real = realpath($file);
        if ($base === false || $real === false || !str_starts_with($real, rtrim($base, '/\\') . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $key = ltrim(str_replace('\\', '/', substr($real, strlen(rtrim($base, '/\\')))), '/');
        $mime = function_exists('wp_check_filetype') ? (string) (wp_check_filetype($file)['type'] ?? '') : '';
        $mime = $mime !== '' ? $mime : 'application/octet-stream';
        $response = $this->s3Request('PUT', $key, $body, $mime);
        if (!is_array($response) || (int) ($response['status'] ?? 0) < 200 || (int) ($response['status'] ?? 0) >= 300) {
            return null;
        }
        $publicBase = $this->settings->string('media_offload_public_url');
        $url = $publicBase !== ''
            ? rtrim($publicBase, '/') . '/' . $this->encodePath($key)
            : rtrim($this->settings->string('media_offload_endpoint'), '/') . '/' . rawurlencode($this->settings->string('media_offload_bucket')) . '/' . $this->encodePath($key);
        return ['url' => $url, 'provider_key' => $key, 'provider' => 's3', 'verified' => true];
    }

    /** @return array{status: int}|null */
    private function s3Request(string $method, string $key, string $body, string $contentType): ?array
    {
        $endpoint = $this->settings->string('media_offload_endpoint');
        $bucket = $this->settings->string('media_offload_bucket');
        $region = $this->settings->string('media_offload_region');
        $accessKey = defined('WPSC_S3_ACCESS_KEY') ? (string) WPSC_S3_ACCESS_KEY : $this->settings->string('media_offload_access_key');
        $secretKey = defined('WPSC_S3_SECRET_KEY') ? (string) WPSC_S3_SECRET_KEY : $this->settings->string('media_offload_secret_key');
        if ($endpoint === '' || $bucket === '' || $region === '' || $accessKey === '' || $secretKey === '') {
            return null;
        }
        $basePath = rtrim((string) parse_url($endpoint, PHP_URL_PATH), '/');
        $canonicalUri = ($basePath !== '' ? $basePath : '') . '/' . rawurlencode($bucket) . '/' . $this->encodePath($key);
        $url = (string) parse_url($endpoint, PHP_URL_SCHEME) . '://' . (string) parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);
        if (is_int($port)) {
            $url .= ':' . $port;
        }
        $url .= $canonicalUri;
        $host = (string) parse_url($url, PHP_URL_HOST) . (is_int($port) ? ':' . $port : '');
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $payloadHash = hash('sha256', $body);
        $canonicalHeaders = 'content-type:' . trim($contentType) . "\n" . 'host:' . strtolower($host) . "\n" . 'x-amz-content-sha256:' . $payloadHash . "\n" . 'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = $method . "\n" . $canonicalUri . "\n\n" . $canonicalHeaders . $signedHeaders . "\n" . $payloadHash;
        $scope = $date . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $response = wp_safe_remote_request($url, [
            'method' => $method,
            'timeout' => 60,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
                'Content-Type' => $contentType,
                'Host' => $host,
                'x-amz-content-sha256' => $payloadHash,
                'x-amz-date' => $amzDate,
            ],
            'body' => $body,
        ]);
        return is_wp_error($response) ? null : ['status' => wp_remote_retrieve_response_code($response)];
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }
}
