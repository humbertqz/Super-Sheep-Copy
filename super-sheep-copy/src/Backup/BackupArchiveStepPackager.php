<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use SuperSheepCopy\Backup\Package\DirectoryPackageWriter;
use SuperSheepCopy\Backup\Package\PackageWriterFactory;
use SuperSheepCopy\Backup\Package\PackageWriterInterface;
use SuperSheepCopy\Shared\Archive\ArchiveValidator;
use SuperSheepCopy\Support\Filesystem;

final class BackupArchiveStepPackager implements BackupArchiveStepPackagerInterface
{
    private const DEFAULT_BATCH_SIZE = 2000;
    private const DEFAULT_TIME_BUDGET_SECONDS = 20.0;

    private ManifestBuilder $manifest_builder;
    private int $batch_size;
    private float $time_budget_seconds;
    private AdaptiveBackupLimits $adaptive_limits;
    private PackageWriterFactory $package_writer_factory;

    public function __construct(ManifestBuilder $manifest_builder, int $batch_size = self::DEFAULT_BATCH_SIZE, float $time_budget_seconds = self::DEFAULT_TIME_BUDGET_SECONDS, ?PackageWriterFactory $package_writer_factory = null)
    {
        $this->manifest_builder = $manifest_builder;
        $this->batch_size = max(1, $batch_size);
        $this->time_budget_seconds = max(0.0, $time_budget_seconds);
        $this->adaptive_limits = new AdaptiveBackupLimits();
        $this->package_writer_factory = $package_writer_factory ?? new PackageWriterFactory();
    }

    public function packageStep(string $job_id, string $working_directory, string $database_directory, array $site_files, array $metadata, array $payload): array
    {
        if (!isset($payload['archive_entries']) || !is_array($payload['archive_entries'])) {
            $payload = $this->preparePayload($job_id, $working_directory, $database_directory, $site_files, $payload);
        }

        $archive_path = isset($payload['archive_path']) ? (string) $payload['archive_path'] : '';
        $entries = isset($payload['archive_entries']) && is_array($payload['archive_entries']) ? array_values($payload['archive_entries']) : array();
        $index = isset($payload['archive_index']) ? (int) $payload['archive_index'] : 0;
        $step_start_index = $index;
        $step_start_time = microtime(true);
        $effective_time_budget = $this->time_budget_seconds > 0.0
            ? max($this->time_budget_seconds, $this->adaptive_limits->archiveTimeBudgetSeconds($payload))
            : 0.0;
        $payload['archive_adaptive_time_budget_seconds'] = $effective_time_budget;
        $step_bytes = 0;
        if (!isset($payload['archive_started_at'])) {
            $payload['archive_started_at'] = $step_start_time;
        }
        $checksums = isset($payload['archive_checksums']) && is_array($payload['archive_checksums']) ? $payload['archive_checksums'] : array();
        $writer = $this->writerForPayload($payload);
        $writer->open($this->writePathForPayload($archive_path, $payload));

        $limit = min(count($entries), $index + $this->batch_size);
        for (; $index < $limit; $index++) {
            $entry = is_array($entries[$index]) ? $entries[$index] : array();
            if (!empty($entry['symlink'])) {
                continue;
            }

            $absolute_path = isset($entry['absolute_path']) ? (string) $entry['absolute_path'] : '';
            $archive_name = isset($entry['archive_name']) ? (string) $entry['archive_name'] : '';
            if ($absolute_path === '' || $archive_name === '') {
                continue;
            }

            $checksum = hash_file('sha256', $absolute_path);
            if ($checksum === false) {
                throw new RuntimeException('Unable to calculate checksum for: ' . esc_html($archive_name));
            }

            $checksums[$archive_name] = $checksum;
            $writer->addFile($absolute_path, $archive_name);
            $entry_size = filesize($absolute_path);
            if ($entry_size !== false) {
                $step_bytes += (int) $entry_size;
            }
            if ($index + 1 > $step_start_index && microtime(true) - $step_start_time >= $effective_time_budget) {
                $index++;
                break;
            }
        }

        $payload['archive_index'] = $index;
        $payload['archive_checksums'] = $checksums;
        $payload = $this->addProgressMetrics($payload, count($entries), $step_start_index, $step_start_time, $step_bytes);

        if ($index >= count($entries)) {
            $writer->close();
            $payload = $this->stabilizeMetadata($archive_path, $job_id, $metadata, $payload);
            if ((isset($payload['package_format']) ? (string) $payload['package_format'] : '') === 'tar.gz') {
                $payload = $this->finalizeTarGzPackage($archive_path, $payload);
            }
            $payload = $this->validateArchive($archive_path, $payload);
            $payload['archive_complete'] = true;
            $payload['message'] = 'Backup completed.';

            return $payload;
        }

        $writer->close();
        $payload['archive_complete'] = false;
        $payload['message'] = $this->progressMessage($payload, count($entries));

        return $payload;
    }

