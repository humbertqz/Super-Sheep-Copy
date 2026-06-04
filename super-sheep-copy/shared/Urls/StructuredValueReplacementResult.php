<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Urls;

final class StructuredValueReplacementResult
{
    private string $value;
    private int $replacement_count;
    private string $format;

    public function __construct(string $value, int $replacement_count, string $format)
    {
        $this->value = $value;
        $this->replacement_count = $replacement_count;
        $this->format = $format;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function replacementCount(): int
    {
        return $this->replacement_count;
    }

    public function format(): string
    {
        return $this->format;
    }
}
