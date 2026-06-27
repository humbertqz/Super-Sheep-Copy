<?php
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec, WordPress.WP.AlternativeFunctions.file_system_operations_copy -- CLI zip is an optional fallback when PHP ZIP is missing.

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use SuperSheepCopy\Support\Filesystem;

final class CliZipPackageWriter implements PackageWriterInterface
{
    private string $package_path = '';
    private string $staging_path = '';

    public function format(): string
    {
        return 'zip-cli';
    }

    public function extension(): string
    {
        return '.zip';
    }

    public function isAvailable(): bool
    {
        return $this->zipCommand() !== null;
    }

    public function open(string $package_path): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('CLI zip is not available.');
        }

        $this->package_path = $package_path;
        $this->staging_path = rtrim($package_path, '/\\') . '.zip-cli-step-' . bin2hex(random_bytes(4));
        if (!Filesystem::makeDirectory($this->staging_path)) {
            $this->package_path = '';
            $this->staging_path = '';
            throw new RuntimeException('Unable to create CLI zip staging directory.');
        }
    }

    public function addFile(string $source_path, string $entry_path): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();

        if (!is_file($source_path)) {
            throw new RuntimeException('CLI zip source file does not exist.');
        }

        $path = $this->stagingPath($entry_path);
        if (!Filesystem::makeDirectory(dirname($path)) || !copy($source_path, $path)) {
            throw new RuntimeException('Unable to stage CLI zip package file.');
        }
    }

    public function addString(string $entry_path, string $contents): void
    {
        PackagePathGuard::assertSafeEntryPath($entry_path);
        $this->assertOpen();

        $path = $this->stagingPath($entry_path);
        if (!Filesystem::makeDirectory(dirname($path)) || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to stage CLI zip package entry.');
        }
    }

    public function close(): void
    {
        try {
            if ($this->package_path !== '' && $this->stagedFiles() !== array()) {
                $this->flushStagedEntries();
            }
        } finally {
            if ($this->staging_path !== '') {
                Filesystem::removeDirectory($this->staging_path);
            }
            $this->package_path = '';
            $this->staging_path = '';
        }
    }

    private function flushStagedEntries(): void
    {
        $zip = $this->zipCommand();
        if ($zip === null) {
            throw new RuntimeException('CLI zip is not available.');
        }

        $output = array();
        $status = 0;
        $command = 'cd ' . escapeshellarg($this->staging_path)
            . ' && ' . escapeshellarg($zip)
            . ' -q -r ' . escapeshellarg($this->package_path)
            . ' . 2>&1';
        exec($command, $output, $status);

        if ($status !== 0) {
            throw new RuntimeException('Unable to write CLI zip package entries: ' . implode("\n", $output));
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

    private function assertOpen(): void
    {
        if ($this->package_path === '' || $this->staging_path === '') {
            throw new RuntimeException('CLI zip package is not open.');
        }
    }

    private function zipCommand(): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        foreach (array('/usr/bin/zip', '/usr/local/bin/zip', '/bin/zip') as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $output = array();
        $status = 0;
        exec('command -v zip 2>/dev/null', $output, $status);
        if ($status !== 0 || $output === array()) {
            return null;
        }

        $path = (string) $output[0];

        return is_executable($path) ? $path : null;
    }
}
