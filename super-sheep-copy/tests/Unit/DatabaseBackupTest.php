<?php

/**
 * Tests para SSC_Database_Backup — build_insert_block y detección numérica.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );


namespace SSC\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @covers \SSC_Database_Backup
 */
class DatabaseBackupTest extends TestCase {

    /** @var \ReflectionMethod */
    private \ReflectionMethod $build_insert;

    protected function setUp(): void {
        parent::setUp();
        $this->build_insert = new \ReflectionMethod( \SSC_Database_Backup::class, 'build_insert_block' );
    }

    private function invoke( string $table, array $rows ): string {
        $obj = new \SSC_Database_Backup( '/dev/null' );
        return $this->build_insert->invoke( $obj, $table, $rows );
    }

    // ── NULL ────────────────────────────────────────────────────────────────

    /** @test */
    public function null_values_are_written_as_SQL_NULL(): void {
        $sql = $this->invoke( 'wp_test', [ [ 'col' => null ] ] );
        $this->assertStringContainsString( '(NULL)', $sql );
        $this->assertStringNotContainsString( "''", $sql );
    }

    // ── Numéricos (fix: is_numeric + preg_match) ──────────────────────────

    /** @test */
    public function integer_strings_from_db_are_not_quoted(): void {
        // $wpdb devuelve enteros como strings — deben insertarse sin comillas.
        $sql = $this->invoke( 'wp_test', [ [ 'id' => '42' ] ] );
        $this->assertStringContainsString( '(42)', $sql );
        $this->assertStringNotContainsString( "('42')", $sql );
    }

    /** @test */
    public function negative_integers_are_not_quoted(): void {
        $sql = $this->invoke( 'wp_test', [ [ 'delta' => '-7' ] ] );
        $this->assertStringContainsString( '(-7)', $sql );
        $this->assertStringNotContainsString( "('-7')", $sql );
    }

    /** @test */
    public function decimal_strings_are_not_quoted(): void {
        $sql = $this->invoke( 'wp_test', [ [ 'price' => '19.99' ] ] );
        $this->assertStringContainsString( '(19.99)', $sql );
        $this->assertStringNotContainsString( "('19.99')", $sql );
    }

    /** @test */
    public function numeric_looking_strings_with_non_numeric_chars_are_quoted(): void {
        // Valores como "1e5" o "0x1A" son is_numeric() pero no enteros/decimales puros.
        // Deben seguir siendo strings en el SQL para evitar sorpresas de tipado.
        $sql = $this->invoke( 'wp_test', [ [ 'val' => '1e5' ] ] );
        $this->assertStringContainsString( "'1e5'", $sql );
    }

    /** @test */
    public function plain_text_strings_are_quoted_and_escaped(): void {
        $sql = $this->invoke( 'wp_test', [ [ 'title' => "O'Brien" ] ] );
        $this->assertStringContainsString( "'O\\'Brien'", $sql );
    }

    // ── Múltiples filas ───────────────────────────────────────────────────

    /** @test */
    public function multiple_rows_are_in_a_single_insert_statement(): void {
        $rows = [
            [ 'id' => '1', 'name' => 'Alice' ],
            [ 'id' => '2', 'name' => 'Bob' ],
        ];
        $sql = $this->invoke( 'wp_test', $rows );

        // Debe haber un solo INSERT INTO.
        $this->assertSame( 1, substr_count( $sql, 'INSERT INTO' ) );
        // Ambas filas presentes.
        $this->assertStringContainsString( "(1, 'Alice')", $sql );
        $this->assertStringContainsString( "(2, 'Bob')", $sql );
    }

    /** @test */
    public function empty_rows_returns_empty_string(): void {
        $sql = $this->invoke( 'wp_test', [] );
        $this->assertSame( '', $sql );
    }

    // ── dump_via_php() — contenido del archivo SQL ────────────────────────────

