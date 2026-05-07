<?php

/**
 * Tests para SSC_Zip_Writer.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );


namespace SSC\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @covers \SSC_Zip_Writer
 */
class ZipWriterTest extends TestCase {

    private string $work_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->work_dir = $this->tmp_dir( '_zip_writer' );
    }

    protected function tearDown(): void {
        $this->remove_dir( $this->work_dir );
        parent::tearDown();
    }

    /** @test */
    public function it_creates_a_zip_file(): void {
        $zip_path = $this->work_dir . '/test.zip';
        $writer   = new \SSC_Zip_Writer( $zip_path );

        $this->assertTrue( $writer->open() );
        $writer->add_from_string( 'hello.txt', 'Hello World' );
        $writer->close();

        $this->assertFileExists( $zip_path );
    }

    /** @test */
    public function it_adds_string_content(): void {
        $zip_path = $this->work_dir . '/test.zip';
        $writer   = new \SSC_Zip_Writer( $zip_path );
        $writer->open();
        $writer->add_from_string( 'manifest.json', '{"key":"value"}' );
        $writer->close();

        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::RDONLY );
        $content = $zip->getFromName( 'manifest.json' );
        $zip->close();

        $this->assertSame( '{"key":"value"}', $content );
    }

    /** @test */
    public function it_adds_a_real_file(): void {
        $src_file = $this->work_dir . '/source.txt';
        file_put_contents( $src_file, 'file content' );

        $zip_path = $this->work_dir . '/test.zip';
        $writer   = new \SSC_Zip_Writer( $zip_path );
        $writer->open();
        $writer->add_file( $src_file, 'subdir/source.txt' );
        $writer->close();

        $zip = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::RDONLY );
        $content = $zip->getFromName( 'subdir/source.txt' );
        $zip->close();

        $this->assertSame( 'file content', $content );
    }

    /** @test */
    public function it_counts_added_files(): void {
        $zip_path = $this->work_dir . '/test.zip';
        $writer   = new \SSC_Zip_Writer( $zip_path );
        $writer->open();
        $writer->add_from_string( 'a.txt', 'aaa' );
        $writer->add_from_string( 'b.txt', 'bbb' );
        $writer->close();

        $this->assertSame( 2, $writer->get_file_count() );
    }

    /** @test */
    public function it_writes_sha256_checksum_file(): void {
        $zip_path = $this->work_dir . '/test.zip';
        $writer   = new \SSC_Zip_Writer( $zip_path );
        $writer->open();
        $writer->add_from_string( 'file.txt', 'content' );
        $writer->close();

        $hash = $writer->write_checksum();

        $this->assertIsString( $hash );
        $this->assertSame( 64, strlen( $hash ) ); // SHA-256 hex = 64 chars
        $this->assertFileExists( $zip_path . '.sha256' );
        $this->assertSame( $hash, trim( file_get_contents( $zip_path . '.sha256' ) ) );
    }

    /** @test */
    public function checksum_matches_actual_file_hash(): void {
        $zip_path = $this->work_dir . '/test.zip';
        $writer   = new \SSC_Zip_Writer( $zip_path );
        $writer->open();
        $writer->add_from_string( 'x.txt', 'x' );
        $writer->close();

        $hash_from_writer = $writer->write_checksum();
        $hash_direct      = hash_file( 'sha256', $zip_path );

        $this->assertSame( $hash_direct, $hash_from_writer );
    }

    /** @test */
    public function open_returns_error_on_invalid_path(): void {
        $writer = new \SSC_Zip_Writer( '/nonexistent/path/test.zip' );
        $result = $writer->open();
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** @test */
    public function add_file_warns_and_continues_for_nonexistent_file(): void {
        $zip_path = $this->work_dir . '/test.zip';
        $writer   = new \SSC_Zip_Writer( $zip_path );
        $writer->open();
        $result = $writer->add_file( '/nonexistent/file.txt', 'file.txt' );
        $writer->close();

        // Devuelve true (no aborta el backup por un archivo no legible).
        $this->assertTrue( $result );
    }

    // ── Integridad del archivo ZIP ────────────────────────────────────────────

    /** @test */
    public function zip_can_be_reopened_after_close(): void {
        $zip_path = $this->work_dir . '/integrity.zip';
        $writer   = new \SSC_Zip_Writer( $zip_path );
        $writer->open();
        $writer->add_from_string( 'data.txt', 'some data' );
        $writer->close();

        // Reabrir en modo lectura verifica que el ZIP no está corrupto.
        $zip    = new \ZipArchive();
        $opened = $zip->open( $zip_path, \ZipArchive::RDONLY );
        $zip->close();

        $this->assertTrue( $opened, 'ZIP should be openable after close (not corrupt).' );
    }

    /** @test */
    public function zip_count_reflects_number_of_added_entries(): void {
        $zip_path = $this->work_dir . '/count.zip';
        $writer   = new \SSC_Zip_Writer( $zip_path );
        $writer->open();
        $writer->add_from_string( 'one.txt', 'a' );
        $writer->add_from_string( 'two.txt', 'b' );
        $writer->add_from_string( 'three.txt', 'c' );
        $writer->close();

        $zip   = new \ZipArchive();
        $zip->open( $zip_path, \ZipArchive::RDONLY );
        $count = $zip->count();
        $zip->close();

        $this->assertSame( 3, $count );
        $this->assertSame( 3, $writer->get_file_count() );
    }
}
