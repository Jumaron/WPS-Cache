<?php

declare(strict_types=1);

namespace WPSCache\Infrastructure\WordPress;

/** Immutable result of an object-cache environment preflight. */
final class ObjectCacheCheck
{
    /** @param list<string> $issues */
    public function __construct(
        public readonly ?string $backend,
        public readonly array $issues = [],
    ) {
    }

    public function compatible(): bool
    {
        return $this->backend !== null && $this->issues === [];
    }

    public function message(): string
    {
        return $this->issues === []
            ? 'The object-cache environment is ready.'
            : implode(' ', $this->issues);
    }
}
