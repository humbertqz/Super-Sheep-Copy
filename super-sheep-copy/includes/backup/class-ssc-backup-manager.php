<?php
/**
 * Orquestador del proceso de respaldo completo.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Backup_Manager
 *
 * Coordina los pasos del respaldo siguiendo la secuencia definida en §5.2:
 *  1. Pre-check de espacio en disco.
 *  2. Lock anti-concurrencia.
 *  3. Dump de base de datos.
 *  4. Empaquetado de archivos.
 *  5. Manifest.
 *  6. Checksum SHA-256.
 *  7. Liberar lock + actualizar opción + disparar hook.
 *
 * El progreso se persiste en el transient `ssc_backup_state_<job_id>`
 * para que el polling AJAX pueda consultarlo.
 */
class SSC_Backup_Manager {

	/**
	 * Multiplicador de espacio mínimo requerido (1.5× el tamaño estimado).
	 */
	const DISK_SPACE_FACTOR = 1.5;

	/**
	 * TTL del lock anti-concurrencia en segundos.
	 */
	const LOCK_TTL = 600;

	/**
	 * TTL del transient de estado del job en segundos.
	 */
	const STATE_TTL = 3600;

	/**
	 * ID del job actual.
	 *
	 * @var string
	 */
	protected string $job_id = '';

	/**
	 * Configuración del plugin.
	 *
	 * @var array
	 */
	private array $settings = array();

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Inicia el proceso de respaldo de forma síncrona.
	 *
	 * @param string $label  Etiqueta opcional del respaldo.
	 * @param string $type   Origen del respaldo: 'manual' (usuario) o 'pre-restore' (sistema).
	 * @param string $job_id ID de job pre-generado (opcional). Si se omite se genera uno nuevo.
	 *                       Pasar un ID externo permite escribir el estado inicial en el
	 *                       transient antes de enviar la respuesta HTTP al navegador.
	 * @return string|WP_Error Job ID en caso de éxito, WP_Error en caso de fallo.
	 */
	public function start( string $label = '', string $type = 'manual', string $job_id = '' ) {
		$this->job_id   = $job_id ?: $this->generate_job_id();
		$this->settings = (array) get_option( 'ssc_settings', array() );

		// Elevar límites si el servidor lo permite.
		wp_raise_memory_limit( 'admin' );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		do_action( 'ssc_backup_started', $this->job_id );

		// 1. Pre-check de espacio.
		$space_check = $this->check_disk_space();
		if ( is_wp_error( $space_check ) ) {
			$this->fail( $space_check->get_error_message() );
			return $space_check;
		}

		// 2. Lock anti-concurrencia.
		if ( ! $this->acquire_lock() ) {
			$error = new WP_Error( 'backup_running', __( 'Ya hay un respaldo en curso. Inténtalo de nuevo en unos minutos.', 'super-sheep-copy' ) );
			$this->fail( $error->get_error_message() );
			return $error;
		}

		$this->update_state( 'running', __( 'Iniciando respaldo…', 'super-sheep-copy' ), 0 );

		// Establecer contexto de job para que todos los logs de esta operación
		// (db_backup, files_backup, zip_writer…) queden agrupados bajo el mismo job_id.
		SSC_Logger::set_job_context( $this->job_id );

		SSC_Logger::info(
			'backup_manager',
			sprintf(
				'Respaldo iniciado. Tipo: %s%s',
				$type,
				$label ? ' / Etiqueta: ' . $label : ''
			)
		);

		$filename = $this->generate_filename( $label );
		$zip_path = SSC_BACKUPS_DIR . $filename;
		$sql_path = sys_get_temp_dir() . '/ssc_db_' . $this->job_id . '.sql';

		$zip    = new SSC_Zip_Writer( $zip_path );
		$result = $zip->open();
		if ( is_wp_error( $result ) ) {
			$this->release_lock();
			$this->fail( $result->get_error_message() );
			return $result;
		}

		try {
			// 3. Dump de DB.
			$this->update_state( 'running', __( 'Volcando base de datos…', 'super-sheep-copy' ), 10 );
			$db_backup = new SSC_Database_Backup( $sql_path );
			$result    = $db_backup->run();
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}

			$result = $zip->add_file( $sql_path, 'database.sql' );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			$db_size = file_exists( $sql_path ) ? filesize( $sql_path ) : 0;

			// 4. Empaquetado de archivos.
			$this->update_state( 'running', __( 'Empaquetando archivos del sitio…', 'super-sheep-copy' ), 30 );
			$files_backup = new SSC_Files_Backup( $zip, $this->settings );
			$result       = $files_backup->run();
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			$file_count = $files_backup->get_file_count();

			// 5. Manifest.
			$this->update_state( 'running', __( 'Generando manifest…', 'super-sheep-copy' ), 90 );
			$manifest = new SSC_Manifest();
			$manifest->set_file_count( $file_count );
			$manifest->set_db_size( $db_size );
			$manifest->set_include_core( ! empty( $this->settings['include_core'] ) );

			$result = $zip->add_from_string( 'manifest.json', $manifest->to_json() );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}

