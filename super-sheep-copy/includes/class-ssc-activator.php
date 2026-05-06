<?php
/**
 * Acciones de activación del plugin.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Activator
 *
 * Se ejecuta al activar el plugin. Crea el directorio de respaldos,
 * los archivos de protección, la tabla de auditoría y registra la
 * capacidad personalizada.
 */
class SSC_Activator {

	/**
	 * Punto de entrada del hook de activación.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::check_requirements();
		self::create_backups_directory();
		self::create_audit_table();
		SSC_Capabilities::add_capabilities();
		update_option( 'ssc_version', SSC_VERSION );
	}

	// ── Requisitos ────────────────────────────────────────────────────────────

	/**
	 * Verifica los requisitos mínimos antes de activar.
	 *
	 * @return void
	 */
	private static function check_requirements(): void {
		global $wp_version;

		if ( version_compare( PHP_VERSION, SSC_MIN_PHP, '<' ) ) {
			deactivate_plugins( plugin_basename( SSC_PLUGIN_FILE ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: required PHP version */
						__( 'Super Sheep Copy requiere PHP %s o superior.', 'super-sheep-copy' ),
						SSC_MIN_PHP
					)
				)
			);
		}

		if ( version_compare( $wp_version, SSC_MIN_WP, '<' ) ) {
			deactivate_plugins( plugin_basename( SSC_PLUGIN_FILE ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: required WP version */
						__( 'Super Sheep Copy requiere WordPress %s o superior.', 'super-sheep-copy' ),
						SSC_MIN_WP
					)
				)
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			deactivate_plugins( plugin_basename( SSC_PLUGIN_FILE ) );
			wp_die(
				esc_html( __( 'Super Sheep Copy requiere la extensión PHP ZipArchive.', 'super-sheep-copy' ) )
			);
		}
	}

	// ── Directorio de respaldos ───────────────────────────────────────────────

	/**
	 * Crea el directorio de respaldos y sus archivos de protección.
	 *
	 * @return void
	 */
	private static function create_backups_directory(): void {
		$dir = SSC_BACKUPS_DIR;

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Asegurar permisos correctos.
		if ( is_dir( $dir ) ) {
			@chmod( $dir, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		self::write_htaccess( $dir );
		self::write_web_config( $dir );
		self::write_index( $dir );
	}

	/**
	 * Escribe el .htaccess para bloquear acceso directo (Apache).
	 *
	 * @param string $dir Ruta absoluta al directorio.
	 * @return void
	 */
	private static function write_htaccess( string $dir ): void {
		$file = $dir . '.htaccess';
		if ( file_exists( $file ) ) {
			return;
		}

		$content  = "# Super Sheep Copy — deny direct access\n";
		$content .= "<IfModule mod_authz_core.c>\n";
		$content .= "    Require all denied\n";
		$content .= "</IfModule>\n";
		$content .= "<IfModule !mod_authz_core.c>\n";
		$content .= "    Deny from all\n";
		$content .= "</IfModule>\n";

		file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Escribe el web.config para bloquear acceso directo (IIS).
	 *
	 * @param string $dir Ruta absoluta al directorio.
	 * @return void
	 */
	private static function write_web_config( string $dir ): void {
		$file = $dir . 'web.config';
		if ( file_exists( $file ) ) {
			return;
		}

		$content  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$content .= "<configuration>\n";
		$content .= "  <system.webServer>\n";
		$content .= "    <security>\n";
		$content .= "      <authorization>\n";
		$content .= "        <remove users=\"*\" roles=\"\" verbs=\"\" />\n";
		$content .= "        <add accessType=\"Deny\" users=\"*\" />\n";
		$content .= "      </authorization>\n";
		$content .= "    </security>\n";
		$content .= "  </system.webServer>\n";
		$content .= "</configuration>\n";

		file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Escribe un index.php silencioso para evitar listado de directorio.
	 *
	 * @param string $dir Ruta absoluta al directorio.
	 * @return void
	 */
	private static function write_index( string $dir ): void {
		$file = $dir . 'index.php';
		if ( file_exists( $file ) ) {
			return;
		}
		file_put_contents( $file, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	// ── Tabla de auditoría ────────────────────────────────────────────────────

	/**
	 * Crea o actualiza la tabla de log de auditoría.
	 *
	 * dbDelta añade columnas o índices nuevos sin destruir datos existentes,
	 * por lo que es seguro ejecutarlo también en actualizaciones del plugin.
	 *
	 * @return void
	 */
	public static function create_audit_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'ssc_audit_log';
		$charset_collate = $wpdb->get_charset_collate();

		// job_id agrupa todos los eventos de un mismo respaldo/restauración,
		// permitiendo mostrarlos colapsados por operación en la vista de log.
		$sql = "CREATE TABLE {$table_name} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id      VARCHAR(32)         NOT NULL DEFAULT '',
			user_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_login  VARCHAR(60)         NOT NULL DEFAULT '',
			ip_address  VARCHAR(45)         NOT NULL DEFAULT '',
			action      VARCHAR(100)        NOT NULL DEFAULT '',
			object_name VARCHAR(255)        NOT NULL DEFAULT '',
			result      VARCHAR(20)         NOT NULL DEFAULT '',
			message     TEXT                NOT NULL,
			created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY job_id     (job_id),
			KEY action     (action),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ejecuta migraciones de base de datos tras una actualización del plugin.
	 *
	 * Se llama desde SSC_Plugin::maybe_upgrade() cuando la versión almacenada
	 * es anterior a la versión actual. Usa dbDelta, que es idempotente.
	 *
	 * @return void
	 */
	public static function upgrade(): void {
		self::create_audit_table();
	}
}
