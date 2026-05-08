<?php
/**
 * Plugin Name: Super Sheep Copy
 * Description: Respaldos completos (archivos + DB) con restauración y reescritura de URLs.
 * Version:     0.0.7
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Humberto Quezada
 * Author URI: https://github.com/humbertqz/Super-Sheep-Copy
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: super-sheep-copy
 * Domain Path: /languages
 */

/*
 * Copyright (C) 2026 Super Sheep Copy
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Versión mínima requerida ──────────────────────────────────────────────────
define( 'SSC_MIN_PHP', '7.4' );
define( 'SSC_MIN_WP',  '6.0' );

// ── Verificación temprana de requisitos ──────────────────────────────────────
if ( version_compare( PHP_VERSION, SSC_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>' .
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'Super Sheep Copy requiere PHP %1$s o superior. Tu servidor usa PHP %2$s.', 'super-sheep-copy' ),
					SSC_MIN_PHP,
					PHP_VERSION
				)
			) .
			'</p></div>';
	} );
	return;
}

// ── Constantes globales ───────────────────────────────────────────────────────
define( 'SSC_VERSION',     '0.0.7' );
define( 'SSC_PLUGIN_FILE', __FILE__ );
define( 'SSC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SSC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'SSC_BACKUPS_DIR', WP_CONTENT_DIR . '/uploads/ssc-backups/' );

// ── Autoloader simple ─────────────────────────────────────────────────────────
/**
 * Carga automática de clases SSC_ buscando en el mapa de archivos.
 *
 * @param string $class_name Nombre de la clase a cargar.
 */
function ssc_autoloader( string $class_name ): void {
	if ( strpos( $class_name, 'SSC_' ) !== 0 ) {
		return;
	}

	/*
	 * Mapa clase → ruta relativa a SSC_PLUGIN_DIR.
	 * Mantener ordenado alfabéticamente por clave.
	 */
	$class_map = array(
		// Admin
		'SSC_Admin'             => 'includes/admin/class-ssc-admin.php',
		'SSC_Admin_Ajax'        => 'includes/admin/class-ssc-admin-ajax.php',
		'SSC_List_Table'        => 'includes/admin/class-ssc-list-table.php',
		// Backup
		'SSC_Backup_Manager'    => 'includes/backup/class-ssc-backup-manager.php',
		'SSC_Database_Backup'   => 'includes/backup/class-ssc-database-backup.php',
		'SSC_Files_Backup'      => 'includes/backup/class-ssc-files-backup.php',
		'SSC_Manifest'          => 'includes/backup/class-ssc-manifest.php',
		'SSC_Zip_Writer'        => 'includes/backup/class-ssc-zip-writer.php',
		// Core
		'SSC_Activator'         => 'includes/class-ssc-activator.php',
		'SSC_Capabilities'      => 'includes/class-ssc-capabilities.php',
		'SSC_Deactivator'       => 'includes/class-ssc-deactivator.php',
		'SSC_Filesystem'        => 'includes/class-ssc-filesystem.php',
		'SSC_Logger'            => 'includes/class-ssc-logger.php',
		'SSC_Plugin'            => 'includes/class-ssc-plugin.php',
		'SSC_Security'          => 'includes/class-ssc-security.php',
		// Cron
		'SSC_Scheduler'         => 'includes/cron/class-ssc-scheduler.php',
		// Restore
		'SSC_Database_Restore'  => 'includes/restore/class-ssc-database-restore.php',
		'SSC_Files_Restore'     => 'includes/restore/class-ssc-files-restore.php',
		'SSC_Restore_Manager'   => 'includes/restore/class-ssc-restore-manager.php',
		'SSC_Self_Updater'      => 'includes/restore/class-ssc-self-updater.php',
		'SSC_URL_Rewriter'      => 'includes/restore/class-ssc-url-rewriter.php',
	);

	if ( isset( $class_map[ $class_name ] ) ) {
		$file = SSC_PLUGIN_DIR . $class_map[ $class_name ];
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
spl_autoload_register( 'ssc_autoloader' );

// ── Hooks de ciclo de vida ────────────────────────────────────────────────────
register_activation_hook( SSC_PLUGIN_FILE, array( 'SSC_Activator', 'activate' ) );
register_deactivation_hook( SSC_PLUGIN_FILE, array( 'SSC_Deactivator', 'deactivate' ) );

// ── Arranque del plugin ───────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
	SSC_Plugin::get_instance();
} );
