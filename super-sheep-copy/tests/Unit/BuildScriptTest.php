<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

final class BuildScriptTest extends TestCase
{
    private string $project_root;
    private string $plugin_dir;
    private string $archive_path;

    protected function setUp(): void
    {
        $this->plugin_dir = dirname(__DIR__, 2);
        $this->project_root = sys_get_temp_dir() . '/ssc-build-test-' . bin2hex(random_bytes(4));
        $this->archive_path = $this->project_root . '/dist/super-sheep-copy.zip';
        $this->removeArchive();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->project_root);
    }

    public function testComposerDefinesBuildScript(): void
    {
        $composer = json_decode((string) file_get_contents($this->plugin_dir . '/composer.json'), true);

        self::assertIsArray($composer);
        self::assertSame('@php bin/build.php', $composer['scripts']['build'] ?? null);
    }

    public function testReleaseMetadataDeclaresWordPressOrgHeaders(): void
    {
        $plugin_header = (string) file_get_contents($this->plugin_dir . '/super-sheep-copy.php');
        $readme = (string) file_get_contents($this->plugin_dir . '/readme.txt');

        self::assertStringContainsString('License: GPLv3 or later', $plugin_header);
        self::assertStringContainsString('License URI: https://www.gnu.org/licenses/gpl-3.0.html', $plugin_header);
        self::assertStringContainsString('Version: 0.1.1', $plugin_header);
        self::assertStringContainsString("define('SUPER_SHEEP_COPY_VERSION', '0.1.1');", $plugin_header);
        self::assertStringContainsString('Tested up to: 7.0', $readme);
        self::assertStringContainsString('Stable tag: 0.1.1', $readme);
        self::assertStringContainsString('License: GPLv3 or later', $readme);
    }

    public function testBuildScriptCreatesDistributableZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $build_plugin = $this->project_root . '/super-sheep-copy';
        $this->copyDirectory($this->plugin_dir, $build_plugin);
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($build_plugin . '/bin/build.php');
        exec($command, $output, $exit_code);

        self::assertSame(0, $exit_code, implode("\n", $output));
        self::assertFileExists($this->archive_path);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archive_path));
        self::assertNotFalse($zip->getFromName('super-sheep-copy/super-sheep-copy.php'));
        self::assertNotFalse($zip->getFromName('super-sheep-copy/resources/MANUAL-RESTORE.md'));
        self::assertNotFalse($zip->getFromName('super-sheep-copy/resources/build-database.php'));
        self::assertFalse($zip->getFromName('super-sheep-copy/tests/bootstrap.php'));
        self::assertFalse($zip->getFromName('super-sheep-copy/.phpunit.result.cache'));
        self::assertFalse($zip->getFromName('super-sheep-copy/.DS_Store'));
        self::assertFalse($zip->getFromName('super-sheep-copy/assets/.DS_Store'));
        self::assertFalse($zip->getFromName('super-sheep-copy/composer.json'));
        self::assertFalse($zip->getFromName('super-sheep-copy/bin/build.php'));
        $zip->close();
    }

    private function removeArchive(): void
    {
        if (is_file($this->archive_path)) {
            unlink($this->archive_path);
        }
        $dist = dirname($this->archive_path);
        if (is_dir($dist) && array_diff(scandir($dist) ?: array(), array('.', '..')) === array()) {
            rmdir($dist);
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0777, true);
        foreach (array_diff(scandir($source), array('.', '..', 'vendor', '.git')) as $name) {
            if (is_dir($source . '/' . $name)) {
                $this->copyDirectory($source . '/' . $name, $destination . '/' . $name);
            } else {
                copy($source . '/' . $name, $destination . '/' . $name);
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), array('.', '..')) as $name) {
            $child = $path . '/' . $name;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
