<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;
use SuperSheepCopy\Restore\RestorePreparationManager;
use SuperSheepCopy\Shared\Archive\ArchiveValidator;
use PharData;
use ZipArchive;

final class RestorePreparationManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-restore-prep-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPreparesValidRestoreArchive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $jobs = new MemoryRestoreJobRepository();
        $manager = new RestorePreparationManager(new ArchiveValidator(), $jobs, $this->root . '/restore');
        $upload = $this->upload('backup.zip', $this->validArchive());

        $result = $manager->prepare($upload);

        self::assertSame(Job::COMPLETED, $result->state());
        self::assertSame('https://source.example', $result->sourceSiteUrl());
        self::assertSame('https://source.example/home', $result->sourceHomeUrl());
        self::assertSame(2, $result->databaseEntryCount());
        self::assertSame(5, $result->archiveEntryCount());
        self::assertStringStartsWith('restore-', $result->stagedArchiveBasename());
        self::assertStringEndsWith('.zip', $result->stagedArchiveBasename());
        self::assertFileExists($this->root . '/restore/' . $result->stagedArchiveBasename());
        self::assertSame(array(Job::VALIDATING_RESTORE, Job::COMPLETED), $jobs->states());

        $completed = $jobs->find($result->jobId());
        self::assertInstanceOf(Job::class, $completed);
        self::assertSame('restore', $completed->type());
        self::assertSame($result->stagedArchiveBasename(), $completed->payload()['staged_archive']);
        self::assertSame('https://source.example', $completed->payload()['source_site_url']);
        self::assertSame('source-theme', $completed->payload()['active_theme']);
        self::assertSame(array('active-plugin/active.php'), $completed->payload()['active_plugins']);
        self::assertSame(array('mu-loader.php'), $completed->payload()['must_use_plugins']);
        self::assertSame('wp_', $completed->payload()['source_table_prefix']);
        self::assertSame(2, $completed->payload()['database_entry_count']);
    }

    public function testRejectsUploadError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore archive upload failed.');

        $manager = new RestorePreparationManager(new ArchiveValidator(), new MemoryRestoreJobRepository(), $this->root . '/restore');
        $manager->prepare(array('name' => 'backup.zip', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0));
    }

    public function testRejectsNonZipUpload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore package must be a .zip, .tar, or .tar.gz file.');

        $file = $this->root . '/backup.txt';
        file_put_contents($file, 'not a zip');

        $manager = new RestorePreparationManager(new ArchiveValidator(), new MemoryRestoreJobRepository(), $this->root . '/restore');
        $manager->prepare($this->upload('backup.txt', $file));
    }

    public function testPreparesValidTarGzRestorePackage(): void
    {
        if (!class_exists(PharData::class)) {
            self::markTestSkipped('PharData is not available.');
        }

        $jobs = new MemoryRestoreJobRepository();
        $manager = new RestorePreparationManager(new ArchiveValidator(), $jobs, $this->root . '/restore');

        $result = $manager->prepare($this->upload('backup.tar.gz', $this->validTarGzArchive()));

        self::assertSame(Job::COMPLETED, $result->state());
        self::assertStringEndsWith('.tar.gz', $result->stagedArchiveBasename());
        self::assertFileExists($this->root . '/restore/' . $result->stagedArchiveBasename());
    }

    public function testPreparesValidDirectoryRestorePackage(): void
    {
        $jobs = new MemoryRestoreJobRepository();
        $manager = new RestorePreparationManager(new ArchiveValidator(), $jobs, $this->root . '/restore');

        $result = $manager->prepare($this->upload('backup-package', $this->validDirectoryPackage()));

        self::assertSame(Job::COMPLETED, $result->state());
        self::assertStringStartsWith('restore-', $result->stagedArchiveBasename());
        self::assertDirectoryExists($this->root . '/restore/' . $result->stagedArchiveBasename());
        self::assertFileExists($this->root . '/restore/' . $result->stagedArchiveBasename() . '/manifest.json');
    }

    private function validArchive(): string
    {
        $archive = $this->root . '/backup.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', json_encode(array(
            'project' => 'Super Sheep Copy',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
            'active_theme' => 'source-theme',
            'active_plugins' => array('active-plugin/active.php'),
            'must_use_plugins' => array('mu-loader.php'),
            'source_table_prefix' => 'wp_',
        )));
        $zip->addFromString('database/tables.json', '{}');
        $zip->addFromString('database/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');
        $zip->addFromString('files/index.php', '<?php echo "site";');
        $zip->addFromString('checksums.json', $this->checksumManifest());
        $zip->close();

        return $archive;
    }

    private function validTarGzArchive(): string
    {
        $root = $this->root . '/tar-source';
        mkdir($root, 0777, true);
        $tar_path = $root . '/backup.tar';
        $archive = $root . '/backup.tar.gz';
        $tar = new PharData($tar_path);
        foreach ($this->validPackageEntries() as $name => $contents) {
            $tar->addFromString($name, $contents);
        }
        $tar->compress(\Phar::GZ);
        unset($tar);
        unlink($tar_path);

        return $archive;
    }

    private function validDirectoryPackage(): string
    {
        $directory = $this->root . '/directory-package';
        foreach ($this->validPackageEntries() as $name => $contents) {
            $path = $directory . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }

        return $directory;
    }

    /**
     * @return array<string,string>
     */
    private function validPackageEntries(): array
    {
        return array(
            'manifest.json' => (string) json_encode(array(
                'project' => 'Super Sheep Copy',
                'source_site_url' => 'https://source.example',
                'source_home_url' => 'https://source.example/home',
                'active_theme' => 'source-theme',
                'active_plugins' => array('active-plugin/active.php'),
                'must_use_plugins' => array('mu-loader.php'),
                'source_table_prefix' => 'wp_',
            )),
            'checksums.json' => $this->checksumManifest(),
            'database/tables.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
            'files/index.php' => '<?php echo "site";',
        );
    }

    private function checksumManifest(): string
    {
        return (string) json_encode(array(
            'database/tables.json' => hash('sha256', '{}'),
            'database/chunks/wp_posts.part001.sql' => hash('sha256', 'CREATE TABLE wp_posts;'),
            'files/index.php' => hash('sha256', '<?php echo "site";'),
        ));
    }

    /**
     * @return array{name:string,tmp_name:string,error:int,size:int}
     */
    private function upload(string $name, string $path): array
    {
        return array('name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path) ?: 0);
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

final class MemoryRestoreJobRepository implements JobRepositoryInterface
{
    /** @var array<string, Job> */
    private array $jobs = array();
    /** @var string[] */
    private array $states = array();

    public function save(Job $job): void
    {
        $this->jobs[$job->id()] = $job;
        $this->states[] = $job->state();
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

    /**
     * @return string[]
     */
    public function states(): array
    {
        return $this->states;
    }
}
