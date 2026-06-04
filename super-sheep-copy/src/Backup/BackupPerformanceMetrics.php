<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class BackupPerformanceMetrics
{
    /**
     * @param array<string,mixed> $payload
     */
    public function summary(array $payload, string $state = ''): string
    {
        if ($state === 'completed') {
            return $this->completedSummary($payload);
        }

        $parts = array();
        $entries_per_second = isset($payload['archive_entries_per_second']) && is_numeric($payload['archive_entries_per_second']) ? (float) $payload['archive_entries_per_second'] : 0.0;
        $mb_per_second = isset($payload['archive_mb_per_second']) && is_numeric($payload['archive_mb_per_second']) ? (float) $payload['archive_mb_per_second'] : 0.0;
        $eta = isset($payload['archive_eta_seconds']) && $payload['archive_eta_seconds'] !== null && is_numeric($payload['archive_eta_seconds']) ? (int) $payload['archive_eta_seconds'] : null;
        $bottleneck = isset($payload['backup_bottleneck']) && is_scalar($payload['backup_bottleneck']) ? (string) $payload['backup_bottleneck'] : '';

        if ($entries_per_second > 0.0) {
            $parts[] = number_format($entries_per_second * 60, 0) . ' entries/min';
        }
        if ($mb_per_second > 0.0) {
            $parts[] = number_format($mb_per_second * 60, 1) . ' MB/min';
        }
        if ($eta !== null) {
            $parts[] = 'ETA ' . $this->durationLabel($eta);
        }
        if ($bottleneck !== '') {
            $parts[] = 'Bottleneck: ' . $bottleneck;
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function completedSummary(array $payload): string
    {
        $seconds = isset($payload['backup_total_seconds']) && is_numeric($payload['backup_total_seconds']) ? (int) $payload['backup_total_seconds'] : 0;
        $archive_size = isset($payload['archive_size']) && is_numeric($payload['archive_size']) ? (int) $payload['archive_size'] : 0;
        if ($seconds < 1 || $archive_size < 1) {
            return '';
        }

        $mb_per_minute = ($archive_size / 1048576) / ($seconds / 60);

        return 'Completed in ' . $this->durationLabel($seconds) . ' | Avg ' . number_format($mb_per_minute, 1) . ' MB/min';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function bottleneck(array $payload): string
    {
        $seconds = array(
            'database' => isset($payload['database_last_step_seconds']) && is_numeric($payload['database_last_step_seconds']) ? (float) $payload['database_last_step_seconds'] : 0.0,
            'file scan' => isset($payload['file_scan_last_step_seconds']) && is_numeric($payload['file_scan_last_step_seconds']) ? (float) $payload['file_scan_last_step_seconds'] : 0.0,
            'archive' => isset($payload['archive_last_step_seconds']) && is_numeric($payload['archive_last_step_seconds']) ? (float) $payload['archive_last_step_seconds'] : 0.0,
        );

        arsort($seconds);
        $name = (string) array_key_first($seconds);

        return $seconds[$name] > 0.0 ? $name : '';
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remaining_seconds = $seconds % 60;

        return $remaining_seconds > 0 ? $minutes . 'm ' . $remaining_seconds . 's' : $minutes . 'm';
    }
}
