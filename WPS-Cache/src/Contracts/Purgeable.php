<?php

declare(strict_types=1);

namespace WPSCache\Contracts;

/**
 * A component that owns generated or remote cache state.
 */
interface Purgeable
{
    public function purge(): void;
}
