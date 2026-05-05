<?php
/**
 * Clase principal del plugin (singleton bootstrap).
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Plugin
 *
 * Orquesta el arranque del plugin: registra los hooks de las distintas
 * partes (admin, AJAX, cron) y sirve de punto de acceso global.
 */
class SSC_Plugin {

	/**
	 * Instancia única de la clase (singleton).
	 *
	 * @var SSC_Plugin|null
	 */
	private static ?SSC_Plugin $instance = null;

	/**
	 * Devuelve (o crea) la instancia única del plugin.
	 *
	 * @return SSC_Plugin
	 */
	public static function get_instance(): SSC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Constructor privado para reforzar el patrón singleton.
	 */
	private function __construct() {}

	/**
	 * Registra todos los hooks del plugin.
	 *
	 * @return void
	 */
	private function init(): void {
		$this->maybe_upgrade();

		if ( is_admin() ) {
			$admin = new SSC_Admin();
			$admin->register_hooks();

			$ajax = new SSC_Admin_Ajax();
			$ajax->register_hooks();
		}

		// Cron scheduler (Fase 2) — se conectará aquí.
	}

	/**
	 * Ejecuta migraciones de DB si la versión almacenada es anterior a la actual.
	 *
	 * Esto garantiza que columnas o índices nuevos se añadan en installs existentes
	 * sin necesidad de que el usuario desactive y reactive el plugin manualmente.
	 *
	 * @return void
	 */
	private function maybe_upgrade(): void {
		$installed = get_option( 'ssc_version', '0' );
		if ( version_compare( $installed, SSC_VERSION, '<' ) ) {
			SSC_Activator::upgrade();
			update_option( 'ssc_version', SSC_VERSION );
		}

		// Always run dbDelta on boot: adds missing columns/indexes to existing tables
		// and creates the table if it was dropped. dbDelta is idempotent and fast.
		SSC_Activator::create_audit_table();
	}
}
