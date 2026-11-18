<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class AdaptiveBackupLimits
{
    private const DATABASE_INITIAL = 500;
    private const DATABASE_MAX = 5000;
    private const DATABASE_TARGET_BYTES = 4194304;
    private const FILE_SCAN_MIN = 1000;
    private const FILE_SCAN_MAX = 5000;
    private const ARCHIVE_MIN_SECONDS = 5.0;
    private const ARCHIVE_MAX_SECONDS = 10.0;

    /**
     * @param array<string,mixed> $payload
     */
    public function databaseChunkSize(array $payload, bool $first_chunk = false): int
    {
        $configured = min(self::DATABASE_MAX, $this->intPayload($payload, 'database_chunk_size', self::DATABASE_MAX));
        $configured = min($configured, $this->intPayload($payload, 'database_retry_ceiling', $configured));
        if (isset($payload['database_attempt_limit'])) {
            return max(1, min($configured, intdiv((int) $payload['database_attempt_limit'], 2)));
        }
        if ($first_chunk || !isset($payload['database_last_step_bytes'])) {
            return min(self::DATABASE_INITIAL, $configured);
        }

        $current = min($configured, $this->intPayload($payload, 'database_adaptive_chunk_size', self::DATABASE_INITIAL));
        $seconds = $this->floatPayload($payload, 'database_last_step_seconds', 0.0);
        if ($seconds > 10.0) {
            $current = max(1, intdiv($current, 2));
        } elseif ($seconds > 0.0 && $seconds < 3.0) {
            $current *= 2;
        }

        $last_bytes = $this->intPayload($payload, 'database_last_step_bytes', 1);
        $last_rows = $this->intPayload($payload, 'database_last_step_rows', 1);
        $byte_limit = (int) floor(self::DATABASE_TARGET_BYTES * ((float) $last_rows / $last_bytes));

        return max(1, min($configured, $current, $byte_limit));
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
        $current = max(self::ARCHIVE_MIN_SECONDS, min(self::ARCHIVE_MAX_SECONDS, $this->floatPayload($payload, 'archive_adaptive_time_budget_seconds', self::ARCHIVE_MIN_SECONDS)));
        $seconds = $this->floatPayload($payload, 'archive_last_step_seconds', 0.0);

        if ($seconds > 0.0 && $seconds < 3.0) {
            return min(self::ARCHIVE_MAX_SECONDS, $current + 1.0);
        }

        if ($seconds > 10.0) {
            return max(self::ARCHIVE_MIN_SECONDS, $current / 2);
        }

        return max(self::ARCHIVE_MIN_SECONDS, min(self::ARCHIVE_MAX_SECONDS, $current));
    }

    /** @param array<string,mixed> $payload */
    public function archiveBatchBytes(array $payload): int
    {
        // ZIP defers compression until close(); bound queued bytes using the full previous step.
        $bytes = $this->intPayload($payload, 'archive_last_step_bytes', 0);
        $seconds = $this->floatPayload($payload, 'archive_last_step_seconds', 0.0);
        $budget = $bytes > 0 && $seconds > 0.0 ? (int) ($bytes / $seconds * self::ARCHIVE_MIN_SECONDS) : 8 * 1048576;

        return max(1048576, min(128 * 1048576, $budget));
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
