<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

interface PackageReaderInterface
{
    /**
     * @return list<string>
     */
    public function entries(): array;

    public function read(string $entry_path): ?string;

    public function copyToFile(string $entry_path, string $destination_path): bool;
}
