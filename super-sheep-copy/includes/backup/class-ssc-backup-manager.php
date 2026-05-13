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
		$this->settings = wp_parse_args(
			(array) get_option( 'ssc_settings', array() ),
			array( 'chunk_size' => SSC_Files_Backup::FILES_PER_CHUNK )
		);

		// Elevar límites si el servidor lo permite.
		wp_raise_memory_limit( 'admin' );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged

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
				@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
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

	/**
	 * Ejecuta el flujo chunked completo en el proceso actual.
	 *
	 * Usado por flujos internos que no tienen un navegador llamando a advance()
	 * repetidamente, como el snapshot previo a una restauración. Mantiene una sola
	 * ruta de creación de respaldos: init() + advance().
	 *
	 * @param string $label  Etiqueta opcional del respaldo.
	 * @param string $type   Tipo: 'manual', 'pre-restore', etc.
	 * @param string $job_id ID de job opcional.
	 * @return string|WP_Error Job ID en éxito, WP_Error en fallo.
	 */
	public function run_to_completion( string $label = '', string $type = 'manual', string $job_id = '' ) {
		$job_id = $job_id ?: $this->generate_job_id();

		$result = $this->init( $label, $type, $job_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$max_iterations = 10000;
		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$state = $this->advance( $job_id );
			if ( is_wp_error( $state ) ) {
				return $state;
			}

			if ( ! is_array( $state ) ) {
				return new WP_Error(
					'backup_invalid_state',
					__( 'El respaldo devolvió un estado inválido.', 'super-sheep-copy' )
				);
			}

			if ( 'completed' === ( $state['status'] ?? '' ) ) {
				return $job_id;
			}

			if ( 'failed' === ( $state['status'] ?? '' ) ) {
				return new WP_Error(
					'backup_failed',
					isset( $state['message'] ) ? (string) $state['message'] : __( 'El respaldo falló.', 'super-sheep-copy' )
				);
			}
		}

		$this->job_id = $job_id;
		$this->fail( __( 'El respaldo excedió el límite interno de iteraciones.', 'super-sheep-copy' ) );
		return new WP_Error(
			'backup_iteration_limit',
			__( 'El respaldo excedió el límite interno de iteraciones.', 'super-sheep-copy' )
		);
	}

	// ── API resumable (chunked) ───────────────────────────────────────────────

	/**
	 * Fase de inicialización del respaldo resumable.
	 *
	 * Realiza el volcado de BD, abre el ZIP, añade database.sql, escanea los
	 * archivos del sitio y persiste el estado en el transient para que las
	 * llamadas sucesivas a advance() continúen el proceso.
	 *
	 * @param string $label  Etiqueta opcional del respaldo.
	 * @param string $type   Tipo: 'manual', 'pre-restore', etc.
	 * @param string $job_id ID de job pre-generado (requerido para la respuesta AJAX).
	 * @return string|WP_Error Job ID en éxito, WP_Error en fallo.
	 */
	public function init( string $label, string $type, string $job_id ) {
		$this->job_id   = $job_id;
		$this->settings = wp_parse_args(
			(array) get_option( 'ssc_settings', array() ),
			array( 'chunk_size' => SSC_Files_Backup::FILES_PER_CHUNK )
		);

		wp_raise_memory_limit( 'admin' );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged

		do_action( 'ssc_backup_started', $this->job_id );

		// 1. Pre-check de espacio en disco.
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
		SSC_Logger::set_job_context( $this->job_id );
		SSC_Logger::info(
			'backup_manager',
			sprintf(
				'Respaldo chunked iniciado. Tipo: %s%s',
				$type,
				$label ? ' / Etiqueta: ' . $label : ''
			)
		);

		$filename  = $this->generate_filename( $label );
		$zip_path  = SSC_BACKUPS_DIR . $filename;
		$sql_path  = sys_get_temp_dir() . '/ssc_db_' . $this->job_id . '.sql';
		$list_file = SSC_BACKUPS_DIR . 'ssc_files_' . $this->job_id . '.json';

		// 3. Dump de base de datos.
		$this->update_state( 'running', __( 'Volcando base de datos…', 'super-sheep-copy' ), 5 );
		$db_backup = new SSC_Database_Backup( $sql_path );
		$result    = $db_backup->run();
		if ( is_wp_error( $result ) ) {
			$this->release_lock();
			$this->fail( $result->get_error_message() );
			@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			return $result;
		}

		$db_size = file_exists( $sql_path ) ? (int) filesize( $sql_path ) : 0;

		// 4. Abrir ZIP y añadir database.sql.
		$this->update_state( 'running', __( 'Preparando empaquetado…', 'super-sheep-copy' ), 15 );
		$zip    = new SSC_Zip_Writer( $zip_path );
		$result = $zip->open();
		if ( is_wp_error( $result ) ) {
			$this->release_lock();
			$this->fail( $result->get_error_message() );
			@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			return $result;
		}

		$result = $zip->add_file( $sql_path, 'database.sql' );
		if ( is_wp_error( $result ) ) {
			$zip->close();
			$this->release_lock();
			$this->fail( $result->get_error_message() );
			@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			return $result;
		}

		$result = $zip->close();
		if ( is_wp_error( $result ) ) {
			$this->release_lock();
			$this->fail( $result->get_error_message() );
			@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			return $result;
		}

		// El SQL puede borrarse ahora que está en el ZIP.
		@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink

		// 5. Escanear archivos del sitio.
		$this->update_state( 'running', __( 'Escaneando archivos del sitio…', 'super-sheep-copy' ), 20 );
		$files_backup = new SSC_Files_Backup( new SSC_Zip_Writer( $zip_path ), $this->settings );
		$total_files  = $files_backup->scan( $list_file );
		if ( is_wp_error( $total_files ) ) {
			$this->release_lock();
			$this->fail( $total_files->get_error_message() );
			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			return $total_files;
		}

		// 6. Guardar estado para las llamadas continue.
		$this->update_state_raw( array(
			'status'      => 'running',
			'phase'       => 'files',
			'message'     => __( 'Empaquetando archivos…', 'super-sheep-copy' ),
			'percent'     => 25,
			'zip_filename' => $filename,
			'list_file'   => $list_file,
			'next_offset' => 0,
			'total_files' => (int) $total_files,
			'files_done'  => 0,
			'db_size'     => $db_size,
			'label'       => $label,
			'type'        => $type,
		) );

		SSC_Logger::info(
			'backup_manager',
			sprintf( 'Init completado. Total archivos a empaquetar: %d.', (int) $total_files )
		);
		SSC_Logger::clear_job_context();

		return $this->job_id;
	}

	/**
	 * Avanza el respaldo chunked procesando el siguiente lote de archivos.
	 *
	 * Debe llamarse repetidamente hasta que el estado devuelto tenga
	 * status === 'completed' o status === 'failed'.
	 *
	 * @param string $job_id ID del job devuelto por init().
	 * @return array|WP_Error Estado actualizado o WP_Error si el estado no existe.
	 */
	public function advance( string $job_id ) {
		$state = get_transient( 'ssc_backup_state_' . $job_id );

		if ( false === $state || ! is_array( $state ) || ! isset( $state['phase'] ) ) {
			return new WP_Error(
				'state_not_found',
				__( 'Estado del job no encontrado o expirado.', 'super-sheep-copy' )
			);
		}

		$this->job_id   = $job_id;
		$this->settings = wp_parse_args(
			(array) get_option( 'ssc_settings', array() ),
			array( 'chunk_size' => SSC_Files_Backup::FILES_PER_CHUNK )
		);

		SSC_Logger::set_job_context( $job_id );

		if ( 'files' === $state['phase'] ) {
			// Per-job lock: prevents two concurrent advance() calls from processing the
			// same offset (e.g. when the JS 90s timeout fires before close() finishes).
			$adv_lock = 'ssc_adv_' . $job_id;
			if ( get_transient( $adv_lock ) ) {
				SSC_Logger::info( 'backup_manager', 'advance() ya en ejecución para este job, retornando estado actual.' );
				SSC_Logger::clear_job_context();
				return $state;
			}
			set_transient( $adv_lock, 1, 30 );

			$zip_path    = SSC_BACKUPS_DIR . $state['zip_filename'];
			$list_file   = $state['list_file'];
			$offset      = (int) ( isset( $state['next_offset'] ) ? $state['next_offset'] : 0 );
			$files_done  = (int) ( isset( $state['files_done'] )  ? $state['files_done']  : 0 );
			$total_files = (int) ( isset( $state['total_files'] ) ? $state['total_files'] : 1 );

			if ( ! file_exists( $zip_path ) ) {
				delete_transient( $adv_lock );
				$this->fail_with_cleanup( $state, __( 'ZIP no encontrado. El proceso de respaldo no puede continuar.', 'super-sheep-copy' ) );
				SSC_Logger::clear_job_context();
				return new WP_Error( 'zip_missing', __( 'ZIP no encontrado.', 'super-sheep-copy' ) );
			}

			if ( ! file_exists( $list_file ) ) {
				delete_transient( $adv_lock );
				$this->fail_with_cleanup( $state, __( 'Lista de archivos no encontrada. El proceso de respaldo no puede continuar.', 'super-sheep-copy' ) );
				SSC_Logger::clear_job_context();
				return new WP_Error( 'list_missing', __( 'Lista de archivos no encontrada.', 'super-sheep-copy' ) );
			}

			$zip    = new SSC_Zip_Writer( $zip_path );
			$result = $zip->reopen();
			if ( is_wp_error( $result ) ) {
				delete_transient( $adv_lock );
				$this->fail_with_cleanup( $state, $result->get_error_message() );
				SSC_Logger::clear_job_context();
				return $result;
			}

			$files_backup = new SSC_Files_Backup( $zip, $this->settings );
			$batch        = $files_backup->run_batch( $list_file, $offset, 500 );
			if ( is_wp_error( $batch ) ) {
				$zip->close();
				delete_transient( $adv_lock );
				$this->fail_with_cleanup( $state, $batch->get_error_message() );
				SSC_Logger::clear_job_context();
				return $batch;
			}

			$result = $zip->close();
			if ( is_wp_error( $result ) ) {
				delete_transient( $adv_lock );
				$this->fail_with_cleanup( $state, $result->get_error_message() );
				SSC_Logger::clear_job_context();
				return $result;
			}

			$new_files_done = $files_done + (int) $batch['files_added'];
			$percent        = min( 89, 25 + (int) ( ( $new_files_done / max( 1, $total_files ) ) * 65 ) );

			if ( $batch['done'] ) {
				// Último lote — finalizar el respaldo.
				$finalized = $this->finalize_backup( $state, $new_files_done );
				delete_transient( $adv_lock );
				SSC_Logger::clear_job_context();
				return $finalized;
			}

			// Hay más lotes — persistir estado y devolver.
			$new_state = array_merge(
				$state,
				array(
					'message'     => sprintf(
						/* translators: 1: files done, 2: total files */
						__( 'Empaquetando archivos (%1$d de %2$d)…', 'super-sheep-copy' ),
						$new_files_done,
						$total_files
					),
					'percent'     => $percent,
					'next_offset' => (int) $batch['next_offset'],
					'files_done'  => $new_files_done,
				)
			);
			$this->update_state_raw( $new_state );
			delete_transient( $adv_lock );
			SSC_Logger::clear_job_context();
			return $new_state;
		}

		// Fase desconocida o ya completada — devolver el estado tal cual.
		SSC_Logger::clear_job_context();
		return $state;
	}

	/**
	 * Finaliza el respaldo: añade el manifest, calcula el checksum y libera recursos.
	 *
	 * @param array $state      Estado actual del job.
	 * @param int   $file_count Número total de archivos añadidos al ZIP.
	 * @return array|WP_Error Estado 'completed' en éxito, WP_Error en fallo crítico.
	 */
	private function finalize_backup( array $state, int $file_count ) {
		$zip_path  = SSC_BACKUPS_DIR . $state['zip_filename'];
		$filename  = $state['zip_filename'];
		$list_file = $state['list_file'];

		$this->update_state_raw( array_merge( $state, array(
			'message' => __( 'Generando manifest…', 'super-sheep-copy' ),
			'percent' => 90,
		) ) );

		$zip    = new SSC_Zip_Writer( $zip_path );
		$result = $zip->reopen();
		if ( is_wp_error( $result ) ) {
			$this->fail_with_cleanup( $state, $result->get_error_message() );
			return $result;
		}

		// Construir manifest.
		$manifest = new SSC_Manifest();
		$manifest->set_file_count( $file_count );
		$manifest->set_db_size( isset( $state['db_size'] ) ? (int) $state['db_size'] : 0 );
		$manifest->set_include_core( ! empty( $this->settings['include_core'] ) );

		$result = $zip->add_from_string( 'manifest.json', $manifest->to_json() );
		if ( is_wp_error( $result ) ) {
			$zip->close();
			$this->fail_with_cleanup( $state, $result->get_error_message() );
			return $result;
		}

		$result = $zip->close();
		if ( is_wp_error( $result ) ) {
			$this->fail_with_cleanup( $state, $result->get_error_message() );
			return $result;
		}

		// Calcular checksum.
		$this->update_state_raw( array_merge( $state, array(
			'message' => __( 'Calculando checksum…', 'super-sheep-copy' ),
			'percent' => 95,
		) ) );

		$hash = $zip->write_checksum();
		if ( is_wp_error( $hash ) ) {
			SSC_Logger::warn( 'backup_manager', 'No se pudo calcular checksum: ' . $hash->get_error_message() );
			$hash = '';
		}

		// Escribir .meta y limpiar archivos temporales.
		$this->write_meta(
			SSC_BACKUPS_DIR . $filename,
			isset( $state['label'] ) ? $state['label'] : '',
			isset( $state['type'] )  ? $state['type']  : 'manual'
		);

		if ( file_exists( $list_file ) ) {
			@unlink( $list_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$this->release_lock();
		update_option( 'ssc_last_backup', gmdate( 'Y-m-d\TH:i:s\Z' ) );

		$completed = array(
			'status'   => 'completed',
			'message'  => __( 'Respaldo completado correctamente.', 'super-sheep-copy' ),
			'percent'  => 100,
			'filename' => $filename,
			'checksum' => $hash,
			'phase'    => 'completed',
		);

		$this->update_state_raw( $completed );
		SSC_Logger::info( 'backup_manager', 'Respaldo completado.', $filename );
		do_action( 'ssc_backup_completed', $this->job_id, $filename );

		return $completed;
	}

	/**
	 * Limpia archivos temporales del respaldo chunked y marca el job como fallido.
	 *
	 * @param array  $state   Estado actual del job (puede contener 'list_file' y 'zip_filename').
	 * @param string $message Mensaje de error.
	 * @return void
	 */
	private function fail_with_cleanup( array $state, string $message ): void {
		if ( isset( $state['list_file'] ) && file_exists( $state['list_file'] ) ) {
			@unlink( $state['list_file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		if ( isset( $state['zip_filename'] ) ) {
			$zip_path = SSC_BACKUPS_DIR . $state['zip_filename'];
			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $zip_path . '.sha256' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $zip_path . '.meta' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$this->fail( $message );
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
			@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( file_exists( $zip_path ) ) {
			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		$meta_path = $zip_path . '.meta';
		if ( file_exists( $meta_path ) ) {
			@unlink( $meta_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}
