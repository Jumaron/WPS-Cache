<?php

declare(strict_types=1);

namespace WPSCache\Contracts;

/**
 * A runtime component that registers its WordPress hooks when booted.
 */
interface Module
{
    public function id(): string;

    public function boot(): void;
}
