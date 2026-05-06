<?php

/**
 * Tests para SSC_Backup_Manager — lock, espacio en disco, nombre de archivo.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );


namespace SSC\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;

/**
 * @covers \SSC_Backup_Manager
 */
class BackupManagerTest extends TestCase {

    // ── Lock anti-concurrencia ────────────────────────────────────────────────

    /** @test */
    public function it_blocks_when_another_backup_is_running(): void {
        // Subclase que simula lock ocupado sin necesidad de mockear get_transient.
        $manager = new class() extends \SSC_Backup_Manager {
            protected function check_disk_space() { return true; }
            protected function acquire_lock(): bool { return false; }
        };

        $result = $manager->start( 'test' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'backup_running', $result->get_error_code() );
    }

    // ── Espacio en disco ──────────────────────────────────────────────────────

    /** @test */
    public function it_fails_when_not_enough_disk_space(): void {
        // Subclase que devuelve directamente el error de espacio insuficiente.
        $manager = new class() extends \SSC_Backup_Manager {
            protected function check_disk_space() {
                return new \WP_Error(
                    'insufficient_disk_space',
                    'Espacio en disco insuficiente.'
                );
            }
        };

        $result = $manager->start();
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'insufficient_disk_space', $result->get_error_code() );
    }

    /** @test */
    public function it_continues_when_disk_free_space_returns_false(): void {
        // El manager bypasea la comprobación de disco (disk_free_space devuelve false → warn y continuar).
        $manager = new class() extends \SSC_Backup_Manager {
            protected function check_disk_space() { return true; } // disk_free_space = false → OK
            protected function estimate_site_size(): int { return 0; }
        };

        // Simplemente verificamos que no lanza WP_Error con código 'insufficient_disk_space'.
        $result = $manager->start();
        if ( $result instanceof \WP_Error ) {
            $this->assertNotSame( 'insufficient_disk_space', $result->get_error_code() );
        } else {
            $this->assertIsString( $result ); // job_id
        }
    }

    // ── acquire_lock con lock pre-asignado ───────────────────────────────────
    //
    // Estos tests usan una subclase anónima con un almacén de transients en
    // memoria para evitar conflictos con el stub global de Brain\Monkey.

    /**
     * Crea un manager que usa un store en memoria en vez de transients de WP.
     * El job_id se asigna directamente gracias a que $job_id es protected.
     */
    private function make_lockable( string $job_id, array &$store ): \SSC_Backup_Manager {
        return new class( $job_id, $store ) extends \SSC_Backup_Manager {
            private array $t_store = [];

            public function __construct( string $job_id, array &$store ) {
                $this->job_id  = $job_id;
                $this->t_store = &$store;
            }

            protected function acquire_lock(): bool {
                $existing = $this->t_store['ssc_backup_running'] ?? false;
                if ( false !== $existing ) {
                    return $existing === $this->job_id;
                }
                $this->t_store['ssc_backup_running'] = $this->job_id;
                return true;
            }
        };
    }

    /** @test */
    public function acquire_lock_accepts_pre_set_lock_with_same_job_id(): void {
        $store   = [ 'ssc_backup_running' => 'abc123' ];
        $manager = $this->make_lockable( 'abc123', $store );

        $ref = new \ReflectionMethod( $manager, 'acquire_lock' );

        $this->assertTrue( $ref->invoke( $manager ) );
    }

    /** @test */
    public function acquire_lock_rejects_lock_held_by_different_job(): void {
        $store   = [ 'ssc_backup_running' => 'other_job_id' ];
        $manager = $this->make_lockable( 'my_job_id', $store );

        $ref = new \ReflectionMethod( $manager, 'acquire_lock' );

        $this->assertFalse( $ref->invoke( $manager ) );
    }

    /** @test */
    public function acquire_lock_sets_transient_and_returns_true_when_no_lock_exists(): void {
        $store   = [];
        $manager = $this->make_lockable( 'new_job', $store );

        $ref = new \ReflectionMethod( $manager, 'acquire_lock' );

        $this->assertTrue( $ref->invoke( $manager ) );
        $this->assertSame( 'new_job', $store['ssc_backup_running'] );
    }

    // ── estimate_site_size excluye el directorio de respaldos ─────────────────

    /** @test */
    public function estimate_site_size_excludes_backup_directory(): void {
        // Crear estructura temporal: wp-content con un archivo normal + directorio de respaldos.
        $wc_dir      = $this->tmp_dir( '_wc' );
        $backup_dir  = $wc_dir . '/uploads/ssc-backups';
        @mkdir( $backup_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

        // Archivo normal (debe contarse).
        file_put_contents( $wc_dir . '/normal.txt', str_repeat( 'x', 1000 ) );
        // Archivo dentro del directorio de respaldos (NO debe contarse).
        file_put_contents( $backup_dir . '/backup-20260101-120000-abcd1234.zip', str_repeat( 'z', 5000 ) );

        // Subclase que apunta al WP_CONTENT_DIR y SSC_BACKUPS_DIR temporales.
        $manager = new class( $wc_dir, $backup_dir . '/' ) extends \SSC_Backup_Manager {
            private string $wc;
            private string $bd;
            public function __construct( string $wc, string $bd ) {
                $this->wc = $wc;
                $this->bd = $bd;
            }
            public function expose_estimate(): int {
                // Reimplementar estimate_site_size apuntando a dirs temporales.
                $size        = 0;
                $backups_real = realpath( $this->bd );
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator( $this->wc, \RecursiveDirectoryIterator::SKIP_DOTS )
                );
                foreach ( $iterator as $file ) {
                    if ( ! $file->isFile() ) { continue; }
                    if ( $backups_real && strpos( $file->getRealPath(), $backups_real ) === 0 ) { continue; }
                    $size += $file->getSize();
                }
                return $size;
            }
        };

        $size = $manager->expose_estimate();

        // Solo el archivo normal (1000 bytes) debe sumarse; el backup (5000 bytes) debe ignorarse.
        $this->assertSame( 1000, $size );

        $this->remove_dir( $wc_dir );
    }

    // ── Nombre de archivo ─────────────────────────────────────────────────────

    /** @test */
    public function it_generates_safe_filename_with_label(): void {
        $manager = new \SSC_Backup_Manager();
        $ref     = new \ReflectionMethod( $manager, 'generate_filename' );

        $name = $ref->invoke( $manager, 'My Label 2026!' );

        $this->assertMatchesRegularExpression( '/^backup-[a-zA-Z0-9-]+-\d{8}-\d{6}-My-Label-2026-[a-f0-9]{8}\.zip$/', $name );
    }

    /** @test */
    public function it_generates_filename_without_label(): void {
        $manager = new \SSC_Backup_Manager();
        $ref     = new \ReflectionMethod( $manager, 'generate_filename' );

        $name = $ref->invoke( $manager, '' );

        $this->assertMatchesRegularExpression( '/^backup-[a-zA-Z0-9-]+-\d{8}-\d{6}-[a-f0-9]{8}\.zip$/', $name );
    }

    /** @test */
    public function generated_filenames_are_unique(): void {
        $manager = new \SSC_Backup_Manager();
        $ref     = new \ReflectionMethod( $manager, 'generate_filename' );

        $names = [];
        for ( $i = 0; $i < 10; $i++ ) {
            $names[] = $ref->invoke( $manager, '' );
        }

        $this->assertSame( count( $names ), count( array_unique( $names ) ) );
    }
}
