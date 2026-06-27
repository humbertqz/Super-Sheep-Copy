<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_copy,WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- Writer stages individual package entries when PclZip cannot rename source paths directly.

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use SuperSheepCopy\Support\Filesystem;

final class PclZipPackageWriter implements PackageWriterInterface
{
    private string $package_path = '';
    private string $staging_path = '';
    /** @var string[] */
    private array $staged_entries = array();

    public function format(): string
    {
        return 'pclzip';
    }

    public function extension(): string
    {
        return '.zip';
    }

    public function isAvailable(): bool
    {
        return $this->loadPclZip();
    }

    public function open(string $package_path): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('PclZip is not available.');
        }

        $this->package_path = $package_path;
        $this->staging_path = rtrim($package_path, '/\\') . '.pclzip-step-' . bin2hex(random_bytes(4));
        $this->staged_entries = array();
        if (!Filesystem::makeDirectory($this->staging_path)) {
            $this->package_path = '';
            $this->staging_path = '';
            throw new RuntimeException('Unable to create PclZip staging directory.');
        }
    }

    public function addFile(string $source_path, string $entry_path): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();

        if (!is_file($source_path)) {
            throw new RuntimeException('PclZip source file does not exist.');
        }

        $this->stageFile($source_path, $entry_path);
    }

    public function addString(string $entry_path, string $contents): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();

        $path = $this->stagingPath($entry_path);
        if (!Filesystem::makeDirectory(dirname($path)) || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to stage PclZip package entry.');
        }
        $this->staged_entries[] = str_replace('\\', '/', $entry_path);
    }

    public function close(): void
    {
        if ($this->package_path !== '' && $this->staged_entries !== array()) {
            try {
                $this->flushStagedEntries();
            } finally {
                Filesystem::removeDirectory($this->staging_path);
            }
        } elseif ($this->staging_path !== '') {
            Filesystem::removeDirectory($this->staging_path);
        }

        $this->package_path = '';
        $this->staging_path = '';
        $this->staged_entries = array();
    }

    private function stageFile(string $source_path, string $entry_path): void
    {
        $path = $this->stagingPath($entry_path);
        if (!Filesystem::makeDirectory(dirname($path)) || !copy($source_path, $path)) {
            throw new RuntimeException('Unable to stage PclZip package file.');
        }
        $this->staged_entries[] = str_replace('\\', '/', $entry_path);
    }

    private function flushStagedEntries(): void
    {
        $archive = new \PclZip($this->package_path);
        if (is_file($this->package_path)) {
            foreach (array_unique($this->staged_entries) as $entry_path) {
                $archive->delete(PCLZIP_OPT_BY_NAME, $entry_path);
            }
        }

        $files = $this->stagedFiles();
        if ($files === array()) {
            return;
        }

        $result = is_file($this->package_path)
            ? $archive->add($files, PCLZIP_OPT_REMOVE_PATH, $this->staging_path)
            : $archive->create($files, PCLZIP_OPT_REMOVE_PATH, $this->staging_path);

        if ($result === 0) {
            throw new RuntimeException('Unable to write PclZip package entries: ' . $archive->errorInfo(true));
        }
    }

    /**
     * @return string[]
     */
    private function stagedFiles(): array
    {
        $files = array();
        if (!is_dir($this->staging_path)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->staging_path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }

    private function stagingPath(string $entry_path): string
    {
        return rtrim($this->staging_path, '/\\') . '/' . str_replace('\\', '/', $entry_path);
    }

    private function deleteEntry(string $entry_path): void
    {
        if (!is_file($this->package_path)) {
            return;
        }

        $archive = new \PclZip($this->package_path);
        $archive->delete(PCLZIP_OPT_BY_NAME, $entry_path);
    }

    private function assertOpen(): void
    {
        if ($this->package_path === '' || $this->staging_path === '') {
            throw new RuntimeException('PclZip package is not open.');
        }
    }

    private function loadPclZip(): bool
    {
        if (class_exists(\PclZip::class)) {
            return true;
        }

        $path = defined('ABSPATH') ? rtrim((string) ABSPATH, '/\\') . '/wp-admin/includes/class-pclzip.php' : '';
        if ($path !== '' && is_file($path)) {
            require_once $path;
        }

        return class_exists(\PclZip::class);
    }
}
