<?php

declare(strict_types=1);

namespace WPSCache\Scheduling;

use WPSCache\Config\Settings;
use WPSCache\Infrastructure\Http\SameOriginUrlGuard;

/** Discovers complete, de-duplicated warmup targets from WP sitemaps with a query fallback. */
final class PreloadUrlProvider
{
    public function __construct(private readonly Settings $settings, private readonly SameOriginUrlGuard $guard)
    {
    }

    /** @return list<string> */
    public function discover(int $limit = 10000): array
    {
        $urls = [home_url('/')];
        $source = $this->settings->string('preload_source');
        if ($source === 'sitemap' || $source === 'both') {
            $urls = array_merge($urls, $this->fromSitemap(home_url('/wp-sitemap.xml'), $limit));
        }
        if (($source === 'wordpress' || $source === 'both' || count($urls) === 1) && count($urls) < $limit) {
            $urls = array_merge($urls, $this->fromWordPress($limit - count($urls)));
        }
        $urls = array_values(array_unique(array_filter($urls, fn(mixed $url): bool => is_string($url) && $this->guard->allows($url))));
        return array_slice($urls, 0, $limit);
    }

    /** @return list<string> */
    private function fromSitemap(string $url, int $limit): array
    {
        $pending = [$url];
        $seen = [];
        $urls = [];
        while ($pending !== [] && count($urls) < $limit && count($seen) < 250) {
            $current = array_shift($pending);
            if (!is_string($current) || isset($seen[$current]) || !$this->guard->allows($current)) {
                continue;
            }
            $seen[$current] = true;
            $response = wp_safe_remote_get($current, ['timeout' => 10, 'redirection' => 2, 'limit_response_size' => 5 * 1024 * 1024]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                continue;
            }
            $body = wp_remote_retrieve_body($response);
            if (!is_string($body) || $body === '') {
                continue;
            }
            preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $body, $matches);
            foreach ($matches[1] ?? [] as $location) {
                $location = html_entity_decode(strip_tags((string) $location), ENT_QUOTES | ENT_XML1);
                if (!$this->guard->allows($location)) {
                    continue;
                }
                if (preg_match('~(?:sitemap|sitemap_index)\.(?:xml|xml\.gz)(?:\?|$)~i', $location) === 1) {
                    $pending[] = $location;
                } else {
                    $urls[] = $location;
                }
                if (count($urls) >= $limit) {
                    break;
                }
            }
        }
        return $urls;
    }

    /** @return list<string> */
    private function fromWordPress(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $query = new \WP_Query([
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => min(10000, $limit),
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);
        $urls = [];
        foreach ($query->posts as $id) {
            $url = get_permalink($id);
            if (is_string($url)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }
}
