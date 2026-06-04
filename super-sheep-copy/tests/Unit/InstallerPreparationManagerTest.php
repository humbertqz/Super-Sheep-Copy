<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Restore\InstallerPreparationManager;

final class InstallerPreparationManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-installer-prep-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/source/restore-engine', 0777, true);
        mkdir($this->root . '/shared/Serialization', 0777, true);
        mkdir($this->root . '/shared/Urls', 0777, true);
        mkdir($this->root . '/staging', 0777, true);
        mkdir($this->root . '/wp-root', 0777, true);
        file_put_contents($this->root . '/source/installer.php', "<?php\nif (!is_readable(__DIR__ . '/restore-engine/config.php')) {\n    exit;\n}\nrequire_once __DIR__ . '/restore-engine/Bootstrap.php';\n");
        file_put_contents($this->root . '/source/restore-engine/Bootstrap.php', "<?php\n");
        file_put_contents($this->root . '/source/restore-engine/Security.php', "<?php\n");
        file_put_contents($this->root . '/shared/Serialization/SerializationWalkerInterface.php', "<?php\n");
        file_put_contents($this->root . '/shared/Urls/UrlReplacementEngine.php', "<?php\n");
        file_put_contents($this->root . '/staging/restore-123.zip', 'zip bytes');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPreparesRootInstallerAndConfigForCompletedRestoreJob(): void
    {
        $jobs = new MemoryInstallerJobRepository();
        $jobs->save(new Job('restore-123', 'restore', Job::COMPLETED, array(
            'staged_archive' => 'restore-123.zip',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
            'active_theme' => 'source-theme',
            'active_plugins' => array('active-plugin/active.php'),
            'must_use_plugins' => array('mu-loader.php'),
            'source_table_prefix' => 'wp_',
            'database_entry_count' => 2,
            'archive_entry_count' => 5,
        )));

        $manager = new InstallerPreparationManager(
            $this->root . '/source',
            $this->root . '/wp-root',
            $this->root . '/staging',
            'https://destination.example',
            $jobs
        );

        $result = $manager->prepare('restore-123');

        self::assertSame('restore-123', $result->jobId());
        self::assertSame('https://destination.example/installer.php', $result->installerUrl());
        self::assertStringStartsWith('https://destination.example/installer.php?token=', $result->launchUrl());
        self::assertNotSame('', $result->token());
        self::assertSame('ssc-restore-engine', $result->engineDirectoryBasename());
        self::assertSame('restore-123.zip', $result->stagedArchiveBasename());
        self::assertFileExists($this->root . '/wp-root/installer.php');
        self::assertFileExists($this->root . '/wp-root/ssc-restore-engine/Bootstrap.php');
        self::assertFileExists($this->root . '/wp-root/ssc-restore-engine/config.php');
        self::assertFileExists($this->root . '/wp-root/ssc-restore-engine/shared/Serialization/SerializationWalkerInterface.php');
        self::assertFileExists($this->root . '/wp-root/ssc-restore-engine/shared/Urls/UrlReplacementEngine.php');

        $installer = file_get_contents($this->root . '/wp-root/installer.php');
        self::assertIsString($installer);
        self::assertStringContainsString("ssc-restore-engine/Bootstrap.php", $installer);
        self::assertStringContainsString("ssc-restore-engine/config.php", $installer);
        self::assertStringNotContainsString("'/restore-engine/config.php'", $installer);
        $config = require $this->root . '/wp-root/ssc-restore-engine/config.php';
        self::assertSame('restore-123', $config['restore_job_id']);
        self::assertSame($this->root . '/staging/restore-123.zip', $config['staged_archive_path']);
        self::assertSame('restore-123.zip', $config['staged_archive_basename']);
        self::assertSame('https://source.example', $config['source_site_url']);
        self::assertSame('https://source.example/home', $config['source_home_url']);
        self::assertSame('source-theme', $config['active_theme']);
        self::assertSame(array('active-plugin/active.php'), $config['active_plugins']);
        self::assertSame(array('mu-loader.php'), $config['must_use_plugins']);
        self::assertSame('wp_', $config['source_table_prefix']);
        self::assertSame('valid', $config['archive_validation_status']);
        self::assertSame(array(), $config['archive_validation_errors']);
        self::assertSame(5, $config['archive_entry_count']);
        self::assertSame(2, $config['database_entry_count']);
        self::assertFalse($config['locked']);
        self::assertTrue(password_verify($result->token(), $config['token_hash']));

        $updated = $jobs->find('restore-123');
        self::assertInstanceOf(Job::class, $updated);
        self::assertTrue($updated->payload()['installer_prepared']);
        self::assertSame('https://destination.example/installer.php', $updated->payload()['installer_url']);
        self::assertSame('ssc-restore-engine', $updated->payload()['installer_engine_dir']);
        self::assertArrayNotHasKey('installer_token', $updated->payload());
        self::assertArrayNotHasKey('token_hash', $updated->payload());
    }

    public function testBootstrapCanLoadPackagedSharedDependencies(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/installer/restore-engine/Bootstrap.php');

        self::assertStringContainsString("__DIR__ . '/shared'", $bootstrap);
        self::assertStringContainsString("dirname(__DIR__, 2) . '/shared'", $bootstrap);
    }

    public function testRejectsUnsafeStagedArchiveBasename(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore archive basename is invalid.');

        $jobs = new MemoryInstallerJobRepository();
        $jobs->save(new Job('restore-123', 'restore', Job::COMPLETED, array('staged_archive' => '../backup.zip')));

        $manager = new InstallerPreparationManager($this->root . '/source', $this->root . '/wp-root', $this->root . '/staging', 'https://destination.example', $jobs);
        $manager->prepare('restore-123');
    }

    public function testRejectsIncompleteRestoreJob(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore job is not ready for installer preparation.');

        $jobs = new MemoryInstallerJobRepository();
        $jobs->save(new Job('restore-123', 'restore', Job::VALIDATING_RESTORE, array('staged_archive' => 'restore-123.zip')));

        $manager = new InstallerPreparationManager($this->root . '/source', $this->root . '/wp-root', $this->root . '/staging', 'https://destination.example', $jobs);
        $manager->prepare('restore-123');
    }

    public function testRejectsMissingRestoreJob(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore job was not found.');

        $manager = new InstallerPreparationManager($this->root . '/source', $this->root . '/wp-root', $this->root . '/staging', 'https://destination.example', new MemoryInstallerJobRepository());
        $manager->prepare('missing');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
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

final class MemoryInstallerJobRepository implements JobRepositoryInterface
{
    /** @var array<string, Job> */
    private array $jobs = array();

    public function save(Job $job): void
    {
        $this->jobs[$job->id()] = $job;
    }

    public function delete(string $id): void
    {
        unset($this->jobs[$id]);
    }

    public function find(string $id): ?Job
    {
        return $this->jobs[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->jobs);
    }
}
