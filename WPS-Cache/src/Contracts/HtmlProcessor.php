<?php

declare(strict_types=1);

namespace WPSCache\Contracts;

interface HtmlProcessor
{
    public function process(string $html): string;
}