			// Cerrar ZIP.
			$result = $zip->close();
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}

		} catch ( \Throwable $e ) {
			$zip->close();
			$this->cleanup_temp_files( $sql_path, $zip_path );
			$this->release_lock();
			$this->fail( $e->getMessage() );
			return new WP_Error( 'backup_failed', $e->getMessage() );

		} finally {
			if ( file_exists( $sql_path ) ) {
				@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}

		// 6. Checksum.
		$this->update_state( 'running', __( 'Calculando checksum…', 'super-sheep-copy' ), 95 );
		$hash = $zip->write_checksum();
		if ( is_wp_error( $hash ) ) {
			SSC_Logger::warn( 'backup_manager', 'No se pudo calcular checksum: ' . $hash->get_error_message() );
			$hash = '';
		}

		// 6b. Escribir archivo .meta con el tipo de respaldo (manual / pre-restore / …).
		$this->write_meta( SSC_BACKUPS_DIR . $filename, $label, $type );

		// 7. Finalizar.
		$this->release_lock();
		update_option( 'ssc_last_backup', gmdate( 'Y-m-d\TH:i:s\Z' ) );

		$this->update_state_raw( array(
			'status'   => 'completed',
			'message'  => __( 'Respaldo completado correctamente.', 'super-sheep-copy' ),
			'percent'  => 100,
			'filename' => $filename,
			'checksum' => $hash,
		) );

		SSC_Logger::info( 'backup_manager', 'Respaldo completado.', $filename );
		do_action( 'ssc_backup_completed', $this->job_id, $filename );

		SSC_Logger::clear_job_context();
		return $this->job_id;
	}

	// ── Helpers internos ──────────────────────────────────────────────────────

	/**
	 * Genera un ID de job único.
	 *
	 * @return string
	 */
	private function generate_job_id(): string {
		return substr( md5( uniqid( 'ssc_', true ) ), 0, 16 );
	}

	/**
	 * Genera el nombre del archivo ZIP de respaldo.
	 *
	 * Formato: backup-YYYYMMDD-HHmmss-<hash8>.zip
	 *
	 * @param string $label Etiqueta opcional.
	 * @return string
	 */
	private function generate_filename( string $label = '' ): string {
		$timestamp = gmdate( 'Ymd-His' );
		$hash      = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		$host      = wp_parse_url( site_url(), PHP_URL_HOST );
		$safe_host = preg_replace( '/[^a-zA-Z0-9_-]/', '-', (string) $host );
		$safe_host = trim( $safe_host, '-' );
		$safe_host = substr( $safe_host, 0, 50 );

		if ( $label ) {
			$safe_label = preg_replace( '/[^a-zA-Z0-9_-]/', '-', sanitize_file_name( $label ) );
			// Eliminar guiones sobrantes en los extremos (ej. etiqueta terminada en '!').
			$safe_label = trim( $safe_label, '-' );
			$safe_label = substr( $safe_label, 0, 40 );
			$safe_label = rtrim( $safe_label, '-' );
			if ( $safe_label ) {
				return "backup-{$safe_host}-{$timestamp}-{$safe_label}-{$hash}.zip";
			}
		}

		return "backup-{$safe_host}-{$timestamp}-{$hash}.zip";
	}

	/**
	 * Verifica que haya suficiente espacio libre en disco.
	 *
	 * @return true|WP_Error
	 */
	protected function check_disk_space() {
		$free      = disk_free_space( SSC_BACKUPS_DIR );
		$site_size = $this->estimate_site_size();

		if ( false === $free ) {
			SSC_Logger::warn( 'backup_manager', 'No se pudo determinar el espacio libre en disco.' );
			return true;
		}

		$required = $site_size * self::DISK_SPACE_FACTOR;
		if ( $free < $required ) {
			return new WP_Error(
				'insufficient_disk_space',
				sprintf(
					/* translators: 1: required space, 2: free space */
					__( 'Espacio en disco insuficiente. Se necesitan %1$s, disponibles: %2$s.', 'super-sheep-copy' ),
					size_format( (int) $required ),
					size_format( (int) $free )
				)
			);
		}

		return true;
	}

	/**
	 * Estima el tamaño total del sitio en bytes.
	 *
	 * Excluye el directorio de respaldos para evitar que los propios ZIPs
	 * inflen la estimación y generen falsos errores de espacio insuficiente.
	 *
	 * @return int Bytes estimados.
	 */
	protected function estimate_site_size(): int {
		$size = 0;

		// Normalizar el directorio de respaldos para excluirlo durante la iteración.
		$backups_real = realpath( SSC_BACKUPS_DIR );

		if ( is_dir( WP_CONTENT_DIR ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( WP_CONTENT_DIR, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				// Excluir archivos dentro del directorio de respaldos.
				if ( $backups_real && strpos( $file->getRealPath(), $backups_real ) === 0 ) {
					continue;
				}
				$size += $file->getSize();
			}
		}

		global $wpdb;
		$db_size = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s",
				DB_NAME
			)
		);
		$size += (int) $db_size;

		return $size;
	}

	/**
	 * Intenta adquirir el lock anti-concurrencia.
	 *
	 * Acepta el caso en que el AJAX handler ya pre-asignó el lock con este
	 * job_id antes de enviar la respuesta HTTP (para reducir la ventana de
	 * condición de carrera). Si el lock existe y pertenece a este job, lo
	 * considera válido sin sobrescribir.
	 *
	 * @return bool
	 */
	protected function acquire_lock(): bool {
		$existing = get_transient( 'ssc_backup_running' );
		if ( false !== $existing ) {
			// Lock existe — es nuestro si coincide el job_id.
			return $existing === $this->job_id;
		}
		set_transient( 'ssc_backup_running', $this->job_id, self::LOCK_TTL );
		return true;
	}

	/**
	 * Libera el lock anti-concurrencia.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		delete_transient( 'ssc_backup_running' );
	}

	/**
	 * Actualiza el estado del job en el transient.
	 *
	 * @param string $status  Estado ('running'|'completed'|'failed').
	 * @param string $message Mensaje del paso actual.
	 * @param int    $percent Porcentaje 0-100.
	 * @return void
	 */
	private function update_state( string $status, string $message, int $percent ): void {
		$this->update_state_raw( array(
			'status'  => $status,
			'message' => $message,
			'percent' => $percent,
		) );
	}

	/**
	 * Escribe el transient de estado directamente con un array arbitrario.
	 *
	 * @param array $state Datos de estado.
	 * @return void
	 */
	private function update_state_raw( array $state ): void {
		set_transient( 'ssc_backup_state_' . $this->job_id, $state, self::STATE_TTL );
	}

	/**
	 * Marca el job como fallido.
	 *
	 * @param string $message Mensaje de error.
	 * @return void
	 */
	private function fail( string $message ): void {
		$this->update_state( 'failed', $message, 0 );
		SSC_Logger::error( 'backup_manager', $message );
		SSC_Logger::clear_job_context();
		do_action( 'ssc_backup_failed', $this->job_id, $message );
	}

	/**
	 * Escribe un archivo .meta JSON junto al ZIP con el tipo y etiqueta del respaldo.
	 *
	 * El .meta permite al listado distinguir entre respaldos manuales y automáticos
	 * sin necesidad de abrir el ZIP ni parsear el nombre del archivo.
	 *
	 * Formato: { "type": "manual|pre-restore|scheduled", "label": "...", "created_by": "..." }
	 *
	 * @param string $zip_path Ruta absoluta al archivo ZIP.
	 * @param string $label    Etiqueta del respaldo.
	 * @param string $type     Tipo: 'manual', 'pre-restore', 'scheduled', etc.
	 * @return void
	 */
	private function write_meta( string $zip_path, string $label, string $type ): void {
		$current_user = wp_get_current_user();
		$created_by   = $current_user && $current_user->ID ? $current_user->user_login : 'system';

		$meta = array(
			'type'       => $type,
			'label'      => $label,
			'created_by' => $created_by,
		);

		$meta_path = $zip_path . '.meta';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $meta_path, wp_json_encode( $meta, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Elimina archivos temporales en caso de error.
	 *
	 * @param string $sql_path Ruta al archivo SQL temporal.
	 * @param string $zip_path Ruta al ZIP incompleto.
	 * @return void
	 */
	private function cleanup_temp_files( string $sql_path, string $zip_path ): void {
		if ( file_exists( $sql_path ) ) {
			@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( file_exists( $zip_path ) ) {
			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$meta_path = $zip_path . '.meta';
		if ( file_exists( $meta_path ) ) {
			@unlink( $meta_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}
