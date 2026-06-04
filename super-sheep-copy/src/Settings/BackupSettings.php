<?php

declare(strict_types=1);

namespace SuperSheepCopy\Settings;

final class BackupSettings
{
    private bool $exclude_cache_files;
    private bool $skip_large_files;
    private int $large_file_limit_mb;
    private int $retention_count;
    private bool $auto_clean_failed_jobs;
    private bool $debug_logging;

    public function __construct(
        bool $exclude_cache_files,
        bool $skip_large_files,
        int $large_file_limit_mb,
        int $retention_count,
        bool $auto_clean_failed_jobs,
        bool $debug_logging
    ) {
        $this->exclude_cache_files = $exclude_cache_files;
        $this->skip_large_files = $skip_large_files;
        $this->large_file_limit_mb = self::clamp($large_file_limit_mb, 10, 2048);
        $this->retention_count = self::clamp($retention_count, 1, 20);
        $this->auto_clean_failed_jobs = $auto_clean_failed_jobs;
        $this->debug_logging = $debug_logging;
    }

    public static function defaults(): self
    {
        return new self(true, true, 250, 5, true, false);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $defaults = self::defaults();

        return new self(
            self::boolValue($data, 'exclude_cache_files', $defaults->excludeCacheFiles()),
            self::boolValue($data, 'skip_large_files', $defaults->skipLargeFiles()),
            self::intValue($data, 'large_file_limit_mb', $defaults->largeFileLimitMb()),
            self::intValue($data, 'retention_count', $defaults->retentionCount()),
            self::boolValue($data, 'auto_clean_failed_jobs', $defaults->autoCleanFailedJobs()),
            self::boolValue($data, 'debug_logging', $defaults->debugLogging())
        );
    }

    public function excludeCacheFiles(): bool
    {
        return $this->exclude_cache_files;
    }

    public function skipLargeFiles(): bool
    {
        return $this->skip_large_files;
    }

    public function largeFileLimitMb(): int
    {
        return $this->large_file_limit_mb;
    }

    public function largeFileLimitBytes(): int
    {
        return $this->large_file_limit_mb * 1024 * 1024;
    }

    public function retentionCount(): int
    {
        return $this->retention_count;
    }

    public function autoCleanFailedJobs(): bool
    {
        return $this->auto_clean_failed_jobs;
    }

    public function debugLogging(): bool
    {
        return $this->debug_logging;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'exclude_cache_files' => $this->exclude_cache_files,
            'skip_large_files' => $this->skip_large_files,
            'large_file_limit_mb' => $this->large_file_limit_mb,
            'retention_count' => $this->retention_count,
            'auto_clean_failed_jobs' => $this->auto_clean_failed_jobs,
            'debug_logging' => $this->debug_logging,
        );
    }

    /**
     * @return string[]
     */
    public function summaryLabels(): array
    {
        $labels = array();
        $labels[] = $this->exclude_cache_files ? 'Cache folders excluded' : 'Cache folders included';
        $labels[] = $this->skip_large_files
            ? 'Files over ' . $this->large_file_limit_mb . ' MB skipped'
            : 'Large files included';
        $labels[] = 'Keeping last ' . $this->retention_count . ' successful backups';

        return $labels;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function boolValue(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return $value === 'on' || $value === 'yes' || $value === 'true';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function intValue(array $data, string $key, int $default): int
    {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            return $default;
        }

        return (int) $data[$key];
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
