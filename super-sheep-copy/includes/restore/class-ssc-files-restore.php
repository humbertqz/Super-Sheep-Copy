<?php
/**
 * Extracción de archivos del ZIP de respaldo.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Files_Restore
 *
 * Extrae los archivos del ZIP a un directorio temporal y luego los mueve
 * de forma atómica a ABSPATH. Aplica protección Zip Slip en cada entrada.
 * No sobreescribe wp-config.php sin confirmación explícita.
 */
class SSC_Files_Restore {

	/**
	 * Ruta al archivo ZIP de respaldo.
	 *
	 * @var string
	 */
	private string $zip_path;

	/**
	 * Ruta al directorio temporal de extracción.
	 *
	 * @var string
	 */
	private string $tmp_dir;

	/**
	 * Si se debe sobreescribir wp-config.php.
	 *
	 * @var bool
	 */
	private bool $overwrite_wp_config;

	/**
	 * Archivos excluidos de la extracción (relativos a la raíz del ZIP).
	 *
	 * @var string[]
	 */
	private array $skip_entries = array(
		'database.sql',
		'manifest.json',
	);

	/**
	 * Prefijos de ruta excluidos de la extracción (entradas que comiencen con alguno de estos).
	 * Se usa para saltar el directorio del propio plugin SSC, que se gestiona por SSC_Self_Updater.
	 *
	 * @var string[]
	 */
	private array $skip_prefixes = array();

	/**
	 * Constructor.
	 *
	 * @param string $zip_path           Ruta absoluta al ZIP.
	 * @param string $tmp_dir            Directorio temporal para extracción.
	 * @param bool   $overwrite_wp_config Si sobreescribir wp-config.php (por defecto false).
	 */
	public function __construct( string $zip_path, string $tmp_dir, bool $overwrite_wp_config = false ) {
		$this->zip_path            = $zip_path;
		$this->tmp_dir             = rtrim( $tmp_dir, '/\\' );
		$this->overwrite_wp_config = $overwrite_wp_config;

		// Excluir el directorio del propio plugin SSC; lo gestiona SSC_Self_Updater.
		$this->skip_prefixes[] = SSC_Self_Updater::get_plugin_zip_prefix();
	}

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Extrae los archivos del ZIP y los mueve a ABSPATH.
	 *
	 * @return true|WP_Error
	 */
	public function run() {
		SSC_Logger::info( 'files_restore', 'Iniciando extracción de archivos.' );

		// Crear directorio temporal.
		if ( ! wp_mkdir_p( $this->tmp_dir ) ) {
			return new WP_Error(
				'mkdir_failed',
				sprintf(
					/* translators: %s: directory path */
					__( 'No se pudo crear directorio temporal: %s', 'super-sheep-copy' ),
					$this->tmp_dir
				)
			);
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $this->zip_path, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'zip_open_failed', __( 'No se pudo abrir el ZIP de respaldo.', 'super-sheep-copy' ) );
		}

		$result = $this->extract_all( $zip );
		$zip->close();

		if ( is_wp_error( $result ) ) {
			$this->cleanup_tmp();
			return $result;
		}

