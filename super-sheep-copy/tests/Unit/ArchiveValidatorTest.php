<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Shared\Archive\ArchiveValidator;

final class ArchiveValidatorTest extends TestCase
{
    public function testRejectsTraversalPaths(): void
    {
        $validator = new ArchiveValidator();

        self::assertFalse($validator->isSafePath('../wp-config.php'));
        self::assertFalse($validator->isSafePath('files/../../wp-config.php'));
        self::assertFalse($validator->isSafePath('/absolute/path.php'));
    }

    public function testAcceptsRelativeArchivePaths(): void
    {
        $validator = new ArchiveValidator();

        self::assertTrue($validator->isSafePath('files/wp-content/uploads/image.jpg'));
        self::assertTrue($validator->isSafePath('database/chunks/wp_posts.part001.sql'));
    }

    public function testValidatesBackupPackageStructure(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'manifest.json' => json_encode(array(
                'project' => 'Super Sheep Copy',
                'source_site_url' => 'https://source.example',
                'source_home_url' => 'https://source.example',
            )),
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
            'files/index.php' => '<?php echo "site";',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertTrue($result->isValid());
        self::assertSame(array(), $result->errors());
        self::assertSame('Super Sheep Copy', $result->manifest()['project']);
        self::assertSame(5, $result->entryCount());
        self::assertSame(2, $result->databaseEntryCount());
    }

    public function testRejectsPackageWithoutManifest(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertFalse($result->isValid());
        self::assertContains('Missing manifest.json.', $result->errors());
    }

    public function testRejectsPackageWithUnsafeEntry(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            '../wp-config.php' => 'bad',
            'database/tables.json' => '{}',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertFalse($result->isValid());
        self::assertContains('Unsafe archive entry: ../wp-config.php', $result->errors());
    }

    public function testRejectsPackageWithoutDatabaseEntries(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            'files/index.php' => '<?php echo "site";',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertFalse($result->isValid());
        self::assertContains('No database entries were found.', $result->errors());
    }

    public function testRejectsPackageWithoutDatabaseTablesManifest(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
            'files/index.php' => '<?php echo "site";',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertFalse($result->isValid());
        self::assertContains('Missing database/tables.json.', $result->errors());
    }

    public function testRejectsPackageWithoutDatabaseChunks(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
            'files/index.php' => '<?php echo "site";',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertFalse($result->isValid());
        self::assertContains('Missing database/chunks/*.sql.', $result->errors());
    }

    public function testRejectsPackageWithoutFilesEntry(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->createArchive(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
        ));

        $result = (new ArchiveValidator())->validatePackage($archive);

        self::assertFalse($result->isValid());
        self::assertContains('Missing files/ entries.', $result->errors());
    }

    public function testValidatesDirectoryPackageStructure(): void
    {
        $package = $this->createDirectoryPackage(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
            'files/index.php' => '<?php echo "site";',
        ));

        $result = (new ArchiveValidator())->validatePackage($package);

        self::assertTrue($result->isValid());
        self::assertSame(array(), $result->errors());
        self::assertSame(5, $result->entryCount());
        self::assertSame(2, $result->databaseEntryCount());
    }

    public function testValidatesTarGzPackageStructure(): void
    {
        if (!class_exists(\PharData::class)) {
            self::markTestSkipped('PharData is not available.');
        }

        $package = $this->createTarGzPackage(array(
            'manifest.json' => json_encode(array('project' => 'Super Sheep Copy')),
            'checksums.json' => '{}',
            'database/tables.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
            'files/index.php' => '<?php echo "site";',
        ));

        $result = (new ArchiveValidator())->validatePackage($package);

        self::assertTrue($result->isValid());
        self::assertSame(array(), $result->errors());
        self::assertSame(5, $result->entryCount());
        self::assertSame(2, $result->databaseEntryCount());
    }

    /**
     * @param array<string,string|false> $entries
     */
    private function createArchive(array $entries): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'ssc-archive-validator-');
        self::assertIsString($archive);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents === false ? '' : $contents);
        }

        $zip->close();

        return $archive;
    }

    /**
     * @param array<string,string|false> $entries
     */
    private function createDirectoryPackage(array $entries): string
    {
        $directory = sys_get_temp_dir() . '/ssc-archive-validator-dir-' . bin2hex(random_bytes(4));
        foreach ($entries as $name => $contents) {
            $path = $directory . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents === false ? '' : $contents);
        }

        return $directory;
    }

    /**
     * @param array<string,string|false> $entries
     */
    private function createTarGzPackage(array $entries): string
    {
        $root = sys_get_temp_dir() . '/ssc-archive-validator-tar-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $tar_path = $root . '/package.tar';
        $archive = $root . '/package.tar.gz';
        $tar = new \PharData($tar_path);
        foreach ($entries as $name => $contents) {
            $tar->addFromString($name, $contents === false ? '' : $contents);
        }
        $tar->compress(\Phar::GZ);
        unset($tar);
        unlink($tar_path);

        return $archive;
    }
}
