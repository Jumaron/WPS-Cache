<?php

declare(strict_types=1);

namespace WPSCache\Cache\Page;

/** Canonicalizes query strings and rejects unsafe or unbounded variants. */
final class QueryPolicy
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function shouldBypass(array $parameters, string $rawQuery = ''): bool
    {
        if (count($parameters) > 25 || strlen($rawQuery) > 2048) {
            return true;
        }

        $denied = $this->patterns('cache_query_denylist');
        foreach (array_keys($parameters) as $key) {
            if ($this->matches((string) $key, $denied)) {
                return true;
            }
        }

        if (($this->settings['cache_query_mode'] ?? 'variants') === 'allowlist') {
            $allowed = $this->patterns('cache_query_allowlist');
            foreach (array_keys($parameters) as $key) {
                if (!$this->matches((string) $key, $allowed) && !$this->isIgnored((string) $key)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $parameters */
    public function canonical(array $parameters): string
    {
        if (($this->settings['cache_query_mode'] ?? 'variants') === 'ignore') {
            return '';
        }

        foreach (array_keys($parameters) as $key) {
            if ($this->isIgnored((string) $key)) {
                unset($parameters[$key]);
            }
        }

        $parameters = $this->sortRecursive($parameters);
        return http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    private function isIgnored(string $key): bool
    {
        return $this->matches($key, $this->patterns('cache_ignored_query_params'));
    }

    /** @return list<string> */
    private function patterns(string $key): array
    {
        $value = $this->settings[$key] ?? [];
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @param list<string> $patterns */
    private function matches(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $expression = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i';
            if (preg_match($expression, $value) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string|int, mixed> $values @return array<string|int, mixed> */
    private function sortRecursive(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sortRecursive($value);
            }
        }
        ksort($values);
        return $values;
    }
}
