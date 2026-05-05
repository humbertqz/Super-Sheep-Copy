<?php
/**
 * Dump de base de datos a SQL.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Database_Backup
 *
 * Genera un dump completo de la base de datos en formato SQL.
 * Estrategia:
 *   1. Intentar mysqldump vía shell (más rápido, usa --defaults-extra-file
 *      para no exponer contraseñas en la línea de comandos).
 *   2. Fallback PHP puro: SHOW CREATE TABLE + SELECT paginado (LIMIT/OFFSET)
 *      para no agotar memoria en tablas grandes.
 */
class SSC_Database_Backup {

	/**
	 * Número de filas a procesar por página en el fallback PHP.
	 */
	const ROWS_PER_PAGE = 500;

	/**
	 * Ruta al archivo SQL de destino.
	 *
	 * @var string
	 */
	private string $output_file;

	/**
	 * Constructor.
	 *
	 * @param string $output_file Ruta absoluta al archivo .sql que se generará.
	 */
	public function __construct( string $output_file ) {
		$this->output_file = $output_file;
	}

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Ejecuta el dump completo.
	 *
	 * @return true|WP_Error
	 */
	public function run() {
		SSC_Logger::info( 'db_backup', 'Iniciando dump de base de datos.' );

		// Intentar mysqldump primero.
		if ( $this->is_mysqldump_available() ) {
			$result = $this->dump_via_mysqldump();
			if ( true === $result ) {
				SSC_Logger::info( 'db_backup', 'Dump completado vía mysqldump.' );
				return true;
			}
			SSC_Logger::warn( 'db_backup', 'mysqldump falló, usando fallback PHP.' );
		}

		// Fallback PHP puro.
		$result = $this->dump_via_php();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		SSC_Logger::info( 'db_backup', 'Dump completado vía PHP puro.' );
		return true;
	}

	// ── mysqldump ─────────────────────────────────────────────────────────────

	/**
	 * Verifica si mysqldump está disponible y ejecutable.
	 *
	 * @return bool
	 */
	private function is_mysqldump_available(): bool {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		// Comprobar que shell_exec no esté en disable_functions.
		$disabled = explode( ',', ini_get( 'disable_functions' ) );
		$disabled = array_map( 'trim', $disabled );
		if ( in_array( 'shell_exec', $disabled, true ) ) {
			return false;
		}

		$output = @shell_exec( 'mysqldump --version 2>&1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return ! empty( $output ) && strpos( $output, 'mysqldump' ) !== false;
	}

	/**
	 * Ejecuta mysqldump usando un archivo de credenciales temporal (chmod 600)
	 * para no exponer la contraseña en los argumentos del proceso.
	 *
	 * @return true|WP_Error
	 */
	private function dump_via_mysqldump() {
		global $wpdb;

		// Obtener parámetros de conexión.
		$host   = DB_HOST;
		$port   = '3306';
		$socket = '';

		// DB_HOST puede incluir puerto (host:port) o socket (localhost:/path/to/socket).
		if ( strpos( $host, ':' ) !== false ) {
			list( $host, $port_or_socket ) = explode( ':', $host, 2 );
			if ( is_numeric( $port_or_socket ) ) {
				$port = $port_or_socket;
			} else {
				$socket = $port_or_socket;
			}
		}

		// Crear archivo de credenciales temporal.
		$tmp_creds = tempnam( sys_get_temp_dir(), 'ssc_' );
		if ( false === $tmp_creds ) {
			return new WP_Error( 'tmpfile_failed', __( 'No se pudo crear archivo temporal para credenciales.', 'super-sheep-copy' ) );
		}

		$creds_content  = "[client]\n";
		$creds_content .= 'user=' . DB_USER . "\n";
		$creds_content .= 'password=' . DB_PASSWORD . "\n";
		$creds_content .= 'host=' . $host . "\n";
		if ( $socket ) {
			$creds_content .= 'socket=' . $socket . "\n";
		} else {
			$creds_content .= 'port=' . $port . "\n";
		}

		file_put_contents( $tmp_creds, $creds_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		chmod( $tmp_creds, 0600 );

		// Construir comando.
		$output_escaped = escapeshellarg( $this->output_file );
		$db_escaped     = escapeshellarg( DB_NAME );
		$creds_escaped  = escapeshellarg( $tmp_creds );

		$cmd = sprintf(
			'mysqldump --defaults-extra-file=%s --single-transaction --quick --lock-tables=false --skip-comments %s > %s 2>&1',
			$creds_escaped,
			$db_escaped,
			$output_escaped
		);

		$output      = @shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$exit_ok     = file_exists( $this->output_file ) && filesize( $this->output_file ) > 0;

		// Eliminar el archivo de credenciales SIEMPRE, incluso si falla.
		@unlink( $tmp_creds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $exit_ok ) {
			$detail = $output ? substr( $output, 0, 500 ) : 'sin salida';
			SSC_Logger::warn( 'db_backup', 'mysqldump retornó error: ' . $detail );
			return new WP_Error( 'mysqldump_failed', $detail );
		}

		return true;
	}

	// ── Fallback PHP puro ─────────────────────────────────────────────────────

	/**
	 * Genera el dump en PHP puro escribiendo en streaming al archivo de salida.
	 *
	 * @return true|WP_Error
	 */
	private function dump_via_php() {
		global $wpdb;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $this->output_file, 'wb' );
		if ( ! $handle ) {
			return new WP_Error( 'cannot_open_output', __( 'No se pudo abrir el archivo SQL de salida.', 'super-sheep-copy' ) );
		}

		// Cabecera del dump.
		$this->write( $handle, $this->build_header() );

		// Obtener lista de tablas.
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( empty( $tables ) ) {
			fclose( $handle );
			return new WP_Error( 'no_tables', __( 'No se encontraron tablas en la base de datos.', 'super-sheep-copy' ) );
		}

		foreach ( $tables as $table ) {
			$table_result = $this->dump_table( $handle, $table );
			if ( is_wp_error( $table_result ) ) {
				fclose( $handle );
				return $table_result;
			}
		}

		$this->write( $handle, "\n-- Dump completado: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
		fclose( $handle );

		return true;
	}

	/**
	 * Vuelca una tabla completa (estructura + datos) al handle.
	 *
	 * @param resource $handle Handle de archivo abierto.
	 * @param string   $table  Nombre de la tabla.
	 * @return true|WP_Error
	 */
	private function dump_table( $handle, string $table ) {
		global $wpdb;

		SSC_Logger::info( 'db_backup', 'Volcando tabla: ' . $table );

		// ── Estructura ────────────────────────────────────────────────────
		$this->write( $handle, "\n-- --------------------------------------------------------\n" );
		$this->write( $handle, "-- Tabla: `{$table}`\n" );
		$this->write( $handle, "-- --------------------------------------------------------\n\n" );
		$this->write( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );

		$create_row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SHOW CREATE TABLE `%1s`', $table ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
			ARRAY_N
		);

		if ( ! $create_row ) {
			return new WP_Error( 'show_create_failed', sprintf( 'No se pudo obtener CREATE TABLE para: %s', $table ) );
		}

		$this->write( $handle, $create_row[1] . ";\n\n" );

		// ── Datos (paginado) ───────────────────────────────────────────────
		$offset = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `%1s` LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					$table,
					self::ROWS_PER_PAGE,
					$offset
				),
				ARRAY_A
			);

