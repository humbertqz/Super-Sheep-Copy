<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/ArchiveValidationResult.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackageReaderInterface.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackagePathGuard.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DirectoryPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ZipPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/TarGzPackageReader.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/PackageReaderFactory.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/ArchiveValidator.php';

final class InstallerArchiveValidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-installer-validator-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: array() as $file) {
            if (is_dir($file)) {
                $this->removeDirectory($file);
                continue;
            }
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testValidSuperSheepCopyArchivePasses(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $validator = new \SuperSheepCopyInstaller\ArchiveValidator();
        $result = $validator->validatePackage($this->archive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy', 'source_site_url' => 'https://source.example')),
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
            'files/index.php' => '<?php echo "site";',
        )));

        self::assertTrue($result->isValid());
        self::assertSame('https://source.example', $result->manifest()['source_site_url']);
        self::assertSame(5, $result->entryCount());
        self::assertSame(2, $result->databaseEntryCount());
    }

    public function testValidDirectoryPackagePasses(): void
    {
        $validator = new \SuperSheepCopyInstaller\ArchiveValidator();
        $result = $validator->validatePackage($this->directoryPackage(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy', 'source_site_url' => 'https://source.example')),
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
            'files/index.php' => '<?php echo "site";',
        )));

        self::assertTrue($result->isValid());
        self::assertSame('https://source.example', $result->manifest()['source_site_url']);
        self::assertSame(5, $result->entryCount());
        self::assertSame(2, $result->databaseEntryCount());
    }

    public function testUnsafeEntryFails(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $validator = new \SuperSheepCopyInstaller\ArchiveValidator();
        $result = $validator->validatePackage($this->archive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            '../evil.php' => 'bad',
            'database/tables.json' => '{}',
        )));

        self::assertFalse($result->isValid());
        self::assertNotSame(array(), $result->errors());
    }

    public function testMissingManifestFails(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $validator = new \SuperSheepCopyInstaller\ArchiveValidator();
        $result = $validator->validatePackage($this->archive(array(
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
        )));

        self::assertFalse($result->isValid());
    }

    /**
     * @param array<string,string|false> $entries
     */
    private function archive(array $entries): string
    {
        $path = $this->root . '/backup-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, (string) $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * @param array<string,string|false> $entries
     */
    private function directoryPackage(array $entries): string
    {
        $path = $this->root . '/package-' . bin2hex(random_bytes(4));
        foreach ($entries as $name => $contents) {
            $file = $path . '/' . $name;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, (string) $contents);
        }

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}
