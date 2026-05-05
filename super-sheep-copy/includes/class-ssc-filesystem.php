<?php
/**
 * Wrapper sobre WP_Filesystem para operaciones de archivos.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Filesystem
 *
 * Inicializa WP_Filesystem y expone métodos convenientes para las
 * operaciones de lectura/escritura que requiere el plugin.
 */
class SSC_Filesystem {

	/**
	 * Instancia de WP_Filesystem_Base.
	 *
	 * @var \WP_Filesystem_Base|null
	 */
	private static ?\WP_Filesystem_Base $fs = null;

	/**
	 * Inicializa y devuelve la instancia de WP_Filesystem.
	 *
	 * @return \WP_Filesystem_Base|false
	 */
	public static function get() {
		if ( null !== self::$fs ) {
			return self::$fs;
		}

		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( WP_Filesystem() ) {
			self::$fs = $wp_filesystem;
			return self::$fs;
		}

		return false;
	}

	/**
	 * Escribe contenido en un archivo usando WP_Filesystem cuando está disponible,
	 * con fallback a file_put_contents.
	 *
	 * @param string $path    Ruta absoluta al archivo.
	 * @param string $content Contenido a escribir.
	 * @return bool
	 */
	public static function put_contents( string $path, string $content ): bool {
		$fs = self::get();
		if ( $fs ) {
			return $fs->put_contents( $path, $content, FS_CHMOD_FILE );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $path, $content );
	}

	/**
	 * Lee el contenido de un archivo.
	 *
	 * @param string $path Ruta absoluta al archivo.
	 * @return string|false
	 */
	public static function get_contents( string $path ) {
		$fs = self::get();
		if ( $fs ) {
			return $fs->get_contents( $path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $path );
	}

	/**
	 * Elimina un archivo o directorio recursivamente.
	 *
	 * @param string $path      Ruta a eliminar.
	 * @param bool   $recursive Si debe eliminar recursivamente.
	 * @return bool
	 */
	public static function delete( string $path, bool $recursive = false ): bool {
		$fs = self::get();
		if ( $fs ) {
			return $fs->delete( $path, $recursive );
		}
		if ( $recursive && is_dir( $path ) ) {
			return self::recursive_delete( $path );
		}
		return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Elimina un directorio y su contenido de forma recursiva.
	 *
	 * @param string $dir Ruta del directorio.
	 * @return bool
	 */
	private static function recursive_delete( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getRealPath() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} else {
				@unlink( $item->getRealPath() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
