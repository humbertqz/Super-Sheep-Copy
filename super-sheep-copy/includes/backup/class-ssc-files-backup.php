<?php
/**
 * Empaquetado de archivos del sitio en el ZIP de respaldo.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Files_Backup
 *
 * Recorre ABSPATH con RecursiveIteratorIterator y añade cada archivo
 * al SSC_Zip_Writer, aplicando las exclusiones configuradas.
 */
class SSC_Files_Backup {

	/**
	 * Instancia del writer ZIP.
	 *
	 * @var SSC_Zip_Writer
	 */
	private SSC_Zip_Writer $zip;

	/**
	 * Configuración del respaldo.
	 *
	 * @var array
	 */
	private array $settings;

	/**
	 * Lista de directorios/archivos excluidos (rutas absolutas o patrones).
	 *
	 * @var string[]
	 */
	private array $excluded_paths = array();

	/**
	 * Número de archivos añadidos.
	 *
	 * @var int
	 */
	private int $file_count = 0;

	/**
	 * Constructor.
	 *
	 * @param SSC_Zip_Writer $zip      Instancia del writer ZIP ya abierta.
	 * @param array          $settings Opciones del plugin (ssc_settings).
	 */
	public function __construct( SSC_Zip_Writer $zip, array $settings = array() ) {
		$this->zip      = $zip;
		$this->settings = wp_parse_args(
			$settings,
			array(
				'include_core' => 1,
				'exclude_logs' => 1,
			)
		);
		$this->build_excluded_paths();
	}

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Recorre el sistema de archivos y añade todo al ZIP.
	 *
	 * @return true|WP_Error
	 */
	public function run() {
		SSC_Logger::info( 'files_backup', 'Iniciando empaquetado de archivos.' );

		$abspath = rtrim( ABSPATH, '/\\' );

		try {
			$dir_iterator = new RecursiveDirectoryIterator(
				$abspath,
				RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
			);
			$iterator = new RecursiveIteratorIterator(
				$dir_iterator,
				RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'iterator_failed', $e->getMessage() );
		}

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}

			$real_path = $file->getRealPath();
			if ( false === $real_path ) {
				continue;
			}

			// Aplicar exclusiones.
			if ( $this->is_excluded( $real_path ) ) {
				continue;
			}

			// Ruta relativa dentro del ZIP (siempre con slash forward).
			$local_name = ltrim( str_replace( $abspath, '', $real_path ), '/\\' );
			$local_name = str_replace( '\\', '/', $local_name );

			$result = $this->zip->add_file( $real_path, $local_name );
			if ( is_wp_error( $result ) ) {
				// Loggear pero no abortar: continuar con el resto de archivos.
				SSC_Logger::warn( 'files_backup', $result->get_error_message() );
				continue;
			}

			++$this->file_count;
		}

		SSC_Logger::info( 'files_backup', sprintf( 'Empaquetado completado. Archivos añadidos: %d', $this->file_count ) );
		return true;
	}

	/**
	 * Devuelve el número de archivos añadidos.
	 *
	 * @return int
	 */
	public function get_file_count(): int {
		return $this->file_count;
	}

	// ── Exclusiones ───────────────────────────────────────────────────────────

	/**
	 * Construye la lista de rutas/directorios excluidos.
	 *
	 * @return void
	 */
	private function build_excluded_paths(): void {
		// CRÍTICO: excluir el propio directorio de respaldos para evitar recursión.
		$this->excluded_paths[] = realpath( SSC_BACKUPS_DIR ) ?: SSC_BACKUPS_DIR;

		// Excluir wp-admin y wp-includes si include_core está desactivado.
		if ( empty( $this->settings['include_core'] ) ) {
			$this->excluded_paths[] = realpath( ABSPATH . 'wp-admin' )    ?: ABSPATH . 'wp-admin';
			$this->excluded_paths[] = realpath( ABSPATH . 'wp-includes' ) ?: ABSPATH . 'wp-includes';
		}

		// Directorios de otros plugins de backup conocidos.
		$known_backup_dirs = array(
			WP_CONTENT_DIR . '/updraft',
			WP_CONTENT_DIR . '/backwpup-*',
			WP_CONTENT_DIR . '/uploads/backupbuddy_backups',
			WP_CONTENT_DIR . '/uploads/backwpup-*',
		);
		foreach ( $known_backup_dirs as $dir ) {
			$real = realpath( $dir );
			if ( $real ) {
				$this->excluded_paths[] = $real;
			}
		}

		// Directorio temporal del plugin.
		$tmp_dir = realpath( WP_CONTENT_DIR . '/uploads/ssc-tmp-' ) ?: '';
		if ( $tmp_dir ) {
			$this->excluded_paths[] = $tmp_dir;
		}
	}

	/**
	 * Determina si un archivo/directorio debe excluirse.
	 *
	 * @param string $real_path Ruta absoluta real del archivo.
	 * @return bool
	 */
	private function is_excluded( string $real_path ): bool {
		// Comprobar si está dentro de algún directorio excluido.
		foreach ( $this->excluded_paths as $excluded ) {
			if ( ! $excluded ) {
				continue;
			}
			$excluded = rtrim( $excluded, '/\\' );
			if ( strpos( $real_path, $excluded ) === 0 ) {
				return true;
			}
		}

		// Excluir archivos temporales y basura del sistema.
		$basename = basename( $real_path );
		$static_excludes = array( '.DS_Store', 'Thumbs.db', 'desktop.ini' );
		if ( in_array( $basename, $static_excludes, true ) ) {
			return true;
		}

		// Excluir archivos .tmp.
		if ( substr( $basename, -4 ) === '.tmp' ) {
			return true;
		}

		// Excluir archivos .log grandes (> 5 MB) si la opción está activa.
		if ( ! empty( $this->settings['exclude_logs'] ) ) {
			if ( substr( $basename, -4 ) === '.log' && filesize( $real_path ) > 5 * 1024 * 1024 ) {
				return true;
			}
		}

		return false;
	}
}
