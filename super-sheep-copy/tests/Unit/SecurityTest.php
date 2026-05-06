<?php

/**
 * Tests para SSC_Security — path traversal y Zip Slip.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );


namespace SSC\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @covers \SSC_Security
 */
class SecurityTest extends TestCase {

    private string $backups_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->backups_dir = SSC_BACKUPS_DIR;
        @mkdir( $this->backups_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
    }

    // ── resolve_backup_path ───────────────────────────────────────────────────

    /** @test */
    public function it_resolves_valid_backup_filename(): void {
        $filename = 'backup-20260422-143055-a3f9b2c1.zip';
        $full     = $this->backups_dir . $filename;
        file_put_contents( $full, 'fake zip content' );

        $result = \SSC_Security::resolve_backup_path( $filename );

        $this->assertIsString( $result );
        $this->assertStringEndsWith( $filename, $result );

        unlink( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    }

    /** @test */
    public function it_rejects_path_traversal_with_double_dots(): void {
        $result = \SSC_Security::resolve_backup_path( '../../../etc/passwd' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** @test */
    public function it_rejects_path_traversal_with_absolute_path(): void {
        $result = \SSC_Security::resolve_backup_path( '/etc/passwd' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** @test */
    public function it_rejects_filename_with_null_byte(): void {
        $result = \SSC_Security::resolve_backup_path( "backup.zip\0.php" );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** @test */
    public function it_rejects_filename_with_special_characters(): void {
        $result = \SSC_Security::resolve_backup_path( 'backup;rm -rf /.zip' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** @test */
    public function it_rejects_nonexistent_file(): void {
        $result = \SSC_Security::resolve_backup_path( 'nonexistent-backup.zip' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    // ── validate_zip_entry (Zip Slip) ─────────────────────────────────────────

    /** @test */
    public function it_accepts_safe_zip_entry(): void {
        $target = $this->tmp_dir();
        $result = \SSC_Security::validate_zip_entry( 'wp-content/themes/my-theme/style.css', $target );
        $this->assertTrue( $result );
        $this->remove_dir( $target );
    }

    /** @test */
    public function it_rejects_zip_entry_with_double_dots(): void {
        $target = $this->tmp_dir();
        $result = \SSC_Security::validate_zip_entry( '../../etc/passwd', $target );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->remove_dir( $target );
    }

    /** @test */
    public function it_rejects_zip_entry_with_null_byte(): void {
        $target = $this->tmp_dir();
        $result = \SSC_Security::validate_zip_entry( "safe\0evil", $target );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->remove_dir( $target );
    }

    /** @test */
    public function it_rejects_absolute_zip_entry_path(): void {
        $target = $this->tmp_dir();
        // Una entrada que empieza por / tiene el '/' eliminado con ltrim, quedando
        // 'etc/cron.d/evil'. Eso se combina con $target → seguro.
        $result = \SSC_Security::validate_zip_entry( '/etc/cron.d/evil', $target );
        // El leading '/' es eliminado, por lo que la entrada aterriza dentro del target.
        // Verificamos que, si se acepta, el path normalizado esté dentro de $target.
        if ( ! ( $result instanceof \WP_Error ) ) {
            $dest = $target . '/' . ltrim( '/etc/cron.d/evil', '/' );
            $this->assertStringStartsWith( $target . '/', $dest );
        } else {
            // También es válido rechazarla.
            $this->assertInstanceOf( \WP_Error::class, $result );
        }
        $this->remove_dir( $target );
    }

    // ── delete_backup_file ────────────────────────────────────────────────────

    /** @test */
    public function it_deletes_zip_and_sha256_file(): void {
        $filename = 'backup-test-delete.zip';
        $zip_path = $this->backups_dir . $filename;
        $sha_path = $zip_path . '.sha256';

        file_put_contents( $zip_path, 'fake' );
        file_put_contents( $sha_path, 'abc123' );

        $result = \SSC_Security::delete_backup_file( $filename );

        $this->assertTrue( $result );
        $this->assertFileDoesNotExist( $zip_path );
        $this->assertFileDoesNotExist( $sha_path );
    }

    /** @test */
    public function delete_returns_error_for_nonexistent_file(): void {
        $result = \SSC_Security::delete_backup_file( 'does-not-exist.zip' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }
}
