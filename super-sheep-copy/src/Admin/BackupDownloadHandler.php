<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Backup download streams a verified archive from plugin-owned backup storage.

declare(strict_types=1);

namespace SuperSheepCopy\Admin;

use RuntimeException;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Security\Capability;
use SuperSheepCopy\Security\Nonce;

final class BackupDownloadHandler
{
    private Capability $capability;
    private Nonce $nonce;
    private JobRepositoryInterface $jobs;
    private string $backup_directory;

    public function __construct(Capability $capability, Nonce $nonce, JobRepositoryInterface $jobs, string $backup_directory)
    {
        $this->capability = $capability;
        $this->nonce = $nonce;
        $this->jobs = $jobs;
        $this->backup_directory = $backup_directory;
    }

    public function handleRequest(): void
    {
        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by Nonce::verifyRequest() above.
        $download = $this->prepare($job_id);

        $this->stream($download);
    }

    /**
     * @return array{path:string,filename:string,size:int}
     */
    public function prepare(string $job_id): array
    {
        $this->capability->assertManageBackups();
        $this->nonce->verifyRequest();

        $job = $this->jobs->find($job_id);
        if ($job === null || $job->state() !== Job::COMPLETED) {
            throw new RuntimeException('Backup archive is not available for download.');
        }

        $payload = $job->payload();
        $archive_path = isset($payload['archive_path']) && is_scalar($payload['archive_path']) ? (string) $payload['archive_path'] : '';
        if ($archive_path === '' || !is_file($archive_path)) {
            throw new RuntimeException('Backup archive file was not found.');
        }

        $archive_realpath = realpath($archive_path);
        $backup_realpath = realpath($this->backup_directory);
        if ($archive_realpath === false || $backup_realpath === false || strpos($archive_realpath, rtrim($backup_realpath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException('Backup archive is outside the protected backup directory.');
        }

        $size = filesize($archive_realpath);
        if ($size === false) {
            throw new RuntimeException('Unable to read backup archive size.');
        }

        return array(
            'path' => $archive_realpath,
            'filename' => basename($archive_realpath),
            'size' => (int) $size,
        );
    }

    /**
     * @param array{path:string,filename:string,size:int} $download
     */
    private function stream(array $download): void
    {
        if (headers_sent()) {
            throw new RuntimeException('Cannot send backup download after headers have been sent.');
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $download['filename']) . '"');
        header('Content-Length: ' . (string) $download['size']);
        header('X-Content-Type-Options: nosniff');
        readfile($download['path']);
        exit;
    }
}