    /**
     * @param ScannedFile[] $site_files
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function preparePayload(string $job_id, string $working_directory, string $database_directory, array $site_files, array $payload): array
    {
        $database_files = $this->databaseFiles($database_directory);
        $entries = array();

        foreach ($site_files as $file) {
            $entries[] = $this->entryForFile('files', $file);
        }
        foreach ($database_files as $file) {
            $entries[] = $this->entryForFile('database', $file);
        }

        $writer = $this->package_writer_factory->bestAvailable();
        $payload['package_format'] = $writer->format();
        $payload['package_extension'] = $writer->extension();
        $payload['package_schema_version'] = 1;
        $payload['archive_path'] = rtrim($working_directory, '/\\') . '/' . $job_id . $writer->extension();
        if ($writer->format() === 'tar.gz') {
            $payload['archive_staging_path'] = $payload['archive_path'] . '.staging';
        }
        $payload['archive_entries'] = $entries;
        $payload['archive_index'] = 0;
        $payload['archive_checksums'] = array();
        $payload['archive_site_file_count'] = count($site_files);
        $payload['archive_database_file_count'] = count($database_files);
        $payload['archive_started_at'] = microtime(true);

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function addProgressMetrics(array $payload, int $total_entries, int $step_start_index, float $step_start_time, int $step_bytes): array
    {
        $index = isset($payload['archive_index']) ? max(0, (int) $payload['archive_index']) : 0;
        $step_elapsed = max(0.001, microtime(true) - $step_start_time);
        $step_entries = max(0, $index - $step_start_index);
        $started_at = isset($payload['archive_started_at']) ? (float) $payload['archive_started_at'] : $step_start_time;
        $total_elapsed = max(0.001, microtime(true) - $started_at);
        $rate = $step_entries > 0 ? $step_entries / $step_elapsed : $index / $total_elapsed;
        $remaining = max(0, $total_entries - $index);

        $payload['archive_last_step_entries'] = $step_entries;
        $payload['archive_last_step_seconds'] = $step_elapsed;
        $payload['archive_entries_per_second'] = $rate;
        $payload['archive_eta_seconds'] = $rate > 0.0 ? (int) ceil($remaining / $rate) : null;
        $payload['archive_last_step_bytes'] = $step_bytes;
        $payload['archive_mb_per_second'] = ($step_bytes / 1048576) / $step_elapsed;
        $payload['backup_bottleneck'] = (new BackupPerformanceMetrics())->bottleneck($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function progressMessage(array $payload, int $total_entries): string
    {
        $index = isset($payload['archive_index']) ? (int) $payload['archive_index'] : 0;
        $rate = isset($payload['archive_entries_per_second']) ? (float) $payload['archive_entries_per_second'] : 0.0;
        $mb_per_second = isset($payload['archive_mb_per_second']) ? (float) $payload['archive_mb_per_second'] : 0.0;
        $eta = isset($payload['archive_eta_seconds']) && $payload['archive_eta_seconds'] !== null ? (int) $payload['archive_eta_seconds'] : null;
        $message = 'Packaged ' . $index . ' of ' . $total_entries . ' archive entries.';
        if ($rate > 0.0 && $eta !== null) {
            $message .= ' ' . number_format($rate * 60, 0) . ' entries/min. ETA ' . $this->durationLabel($eta) . '.';
        }
        if ($mb_per_second > 0.0) {
            $message .= ' ' . number_format($mb_per_second * 60, 1) . ' MB/min.';
        }

        return $message;
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remaining_seconds = $seconds % 60;
        if ($minutes < 60) {
            return $remaining_seconds > 0 ? $minutes . 'm ' . $remaining_seconds . 's' : $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        $remaining_minutes = $minutes % 60;

        return $remaining_minutes > 0 ? $hours . 'h ' . $remaining_minutes . 'm' : $hours . 'h';
    }

    private function writeMetadata(string $archive_path, string $job_id, array $metadata, array $payload): void
    {
        $writer = $this->writerForPayload($payload);
        $writer->open($this->writePathForPayload($archive_path, $payload));

        $checksums = isset($payload['archive_checksums']) && is_array($payload['archive_checksums']) ? $payload['archive_checksums'] : array();
        $metadata['file_count'] = isset($payload['archive_site_file_count']) ? (int) $payload['archive_site_file_count'] : 0;
        $metadata['database_table_count'] = isset($payload['archive_database_file_count']) ? (int) $payload['archive_database_file_count'] : 0;
        $metadata['archive_size'] = isset($payload['archive_size']) ? (int) $payload['archive_size'] : 0;
        $metadata['package_format'] = isset($payload['package_format']) ? (string) $payload['package_format'] : 'zip';
        $metadata['package_extension'] = isset($payload['package_extension']) ? (string) $payload['package_extension'] : '.zip';
        $metadata['package_schema_version'] = 1;
        $metadata['checksums'] = $checksums;

        $writer->addString('manifest.json', $this->manifest_builder->build($metadata)->toJson());
        $writer->addString('checksums.json', (string) json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $writer->addString('logs/backup.log', 'Backup ' . $job_id . ' packaged.');
        $writer->close();
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function stabilizeMetadata(string $archive_path, string $job_id, array $metadata, array $payload): array
    {
        $archive_size = 0;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $payload['archive_size'] = $archive_size;
            $this->writeMetadata($archive_path, $job_id, $metadata, $payload);
            clearstatcache(true, $archive_path);
            $new_archive_size = $this->packageSize($this->writePathForPayload($archive_path, $payload));
            if ($new_archive_size === null) {
                throw new RuntimeException('Unable to read backup archive size.');
            }

            if ($new_archive_size === $archive_size) {
                $payload['archive_size'] = $archive_size;
                $payload['archive_size_stabilized'] = true;

                return $payload;
            }

            $archive_size = $new_archive_size;
        }

        $payload['archive_size'] = $archive_size;
        $payload['archive_size_stabilized'] = false;

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writerForPayload(array $payload): PackageWriterInterface
    {
        $format = isset($payload['package_format']) ? (string) $payload['package_format'] : '';
        foreach ($this->package_writer_factory->availableWriters() as $writer) {
            if ($writer->format() === $format) {
                if ($format === 'tar.gz') {
                    return new DirectoryPackageWriter();
                }

                return $writer;
            }
        }

        $writer = $this->package_writer_factory->bestAvailable();
        if ($writer->format() === 'tar.gz') {
            return new DirectoryPackageWriter();
        }

        return $writer;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writePathForPayload(string $archive_path, array $payload): string
    {
        if ((isset($payload['package_format']) ? (string) $payload['package_format'] : '') === 'tar.gz') {
            return isset($payload['archive_staging_path']) ? (string) $payload['archive_staging_path'] : $archive_path . '.staging';
        }

        return $archive_path;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function validateArchive(string $archive_path, array $payload): array
    {
        $result = (new ArchiveValidator())->validatePackage($archive_path);
        $payload['archive_validation_status'] = $result->isValid() ? 'valid' : 'invalid';
        $payload['archive_validation_errors'] = $result->errors();
        $payload['archive_validation_entry_count'] = $result->entryCount();
        $payload['archive_validation_database_entry_count'] = $result->databaseEntryCount();

        if (!$result->isValid()) {
            throw new RuntimeException('Backup archive validation failed: ' . esc_html(implode(' ', $result->errors())));
        }

        return $payload;
    }

    private function packageSize(string $archive_path): ?int
    {
        if (is_file($archive_path)) {
            $size = filesize($archive_path);

            return $size === false ? null : (int) $size;
        }

        if (!is_dir($archive_path)) {
            return null;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($archive_path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile()) {
                $size += (int) $item->getSize();
            }
        }

        return $size;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function finalizeTarGzPackage(string $archive_path, array $payload): array
    {
        if (!class_exists(PharData::class)) {
            throw new RuntimeException('PharData is not available.');
        }

        $staging_path = isset($payload['archive_staging_path']) ? (string) $payload['archive_staging_path'] : $archive_path . '.staging';
        if (!is_dir($staging_path)) {
            throw new RuntimeException('TAR.GZ staging directory does not exist.');
        }

        $temp_tar_path = $archive_path . '.tmp.tar';
        $temp_gz_path = $temp_tar_path . '.gz';
        Filesystem::deleteFile($temp_tar_path);
        Filesystem::deleteFile($temp_gz_path);
        Filesystem::deleteFile($archive_path);

        $tar = new PharData($temp_tar_path);
        foreach ($this->packageFiles($staging_path) as $source_path => $entry_path) {
            $tar->addFile($source_path, $entry_path);
        }

        $tar->compress(Phar::GZ);
        unset($tar);
        Filesystem::deleteFile($temp_tar_path);

        if (!Filesystem::move($temp_gz_path, $archive_path)) {
            throw new RuntimeException('Unable to finalize TAR.GZ package.');
        }

        $size = filesize($archive_path);
        if ($size !== false) {
            $payload['archive_size'] = (int) $size;
        }

        $this->removeDirectory($staging_path);

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function packageFiles(string $root_path): array
    {
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $source_path = $item->getPathname();
            $entry_path = substr($source_path, strlen($root_path) + 1);
            $files[$source_path] = str_replace('\\', '/', $entry_path);
        }

        return $files;
    }

    private function removeDirectory(string $path): void
    {
        Filesystem::removeDirectory($path);
    }

    /**
     * @return ScannedFile[]
     */
    private function databaseFiles(string $database_directory): array
    {
        if (!is_dir($database_directory)) {
            throw new RuntimeException('Database export directory does not exist.');
        }

        $root = rtrim(str_replace('\\', '/', $database_directory), '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $files = array();
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($absolute, strlen($root)), '/');
            $files[] = new ScannedFile($absolute, $relative, (int) $item->getSize(), $item->isLink());
        }

        usort($files, static function (ScannedFile $a, ScannedFile $b): int {
            return strcmp($a->relativePath(), $b->relativePath());
        });

        return $files;
    }

    /**
     * @return array<string,mixed>
     */
    private function entryForFile(string $prefix, ScannedFile $file): array
    {
        return array(
            'absolute_path' => $file->absolutePath(),
            'archive_name' => $prefix . '/' . $file->relativePath(),
            'symlink' => $file->isSymlink(),
        );
    }
}