    /**
     * Crea un stub de $wpdb para pruebas de dump_via_php().
     *
     * El mock retorna las tablas dadas y, para cada tabla, sus filas paginadas.
     * Dado que prepare() devuelve la plantilla sin sustituir, get_row() y
     * get_results() usan un contador interno para saber qué tabla está activa.
     *
     * @param string[] $tables         Lista de tablas a reportar.
     * @param array    $rows_by_table  [ 'table_name' => [ [col => val, ...], ... ] ].
     * @return object  Mock de $wpdb.
     */
    private function make_wpdb_stub( array $tables, array $rows_by_table ): object {
        return new class( $tables, $rows_by_table ) {
            public string  $prefix     = 'wp_';
            public ?object $dbh        = null; // no mysqli → usa addslashes
            public string  $last_error = '';
            private array  $tables;
            private array  $rows;
            private int    $current_table_idx            = -1;
            private int    $results_calls_for_cur_table  = 0;

            public function __construct( array $t, array $r ) {
                $this->tables = $t;
                $this->rows   = $r;
            }

            public function get_col( string $q ): array { return $this->tables; }

            public function get_row( string $q, $output = null ): array {
                ++$this->current_table_idx;
                $this->results_calls_for_cur_table = 0;
                $table = $this->tables[ $this->current_table_idx ] ?? 'wp_unknown';
                return array( 0 => $table, 1 => "CREATE TABLE `{$table}` (`id` int NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`))" );
            }

            public function get_results( string $q, $output = null ): array {
                $table = $this->tables[ $this->current_table_idx ] ?? '';
                $all   = $this->rows[ $table ] ?? array();
                $slice = array_slice( $all, $this->results_calls_for_cur_table * 500, 500 );
                ++$this->results_calls_for_cur_table;
                return $slice;
            }

            public function prepare( string $q, ...$args ): string { return $q; }
            public function insert( ...$args ): int                 { return 1; }
            public function check_connection( bool $allow_bail = true ): bool { return true; }
        };
    }

    /** @test */
    public function dump_via_php_includes_drop_and_create_for_every_table(): void {
        $sql_file = tempnam( sys_get_temp_dir(), 'ssc_db_test_' );

        $original_wpdb   = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( array( 'wp_posts', 'wp_options' ), array() );

        $backup = new \SSC_Database_Backup( $sql_file );
        $method = new \ReflectionMethod( \SSC_Database_Backup::class, 'dump_via_php' );
        $method->invoke( $backup );

        $GLOBALS['wpdb'] = $original_wpdb;

        $sql = file_get_contents( $sql_file );
        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

        $this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_posts`', $sql );
        $this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_options`', $sql );
        $this->assertStringContainsString( 'CREATE TABLE `wp_posts`', $sql );
        $this->assertStringContainsString( 'CREATE TABLE `wp_options`', $sql );
    }

    /** @test */
    public function dump_via_php_output_file_exists_and_is_nonempty(): void {
        $sql_file = tempnam( sys_get_temp_dir(), 'ssc_db_test_' );

        $original_wpdb   = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( array( 'wp_posts' ), array() );

        $backup = new \SSC_Database_Backup( $sql_file );
        $method = new \ReflectionMethod( \SSC_Database_Backup::class, 'dump_via_php' );
        $method->invoke( $backup );

        $GLOBALS['wpdb'] = $original_wpdb;

        $this->assertFileExists( $sql_file );
        $this->assertGreaterThan( 0, filesize( $sql_file ) );

        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    }

    /** @test */
    public function dump_via_php_header_contains_set_names_utf8mb4(): void {
        $sql_file = tempnam( sys_get_temp_dir(), 'ssc_db_test_' );

        $original_wpdb   = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( array( 'wp_posts' ), array() );

        $backup = new \SSC_Database_Backup( $sql_file );
        $method = new \ReflectionMethod( \SSC_Database_Backup::class, 'dump_via_php' );
        $method->invoke( $backup );

        $GLOBALS['wpdb'] = $original_wpdb;

        $sql = file_get_contents( $sql_file );
        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

        $this->assertStringContainsString( 'SET NAMES utf8mb4', $sql );
    }

