<?php
/**
 * Gestión de capacidades personalizadas del plugin.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Capabilities
 *
 * Registra y elimina la capacidad `manage_ssc_backups` en el rol administrator.
 */
class SSC_Capabilities {

	/**
	 * Nombre de la capacidad personalizada.
	 */
	const CAP = 'manage_ssc_backups';

	/**
	 * Añade la capacidad al rol administrator.
	 *
	 * @return void
	 */
	public static function add_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}
	}

	/**
	 * Elimina la capacidad del rol administrator (usada en desinstalación).
	 *
	 * @return void
	 */
	public static function remove_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( $role && $role->has_cap( self::CAP ) ) {
			$role->remove_cap( self::CAP );
		}
	}
}
