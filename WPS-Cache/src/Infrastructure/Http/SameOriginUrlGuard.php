<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\Http;

/** Restricts internal HTTP requests to the configured WordPress origin. */
final class SameOriginUrlGuard
{
    /** @var array{scheme: string, host: string, port: int}|null */
    private readonly ?array $origin;

    public function __construct(string $origin)
    {
        $this->origin = $this->parseOrigin($origin);
    }

    public function allows(string $url): bool
    {
        $target = $this->parseOrigin($url);

        return $target !== null
            && $this->origin !== null
            && $target === $this->origin;
    }

    /** @return array{scheme: string, host: string, port: int}|null */
    private function parseOrigin(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return [
            'scheme' => $scheme,
            'host' => strtolower($parts['host']),
            'port' => $port,
        ];
    }
}
