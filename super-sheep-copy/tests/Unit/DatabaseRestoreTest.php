<?php
/**
 * Tests para SSC_Database_Restore — importación SQL y reescritura de prefijo.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );

namespace SSC\Tests\Unit;

/**
 * @covers \SSC_Database_Restore
 */
class DatabaseRestoreTest extends TestCase {

    private string $work_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->work_dir = $this->tmp_dir( '_db_restore' );
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->work_dir );
        parent::tearDown();
    }

    // ── Reescritura de prefijo ────────────────────────────────────────────────

    /**
     * Accede al método privado rewrite_prefix via Reflection.
     */
    private function call_rewrite_prefix( string $backup_prefix, string $current_prefix, string $line ): string {
        $restore = new \SSC_Database_Restore( '/dev/null', $backup_prefix, $current_prefix );
        $ref     = new \ReflectionMethod( $restore, 'rewrite_prefix' );
        return $ref->invoke( $restore, $line );
    }

    /** @test */
    public function it_rewrites_table_prefix_in_create_table(): void {
        $result = $this->call_rewrite_prefix(
            'wp_',
            'mysite_',
            'CREATE TABLE `wp_options` ('
        );
        $this->assertSame( 'CREATE TABLE `mysite_options` (', $result );
    }

    /** @test */
    public function it_rewrites_table_prefix_in_insert_into(): void {
        $result = $this->call_rewrite_prefix(
            'wp_',
            'mysite_',
            "INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES"
        );
        $this->assertSame( "INSERT INTO `mysite_posts` (`ID`, `post_title`) VALUES", $result );
    }

    /** @test */
    public function it_rewrites_table_prefix_in_drop_table(): void {
        $result = $this->call_rewrite_prefix(
            'wp_',
            'mysite_',
            'DROP TABLE IF EXISTS `wp_users`;'
        );
        $this->assertSame( 'DROP TABLE IF EXISTS `mysite_users`;', $result );
    }

    /** @test */
    public function it_does_not_change_line_when_prefixes_match(): void {
        $line   = 'CREATE TABLE `wp_options` (';
        $result = $this->call_rewrite_prefix( 'wp_', 'wp_', $line );
        $this->assertSame( $line, $result );
    }

    /** @test */
    public function it_does_not_alter_non_table_lines(): void {
        $line   = '-- Comment with wp_prefix mention';
        $result = $this->call_rewrite_prefix( 'wp_', 'new_', $line );
        // Solo reemplaza identificadores entre backticks.
        $this->assertSame( $line, $result );
    }

    /** @test */
    public function it_rewrites_multiple_tables_on_same_line(): void {
        $result = $this->call_rewrite_prefix(
            'wp_',
            'new_',
            'ALTER TABLE `wp_posts` ADD FOREIGN KEY (`author`) REFERENCES `wp_users`(`ID`);'
        );
        $this->assertSame(
            'ALTER TABLE `new_posts` ADD FOREIGN KEY (`author`) REFERENCES `new_users`(`ID`);',
            $result
        );
    }

    // ── Detección de fin de sentencia ─────────────────────────────────────────

    /**
     * Accede al método privado line_ends_statement via Reflection.
     */
    private function ends_statement( string $line ): bool {
        $restore = new \SSC_Database_Restore( '/dev/null', 'wp_', 'wp_' );
        $ref     = new \ReflectionMethod( $restore, 'line_ends_statement' );
        return $ref->invoke( $restore, $line );
    }

    /** @test */
    public function it_detects_statement_ending_with_semicolon(): void {
        $this->assertTrue( $this->ends_statement( "VALUES ('foo');  " ) );
    }

    /** @test */
    public function it_does_not_flag_lines_without_semicolon(): void {
        $this->assertFalse( $this->ends_statement( "VALUES ('foo')" ) );
    }

    /** @test */
    public function it_does_not_flag_empty_lines(): void {
        $this->assertFalse( $this->ends_statement( '' ) );
        $this->assertFalse( $this->ends_statement( "   \n" ) );
    }

    // ── Ejecución sobre archivo SQL real ─────────────────────────────────────

    /** @test */
    public function it_returns_error_when_sql_file_not_found(): void {
        $restore = new \SSC_Database_Restore( '/nonexistent/file.sql', 'wp_', 'wp_' );
        $result  = $restore->run();
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'sql_not_found', $result->get_error_code() );
    }
}
