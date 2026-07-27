<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\FileScanner;

final class FileScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-file-scan-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/wp-content/uploads', 0777, true);
        mkdir($this->root . '/wp-content/cache', 0777, true);
        mkdir($this->root . '/.git', 0777, true);
        file_put_contents($this->root . '/.htaccess', 'RewriteEngine On');
        file_put_contents($this->root . '/wp-content/uploads/image.txt', 'image');
        file_put_contents($this->root . '/wp-content/cache/page.html', 'cache');
        file_put_contents($this->root . '/.git/config', 'git');
        file_put_contents($this->root . '/.DS_Store', 'junk');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testScansFilesAndExcludesNoisyDirectories(): void
    {
        $scanner = new FileScanner();
        $files = $scanner->scan($this->root);
        $paths = array_map(static fn ($file): string => $file->relativePath(), $files);
        sort($paths);

        self::assertSame(array('.htaccess', 'wp-content/uploads/image.txt'), $paths);
    }

    public function testScanExcludesOnlyWcpdfTemporaryAttachments(): void
    {
        $this->createWcpdfFiles();

        $paths = array_map(
            static fn ($file): string => $file->relativePath(),
            (new FileScanner())->scan($this->root)
        );

        self::assertNotContains(
            'wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/attachments/invoice-8370.pdf',
            $paths
        );
        self::assertContains(
            'wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/archive/invoice-8370.pdf',
            $paths
        );
        self::assertContains(
            'wp-content/uploads/customer-documents/attachments/invoice-8370.pdf',
            $paths
        );
    }

    public function testScanStepExcludesWcpdfTemporaryAttachments(): void
    {
        $this->createWcpdfFiles();
        $payload = array();
        $scanner = new FileScanner();

        while (empty($payload['file_scan_complete'])) {
            $payload = $scanner->scanStep($this->root, $payload, 1);
        }

        $paths = array_map(static function (array $file): string {
            return (string) $file['relative_path'];
        }, $payload['scanned_files']);

        self::assertNotContains(
            'wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/attachments/invoice-8370.pdf',
            $paths
        );
        self::assertContains(
            'wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/archive/invoice-8370.pdf',
            $paths
        );
    }

    public function testScansFilesAcrossBoundedSteps(): void
    {
        file_put_contents($this->root . '/wp-content/uploads/second.txt', 'second');

        $scanner = new FileScanner();
        $payload = array();

        $payload = $scanner->scanStep($this->root, $payload, 1);
        self::assertFalse($payload['file_scan_complete']);
        self::assertLessThanOrEqual(1, $payload['scanned_file_count']);

        $payload = $scanner->scanStep($this->root, $payload, 1);
        self::assertFalse($payload['file_scan_complete']);
        self::assertLessThanOrEqual(1, $payload['scanned_file_count']);

        while (empty($payload['file_scan_complete'])) {
            $payload = $scanner->scanStep($this->root, $payload, 1);
        }

        $paths = array_map(static function (array $file): string {
            return (string) $file['relative_path'];
        }, $payload['scanned_files']);
        sort($paths);

        self::assertSame(array('.htaccess', 'wp-content/uploads/image.txt', 'wp-content/uploads/second.txt'), $paths);
        self::assertSame(3, $payload['scanned_file_count']);
        self::assertSame('File scan finished.', $payload['message']);
    }

    public function testScanStepDoesNotPersistLargeDirectoryEntryList(): void
    {
        for ($index = 0; $index < 5; $index++) {
            file_put_contents($this->root . '/wp-content/uploads/file-' . $index . '.txt', 'file');
        }

        $scanner = new FileScanner();
        $payload = $scanner->scanStep($this->root, array(), 1);

        self::assertArrayNotHasKey('file_scan_current_entries', $payload);
        self::assertArrayHasKey('file_scan_current_index', $payload);
    }

    public function testScanStepCanIncludeCacheWhenSettingIsDisabled(): void
    {
        $scanner = new FileScanner();
        $payload = array(
            'backup_settings' => array(
                'exclude_cache_files' => false,
                'skip_large_files' => false,
                'large_file_limit_mb' => 250,
            ),
        );

        while (empty($payload['file_scan_complete'])) {
            $payload = $scanner->scanStep($this->root, $payload, 10);
        }

        $paths = array_map(static function (array $file): string {
            return (string) $file['relative_path'];
        }, $payload['scanned_files']);
        sort($paths);

        self::assertContains('wp-content/cache/page.html', $paths);
    }

    public function testScanStepSkipsLargeFilesAndRecordsCount(): void
    {
        file_put_contents($this->root . '/wp-content/uploads/large.bin', str_repeat('x', 12));

        $scanner = new FileScanner();
        $payload = array(
            'backup_settings' => array(
                'exclude_cache_files' => true,
                'skip_large_files' => true,
                'large_file_limit_mb' => 0,
            ),
        );

        while (empty($payload['file_scan_complete'])) {
            $payload = $scanner->scanStep($this->root, $payload, 10);
        }

        $paths = array_map(static function (array $file): string {
            return (string) $file['relative_path'];
        }, $payload['scanned_files']);

        self::assertNotContains('wp-content/uploads/large.bin', $paths);
        self::assertSame(3, $payload['skipped_large_file_count']);
        self::assertNotEmpty($payload['skipped_large_files']);
    }

    private function createWcpdfFiles(): void
    {
        mkdir($this->root . '/wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/attachments', 0777, true);
        mkdir($this->root . '/wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/archive', 0777, true);
        mkdir($this->root . '/wp-content/uploads/customer-documents/attachments', 0777, true);

        file_put_contents(
            $this->root . '/wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/attachments/invoice-8370.pdf',
            'temporary invoice'
        );
        file_put_contents(
            $this->root . '/wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/archive/invoice-8370.pdf',
            'persistent invoice'
        );
        file_put_contents(
            $this->root . '/wp-content/uploads/customer-documents/attachments/invoice-8370.pdf',
            'customer document'
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}
