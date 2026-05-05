<?php
/**
 * Clase base para todos los tests del plugin.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );

namespace SSC\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Class TestCase
 *
 * Inicializa Brain\Monkey antes de cada test (para mockear funciones de WP)
 * y lo destruye después para limpiar el estado global.
 */
abstract class TestCase extends PHPUnitTestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->define_common_wp_stubs();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Define stubs de funciones WP que casi todos los tests necesitan.
     */
    private function define_common_wp_stubs(): void {
        // Traducción — devuelve el string sin cambios.
        Monkey\Functions\stubs( [
            '__',
            'esc_html__',
            'esc_attr',
            'esc_html',
            'esc_url',
        ] );

        // Funciones de opción — no hacemos nada por defecto.
        Monkey\Functions\stubs( [
            'get_option'    => null,
            'update_option' => true,
            'delete_option' => true,
        ] );

        // Funciones de transient.
        Monkey\Functions\stubs( [
            'get_transient'    => false,
            'set_transient'    => true,
            'delete_transient' => true,
        ] );

        // Hooks — no-op.
        Monkey\Functions\stubs( [
            'do_action'      => null,
            'apply_filters'  => static fn( $tag, $value ) => $value,
            'add_action'     => null,
        ] );

        // Utilidades.
        Monkey\Functions\stubs( [
            'wp_parse_args'    => static fn( $a, $b ) => array_merge( (array) $b, (array) $a ),
            'is_wp_error'      => static fn( $v ) => $v instanceof \WP_Error,
            'wp_mkdir_p'       => static fn( $dir ) => @mkdir( $dir, 0755, true ) || is_dir( $dir ),
            'sanitize_file_name' => static fn( $v ) => preg_replace( '/[^a-zA-Z0-9._-]/', '-', $v ),
            'size_format'      => static fn( $bytes ) => round( $bytes / 1048576, 2 ) . ' MB',
            'get_bloginfo'     => static fn( $show ) => '6.5',
            'get_site_url'     => static fn() => 'https://example.com',
            'get_home_url'     => static fn() => 'https://example.com',
            'is_multisite'     => static fn() => false,
            'wp_json_encode'   => static fn( $data, $flags = 0 ) => json_encode( $data, $flags ),
            'current_time'     => static fn( $type ) => gmdate( 'Y-m-d H:i:s' ),
            'wp_get_current_user' => static fn() => (object) [ 'ID' => 1, 'user_login' => 'admin' ],
            'get_current_user_id' => static fn() => 1,
            'wp_raise_memory_limit' => static fn() => '256M',
            'wp_parse_url'          => static fn( $url, $component = -1 ) => parse_url( $url, $component ),
            'site_url'              => static fn( $path = '' ) => 'https://example.com' . $path,
        ] );
    }

    // ── Helpers compartidos ───────────────────────────────────────────────────

    /**
     * Crea un archivo ZIP de prueba con las entradas dadas.
     *
     * @param string $zip_path    Ruta de destino del ZIP.
     * @param array  $entries     [ 'local/name' => 'contenido' ].
     */
    protected function create_test_zip( string $zip_path, array $entries ): void {
        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
        foreach ( $entries as $name => $content ) {
            $zip->addFromString( $name, $content );
        }
        $zip->close();
    }

    /**
     * Devuelve un directorio temporal único para el test.
     */
    protected function tmp_dir( string $suffix = '' ): string {
        $dir = sys_get_temp_dir() . '/ssc_test_' . uniqid() . $suffix;
        @mkdir( $dir, 0755, true );
        return $dir;
    }

    /**
     * Elimina un directorio y su contenido de forma recursiva.
     */
    protected function remove_dir( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $it as $item ) {
            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }
        @rmdir( $dir );
    }
}
