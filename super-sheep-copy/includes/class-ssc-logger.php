<?php
/**
 * Sistema de logging del plugin.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Logger
 *
 * Escribe entradas en la tabla de auditoría y, opcionalmente, en un archivo
 * de log rotado. Proporciona métodos estáticos para facilitar el uso desde
 * cualquier clase del plugin.
 */
class SSC_Logger {

	/**
	 * Niveles de log disponibles.
	 */
	const LEVEL_INFO  = 'INFO';
	const LEVEL_WARN  = 'WARN';
	const LEVEL_ERROR = 'ERROR';

	/**
	 * job_id activo para la operación en curso.
	 *
	 * Todas las llamadas a info/warn/error dentro de un respaldo o restauración
	 * quedarán agrupadas bajo el mismo job_id, permitiendo colapsar los eventos
	 * por operación en la vista de log.
	 *
	 * No se comparte entre procesos PHP: cada proceso FastCGI tiene su propia
	 * copia de las variables estáticas, por lo que dos backups simultáneos
	 * (en procesos distintos) no interfieren entre sí.
	 *
	 * @var string
	 */
	private static string $current_job_id = '';

	// ── Contexto de job ───────────────────────────────────────────────────────

	/**
	 * Establece el job_id activo para las entradas de log siguientes.
	 *
	 * @param string $job_id ID de job generado por el manager.
	 * @return void
	 */
	public static function set_job_context( string $job_id ): void {
		self::$current_job_id = $job_id;
	}

	/**
	 * Devuelve el job_id activo (vacío si no hay ninguno).
	 *
	 * @return string
	 */
	public static function get_job_context(): string {
		return self::$current_job_id;
	}

	/**
	 * Limpia el job_id activo al finalizar la operación.
	 *
	 * @return void
	 */
	public static function clear_job_context(): void {
		self::$current_job_id = '';
	}

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Registra una entrada de nivel INFO.
	 *
	 * @param string $action      Identificador de la acción (ej. 'create_backup').
	 * @param string $message     Mensaje descriptivo.
	 * @param string $object_name Nombre del objeto relacionado (ej. nombre del archivo).
	 * @return void
	 */
	public static function info( string $action, string $message, string $object_name = '' ): void {
		self::write( self::LEVEL_INFO, $action, $message, 'success', $object_name );
	}

	/**
	 * Registra una entrada de nivel WARN.
	 *
	 * @param string $action      Identificador de la acción.
	 * @param string $message     Mensaje descriptivo.
	 * @param string $object_name Nombre del objeto relacionado.
	 * @return void
	 */
	public static function warn( string $action, string $message, string $object_name = '' ): void {
		self::write( self::LEVEL_WARN, $action, $message, 'warning', $object_name );
	}

	/**
	 * Registra una entrada de nivel ERROR.
	 *
	 * @param string $action      Identificador de la acción.
	 * @param string $message     Mensaje descriptivo.
	 * @param string $object_name Nombre del objeto relacionado.
	 * @return void
	 */
	public static function error( string $action, string $message, string $object_name = '' ): void {
		self::write( self::LEVEL_ERROR, $action, $message, 'error', $object_name );
	}

	// ── Escritura interna ─────────────────────────────────────────────────────

	/**
	 * Escribe la entrada en la tabla de auditoría.
	 *
	 * @param string $level       Nivel (INFO|WARN|ERROR).
	 * @param string $action      Acción realizada.
	 * @param string $message     Mensaje del log.
	 * @param string $result      Resultado ('success'|'warning'|'error').
	 * @param string $object_name Nombre del objeto relacionado.
	 * @return void
	 */
	private static function write( string $level, string $action, string $message, string $result, string $object_name ): void {
		global $wpdb;

		// Ensure the DB connection is alive — it can drop after fastcgi_finish_request().
		if ( ! $wpdb->check_connection( false ) ) {
			error_log( sprintf( '[SSC][%s] DB connection lost, cannot write log: [%s] %s', $level, $action, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		$current_user = wp_get_current_user();

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'ssc_audit_log',
			array(
				'job_id'      => substr( self::$current_job_id, 0, 32 ),
				'user_id'     => $current_user->ID ?? 0,
				'user_login'  => $current_user->user_login ?? '',
				'ip_address'  => self::get_client_ip(),
				'action'      => substr( $action, 0, 100 ),
				'object_name' => substr( $object_name, 0, 255 ),
				'result'      => substr( $result, 0, 20 ),
				'message'     => '[' . $level . '] ' . $message,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted && $wpdb->last_error ) {
			error_log( sprintf( '[SSC][%s] DB insert error: %s — [%s] %s', $level, $wpdb->last_error, $action, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	// ── Utilidades ────────────────────────────────────────────────────────────

	/**
	 * Obtiene la IP del cliente de forma segura.
	 *
	 * @return string
	 */
	private static function get_client_ip(): string {
		// Solo confiamos en REMOTE_ADDR. Los headers X-Forwarded-For pueden ser falseados.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Elimina entradas de log más antiguas que N días.
	 *
	 * @param int $days Días de retención (por defecto 90).
	 * @return int Número de filas eliminadas.
	 */
	public static function purge_old_entries( int $days = 90 ): int {
		global $wpdb;

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}ssc_audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL
				$days
			)
		);

		return (int) $deleted;
	}

	/**
	 * Obtiene las últimas N entradas del log.
	 *
	 * @param int $limit Número máximo de entradas.
	 * @return array
	 */
	public static function get_entries( int $limit = 100 ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ssc_audit_log ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			),
			ARRAY_A
		);
	}
}
