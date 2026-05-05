<?php
/**
 * Utilidades de seguridad: validación de rutas, descargas, borrado seguro.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Security
 *
 * Centraliza todas las operaciones que requieren validación de seguridad:
 * resolución de rutas, descarga de archivos, eliminación y validación de uploads.
 */
class SSC_Security {

	// ── Resolución de rutas ───────────────────────────────────────────────────

	/**
	 * Resuelve y valida que un nombre de archivo pertenezca al directorio de respaldos.
	 *
	 * @param string $filename Nombre de archivo (solo nombre, sin ruta).
	 * @return string|WP_Error Ruta absoluta validada o WP_Error.
	 */
	public static function resolve_backup_path( string $filename ) {
		// Sanear el nombre.
		$filename = sanitize_file_name( $filename );

		// Solo caracteres permitidos.
		if ( ! preg_match( '/^[a-zA-Z0-9._-]+$/', $filename ) ) {
			return new WP_Error( 'invalid_filename', __( 'Nombre de archivo no válido.', 'super-sheep-copy' ) );
		}

		$backups_dir = realpath( SSC_BACKUPS_DIR );
		if ( false === $backups_dir ) {
			return new WP_Error( 'backups_dir_missing', __( 'El directorio de respaldos no existe.', 'super-sheep-copy' ) );
		}

		$full_path = $backups_dir . DIRECTORY_SEPARATOR . $filename;
		$real_path = realpath( $full_path );

		// Path traversal: la ruta resuelta debe estar dentro del directorio de respaldos.
		if ( false === $real_path || strpos( $real_path, $backups_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
			return new WP_Error( 'path_traversal', __( 'Acceso denegado.', 'super-sheep-copy' ) );
		}

		if ( ! is_file( $real_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Archivo no encontrado.', 'super-sheep-copy' ) );
		}

		return $real_path;
	}

	// ── Descarga segura ───────────────────────────────────────────────────────

	/**
	 * Envía un archivo de respaldo al navegador usando readfile().
	 *
	 * Debe llamarse desde admin-post.php tras verificar nonce y capacidad.
	 *
	 * @param string $filename Nombre del archivo ZIP a descargar.
	 * @return void Termina la ejecución con wp_die() en caso de error.
	 */
	public static function stream_download( string $filename ): void {
		$path = self::resolve_backup_path( $filename );
		if ( is_wp_error( $path ) ) {
			wp_die( esc_html__( 'Archivo no encontrado o acceso denegado.', 'super-sheep-copy' ) );
		}

		$filesize = filesize( $path );

		// Limpiar cualquier output buffer previo.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Headers de descarga.
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( basename( $path ) ) . '"' );
		header( 'Content-Length: ' . $filesize );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Robots-Tag: noindex' );

		// Enviar el archivo.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	// ── Eliminación ──────────────────────────────────────────────────────────

	/**
	 * Elimina de forma segura un archivo de respaldo y su .sha256 asociado.
	 *
	 * @param string $filename Nombre del archivo ZIP.
	 * @return true|WP_Error
	 */
	public static function delete_backup_file( string $filename ) {
		$path = self::resolve_backup_path( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		// Eliminar el ZIP.
		if ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'delete_failed', __( 'No se pudo eliminar el archivo.', 'super-sheep-copy' ) );
		}

		// Eliminar el .sha256 si existe (no es crítico).
		$sha_path = $path . '.sha256';
		if ( file_exists( $sha_path ) ) {
			@unlink( $sha_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Eliminar el .meta si existe.
		$meta_path = $path . '.meta';
		if ( file_exists( $meta_path ) ) {
			@unlink( $meta_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		return true;
	}

	// ── Validación de uploads ─────────────────────────────────────────────────

	/**
	 * Valida un archivo ZIP subido por el usuario.
	 *
	 * Comprueba: extensión, MIME reportado, magic bytes y que sea un ZIP válido.
	 *
	 * @param array $file Entrada de $_FILES.
	 * @return true|WP_Error
	 */
	public static function validate_uploaded_zip( array $file ) {
		// Extensión.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'zip' !== $ext ) {
			return new WP_Error( 'invalid_extension', __( 'Solo se admiten archivos .zip.', 'super-sheep-copy' ) );
		}

		// MIME reportado por el navegador (no confiable solo, pero es una capa adicional).
		$allowed_mimes = array( 'application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream' );
		if ( ! in_array( $file['type'], $allowed_mimes, true ) ) {
			return new WP_Error( 'invalid_mime', __( 'Tipo MIME no permitido.', 'super-sheep-copy' ) );
		}

		// Magic bytes: ZIP empieza con PK\x03\x04.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $file['tmp_name'], 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'cannot_read', __( 'No se pudo leer el archivo subido.', 'super-sheep-copy' ) );
		}
		$magic = fread( $handle, 4 );
		fclose( $handle );

		if ( "\x50\x4B\x03\x04" !== $magic ) {
			return new WP_Error( 'invalid_magic', __( 'El archivo no es un ZIP válido.', 'super-sheep-copy' ) );
		}

		// ZipArchive lo puede abrir.
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_zip', __( 'El archivo ZIP está corrupto o no es válido.', 'super-sheep-copy' ) );
		}
		$zip->close();

		// Tamaño máximo configurable (por defecto 2 GB).
		$max_bytes = absint( get_option( 'ssc_settings', array() )['max_upload_size'] ?? 2 * 1024 * 1024 * 1024 );
		if ( $file['size'] > $max_bytes ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: 1: max size in MB */
					__( 'El archivo supera el tamaño máximo permitido (%s MB).', 'super-sheep-copy' ),
					number_format_i18n( $max_bytes / 1024 / 1024 )
				)
			);
		}

		return true;
	}

