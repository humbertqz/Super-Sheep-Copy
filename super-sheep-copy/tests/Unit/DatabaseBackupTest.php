<?php
/**
 * Tests para SSC_Database_Backup — build_insert_block y detección numérica.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );

namespace SSC\Tests\Unit;

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
}