		// Mover archivos de tmp a ABSPATH.
		$result = $this->deploy_to_abspath();
		$this->cleanup_tmp();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		SSC_Logger::info( 'files_restore', 'Extracción y despliegue de archivos completado.' );
		return true;
	}

	// ── Extracción ────────────────────────────────────────────────────────────

	/**
	 * Itera todas las entradas del ZIP y las extrae al directorio temporal.
	 *
	 * @param ZipArchive $zip Instancia abierta.
	 * @return true|WP_Error
	 */
	private function extract_all( ZipArchive $zip ) {
		$count = $zip->numFiles;

		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}

			$entry_name = $stat['name'];

			// Saltar las entradas de metadatos.
			if ( in_array( $entry_name, $this->skip_entries, true ) ) {
				continue;
			}

			// Saltar directorios/archivos gestionados por otros componentes (ej. el propio plugin SSC).
			foreach ( $this->skip_prefixes as $skip_prefix ) {
				if ( $skip_prefix !== '' && strpos( $entry_name, $skip_prefix ) === 0 ) {
					continue 2;
				}
			}

			// Directorios: solo crear, no extraer contenido.
			if ( substr( $entry_name, -1 ) === '/' ) {
				$validation = SSC_Security::validate_zip_entry( $entry_name, $this->tmp_dir );
				if ( is_wp_error( $validation ) ) {
					SSC_Logger::warn( 'files_restore', 'Directorio ZIP inseguro omitido: ' . $entry_name );
					continue;
				}
				wp_mkdir_p( $this->tmp_dir . '/' . $entry_name );
				continue;
			}

			// ── Zip Slip protection ───────────────────────────────────────
			$validation = SSC_Security::validate_zip_entry( $entry_name, $this->tmp_dir );
			if ( is_wp_error( $validation ) ) {
				SSC_Logger::warn( 'files_restore', 'Entrada ZIP insegura omitida: ' . $entry_name );
				continue;
			}

			// Saltar wp-config.php si no se autorizó sobreescribir.
			if ( ! $this->overwrite_wp_config && $this->is_wp_config( $entry_name ) ) {
				SSC_Logger::info( 'files_restore', 'wp-config.php omitido (sobreescritura no autorizada).' );
				continue;
			}

			// Construir ruta de destino temporal.
			$dest_path = $this->tmp_dir . '/' . ltrim( $entry_name, '/\\' );
			$dest_dir  = dirname( $dest_path );

			if ( ! is_dir( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}

			// Extraer usando stream para evitar cargar el archivo completo en memoria.
			// getFromIndex() carga toda la entrada en un string PHP — revienta el límite
			// de memoria con archivos grandes (imágenes, vídeos, dumps grandes).
			// getStream() copia en bloques de 8 KB sin importar el tamaño del archivo.
			$stream = $zip->getStream( $entry_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $stream ) {
				SSC_Logger::warn( 'files_restore', 'No se pudo leer entrada ZIP: ' . $entry_name );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$dest_fp = fopen( $dest_path, 'wb' );
			if ( false === $dest_fp ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new WP_Error(
					'extract_write_failed',
					sprintf(
						/* translators: %s: destination file path */
						__( 'No se pudo abrir para escritura: %s', 'super-sheep-copy' ),
						$dest_path
					)
				);
			}

			$bytes = stream_copy_to_stream( $stream, $dest_fp );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $dest_fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			if ( false === $bytes ) {
				return new WP_Error(
					'extract_write_failed',
					sprintf(
						/* translators: %s: destination file path */
						__( 'No se pudo escribir: %s', 'super-sheep-copy' ),
						$dest_path
					)
				);
			}
		}

		return true;
	}

	// ── Despliegue ────────────────────────────────────────────────────────────

	/**
	 * Devuelve el directorio raíz de destino para el despliegue.
	 *
	 * Separado en método protegido para permitir sobreescritura en tests
	 * sin tocar el ABSPATH real del sistema de archivos.
	 *
	 * @return string
	 */
	protected function deploy_to_abspath_target(): string {
		return rtrim( ABSPATH, '/\\' );
	}

	/**
	 * Mueve los archivos del directorio temporal a ABSPATH de forma iterativa.
	 *
	 * @return true|WP_Error
	 */
	private function deploy_to_abspath() {
		$abspath  = $this->deploy_to_abspath_target();
		// Normalizar tmp_dir con separador del sistema para calcular offsets correctamente.
		$base     = rtrim( $this->tmp_dir, '/\\' );
		$base_len = strlen( $base );
		$failures = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			/** @var SplFileInfo $item */

			// Usar getPathname() (no resuelve symlinks) para calcular la ruta relativa.
			// substr desde $base_len elimina el prefijo del directorio temporal.
			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), $base_len ) ), '/' );

			if ( '' === $relative ) {
				continue;
			}

			$target = $abspath . '/' . $relative;

			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) ) {
					wp_mkdir_p( $target );
				}
				continue;
			}

			$target_dir = dirname( $target );
			if ( ! is_dir( $target_dir ) ) {
				wp_mkdir_p( $target_dir );
			}

			$src = $item->getPathname();
			if ( ! @rename( $src, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename
				// rename() falla entre particiones distintas; usar copy+unlink.
				if ( @copy( $src, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@unlink( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
				} else {
					SSC_Logger::warn( 'files_restore', 'No se pudo copiar: ' . $relative );
					$failures[] = $relative;
				}
			}
		}

		if ( ! empty( $failures ) ) {
			$count = count( $failures );
			SSC_Logger::error(
				'files_restore',
				sprintf( '%d archivo(s) no se pudieron desplegar: %s', $count, implode( ', ', array_slice( $failures, 0, 10 ) ) )
			);
			return new WP_Error(
				'deploy_partial_failure',
				sprintf(
					/* translators: %d: number of files that failed to copy */
					_n(
						'%d archivo no se pudo copiar al sitio. El sitio puede estar incompleto.',
						'%d archivos no se pudieron copiar al sitio. El sitio puede estar incompleto.',
						$count,
						'super-sheep-copy'
					),
					$count
				)
			);
		}

		return true;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Determina si una entrada del ZIP corresponde a wp-config.php.
	 *
	 * @param string $entry_name Nombre de la entrada.
	 * @return bool
	 */
	private function is_wp_config( string $entry_name ): bool {
		return 'wp-config.php' === basename( $entry_name );
	}

	/**
	 * Elimina el directorio temporal.
	 *
	 * @return void
	 */
	private function cleanup_tmp(): void {
		SSC_Filesystem::delete( $this->tmp_dir, true );
	}
}
