<?php

/**
 * Tests para SSC_Files_Restore — extracción y Zip Slip.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );


namespace SSC\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @covers \SSC_Files_Restore
 */
class FilesRestoreTest extends TestCase {

    private string $work_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->work_dir = $this->tmp_dir( '_files_restore' );
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->work_dir );
        parent::tearDown();
    }

    /** @test */
    public function it_extracts_files_to_abspath(): void {
        $zip_path = $this->work_dir . '/backup.zip';
        $tmp_dir  = $this->work_dir . '/tmp';
        $target   = $this->work_dir . '/site';
        @mkdir( $target, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

        $this->create_test_zip( $zip_path, [
            'wp-content/themes/my-theme/style.css' => "/* Theme */\nbody {}",
            'wp-content/uploads/image.jpg'          => 'fake image data',
            'manifest.json'                          => '{}',
            'database.sql'                           => '-- SQL',
        ] );

        // Redefinir ABSPATH para este test apuntando a $target.
        $restore = $this->make_restore( $zip_path, $tmp_dir, $target );
        $result  = $restore->run();

        $this->assertTrue( $result );
        $this->assertFileExists( $target . '/wp-content/themes/my-theme/style.css' );
        $this->assertFileExists( $target . '/wp-content/uploads/image.jpg' );
        $this->assertSame( "/* Theme */\nbody {}", file_get_contents( $target . '/wp-content/themes/my-theme/style.css' ) );
    }

    /** @test */
    public function it_skips_manifest_and_database_sql(): void {
        $zip_path = $this->work_dir . '/backup.zip';
        $tmp_dir  = $this->work_dir . '/tmp';
        $target   = $this->work_dir . '/site';
        @mkdir( $target, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

        $this->create_test_zip( $zip_path, [
            'manifest.json' => '{"key":"val"}',
            'database.sql'  => '-- SQL dump',
            'index.php'     => '<?php',
        ] );

        $restore = $this->make_restore( $zip_path, $tmp_dir, $target );
        $restore->run();

        $this->assertFileDoesNotExist( $target . '/manifest.json' );
        $this->assertFileDoesNotExist( $target . '/database.sql' );
        $this->assertFileExists( $target . '/index.php' );
    }

    /** @test */
    public function it_skips_wp_config_by_default(): void {
        $zip_path = $this->work_dir . '/backup.zip';
        $tmp_dir  = $this->work_dir . '/tmp';
        $target   = $this->work_dir . '/site';
        @mkdir( $target, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

        $this->create_test_zip( $zip_path, [
            'wp-config.php' => '<?php define("DB_NAME","old");',
            'index.php'     => '<?php',
        ] );

        $restore = $this->make_restore( $zip_path, $tmp_dir, $target, false );
        $restore->run();

        $this->assertFileDoesNotExist( $target . '/wp-config.php' );
        $this->assertFileExists( $target . '/index.php' );
    }

    /** @test */
    public function it_overwrites_wp_config_when_authorized(): void {
        $zip_path = $this->work_dir . '/backup.zip';
        $tmp_dir  = $this->work_dir . '/tmp';
        $target   = $this->work_dir . '/site';
        @mkdir( $target, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

        $this->create_test_zip( $zip_path, [
            'wp-config.php' => '<?php define("DB_NAME","newdb");',
        ] );

        $restore = $this->make_restore( $zip_path, $tmp_dir, $target, true );
        $restore->run();

        $this->assertFileExists( $target . '/wp-config.php' );
        $this->assertStringContainsString( 'newdb', file_get_contents( $target . '/wp-config.php' ) );
    }

    /** @test */
    public function it_returns_error_for_non_existent_zip(): void {
        $restore = $this->make_restore( '/nonexistent.zip', $this->work_dir . '/tmp', $this->work_dir . '/site' );
        $result  = $restore->run();
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Crea una instancia de SSC_Files_Restore que escribe a $abspath en lugar
     * de ABSPATH real (para no tocar el sistema de archivos del host).
     */
    private function make_restore(
        string $zip_path,
        string $tmp_dir,
        string $abspath,
        bool $overwrite_wp_config = false
    ): \SSC_Files_Restore {
        // Subclase anónima que sobreescribe deploy_to_abspath para usar $abspath.
        return new class( $zip_path, $tmp_dir, $overwrite_wp_config, $abspath ) extends \SSC_Files_Restore {
            private string $test_abspath;

            public function __construct( string $zip, string $tmp, bool $cfg, string $abs ) {
                parent::__construct( $zip, $tmp, $cfg );
                $this->test_abspath = $abs;
            }

            protected function deploy_to_abspath_target(): string {
                return $this->test_abspath;
            }
        };
    }
}
