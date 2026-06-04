<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class AdaptiveBackupLimits
{
    private const DATABASE_MIN = 5000;
    private const DATABASE_MAX = 50000;
    private const FILE_SCAN_MIN = 1000;
    private const FILE_SCAN_MAX = 5000;
    private const ARCHIVE_MIN_SECONDS = 20.0;
    private const ARCHIVE_MAX_SECONDS = 45.0;

    /**
     * @param array<string,mixed> $payload
     */
    public function databaseChunkSize(array $payload): int
    {
        $configured = $this->intPayload($payload, 'database_chunk_size', self::DATABASE_MIN);
        $current = $this->intPayload($payload, 'database_adaptive_chunk_size', $configured);
        $seconds = $this->floatPayload($payload, 'database_last_step_seconds', 0.0);

        if ($seconds > 0.0 && $seconds < 5.0) {
            return min(self::DATABASE_MAX, $current * 2);
        }

        if ($seconds > 15.0) {
            return max(self::DATABASE_MIN, (int) floor($current / 2));
        }

        return max(1, min(self::DATABASE_MAX, $current));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function fileScanBatchSize(array $payload, int $default = self::FILE_SCAN_MIN): int
    {
        $current = $this->intPayload($payload, 'file_scan_adaptive_batch_size', max(1, $default));
        $seconds = $this->floatPayload($payload, 'file_scan_last_step_seconds', 0.0);

        if ($seconds > 0.0 && $seconds < 3.0) {
            return min(self::FILE_SCAN_MAX, $current * 2);
        }

        if ($seconds > 10.0) {
            return max(1, (int) floor($current / 2));
        }

        return max(1, min(self::FILE_SCAN_MAX, $current));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function archiveTimeBudgetSeconds(array $payload): float
    {
        $current = $this->floatPayload($payload, 'archive_adaptive_time_budget_seconds', self::ARCHIVE_MIN_SECONDS);
        $seconds = $this->floatPayload($payload, 'archive_last_step_seconds', 0.0);

        if ($seconds > 0.0 && $seconds < 10.0) {
            return min(self::ARCHIVE_MAX_SECONDS, $current + 10.0);
        }

        if ($seconds > 45.0) {
            return max(self::ARCHIVE_MIN_SECONDS, $current - 10.0);
        }

        return max(self::ARCHIVE_MIN_SECONDS, min(self::ARCHIVE_MAX_SECONDS, $current));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function intPayload(array $payload, string $key, int $default): int
    {
        return isset($payload[$key]) && is_numeric($payload[$key]) ? (int) $payload[$key] : $default;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function floatPayload(array $payload, string $key, float $default): float
    {
        return isset($payload[$key]) && is_numeric($payload[$key]) ? (float) $payload[$key] : $default;
    }
}
