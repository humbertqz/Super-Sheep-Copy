<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class ScannedFile
{
    private string $absolute_path;
    private string $relative_path;
    private int $size;
    private bool $symlink;

    public function __construct(string $absolute_path, string $relative_path, int $size, bool $symlink)
    {
        $this->absolute_path = $absolute_path;
        $this->relative_path = $relative_path;
        $this->size = $size;
        $this->symlink = $symlink;
    }

    public function absolutePath(): string
    {
        return $this->absolute_path;
    }

    public function relativePath(): string
    {
        return $this->relative_path;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isSymlink(): bool
    {
        return $this->symlink;
    }
}
