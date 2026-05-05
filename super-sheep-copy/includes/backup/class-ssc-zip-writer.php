<?php
/**
 * Wrapper sobre ZipArchive con soporte de streaming y control de errores.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Zip_Writer
 *
 * Encapsula ZipArchive para:
 *  - Abrir/cerrar con manejo de errores claro.
 *  - Añadir archivos comprobando el resultado de cada operación.
 *  - Añadir contenido en memoria (para manifest.json, database.sql, etc.).
 *  - Calcular el SHA-256 del ZIP final y guardarlo en un .sha256 adyacente.
 */
class SSC_Zip_Writer {

	/**
	 * Instancia de ZipArchive.
	 *
	 * @var ZipArchive
	 */
	private ZipArchive $zip;

	/**
	 * Ruta absoluta al archivo ZIP de destino.
	 *
	 * @var string
	 */
	private string $zip_path;

	/**
	 * Indica si el ZIP está abierto.
	 *
	 * @var bool
	 */
	private bool $is_open = false;

	/**
	 * Número de archivos añadidos.
	 *
	 * @var int
	 */
	private int $file_count = 0;

	/**
	 * Constructor.
	 *
	 * @param string $zip_path Ruta absoluta al ZIP que se va a crear.
	 */
	public function __construct( string $zip_path ) {
		$this->zip_path = $zip_path;
		$this->zip      = new ZipArchive();
	}

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Abre (crea o sobreescribe) el archivo ZIP.
	 *
	 * @return true|WP_Error
	 */
	public function open() {
		// Verificar que el directorio padre existe antes de intentar crear el ZIP.
		if ( ! is_dir( dirname( $this->zip_path ) ) ) {
			return new WP_Error(
				'zip_open_failed',
				sprintf(
					/* translators: 1: path */
					__( 'No se pudo crear el archivo ZIP en %1$s (directorio no existe).', 'super-sheep-copy' ),
					$this->zip_path
				)
			);
		}

		$result = $this->zip->open( $this->zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $result ) {
			return new WP_Error(
				'zip_open_failed',
				sprintf(
					/* translators: 1: path, 2: error code */
					__( 'No se pudo crear el archivo ZIP en %1$s (código: %2$d).', 'super-sheep-copy' ),
					$this->zip_path,
					$result
				)
			);
		}

		$this->is_open    = true;
		$this->file_count = 0;
		return true;
	}

	/**
	 * Añade un archivo del sistema de archivos al ZIP.
	 *
	 * @param string $real_path   Ruta absoluta del archivo a añadir.
	 * @param string $local_name  Nombre/ruta dentro del ZIP.
	 * @return true|WP_Error
	 */
	public function add_file( string $real_path, string $local_name ) {
		if ( ! $this->is_open ) {
			return new WP_Error( 'zip_not_open', __( 'El ZIP no está abierto.', 'super-sheep-copy' ) );
		}

		if ( ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
			// Archivo no legible: advertir y continuar (no abortar el backup completo).
			SSC_Logger::warn( 'zip_writer', 'Archivo no legible, omitido: ' . $real_path );
			return true;
		}

		$added = $this->zip->addFile( $real_path, $local_name );
		if ( ! $added ) {
			return new WP_Error(
				'zip_add_failed',
				sprintf( __( 'No se pudo añadir al ZIP: %s', 'super-sheep-copy' ), $real_path )
			);
		}

		++$this->file_count;
		return true;
	}

	/**
	 * Añade contenido de una cadena directamente al ZIP.
	 *
	 * @param string $local_name Nombre/ruta dentro del ZIP.
	 * @param string $content    Contenido a escribir.
	 * @return true|WP_Error
	 */
	public function add_from_string( string $local_name, string $content ) {
		if ( ! $this->is_open ) {
			return new WP_Error( 'zip_not_open', __( 'El ZIP no está abierto.', 'super-sheep-copy' ) );
		}

		$added = $this->zip->addFromString( $local_name, $content );
		if ( ! $added ) {
			return new WP_Error(
				'zip_add_string_failed',
				sprintf( __( 'No se pudo añadir la cadena al ZIP como: %s', 'super-sheep-copy' ), $local_name )
			);
		}

		++$this->file_count;
		return true;
	}

	/**
	 * Cierra el archivo ZIP.
	 *
	 * @return true|WP_Error
	 */
	public function close() {
		if ( ! $this->is_open ) {
			return true;
		}

		$closed = $this->zip->close();
		if ( ! $closed ) {
			return new WP_Error( 'zip_close_failed', __( 'No se pudo cerrar el archivo ZIP correctamente.', 'super-sheep-copy' ) );
		}

		$this->is_open = false;
		return true;
	}

	/**
	 * Calcula el SHA-256 del ZIP cerrado y guarda el hash en un archivo .sha256.
	 *
	 * Debe llamarse DESPUÉS de close().
	 *
	 * @return string|WP_Error Hash SHA-256 en hexadecimal o WP_Error.
	 */
	public function write_checksum() {
		if ( ! file_exists( $this->zip_path ) ) {
			return new WP_Error( 'zip_not_found', __( 'El archivo ZIP no existe para calcular checksum.', 'super-sheep-copy' ) );
		}

		$hash = hash_file( 'sha256', $this->zip_path );
		if ( false === $hash ) {
			return new WP_Error( 'hash_failed', __( 'No se pudo calcular el SHA-256 del ZIP.', 'super-sheep-copy' ) );
		}

		$sha_file = $this->zip_path . '.sha256';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $sha_file, $hash );

		return $hash;
	}

	/**
	 * Devuelve el número de archivos añadidos al ZIP en esta sesión.
	 *
	 * @return int
	 */
	public function get_file_count(): int {
		return $this->file_count;
	}

	/**
	 * Devuelve la ruta del ZIP.
	 *
	 * @return string
	 */
	public function get_zip_path(): string {
		return $this->zip_path;
	}
}
