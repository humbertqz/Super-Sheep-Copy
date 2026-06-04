<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

interface PackageWriterInterface
{
    public function format(): string;

    public function extension(): string;

    public function isAvailable(): bool;

    public function open(string $package_path): void;

    public function addFile(string $source_path, string $entry_path): void;

    public function addString(string $entry_path, string $contents): void;

    public function close(): void;
}
