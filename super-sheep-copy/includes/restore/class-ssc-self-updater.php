<?php
/**
 * Reemplazo del propio plugin SSC desde el respaldo.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Self_Updater
 *
 * Gestiona el reemplazo diferido del propio plugin SSC al restaurar un respaldo.
 *
 * Problema: PHP no puede sobreescribir archivos de un plugin mientras éste está
 * en ejecución en la misma petición. Solución en dos fases:
 *
 *  Fase 1 (durante la restauración):
 *   - Excluir el directorio del plugin SSC del despliegue normal de archivos.
 *   - Extraer el directorio del plugin SSC del backup a un directorio de staging
 *     dentro de SSC_BACKUPS_DIR.
 *   - Guardar la ruta de staging en una wp_option para persistirla entre peticiones.
 *
 *  Fase 2 (siguiente carga del admin):
 *   - Detectar la wp_option con el pendiente.
 *   - Copiar los archivos del staging al directorio real del plugin.
 *   - Limpiar staging y la option.
 *   - Redirigir para forzar un reload completo de PHP.
 */
class SSC_Self_Updater {

	/**
	 * Nombre del archivo JSON de pendiente dentro de SSC_BACKUPS_DIR.
	 * Archivo en disco en vez de wp_option: sobrevive al import de DB en step 5.
	 */
	const PENDING_FILE = '.ssc-pending-self-update.json';

	/**
	 * Prefijo del directorio de staging dentro de SSC_BACKUPS_DIR.
	 */
	const STAGED_DIR_PREFIX = '.ssc-staged-plugin-';

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Retorna la ruta relativa a ABSPATH que ocupa el plugin SSC dentro del ZIP.
	 * Ejemplo: "wp-content/plugins/super-sheep-copy/"
	 *
	 * @return string Ruta con barra final, usando separador "/".
	 */
	public static function get_plugin_zip_prefix(): string {
		$rel = str_replace( '\\', '/', str_replace( ABSPATH, '', SSC_PLUGIN_DIR ) );
		return ltrim( $rel, '/' );
	}