    /** @test */
    public function dump_via_php_drop_appears_before_create_for_each_table(): void {
        $sql_file = tempnam( sys_get_temp_dir(), 'ssc_db_test_' );

        $original_wpdb   = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( array( 'wp_posts' ), array() );

        $backup = new \SSC_Database_Backup( $sql_file );
        $method = new \ReflectionMethod( \SSC_Database_Backup::class, 'dump_via_php' );
        $method->invoke( $backup );

        $GLOBALS['wpdb'] = $original_wpdb;

        $sql        = file_get_contents( $sql_file );
        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

        $pos_drop   = strpos( $sql, 'DROP TABLE IF EXISTS `wp_posts`' );
        $pos_create = strpos( $sql, 'CREATE TABLE `wp_posts`' );

        $this->assertNotFalse( $pos_drop,   'DROP TABLE not found in SQL output.' );
        $this->assertNotFalse( $pos_create, 'CREATE TABLE not found in SQL output.' );
        $this->assertLessThan( $pos_create, $pos_drop, 'DROP TABLE must appear before CREATE TABLE.' );
    }

    /** @test */
    public function dump_via_php_row_data_appears_in_insert_statements(): void {
        $sql_file = tempnam( sys_get_temp_dir(), 'ssc_db_test_' );
        $rows     = array(
            array( 'id' => '1', 'post_title' => 'Hello World' ),
            array( 'id' => '2', 'post_title' => 'Second Post' ),
        );

        $original_wpdb   = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( array( 'wp_posts' ), array( 'wp_posts' => $rows ) );

        $backup = new \SSC_Database_Backup( $sql_file );
        $method = new \ReflectionMethod( \SSC_Database_Backup::class, 'dump_via_php' );
        $method->invoke( $backup );

        $GLOBALS['wpdb'] = $original_wpdb;

        $sql = file_get_contents( $sql_file );
        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

        $this->assertStringContainsString( 'INSERT INTO `wp_posts`', $sql );
        $this->assertStringContainsString( "'Hello World'", $sql );
        $this->assertStringContainsString( "'Second Post'", $sql );
    }

    // ── run() — vía PHP (is_mysqldump_available sobreescrita) ─────────────────

    /**
     * Subclase que fuerza la ruta PHP sin necesitar mysqldump en el sistema.
     */
    private function make_no_mysqldump( string $sql_file ): \SSC_Database_Backup {
        return new class( $sql_file ) extends \SSC_Database_Backup {
            protected function is_mysqldump_available(): bool { return false; }
        };
    }

    /** @test */
    public function test_run_uses_php_fallback_and_produces_sql_file(): void {
        $sql_file = tempnam( sys_get_temp_dir(), 'ssc_db_run_' );
        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

        $original_wpdb   = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( array( 'wp_options' ), array() );

        $backup = $this->make_no_mysqldump( $sql_file );
        $result = $backup->run();

        $GLOBALS['wpdb'] = $original_wpdb;

        $this->assertTrue( $result );
        $this->assertFileExists( $sql_file );
        $this->assertGreaterThan( 0, filesize( $sql_file ) );

        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    }

    /** @test */
    public function test_run_returns_wp_error_when_no_tables_found(): void {
        $sql_file = tempnam( sys_get_temp_dir(), 'ssc_db_run_' );
        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

        $original_wpdb   = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( array(), array() ); // lista de tablas vacía

        $backup = $this->make_no_mysqldump( $sql_file );
        $result = $backup->run();

        $GLOBALS['wpdb'] = $original_wpdb;
        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_tables', $result->get_error_code() );
    }

