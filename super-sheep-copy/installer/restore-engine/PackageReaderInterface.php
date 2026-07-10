<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

interface PackageReaderInterface
{
    public function entries(): array;

    public function read(string $entry_path): ?string;

    public function sha256(string $entry_path): ?string;

    public function copyToFile(string $entry_path, string $destination_path): bool;
}
