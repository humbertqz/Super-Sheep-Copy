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
require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseBackupTableCleaner.php';
require_once dirname(__DIR__, 2) . '/shared/Urls/UrlReplacementEngineInterface.php';
require_once dirname(__DIR__, 2) . '/shared/Urls/UrlReplacementEngine.php';
require_once dirname(__DIR__, 2) . '/installer/restore-engine/FileRestoreManager.php';

final class FileRestoreManagerTest extends TestCase
{
    private string $root_dir;
    private string $engine_dir;

    protected function setUp(): void
    {
        $this->root_dir = sys_get_temp_dir() . '/ssc-file-restore-' . bin2hex(random_bytes(4));
        $this->engine_dir = $this->root_dir . '/ssc-restore-engine';
        mkdir($this->engine_dir, 0777, true);
        file_put_contents($this->root_dir . '/wp-config.php', 'destination config');
        file_put_contents($this->engine_dir . '/config.php', "<?php\nreturn array();\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root_dir);
    }

    public function testRejectsMissingDatabaseUrlReplacement(): void
    {
        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            array(
                'restore_confirmed' => true,
                'rollback_prepared' => true,
                'rollback_database_dump' => 'rollback/db.sql',
                'staged_archive_path' => $this->archive(array('files/index.php' => 'restored')),
            )
        );

        self::assertFalse($result['completed']);
        self::assertSame(array('File restore requires database URL replacement.'), $result['warnings']);
    }