			if ( ! empty( $rows ) ) {
				$sql = $this->build_insert_block( $table, $rows );
				$this->write( $handle, $sql );
			}

			$offset += self::ROWS_PER_PAGE;
		} while ( count( $rows ) === self::ROWS_PER_PAGE );

		return true;
	}

	/**
	 * Construye un bloque de INSERT para un conjunto de filas.
	 *
	 * Usa INSERT INTO … VALUES (…), (…), … para eficiencia.
	 *
	 * @param string $table Nombre de la tabla.
	 * @param array  $rows  Array de filas (ARRAY_A).
	 * @return string
	 */
	private function build_insert_block( string $table, array $rows ): string {
		if ( empty( $rows ) ) {
			return '';
		}

		global $wpdb;

		$columns = array_keys( $rows[0] );
		$cols    = implode( '`, `', array_map( array( $this, 'escape_identifier' ), $columns ) );
		$sql     = "INSERT INTO `{$table}` (`{$cols}`) VALUES\n";

		// _real_escape() llama add_placeholder_escape() que reemplaza '%' con '{hash}'.
		// Eso corrompe valores como permalink_structure = '/%year%/'.
		// Usar mysqli_real_escape_string() directamente sobre $wpdb->dbh.
		$use_raw_escape = $wpdb->dbh instanceof \mysqli;

		$value_groups = array();
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $val ) {
				if ( null === $val ) {
					$values[] = 'NULL';
				} elseif ( is_numeric( $val ) && preg_match( '/^-?\d+(\.\d+)?$/', $val ) ) {
					$values[] = $val;
				} else {
					if ( $use_raw_escape ) {
						$escaped = mysqli_real_escape_string( $wpdb->dbh, $val ); // phpcs:ignore WordPress.DB.RestrictedFunctions
					} else {
						$escaped = addslashes( $val );
					}
					$values[] = "'" . $escaped . "'";
				}
			}
			$value_groups[] = '(' . implode( ', ', $values ) . ')';
		}

		return $sql . implode( ",\n", $value_groups ) . ";\n\n";
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Construye la cabecera del archivo SQL.
	 *
	 * @return string
	 */
	private function build_header(): string {
		global $wpdb;

		$lines   = array();
		$lines[] = '-- Super Sheep Copy — SQL Dump';
		$lines[] = '-- Plugin version: ' . SSC_VERSION;
		$lines[] = '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines[] = '-- WordPress version: ' . get_bloginfo( 'version' );
		$lines[] = '-- PHP version: ' . PHP_VERSION;
		$lines[] = '-- Site URL: ' . get_site_url();
		$lines[] = '-- Table prefix: ' . $wpdb->prefix;
		$lines[] = '';
		$lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
		$lines[] = 'SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";';
		$lines[] = 'SET NAMES utf8mb4;';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Escapa un identificador SQL (nombre de columna).
	 *
	 * @param string $identifier Nombre a escapar.
	 * @return string
	 */
	private function escape_identifier( string $identifier ): string {
		return str_replace( '`', '``', $identifier );
	}

	/**
	 * Escribe una cadena al handle de archivo.
	 *
	 * @param resource $handle Handle de archivo.
	 * @param string   $data   Datos a escribir.
	 * @return void
	 */
	private function write( $handle, string $data ): void {
		fwrite( $handle, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
