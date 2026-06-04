<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Serialization;

interface SerializationWalkerInterface
{
    /**
     * @param callable(string): string $string_replacer
     * @return mixed
     */
    public function walk($value, callable $string_replacer);
}
