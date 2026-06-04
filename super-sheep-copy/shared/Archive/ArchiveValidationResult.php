<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

final class ArchiveValidationResult
{
    private bool $valid;
    /** @var string[] */
    private array $errors;
    /** @var array<string,mixed> */
    private array $manifest;
    private int $entry_count;
    private int $database_entry_count;

    /**
     * @param string[] $errors
     * @param array<string,mixed> $manifest
     */
    public function __construct(bool $valid, array $errors, array $manifest, int $entry_count, int $database_entry_count)
    {
        $this->valid = $valid;
        $this->errors = $errors;
        $this->manifest = $manifest;
        $this->entry_count = $entry_count;
        $this->database_entry_count = $database_entry_count;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return $this->manifest;
    }

    public function entryCount(): int
    {
        return $this->entry_count;
    }

    public function databaseEntryCount(): int
    {
        return $this->database_entry_count;
    }
}
