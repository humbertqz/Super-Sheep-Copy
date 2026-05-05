<?php
/**
 * Acciones de desactivación del plugin.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Deactivator
 *
 * Se ejecuta al desactivar el plugin. No elimina datos ni respaldos
 * para permitir reactivar sin pérdida de información.
 */
class SSC_Deactivator {

	/**
	 * Punto de entrada del hook de desactivación.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Desregistrar eventos cron (Fase 2).
		// Por ahora no hay cron que limpiar.

		// Limpiar transients de operaciones en curso por si quedaron huérfanos.
		delete_transient( 'ssc_backup_running' );
	}
}
