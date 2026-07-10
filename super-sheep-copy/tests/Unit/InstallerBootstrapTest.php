<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/Bootstrap.php';

final class InstallerBootstrapTest extends TestCase
{
    private string $root;
    private string $engine;
    private ?string $previous_engine;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-installer-bootstrap-' . bin2hex(random_bytes(4));
        $this->engine = $this->root . '/ssc-restore-engine';
        mkdir($this->engine, 0777, true);
        $this->previous_engine = $GLOBALS['ssc_installer_engine_dir'] ?? null;
        $GLOBALS['ssc_installer_engine_dir'] = $this->engine;
        $_GET = array();
        $_POST = array();
        $_SERVER = array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php');
    }

    protected function tearDown(): void
    {
        if ($this->previous_engine === null) {
            unset($GLOBALS['ssc_installer_engine_dir']);
        } else {
            $GLOBALS['ssc_installer_engine_dir'] = $this->previous_engine;
        }
        $this->removeDirectory($this->root);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testMissingTokenDoesNotShowArchiveDetails(): void
    {
        $this->writeConfig('plain-token', $this->validArchive());

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Restore token required', $html);
        self::assertStringNotContainsString('https://source.example', $html);
        self::assertStringNotContainsString('Restore Preview', $html);
        self::assertStringNotContainsString('Preflight Checks', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testValidTokenShowsDestinationPreviewAndPreflightChecks(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive());
        $_GET['token'] = 'plain-token';
        $_SERVER = array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php');

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Restore Preview', $html);
        self::assertStringContainsString('https://source.example', $html);
        self::assertStringContainsString('http://destination.example', $html);
        self::assertStringContainsString('Preflight Checks', $html);
        self::assertStringContainsString('class="ssc-installer-workflow"', $html);
        self::assertStringContainsString('class="ssc-installer-step is-current"', $html);
        self::assertStringContainsString('class="ssc-installer-action"', $html);
        self::assertStringContainsString('Type RESTORE to confirm', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInstallerActionFormsShowLoadingStateWhileSubmitting(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive());
        $_GET['token'] = 'plain-token';
        $_SERVER = array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php');

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('class="ssc-installer-action" method="post" data-ssc-installer-action', $html);
        self::assertStringContainsString('data-ssc-installer-loading', $html);
        self::assertStringContainsString('ssc-installer-loading-bar', $html);
        self::assertStringContainsString('Working...', $html);
        self::assertStringContainsString('is-working', $html);
        self::assertStringContainsString('document.addEventListener("submit"', $html);
        self::assertStringContainsString('showInstallerWorkingState(form);', $html);
        self::assertStringNotContainsString('event.preventDefault();', $html);
        self::assertStringNotContainsString('window.HTMLFormElement.prototype.submit.call(form);', $html);
        self::assertStringNotContainsString('document.addEventListener("click"', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConfirmationPostWithPhraseShowsConfirmedStatus(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive());
        $_GET['token'] = 'plain-token';
        $_POST = array('token' => 'plain-token', 'confirm_restore' => '1', 'restore_confirmation' => 'RESTORE', 'restore_warning_accepted' => '1');
        $_SERVER = array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php');

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Restore confirmation recorded', $html);
        $config = require $this->engine . '/config.php';
        self::assertTrue($config['restore_confirmed']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConfirmationPostWithoutPhraseRemainsUnconfirmed(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive());
        $_GET['token'] = 'plain-token';
        $_POST = array('token' => 'plain-token', 'confirm_restore' => '1', 'restore_warning_accepted' => '1');
        $_SERVER = array('HTTP_HOST' => 'destination.example', 'SCRIPT_NAME' => '/installer.php');

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Confirmation was not accepted', $html);
        $config = require $this->engine . '/config.php';
        self::assertArrayNotHasKey('restore_confirmed', $config);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUnconfirmedRestoreDoesNotShowRollbackForm(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive());
        $_GET['token'] = 'plain-token';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Rollback requires restore confirmation.', $html);
        self::assertStringNotContainsString('name="prepare_rollback"', $html);
        self::assertStringNotContainsString('Prepare Rollback', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConfirmedRestoreShowsRollbackPreparationForm(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array('restore_confirmed' => true));
        $_GET['token'] = 'plain-token';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Rollback Preparation', $html);
        self::assertStringContainsString('class="ssc-installer-step is-current"', $html);
        self::assertStringContainsString('Prepare Rollback', $html);
        self::assertStringContainsString('name="prepare_rollback"', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRollbackPostRecordsRollbackPreparedStatus(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array('restore_confirmed' => true));
        $_GET['token'] = 'plain-token';
        $_POST = array('token' => 'plain-token', 'prepare_rollback' => '1');

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Rollback prepared', $html);
        self::assertStringContainsString('Database rollback', $html);
        self::assertStringNotContainsString('secret', $html);
        $config = require $this->engine . '/config.php';
        self::assertTrue($config['rollback_prepared']);
        self::assertFileExists($this->engine . '/' . $config['rollback_manifest']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRollbackPreparedWithDatabaseDumpShowsStagedImportForm(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/id/database/destination.sql',
            'rollback_database_table_count' => 2,
        ));
        $_GET['token'] = 'plain-token';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Database Import', $html);
        self::assertStringContainsString('class="ssc-installer-step is-current"', $html);
        self::assertStringContainsString('Import Database to Staging', $html);
        self::assertStringContainsString('name="stage_database_import"', $html);
        self::assertStringNotContainsString('secret', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testStagedImportStatusShowsCounts(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/id/database/destination.sql',
            'database_import_staged' => true,
            'database_import_table_count' => 1,
            'database_import_chunk_count' => 2,
        ));
        $_GET['token'] = 'plain-token';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Database import staged', $html);
        self::assertStringContainsString('1 tables', $html);
        self::assertStringContainsString('2 chunks', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendersTableSwapGateAfterStagedImport(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_import_staging_tables' => array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example',
        ));
        $_GET['token'] = 'plain-token';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'destination.example';
        $_SERVER['HTTPS'] = 'on';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Database Table Swap', $html);
        self::assertGreaterThan(
            strpos($html, 'Database Import'),
            strpos($html, 'Database Table Swap')
        );
        self::assertStringContainsString('Swap staged database tables into destination table names.', $html);
        self::assertStringContainsString('name="swap_database_tables"', $html);
        self::assertStringContainsString('name="token"', $html);
        self::assertStringNotContainsString('secret', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendersCompletedTableSwapStatus(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swapped' => true,
            'database_swap_table_count' => 2,
            'database_url_replacement_plan' => array('status' => 'planned'),
            'locked' => true,
        ));
        $_GET['token'] = 'plain-token';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Database tables swapped. 2 tables replaced.', $html);
        self::assertStringContainsString('URL replacement plan status: planned.', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendersPendingTableSwapStatusWithoutForm(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swap_pending' => true,
            'locked' => true,
        ));
        $_GET['token'] = 'plain-token';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Database table swap is pending or failed. Installer is locked.', $html);
        self::assertStringNotContainsString('name="swap_database_tables"', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendersDatabaseUrlReplacementActionAfterTableSwap(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swapped' => true,
            'database_url_replacement_plan' => array(
                'status' => 'planned',
                'source_urls' => array('https://source.example'),
                'destination_url' => 'https://destination.example',
                'tables' => array('wp_posts'),
            ),
            'locked' => true,
        ));
        $_GET['token'] = 'plain-token';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Database URL Replacement', $html);
        self::assertStringContainsString('Replace source URLs in swapped database tables.', $html);
        self::assertStringContainsString('name="replace_database_urls"', $html);
        self::assertStringContainsString('name="token"', $html);
        self::assertStringContainsString('Replace Database URLs', $html);
        self::assertStringNotContainsString('secret', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendersCompletedDatabaseUrlReplacementStatus(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swapped' => true,
            'database_url_replacement_completed' => true,
            'database_url_replacement_table_count' => 2,
            'database_url_replacement_changed_rows' => 3,
            'database_url_replacement_changed_cells' => 4,
            'database_url_replacement_count' => 5,
            'locked' => true,
        ));
        $_GET['token'] = 'plain-token';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Database URLs replaced.', $html);
        self::assertStringContainsString('2 tables scanned.', $html);
        self::assertStringContainsString('3 rows changed.', $html);
        self::assertStringContainsString('4 cells changed.', $html);
        self::assertStringContainsString('5 replacements.', $html);
        self::assertStringNotContainsString('name="replace_database_urls"', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendersFileRestoreActionAfterDatabaseUrlReplacement(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swapped' => true,
            'database_url_replacement_completed' => true,
            'locked' => true,
        ));
        $_GET['token'] = 'plain-token';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('File Restore', $html);
        self::assertStringContainsString('Restore archive files into the destination site.', $html);
        self::assertStringContainsString('name="restore_files"', $html);
        self::assertStringContainsString('Restore Files', $html);
        self::assertStringNotContainsString('secret', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testShowsCompletionActionsAfterFileRestoreCompletes(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swapped' => true,
            'database_url_replacement_completed' => true,
            'file_restore_completed' => true,
            'file_restore_file_count' => 14,
            'locked' => true,
        ));
        $_GET['token'] = 'plain-token';
        $_SERVER = array(
            'HTTP_HOST' => 'shotpruebas.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/wptest/installer.php',
            'REQUEST_METHOD' => 'GET',
        );

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Restore complete', $html);
        self::assertStringContainsString('https://shotpruebas.com/wptest', $html);
        self::assertStringContainsString('href="https://shotpruebas.com/wptest"', $html);
        self::assertStringContainsString('href="https://shotpruebas.com/wptest/wp-admin/"', $html);
        self::assertStringContainsString('Delete or lock installer files', $html);
        self::assertStringNotContainsString('Restore Workflow', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFileRestorePostShowsCompletionActionsImmediately(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->validArchive(), array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swapped' => true,
            'database_url_replacement_completed' => true,
            'database_url_replacement_plan' => array(
                'source_urls' => array('https://source.example'),
                'destination_url' => 'https://shotpruebas.com/wptest',
            ),
            'archive_validation_status' => 'valid',
            'archive_validation_errors' => array(),
            'archive_entry_count' => 5,
            'database_entry_count' => 2,
            'locked' => true,
        ));
        $_GET['token'] = 'plain-token';
        $_POST = array('token' => 'plain-token', 'restore_files' => '1');
        $_SERVER = array(
            'HTTP_HOST' => 'shotpruebas.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/wptest/installer.php',
            'REQUEST_METHOD' => 'POST',
        );

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Restore complete', $html);
        self::assertStringContainsString('href="https://shotpruebas.com/wptest"', $html);
        self::assertStringContainsString('href="https://shotpruebas.com/wptest/wp-admin/"', $html);
        self::assertStringNotContainsString('Restore Workflow', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCompletedRestoreDoesNotRevalidateArchiveBeforeRenderingCompletion(): void
    {
        $this->writeWpConfig();
        $this->writeConfig('plain-token', $this->engine . '/missing-backup.zip', array(
            'restore_confirmed' => true,
            'rollback_prepared' => true,
            'rollback_database_dump' => 'rollback/db.sql',
            'database_import_staged' => true,
            'database_tables_swapped' => true,
            'database_url_replacement_completed' => true,
            'file_restore_completed' => true,
            'file_restore_file_count' => 14,
            'locked' => true,
        ));
        $_GET['token'] = 'plain-token';
        $_SERVER = array(
            'HTTP_HOST' => 'shotpruebas.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/wptest/installer.php',
            'REQUEST_METHOD' => 'GET',
        );

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Restore complete', $html);
        self::assertStringNotContainsString('Prepared archive could not be validated', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInstallerUsesCachedArchiveValidationForWorkflowRendering(): void
    {
        $this->writeWpConfig();
        $archive = $this->engine . '/already-validated.zip';
        file_put_contents($archive, 'not a real zip');
        $this->writeConfig('plain-token', $archive, array(
            'archive_validation_status' => 'valid',
            'archive_validation_errors' => array(),
            'archive_entry_count' => 123,
            'database_entry_count' => 45,
        ));
        $_GET['token'] = 'plain-token';
        $_SERVER = array(
            'HTTP_HOST' => 'destination.example',
            'SCRIPT_NAME' => '/installer.php',
            'REQUEST_METHOD' => 'GET',
        );

        ob_start();
        \SuperSheepCopyInstaller\Bootstrap::run();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Restore Workflow', $html);
        self::assertStringContainsString('Archive entries:</strong> 123', $html);
        self::assertStringContainsString('Database entries:</strong> 45', $html);
        self::assertStringNotContainsString('Prepared archive could not be validated', $html);
    }

    private function writeConfig(string $token, string $archive, array $extra = array()): void
    {
        file_put_contents($this->engine . '/config.php', "<?php\nreturn " . var_export(array_merge(array(
            'restore_job_id' => 'restore-123',
            'staged_archive_path' => $archive,
            'staged_archive_basename' => basename($archive),
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
            'token_hash' => password_hash($token, PASSWORD_DEFAULT),
            'token_created_at' => gmdate('c'),
            'locked' => false,
        ), $extra), true) . ";\n");
    }

    private function validArchive(): string
    {
        $archive = $this->engine . '/backup.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', json_encode(array(
            'project' => 'Super Sheep Copy',
            'source_site_url' => 'https://source.example',
            'source_home_url' => 'https://source.example/home',
        )));
        $zip->addFromString('database/tables.json', '{}');
        $zip->addFromString('database/chunks/wp_posts.part001.sql', 'CREATE TABLE wp_posts;');
        $zip->addFromString('files/index.php', '<?php echo "site";');
        $zip->addFromString('checksums.json', (string) json_encode(array(
            'database/tables.json' => hash('sha256', '{}'),
            'database/chunks/wp_posts.part001.sql' => hash('sha256', 'CREATE TABLE wp_posts;'),
            'files/index.php' => hash('sha256', '<?php echo "site";'),
        )));
        $zip->close();

        return $archive;
    }

    private function writeWpConfig(): void
    {
        file_put_contents(dirname($this->engine) . '/wp-config.php', "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'dbuser');\n"
            . "define('DB_PASSWORD', 'secret');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "\$table_prefix = 'wp_';\n");
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
