<?php
/**
 * Limpieza al desinstalar el plugin.
 *
 * @package Full_Site_Backup
 */

// Solo puede ejecutarse desde WordPress durante la desinstalación.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Opciones ──────────────────────────────────────────────────────────────────
$options = array(
	'ssc_version',
	'ssc_settings',
	'ssc_last_backup',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Transients ────────────────────────────────────────────────────────────────
delete_transient( 'ssc_backup_running' );

// ── Capacidad personalizada ───────────────────────────────────────────────────
$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_ssc_backups' );
}

// ── Tabla de auditoría ────────────────────────────────────────────────────────
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ssc_audit_log" ); // phpcs:ignore WordPress.DB

// ── Directorio de respaldos ───────────────────────────────────────────────────
// Por defecto NO se elimina para proteger los datos del usuario.
// Solo se elimina si la opción 'ssc_delete_backups_on_uninstall' estaba activada.
// (Opción de ajuste avanzado — Fase 2.)