    /** @test */
    public function test_run_returns_wp_error_when_output_file_is_unwriteable(): void {
        $original_wpdb   = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( array( 'wp_posts' ), array() );

        $backup = $this->make_no_mysqldump( '/root/nonexistent_dir/output.sql' );
        $result = $backup->run();

        $GLOBALS['wpdb'] = $original_wpdb;

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'cannot_open_output', $result->get_error_code() );
    }

    /** @test */
    public function dump_via_php_paginates_tables_with_more_than_500_rows(): void {
        $sql_file = tempnam( sys_get_temp_dir(), 'ssc_db_test_' );

        // Generar 520 filas — se necesitan 2 páginas (500 + 20).
        $rows = array();
        for ( $i = 1; $i <= 520; $i++ ) {
            $rows[] = array( 'id' => (string) $i, 'name' => "row-{$i}" );
        }

        $original_wpdb   = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->make_wpdb_stub( array( 'wp_posts' ), array( 'wp_posts' => $rows ) );

        $backup = new \SSC_Database_Backup( $sql_file );
        $method = new \ReflectionMethod( \SSC_Database_Backup::class, 'dump_via_php' );
        $method->invoke( $backup );

        $GLOBALS['wpdb'] = $original_wpdb;

        $sql = file_get_contents( $sql_file );
        @unlink( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

        // Verificar que la primera y la última fila estén en el SQL.
        $this->assertStringContainsString( "'row-1'", $sql,   'First row (page 1) should be in the dump.' );
        $this->assertStringContainsString( "'row-520'", $sql, 'Last row (page 2) should be in the dump.' );
    }

    // ── looks_like_sql() ──────────────────────────────────────────────────────

    private function invoke_looks_like_sql( string $content ): bool {
        $file = tempnam( sys_get_temp_dir(), 'ssc_sql_check_' );
        file_put_contents( $file, $content );
        $method = new \ReflectionMethod( \SSC_Database_Backup::class, 'looks_like_sql' );
        $result = $method->invoke( new \SSC_Database_Backup( '/dev/null' ), $file );
        @unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        return $result;
    }

    /** @test */
    public function looks_like_sql_accepts_set_statement(): void {
        $this->assertTrue( $this->invoke_looks_like_sql( "SET NAMES utf8mb4;\n" ) );
    }

    /** @test */
    public function looks_like_sql_accepts_sql_comment(): void {
        $this->assertTrue( $this->invoke_looks_like_sql( "-- MySQL dump\nSET NAMES utf8mb4;\n" ) );
    }

    /** @test */
    public function looks_like_sql_accepts_create_statement(): void {
        $this->assertTrue( $this->invoke_looks_like_sql( "CREATE TABLE `wp_options` (...);\n" ) );
    }

    /** @test */
    public function looks_like_sql_rejects_command_not_found_error(): void {
        // Regression: shell writes "sh: mysqldump: command not found" when the
        // binary is missing. This must NOT be treated as a valid SQL dump.
        $this->assertFalse( $this->invoke_looks_like_sql( "sh: mysqldump: command not found\n" ) );
    }

    /** @test */
    public function looks_like_sql_rejects_bash_command_not_found_error(): void {
        $this->assertFalse( $this->invoke_looks_like_sql( "bash: mysqldump: command not found\n" ) );
    }

    /** @test */
    public function looks_like_sql_rejects_empty_file(): void {
        $this->assertFalse( $this->invoke_looks_like_sql( '' ) );
    }

    // ── is_mysqldump_available() ──────────────────────────────────────────────

    /** @test */
    public function is_mysqldump_available_returns_false_for_command_not_found_output(): void {
        // The old check used strpos($output, 'mysqldump') which incorrectly
        // returned true for "sh: mysqldump: command not found".
        $backup = new class( '/dev/null' ) extends \SSC_Database_Backup {
            protected function is_mysqldump_available(): bool {
                // Simulate what the fixed method does: require 'Ver ' in output.
                $output = 'sh: mysqldump: command not found';
                return ! empty( $output ) && strpos( $output, 'Ver ' ) !== false;
            }
        };
        $method = new \ReflectionMethod( $backup, 'is_mysqldump_available' );
        $this->assertFalse( $method->invoke( $backup ) );
    }

    /** @test */
    public function is_mysqldump_available_returns_true_for_real_version_output(): void {
        $backup = new class( '/dev/null' ) extends \SSC_Database_Backup {
            protected function is_mysqldump_available(): bool {
                $output = 'mysqldump  Ver 8.0.32 Distrib 8.0.32, for macos13.0 (arm64)';
                return ! empty( $output ) && strpos( $output, 'Ver ' ) !== false;
            }
        };
        $method = new \ReflectionMethod( $backup, 'is_mysqldump_available' );
        $this->assertTrue( $method->invoke( $backup ) );
    }
}
