<?php

declare(strict_types=1);

namespace SuperSheepCopy\Support;

final class NullLogger implements LoggerInterface
{
    public function info(string $message, array $context = array()): void
    {
    }

    public function warning(string $message, array $context = array()): void
    {
    }

    public function error(string $message, array $context = array()): void
    {
    }
}
