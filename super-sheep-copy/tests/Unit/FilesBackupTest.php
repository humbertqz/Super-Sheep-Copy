<?php

/**
 * Tests para SSC_Files_Backup — run() e is_excluded().
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );


namespace SSC\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @covers \SSC_Files_Backup
 */
class FilesBackupTest extends TestCase {

	private string $work_dir;

	/** Archivo de referencia siempre presente bajo ABSPATH para que el ZIP nunca quede vacío. */
	private string $canary_file;

	protected function setUp(): void {
		parent::setUp();
		$this->work_dir   = $this->tmp_dir( '_files_backup' );
		$this->canary_file = ABSPATH . 'ssc_canary_' . uniqid() . '.txt';
		file_put_contents( $this->canary_file, 'canary' );
	}

	protected function tearDown(): void {
		@unlink( $this->canary_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->remove_dir( $this->work_dir );
		parent::tearDown();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Crea un SSC_Zip_Writer abierto y devuelve [writer, zip_path].
	 */
	private function make_open_writer(): array {
		$zip_path = $this->work_dir . '/test.zip';
		$writer   = new \SSC_Zip_Writer( $zip_path );
		$writer->open();
		return array( $writer, $zip_path );
	}

	/**
	 * Devuelve los nombres de entradas de un ZIP, o [] si el archivo no existe o es inválido.
	 *
	 * @param string $zip_path Ruta al ZIP.
	 * @return string[]
	 */
	private function get_zip_entries( string $zip_path ): array {
		if ( ! file_exists( $zip_path ) ) {
			return array();
		}
		$zip    = new \ZipArchive();
		$result = $zip->open( $zip_path, \ZipArchive::RDONLY );
		if ( $result !== true ) {
			return array();
		}
		$entries = array();
		for ( $i = 0; $i < $zip->count(); $i++ ) {
			$entries[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		return $entries;
	}

	/**
	 * Devuelve true si alguna entrada del ZIP tiene un basename igual a $filename.
	 */
	private function entries_contain_basename( array $entries, string $filename ): bool {
		foreach ( $entries as $entry ) {
			if ( basename( $entry ) === $filename ) {
				return true;
			}
		}
		return false;
	}

	// ── run() — inclusión de archivos ─────────────────────────────────────────

	/** @test */
	public function test_run_includes_all_site_files(): void {
		$uid   = uniqid( 'fbtest_' );
		$file1 = ABSPATH . $uid . '_a.txt';
		$file2 = ABSPATH . $uid . '_b.txt';
		file_put_contents( $file1, 'content A' );
		file_put_contents( $file2, 'content B' );

		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer );
		$backup->run();
		$writer->close();

		@unlink( $file1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $file2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$entries = $this->get_zip_entries( $zip_path );
		$this->assertTrue(
			$this->entries_contain_basename( $entries, $uid . '_a.txt' ),
			"ZIP should contain a file with basename '{$uid}_a.txt'."
		);
		$this->assertTrue(
			$this->entries_contain_basename( $entries, $uid . '_b.txt' ),
			"ZIP should contain a file with basename '{$uid}_b.txt'."
		);
	}

	/** @test */
	public function test_run_file_count_matches_zip_entries(): void {
		// canary_file existe en ABSPATH — garantiza al menos una entrada en el ZIP.
		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer );
		$backup->run();
		$writer->close();

		$zip_count = 0;
		if ( file_exists( $zip_path ) ) {
			$zip = new \ZipArchive();
			if ( $zip->open( $zip_path, \ZipArchive::RDONLY ) === true ) {
				$zip_count = $zip->count();
				$zip->close();
			}
		}

		$this->assertSame( $zip_count, $backup->get_file_count() );
	}

	/** @test */
	public function test_run_file_content_integrity(): void {
		$uid           = uniqid( 'fbtest_' );
		$known_content = 'integrity-check-' . $uid;
		$file_path     = ABSPATH . $uid . '.txt';
		file_put_contents( $file_path, $known_content );

		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer );
		$backup->run();
		$writer->close();

		@unlink( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$zip = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::RDONLY );

		// Buscar la entrada por basename (resiliente a prefijos de ruta).
		$zip_content = false;
		for ( $i = 0; $i < $zip->count(); $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( basename( $name ) === $uid . '.txt' ) {
				$zip_content = $zip->getFromIndex( $i );
				break;
			}
		}
		$zip->close();

		$this->assertSame( $known_content, $zip_content );
	}

	/** @test */
	public function test_run_paths_use_forward_slashes(): void {
		$uid    = uniqid( 'fbtest_' );
		$subdir = ABSPATH . $uid . '_dir';
		@mkdir( $subdir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		file_put_contents( $subdir . '/nested.txt', 'nested' );

		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer );
		$backup->run();
		$writer->close();

		$this->remove_dir( $subdir );

		$entries = $this->get_zip_entries( $zip_path );
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( '\\', $entry, "ZIP entry '{$entry}' contains a backslash." );
		}
	}

	// ── run() — exclusiones estáticas ─────────────────────────────────────────

	/** @test */
	public function test_run_excludes_ds_store_files(): void {
		$ds_store = ABSPATH . '.DS_Store';
		file_put_contents( $ds_store, 'metadata junk' );

		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer );
		$backup->run();
		$writer->close();

		@unlink( $ds_store ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$entries = $this->get_zip_entries( $zip_path );
		$this->assertFalse(
			$this->entries_contain_basename( $entries, '.DS_Store' ),
			'.DS_Store should not appear in ZIP entries.'
		);
	}

	/** @test */
	public function test_run_excludes_tmp_files(): void {
		$uid      = uniqid( 'fbtest_' );
		$tmp_file = ABSPATH . $uid . '.tmp';
		file_put_contents( $tmp_file, 'ephemeral' );

		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer );
		$backup->run();
		$writer->close();

		@unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$entries = $this->get_zip_entries( $zip_path );
		$this->assertFalse(
			$this->entries_contain_basename( $entries, $uid . '.tmp' ),
			"'.tmp' files should be excluded from backup."
		);
	}

