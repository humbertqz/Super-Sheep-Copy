<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use InvalidArgumentException;

final class BackupOptions
{
    private string $site_root;
    private string $working_base_directory;
    private string $table_prefix;
    private string $table_selection_mode;
    private int $database_chunk_size;
    /** @var array<string,mixed> */
    private array $manifest_metadata;

    /**
     * @param array<string,mixed> $manifest_metadata
     */
    public function __construct(
        string $site_root,
        string $working_base_directory,
        string $table_prefix,
        string $table_selection_mode,
        int $database_chunk_size,
        array $manifest_metadata = array()
    ) {
        if ($site_root === '') {
            throw new InvalidArgumentException('Site root is required.');
        }

        if ($working_base_directory === '') {
            throw new InvalidArgumentException('Working base directory is required.');
        }

        if ($table_prefix === '') {
            throw new InvalidArgumentException('Table prefix is required.');
        }

        if ($table_selection_mode === '') {
            throw new InvalidArgumentException('Table selection mode is required.');
        }

        if ($database_chunk_size < 1) {
            throw new InvalidArgumentException('Database chunk size must be greater than zero.');
        }

        $this->site_root = $site_root;
        $this->working_base_directory = $working_base_directory;
        $this->table_prefix = $table_prefix;
        $this->table_selection_mode = $table_selection_mode;
        $this->database_chunk_size = $database_chunk_size;
        $this->manifest_metadata = $manifest_metadata;
    }

    public function siteRoot(): string
    {
        return $this->site_root;
    }

    public function workingBaseDirectory(): string
    {
        return $this->working_base_directory;
    }

    public function tablePrefix(): string
    {
        return $this->table_prefix;
    }

    public function tableSelectionMode(): string
    {
        return $this->table_selection_mode;
    }

    public function databaseChunkSize(): int
    {
        return $this->database_chunk_size;
    }

    /**
     * @return array<string,mixed>
     */
    public function manifestMetadata(): array
    {
        return $this->manifest_metadata;
    }
}
