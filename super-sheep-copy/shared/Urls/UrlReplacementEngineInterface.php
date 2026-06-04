<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Urls;

interface UrlReplacementEngineInterface
{
    public function replace(string $value, string $from, string $to): string;

    public function countReplacements(string $value, string $from): int;
}