	// ── run() — exclusión de logs grandes ─────────────────────────────────────

	/** @test */
	public function test_run_excludes_log_files_over_5mb(): void {
		$uid      = uniqid( 'fbtest_' );
		$log_file = ABSPATH . $uid . '.log';

		// Crear archivo de exactamente (5 MB + 1 byte).
		$handle = fopen( $log_file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, str_repeat( 'x', 5 * 1024 * 1024 + 1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer, array( 'exclude_logs' => 1 ) );
		$backup->run();
		$writer->close();

		@unlink( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$entries = $this->get_zip_entries( $zip_path );
		$this->assertFalse(
			$this->entries_contain_basename( $entries, $uid . '.log' ),
			'Log files over 5 MB should be excluded when exclude_logs=1.'
		);
	}

	/** @test */
	public function test_run_includes_log_files_under_5mb(): void {
		$uid      = uniqid( 'fbtest_' );
		$log_file = ABSPATH . $uid . '.log';
		file_put_contents( $log_file, 'small log' );

		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer, array( 'exclude_logs' => 1 ) );
		$backup->run();
		$writer->close();

		@unlink( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$entries = $this->get_zip_entries( $zip_path );
		$this->assertTrue(
			$this->entries_contain_basename( $entries, $uid . '.log' ),
			'Log files under 5 MB should be included when exclude_logs=1.'
		);
	}

	// ── run() — exclusión de core cuando include_core = 0 ────────────────────

	/** @test */
	public function test_run_excludes_core_dirs_when_include_core_is_disabled(): void {
		$uid         = uniqid( 'fbtest_' );
		$wp_admin    = ABSPATH . 'wp-admin';
		$wp_includes = ABSPATH . 'wp-includes';

		// Los directorios deben existir ANTES de que SSC_Files_Backup se construya
		// para que realpath() los resuelva al llamar a build_excluded_paths().
		@mkdir( $wp_admin, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		@mkdir( $wp_includes, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		file_put_contents( $wp_admin . '/' . $uid . '.php', '<?php' );
		file_put_contents( $wp_includes . '/' . $uid . '.php', '<?php' );

		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer, array( 'include_core' => 0 ) );
		$backup->run();
		$writer->close();

		@unlink( $wp_admin . '/' . $uid . '.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $wp_includes . '/' . $uid . '.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$entries = $this->get_zip_entries( $zip_path );
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'wp-admin/', $entry, "wp-admin should be excluded but found: {$entry}" );
			$this->assertStringNotContainsString( 'wp-includes/', $entry, "wp-includes should be excluded but found: {$entry}" );
		}
	}

	// ── run() — archivo no legible no debe contarse ──────────────────────────

	/** @test */
	public function test_run_does_not_count_unreadable_files(): void {
		if ( function_exists( 'posix_getuid' ) && posix_getuid() === 0 ) {
			$this->markTestSkipped( 'Los permisos de archivo no funcionan como root.' );
		}

		$uid       = uniqid( 'fbtest_' );
		$good_file = ABSPATH . $uid . '_good.txt';
		$bad_file  = ABSPATH . $uid . '_bad.txt';
		file_put_contents( $good_file, 'readable' );
		file_put_contents( $bad_file, 'unreadable' );
		chmod( $bad_file, 0000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer );
		$backup->run();
		$writer->close();

		chmod( $bad_file, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		@unlink( $good_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $bad_file );  // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$zip_count = 0;
		if ( file_exists( $zip_path ) ) {
			$zip = new \ZipArchive();
			if ( $zip->open( $zip_path, \ZipArchive::RDONLY ) === true ) {
				$zip_count = $zip->count();
				$zip->close();
			}
		}

		$this->assertSame(
			$zip_count,
			$backup->get_file_count(),
			'get_file_count() debe coincidir con ZipArchive::count() aunque haya archivos no legibles.'
		);
	}

	// ── is_excluded() — directorio de respaldos ───────────────────────────────

	/** @test */
	public function test_is_excluded_returns_true_for_backup_directory_paths(): void {
		[ $writer ] = $this->make_open_writer();
		$writer->close();
		$backup = new \SSC_Files_Backup( $writer );

		$is_excluded         = new \ReflectionMethod( \SSC_Files_Backup::class, 'is_excluded' );
		$excluded_paths_prop = new \ReflectionProperty( \SSC_Files_Backup::class, 'excluded_paths' );
		$excluded_paths      = $excluded_paths_prop->getValue( $backup );

		$this->assertNotEmpty( $excluded_paths, 'excluded_paths should not be empty.' );

		// Un archivo bajo el directorio de respaldos debe ser excluido.
		$backup_subpath = rtrim( $excluded_paths[0], '/\\' ) . '/backup-20260101-abcd1234.zip';
		$this->assertTrue( $is_excluded->invoke( $backup, $backup_subpath ) );
	}

	// ── run() — procesamiento en chunks ──────────────────────────────────────

	/** @test */
	public function test_chunked_run_produces_same_result_as_single_run(): void {
		$uid   = uniqid( 'chunk_' );
		$files = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$path           = ABSPATH . $uid . "_file{$i}.txt";
			$files[ $path ] = "content-{$i}";
			file_put_contents( $path, "content-{$i}" );
		}

		[ $writer, $zip_path ] = $this->make_open_writer();
		// chunk_size=1 forces a flush after every single file.
		$backup = new \SSC_Files_Backup( $writer, array( 'chunk_size' => 1 ) );
		$result = $backup->run();
		$writer->close();

		foreach ( $files as $path => $_ ) {
			@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$this->assertTrue( $result, 'run() should return true.' );

		$zip = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::RDONLY );
		for ( $i = 1; $i <= 5; $i++ ) {
			$found = false;
			for ( $j = 0; $j < $zip->count(); $j++ ) {
				if ( basename( $zip->getNameIndex( $j ) ) === $uid . "_file{$i}.txt" ) {
					$this->assertSame( "content-{$i}", $zip->getFromIndex( $j ) );
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, "File {$i} missing from chunked ZIP." );
		}
		$zip->close();
	}

	/** @test */
	public function test_zip_remains_open_after_run(): void {
		[ $writer, $zip_path ] = $this->make_open_writer();
		$backup = new \SSC_Files_Backup( $writer, array( 'chunk_size' => 2 ) );
		$backup->run();

		// ZIP must still be open so BackupManager can add manifest.json without reopening.
		$add_result = $writer->add_from_string( 'manifest.json', '{"status":"ok"}' );
		$writer->close();

		$this->assertTrue( $add_result, 'ZIP should still be open after run() returns.' );

		$zip      = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::RDONLY );
		$manifest = $zip->getFromName( 'manifest.json' );
		$zip->close();

		$this->assertSame( '{"status":"ok"}', $manifest );
	}

	/** @test */
	public function test_chunk_size_default_equals_class_constant(): void {
		[ $writer ] = $this->make_open_writer();
		$writer->close();

		$backup        = new \SSC_Files_Backup( $writer );
		$settings_prop = new \ReflectionProperty( \SSC_Files_Backup::class, 'settings' );
		$settings      = $settings_prop->getValue( $backup );

		$this->assertSame( \SSC_Files_Backup::FILES_PER_CHUNK, $settings['chunk_size'] );

		$backup_custom   = new \SSC_Files_Backup( $writer, array( 'chunk_size' => 50 ) );
		$settings_custom = $settings_prop->getValue( $backup_custom );
		$this->assertSame( 50, $settings_custom['chunk_size'] );
	}
}