	/**
	 * Extrae el directorio del plugin SSC del ZIP de respaldo a un directorio
	 * de staging y registra el pendiente en un archivo JSON en SSC_BACKUPS_DIR.
	 *
	 * Si el backup no contiene el plugin SSC, o si su versión coincide con la
	 * actual, no hace nada y devuelve false.
	 *
	 * @param string $zip_path Ruta absoluta al ZIP de respaldo.
	 * @param string $job_id   ID de job de restauración.
	 * @return bool|WP_Error true si se programó el swap, false si no es necesario, WP_Error en fallo.
	 */
	public static function stage_from_zip( string $zip_path, string $job_id ) {
		$prefix     = self::get_plugin_zip_prefix();
		$staged_dir = rtrim( SSC_BACKUPS_DIR, '/\\' ) . '/' . self::STAGED_DIR_PREFIX . $job_id;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'zip_open_failed', __( 'No se pudo abrir el ZIP para extraer el plugin SSC.', 'super-sheep-copy' ) );
		}

		// Buscar primero la versión del plugin dentro del backup para decidir si hay que actualizar.
		$backup_version = self::read_backup_plugin_version( $zip, $prefix );

		if ( null !== $backup_version && version_compare( $backup_version, SSC_VERSION, '==' ) ) {
			$zip->close();
			SSC_Logger::info( 'self_updater', sprintf( 'Plugin SSC en el respaldo tiene la misma versión (%s); no se requiere reemplazo.', $backup_version ) );
			return false;
		}

		// Extraer todos los archivos del plugin al directorio de staging.
		$extracted = self::extract_plugin_entries( $zip, $prefix, $staged_dir );
		$zip->close();

		if ( is_wp_error( $extracted ) ) {
			return $extracted;
		}

		if ( ! $extracted ) {
			SSC_Logger::info( 'self_updater', 'El respaldo no contiene el directorio del plugin SSC; no se requiere reemplazo.' );
			return false;
		}

		// Persist via flat file — wp_option would be wiped by the DB import (step 5).
		$pending_file = self::pending_file_path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$pending_file,
			wp_json_encode(
				array(
					'staged_dir'     => $staged_dir,
					'job_id'         => $job_id,
					'backup_version' => $backup_version,
					'timestamp'      => time(),
				)
			)
		);

		SSC_Logger::info(
			'self_updater',
			sprintf(
				'Plugin SSC del respaldo (v%s) preparado en staging. Se aplicará en la próxima carga del admin.',
				$backup_version ?? 'desconocida'
			)
		);

		return true;
	}

	/**
	 * Comprueba si hay un swap pendiente y lo aplica.
	 * Debe engancharse a `admin_init` con prioridad alta.
	 *
	 * @return void
	 */
	public static function maybe_apply_pending_swap(): void {
		if ( ! current_user_can( 'manage_ssc_backups' ) ) {
			return;
		}

		$pending_file = self::pending_file_path();
		if ( ! file_exists( $pending_file ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw     = file_get_contents( $pending_file );
		$pending = $raw ? json_decode( $raw, true ) : null;

		// Delete file first — prevents infinite loop if swap fails mid-way.
		@unlink( $pending_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $pending || ! is_array( $pending ) ) {
			return;
		}

		$staged_dir = $pending['staged_dir'] ?? '';

		if ( ! $staged_dir || ! is_dir( $staged_dir ) ) {
			SSC_Logger::warn( 'self_updater', 'Directorio de staging no encontrado; se cancela el reemplazo del plugin.' );
			return;
		}

		// Validar que el staged_dir esté dentro de SSC_BACKUPS_DIR (prevenir path traversal).
		$real_staged  = realpath( $staged_dir );
		$real_backups = realpath( rtrim( SSC_BACKUPS_DIR, '/\\' ) );

		if ( ! $real_staged || ! $real_backups || strpos( $real_staged, $real_backups ) !== 0 ) {
			SSC_Logger::error( 'self_updater', 'Ruta de staging fuera del directorio de respaldos; se cancela el reemplazo.' );
			SSC_Filesystem::delete( $staged_dir, true );
			return;
		}

		$plugin_dir = rtrim( SSC_PLUGIN_DIR, '/\\' );
		$success    = self::copy_directory( $staged_dir, $plugin_dir );

		// Limpiar staging independientemente del resultado.
		SSC_Filesystem::delete( $staged_dir, true );

		if ( $success ) {
			SSC_Logger::info( 'self_updater', 'Plugin SSC reemplazado correctamente desde el respaldo.' );
			wp_safe_redirect( admin_url( 'admin.php?page=super-sheep-copy&ssc_self_updated=1' ) );
			exit;
		}

		SSC_Logger::error( 'self_updater', 'No se pudieron copiar los archivos del plugin SSC desde el staging.' );
		set_transient( 'ssc_self_update_failed', 1, 60 );
	}

	// ── Helpers privados ──────────────────────────────────────────────────────

	/**
	 * Ruta absoluta al archivo JSON de pendiente.
	 * Dentro de SSC_BACKUPS_DIR → sobrevive al import de DB.
	 *
	 * @return string
	 */
	private static function pending_file_path(): string {
		return rtrim( SSC_BACKUPS_DIR, '/\\' ) . '/' . self::PENDING_FILE;
	}

	/**
	 * Lee la versión del plugin SSC contenida en el backup leyendo la cabecera
	 * del archivo principal del plugin dentro del ZIP.
	 *
	 * @param ZipArchive $zip    Instancia abierta del ZIP.
	 * @param string     $prefix Prefijo del plugin SSC dentro del ZIP.
	 * @return string|null Versión o null si no se puede determinar.
	 */
	private static function read_backup_plugin_version( ZipArchive $zip, string $prefix ): ?string {
		$main_file_entry = $prefix . 'super-sheep-copy.php';
		$content         = $zip->getFromName( $main_file_entry );

		if ( false === $content ) {
			return null;
		}

		// Extraer "Version: X.Y.Z" del encabezado del plugin.
		if ( preg_match( '/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $content, $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * Extrae las entradas del ZIP correspondientes al plugin SSC al directorio de staging.
	 *
	 * @param ZipArchive $zip        Instancia abierta del ZIP.
	 * @param string     $prefix     Prefijo de las entradas del plugin dentro del ZIP.
	 * @param string     $staged_dir Directorio de destino de staging.
	 * @return bool|WP_Error true si se extrajo algo, false si no había entradas, WP_Error en fallo.
	 */
	private static function extract_plugin_entries( ZipArchive $zip, string $prefix, string $staged_dir ) {
		$prefix_len = strlen( $prefix );
		$extracted  = false;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}

			$entry = $stat['name'];

			if ( strpos( $entry, $prefix ) !== 0 ) {
				continue;
			}

			$rel = substr( $entry, $prefix_len );

			// Entrada del directorio raíz del plugin o subdirectorios.
			if ( $rel === '' || substr( $entry, -1 ) === '/' ) {
				if ( $rel !== '' ) {
					wp_mkdir_p( $staged_dir . '/' . $rel );
				} else {
					wp_mkdir_p( $staged_dir );
				}
				continue;
			}

			// Zip Slip: verificar que la ruta resultante esté dentro del staging.
			$dest     = $staged_dir . '/' . $rel;
			$dest_dir = dirname( $dest );

			if ( ! is_dir( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}

			$real_dest_dir = realpath( $dest_dir );
			$real_staged   = realpath( $staged_dir ) ?: $staged_dir;

			if ( ! $real_dest_dir || strpos( $real_dest_dir, $real_staged ) !== 0 ) {
				SSC_Logger::warn( 'self_updater', 'Entrada ZIP insegura omitida en staging: ' . $entry );
				continue;
			}

			$content = $zip->getFromIndex( $i );
			if ( false === $content ) {
				SSC_Logger::warn( 'self_updater', 'No se pudo leer entrada ZIP: ' . $entry );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $dest, $content ) ) {
				return new WP_Error( 'staging_write_failed', sprintf( __( 'No se pudo escribir archivo de staging: %s', 'super-sheep-copy' ), $rel ) );
			}

			$extracted = true;
		}

		return $extracted;
	}

	/**
	 * Copia recursivamente el contenido de $src a $dest.
	 *
	 * @param string $src  Directorio origen.
	 * @param string $dest Directorio destino.
	 * @return bool true si todo fue correctamente, false si algún archivo falló.
	 */
	private static function copy_directory( string $src, string $dest ): bool {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$src_len = strlen( rtrim( $src, '/\\' ) );
		$ok      = true;

		foreach ( $iterator as $item ) {
			/** @var SplFileInfo $item */
			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), $src_len ) ), '/' );
			$target   = $dest . '/' . $relative;

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

			if ( ! @copy( $item->getPathname(), $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				SSC_Logger::warn( 'self_updater', 'No se pudo copiar: ' . $relative );
				$ok = false;
			}
		}

		return $ok;
	}
}