	// ── Zip Slip ─────────────────────────────────────────────────────────────

	/**
	 * Valida que una entrada de ZIP no escape del directorio destino (Zip Slip).
	 *
	 * @param string $entry_name  Nombre de la entrada dentro del ZIP.
	 * @param string $target_dir  Directorio de destino (ruta absoluta real).
	 * @return true|WP_Error
	 */
	public static function validate_zip_entry( string $entry_name, string $target_dir ) {
		// Rechazar entradas con componentes peligrosas.
		if ( strpos( $entry_name, '..' ) !== false || strpos( $entry_name, "\0" ) !== false ) {
			return new WP_Error( 'zip_slip', __( 'Entrada ZIP no segura detectada.', 'super-sheep-copy' ) );
		}

		$target_dir = rtrim( $target_dir, '/\\' );
		$dest_path  = $target_dir . '/' . ltrim( $entry_name, '/\\' );

		// Normalizar sin requerir que el path exista (realpath() falla en dirs inexistentes).
		$norm_dest   = self::normalize_path( $dest_path );
		$norm_target = self::normalize_path( $target_dir );

		if ( strpos( $norm_dest . '/', $norm_target . '/' ) !== 0 ) {
			return new WP_Error( 'zip_slip', __( 'Entrada ZIP escapa del directorio destino.', 'super-sheep-copy' ) );
		}

		return true;
	}

	/**
	 * Normaliza una ruta resolviendo segmentos '..' sin requerir que exista en disco.
	 *
	 * @param string $path Ruta a normalizar.
	 * @return string
	 */
	private static function normalize_path( string $path ): string {
		// Detectar separador de raíz (Windows o Unix).
		$prefix = '';
		if ( preg_match( '/^[a-zA-Z]:[\/\\\\]/', $path, $m ) ) {
			$prefix = $m[0];
			$path   = substr( $path, strlen( $prefix ) );
		} elseif ( '' !== $path && ( '/' === $path[0] || '\\' === $path[0] ) ) {
			$prefix = '/';
			$path   = substr( $path, 1 );
		}

		$parts = array();
		foreach ( preg_split( '/[\/\\\\]/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
			} else {
				$parts[] = $part;
			}
		}

		return $prefix . implode( '/', $parts );
	}
}
