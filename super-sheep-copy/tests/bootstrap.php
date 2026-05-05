<?php
/**
 * Bootstrap de PHPUnit para el plugin Super Sheep Copy.
 *
 * Carga el autoloader de Composer (que incluye Brain\Monkey y Mockery),
 * define los stubs mínimos del entorno WordPress y carga las clases del plugin.
 */

declare( strict_types=1 );

// ── Autoloader de Composer ────────────────────────────────────────────────────
require_once __DIR__ . '/../vendor/autoload.php';

// ── Stubs de clases/funciones de WordPress ────────────────────────────────────
require_once __DIR__ . '/stubs/wp-stubs.php';

// ── Constantes del plugin ─────────────────────────────────────────────────────
define( 'SSC_VERSION',     '1.0.0' );
define( 'SSC_MIN_PHP',     '7.4' );
define( 'SSC_MIN_WP',      '6.0' );
define( 'SSC_PLUGIN_FILE', dirname( __DIR__ ) . '/super-sheep-copy.php' );
define( 'SSC_PLUGIN_DIR',  dirname( __DIR__ ) . '/' );
define( 'SSC_PLUGIN_URL',  'http://localhost/wp-content/plugins/ssc/' );
define( 'SSC_BACKUPS_DIR', sys_get_temp_dir() . '/ssc-test-backups/' );

// ── Constantes de WordPress usadas por las clases ─────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/ssc-test-abspath/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}
if ( ! defined( 'DB_NAME' ) )     { define( 'DB_NAME',     'test_db' ); }
if ( ! defined( 'DB_USER' ) )     { define( 'DB_USER',     'root' ); }
if ( ! defined( 'DB_PASSWORD' ) ) { define( 'DB_PASSWORD', '' ); }
if ( ! defined( 'DB_HOST' ) )     { define( 'DB_HOST',     'localhost' ); }
if ( ! defined( 'DB_CHARSET' ) )  { define( 'DB_CHARSET',  'utf8mb4' ); }
if ( ! defined( 'DB_COLLATE' ) )  { define( 'DB_COLLATE',  '' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'FS_CHMOD_FILE' ) )   { define( 'FS_CHMOD_FILE', 0644 ); }

// Crear directorios temporales necesarios para las pruebas.
@mkdir( SSC_BACKUPS_DIR, 0755, true );
@mkdir( ABSPATH, 0755, true );
@mkdir( WP_CONTENT_DIR, 0755, true );

// ── Carga de clases del plugin (orden de dependencias) ────────────────────────
$plugin_includes = [
    'includes/class-ssc-capabilities.php',
    'includes/class-ssc-logger.php',
    'includes/class-ssc-filesystem.php',
    'includes/class-ssc-security.php',
    'includes/backup/class-ssc-manifest.php',
    'includes/backup/class-ssc-zip-writer.php',
    'includes/backup/class-ssc-files-backup.php',
    'includes/backup/class-ssc-database-backup.php',
    'includes/backup/class-ssc-backup-manager.php',
    'includes/restore/class-ssc-url-rewriter.php',
    'includes/restore/class-ssc-database-restore.php',
    'includes/restore/class-ssc-files-restore.php',
    'includes/restore/class-ssc-restore-manager.php',
];

foreach ( $plugin_includes as $file ) {
    require_once SSC_PLUGIN_DIR . $file;
}