    public function testRestoresArchiveFilesAndPreservesWpConfig(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->archive(array(
            'files/index.php' => 'restored index',
            'files/wp-content/themes/theme/style.css' => 'body{color:#111;}',
            'files/wp-config.php' => 'source config',
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $this->readyConfig($archive)
        );

        self::assertTrue($result['completed']);
        self::assertSame(2, $result['file_count']);
        self::assertSame('restored index', file_get_contents($this->root_dir . '/index.php'));
        self::assertSame('body{color:#111;}', file_get_contents($this->root_dir . '/wp-content/themes/theme/style.css'));
        self::assertSame('destination config', file_get_contents($this->root_dir . '/wp-config.php'));

        $config = require $this->engine_dir . '/config.php';
        self::assertTrue($config['file_restore_completed']);
        self::assertSame(2, $config['file_restore_file_count']);
        self::assertTrue($config['locked']);
    }

    public function testRestoresDirectoryPackageFilesAndPreservesWpConfig(): void
    {
        $archive = $this->directoryPackage(array(
            'files/index.php' => 'restored index',
            'files/wp-content/themes/theme/style.css' => 'body{color:#111;}',
            'files/wp-config.php' => 'source config',
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $this->readyConfig($archive)
        );

        self::assertTrue($result['completed']);
        self::assertSame(2, $result['file_count']);
        self::assertSame('restored index', file_get_contents($this->root_dir . '/index.php'));
        self::assertSame('body{color:#111;}', file_get_contents($this->root_dir . '/wp-content/themes/theme/style.css'));
        self::assertSame('destination config', file_get_contents($this->root_dir . '/wp-config.php'));
    }

    public function testRestoresAllSourcePluginFiles(): void
    {
        $archive = $this->directoryPackage(array(
            'files/wp-content/plugins/active-plugin/active.php' => 'active plugin',
            'files/wp-content/plugins/active-plugin/readme.txt' => 'active readme',
            'files/wp-content/plugins/single-file.php' => 'single file plugin',
            'files/wp-content/plugins/inactive-plugin/inactive.php' => 'inactive plugin',
            'files/wp-content/plugins/inactive-single.php' => 'inactive single',
            'files/wp-content/themes/theme/style.css' => 'theme',
        ));
        $config = array_merge($this->readyConfig($archive), array(
            'active_plugins' => array('active-plugin/active.php', 'single-file.php'),
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $config
        );

        self::assertTrue($result['completed']);
        self::assertFileExists($this->root_dir . '/wp-content/plugins/active-plugin/active.php');
        self::assertFileExists($this->root_dir . '/wp-content/plugins/active-plugin/readme.txt');
        self::assertFileExists($this->root_dir . '/wp-content/plugins/single-file.php');
        self::assertFileExists($this->root_dir . '/wp-content/plugins/inactive-plugin/inactive.php');
        self::assertFileExists($this->root_dir . '/wp-content/plugins/inactive-single.php');
        self::assertFileExists($this->root_dir . '/wp-content/themes/theme/style.css');
    }

    public function testRestoresAllSourceThemeFiles(): void
    {
        $archive = $this->directoryPackage(array(
            'files/wp-content/themes/source-theme/style.css' => 'active theme',
            'files/wp-content/themes/source-theme/functions.php' => '<?php',
            'files/wp-content/themes/destination-theme/style.css' => 'inactive theme',
            'files/wp-content/plugins/plugin/plugin.php' => 'plugin',
        ));
        $config = array_merge($this->readyConfig($archive), array(
            'active_theme' => 'source-theme',
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $config
        );

        self::assertTrue($result['completed']);
        self::assertFileExists($this->root_dir . '/wp-content/themes/source-theme/style.css');
        self::assertFileExists($this->root_dir . '/wp-content/themes/source-theme/functions.php');
        self::assertFileExists($this->root_dir . '/wp-content/themes/destination-theme/style.css');
        self::assertFileExists($this->root_dir . '/wp-content/plugins/plugin/plugin.php');
    }

    public function testDoesNotMarkRestoreCompleteWhenNoRestorableFilesExist(): void
    {
        $archive = $this->directoryPackage(array(
            'files/wp-config.php' => 'source config',
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $this->readyConfig($archive)
        );

        self::assertFalse($result['completed']);
        self::assertSame(0, $result['file_count']);
        self::assertSame(array('No restorable files were found in the prepared archive.'), $result['warnings']);

        $config = require $this->engine_dir . '/config.php';
        self::assertArrayNotHasKey('file_restore_completed', $config);
    }

    public function testSkipsArchiveRevalidationWhenValidationSnapshotIsValid(): void
    {
        $archive = $this->directoryPackageWithoutManifest(array(
            'files/index.php' => 'restored index',
        ));
        $config = array_merge($this->readyConfig($archive), array(
            'archive_validation_status' => 'valid',
            'archive_validation_errors' => array(),
            'archive_entry_count' => 1,
            'database_entry_count' => 0,
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $config
        );

        self::assertTrue($result['completed']);
        self::assertSame('restored index', file_get_contents($this->root_dir . '/index.php'));
    }

    public function testReplacesSourceUrlsInsideRestoredTextFiles(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->archive(array(
            'files/wp-content/themes/theme/menu.js' => "window.location='https:\\/\\/shotpruebas.com\\/aliacer\\/nosotros\\/';",
            'files/wp-content/cache/page.html' => '<a data-url="https://shotpruebas.com/aliacer/nosotros/">Nosotros</a>',
            'files/wp-content/uploads/image.bin' => "https://shotpruebas.com/aliacer/binary\0data",
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $this->readyConfig($archive)
        );

        self::assertTrue($result['completed']);
        self::assertSame("window.location='https:\\/\\/shotpruebas.com\\/wptest\\/nosotros\\/';", file_get_contents($this->root_dir . '/wp-content/themes/theme/menu.js'));
        self::assertSame('<a data-url="https://shotpruebas.com/wptest/nosotros/">Nosotros</a>', file_get_contents($this->root_dir . '/wp-content/cache/page.html'));
        self::assertSame("https://shotpruebas.com/aliacer/binary\0data", file_get_contents($this->root_dir . '/wp-content/uploads/image.bin'));

        $config = require $this->engine_dir . '/config.php';
        self::assertSame(2, $config['file_url_replacement_file_count']);
        self::assertSame(2, $config['file_url_replacement_count']);
    }

    public function testRestoredHtaccessDropsReallySimpleSslManagedBlock(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->archive(array(
            'files/.htaccess' => implode("\n", array(
                '# BEGIN rlrssslReallySimpleSSL rsssl_version[3.3.4]',
                '<IfModule mod_rewrite.c>',
                'RewriteEngine on',
                'RewriteCond %{HTTPS} !=on [NC]',
                'RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]',
                '</IfModule>',
                '# END rlrssslReallySimpleSSL',
                '# BEGIN WordPress',
                '<IfModule mod_rewrite.c>',
                'RewriteEngine On',
                'RewriteRule ^index\.php$ - [L]',
                '</IfModule>',
                '# END WordPress',
            )),
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $this->readyConfig($archive)
        );

        self::assertTrue($result['completed']);

        $htaccess = (string) file_get_contents($this->root_dir . '/.htaccess');
        self::assertStringNotContainsString('rlrssslReallySimpleSSL', $htaccess);
        self::assertStringNotContainsString('RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]', $htaccess);
        self::assertStringContainsString('# BEGIN WordPress', $htaccess);
        self::assertStringContainsString('RewriteRule ^index\.php$ - [L]', $htaccess);
    }

    public function testRestoredHtaccessGetsUrlReplacement(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->archive(array(
            'files/.htaccess' => 'Redirect 301 /old https://shotpruebas.com/aliacer/new',
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $this->readyConfig($archive)
        );

        self::assertTrue($result['completed']);
        self::assertSame(
            'Redirect 301 /old https://shotpruebas.com/wptest/new',
            file_get_contents($this->root_dir . '/.htaccess')
        );
    }

    public function testRestoredHtaccessUpdatesWordPressSubdirectoryRewriteRules(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $archive = $this->archive(array(
            'files/.htaccess' => implode("\n", array(
                '#Begin Really Simple Auto Prepend File',
                '<IfModule mod_php.c>',
                'php_value auto_prepend_file /home/shotpruebas/public_html/aliacer/wp-content/advanced-headers.php',
                '</IfModule>',
                '#End Really Simple Auto Prepend File',
                '#Begin Really Simple Security',
                '<IfModule mod_rewrite.c>',
                'RewriteEngine on',
                'RewriteCond %{HTTPS} !=on [NC]',
                'RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]',
                '</IfModule>',
                '#End Really Simple Security',
                '# BEGIN WordPress',
                '<IfModule mod_rewrite.c>',
                'RewriteEngine On',
                'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
                'RewriteBase /aliacer/',
                'RewriteRule ^index\.php$ - [L]',
                'RewriteCond %{REQUEST_FILENAME} !-f',
                'RewriteCond %{REQUEST_FILENAME} !-d',
                'RewriteRule . /aliacer/index.php [L]',
                '</IfModule>',
                '# END WordPress',
            )),
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $this->readyConfig($archive)
        );

        self::assertTrue($result['completed']);

        $htaccess = (string) file_get_contents($this->root_dir . '/.htaccess');
        self::assertStringNotContainsString('Really Simple Auto Prepend File', $htaccess);
        self::assertStringNotContainsString('Really Simple Security', $htaccess);
        self::assertStringNotContainsString('/aliacer/', $htaccess);
        self::assertStringNotContainsString('advanced-headers.php', $htaccess);
        self::assertStringContainsString('RewriteBase /wptest/', $htaccess);
        self::assertStringContainsString('RewriteRule . /wptest/index.php [L]', $htaccess);
    }

    public function testRestoresLargeFileWithoutReadingWholeEntryIntoMemory(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $large = str_repeat('large-file-', 200000);
        $archive = $this->archive(array(
            'files/wp-content/uploads/large.bin' => $large,
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $this->readyConfig($archive)
        );

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['file_count']);
        self::assertSame(strlen($large), filesize($this->root_dir . '/wp-content/uploads/large.bin'));
        self::assertSame(hash('sha256', $large), hash_file('sha256', $this->root_dir . '/wp-content/uploads/large.bin'));
        self::assertStringNotContainsString('getFromName(', (string) file_get_contents(dirname(__DIR__, 2) . '/installer/restore-engine/FileRestoreManager.php'));
    }

    public function testRejectsUnsafeFileRestorePath(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(new \SuperSheepCopyInstaller\ArchiveValidator()))->restore(
            $this->engine_dir,
            $this->readyConfig($this->archive(array('files/../evil.php' => 'bad')))
        );

        self::assertFalse($result['completed']);
        self::assertSame(array('Unsafe file restore path.'), $result['warnings']);
        self::assertFileDoesNotExist($this->root_dir . '/evil.php');
    }

    public function testCleansOldDatabaseTablesAfterSuccessfulFileRestore(): void
    {
        $archive = $this->directoryPackage(array(
            'files/index.php' => 'restored index',
        ));
        $cleaner = new FakeDatabaseBackupTableCleaner();
        $config = array_merge($this->readyConfig($archive), array(
            'database_swap_backup_tables' => array(
                'wp_posts' => 'ssc_old_abcd_wp_posts',
                'wp_options' => 'ssc_old_abcd_wp_options',
            ),
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(
            new \SuperSheepCopyInstaller\ArchiveValidator(),
            $cleaner
        ))->restore($this->engine_dir, $config);

        self::assertTrue($result['completed']);
        self::assertSame(array('ssc_old_abcd_wp_posts', 'ssc_old_abcd_wp_options'), $cleaner->tables);

        $updated = require $this->engine_dir . '/config.php';
        self::assertTrue($updated['database_backup_tables_cleaned']);
        self::assertSame(2, $updated['database_backup_tables_cleaned_count']);
        self::assertSame(array(), $updated['database_backup_tables_cleanup_warnings']);
    }

    public function testDoesNotCleanOldDatabaseTablesWhenFileRestoreFails(): void
    {
        $archive = $this->directoryPackage(array(
            'files/wp-config.php' => 'source config',
        ));
        $cleaner = new FakeDatabaseBackupTableCleaner();
        $config = array_merge($this->readyConfig($archive), array(
            'database_swap_backup_tables' => array('wp_posts' => 'ssc_old_abcd_wp_posts'),
        ));

        $result = (new \SuperSheepCopyInstaller\FileRestoreManager(
            new \SuperSheepCopyInstaller\ArchiveValidator(),
            $cleaner
        ))->restore($this->engine_dir, $config);

        self::assertFalse($result['completed']);
        self::assertSame(array(), $cleaner->tables);
    }

    /**
     * @param array<string,string> $entries
     */
    private function archive(array $entries): string
    {
        $archive = $this->engine_dir . '/backup-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', json_encode(array('project' => 'Super Sheep Copy')));
        $zip->addFromString('database/tables.json', '{}');
        $zip->addFromString('database/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->addFromString('checksums.json', $this->checksums(array_merge(array(
            'database/tables.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
        ), $entries)));
        $zip->close();

        return $archive;
    }

    /**
     * @param array<string,string> $entries
     */
    private function directoryPackage(array $entries): string
    {
        $archive = $this->engine_dir . '/backup-' . bin2hex(random_bytes(3));
        $base_entries = array(
            'manifest.json' => (string) json_encode(array('project' => 'Super Sheep Copy')),
            'database/tables.json' => '{}',
            'database/chunks/wp_posts.part001.sql' => 'CREATE TABLE wp_posts;',
        );
        $base_entries['checksums.json'] = $this->checksums(array_merge($base_entries, $entries));

        foreach (array_merge($base_entries, $entries) as $name => $contents) {
            $path = $archive . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }

        return $archive;
    }

    /**
     * @param array<string,string> $entries
     */
    private function directoryPackageWithoutManifest(array $entries): string
    {
        $archive = $this->engine_dir . '/backup-' . bin2hex(random_bytes(3));
        foreach ($entries as $name => $contents) {
            $path = $archive . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }

        return $archive;
    }

    /**
     * @param array<string,string> $entries
     */
    private function checksums(array $entries): string
    {
        $checksums = array();
        foreach ($entries as $path => $contents) {
            if (strpos($path, 'files/') === 0 || strpos($path, 'database/') === 0) {
                $checksums[$path] = hash('sha256', $contents);
            }
        }

        return (string) json_encode($checksums);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyConfig(string $archive): array
    {
        return array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_url_replacement_completed' => true,
            'database_url_replacement_plan' => array(
                'source_urls' => array('https://shotpruebas.com/aliacer'),
                'destination_url' => 'https://shotpruebas.com/wptest',
            ),
            'staged_archive_path' => $archive,
            'locked' => true,
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
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

final class FakeDatabaseBackupTableCleaner extends \SuperSheepCopyInstaller\DatabaseBackupTableCleaner
{
    /** @var list<string> */
    public array $tables = array();

    public function clean(string $engine_dir, array $config): array
    {
        unset($engine_dir);

        $this->tables = array_values($config['database_swap_backup_tables']);

        return array(
            'cleaned' => true,
            'table_count' => count($this->tables),
            'warnings' => array(),
        );
    }
}
