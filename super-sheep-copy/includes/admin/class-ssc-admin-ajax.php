<?php
/**
 * Endpoints AJAX y admin-post del plugin.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Admin_Ajax
 *
 * Registra y maneja:
 *  - Acciones wp_ajax_ssc_*  (AJAX con respuesta JSON).
 *  - Acciones admin_post_ssc_* (formularios y descarga de archivos).
 *
 * Cada handler verifica capacidad y nonce antes de cualquier side effect.
 */
class SSC_Admin_Ajax {

	/**
	 * Registra los hooks AJAX y admin-post.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// AJAX (respuesta JSON).
		$ajax_actions = array(
			'ssc_create_backup',
			'ssc_get_backup_status',
			'ssc_delete_backup',
			'ssc_restore_backup',
			'ssc_get_restore_status',
			'ssc_get_manifest',
		);
		foreach ( $ajax_actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'dispatch_ajax' ) );
		}

		// Admin-post (formularios y descarga).
		add_action( 'admin_post_ssc_download_backup', array( $this, 'handle_download_backup' ) );
		add_action( 'admin_post_ssc_upload_restore',  array( $this, 'handle_upload_restore' ) );
		add_action( 'admin_post_ssc_clear_log',       array( $this, 'handle_clear_log' ) );
	}

	// ── AJAX dispatcher ───────────────────────────────────────────────────────

	/**
	 * Dispatcher central: valida nonce + capacidad y redirige al handler.
	 *
	 * @return void
	 */
	public function dispatch_ajax(): void {
		if ( ! check_ajax_referer( 'ssc_ajax_nonce', 'nonce', false ) ) {
			SSC_Logger::warn( 'dispatch_ajax', 'Solicitud AJAX rechazada: nonce inválido o expirado.' );
			wp_send_json_error( array( 'message' => __( 'Sesión expirada. Recarga la página e inténtalo de nuevo.', 'super-sheep-copy' ) ), 403 );
		}

		if ( ! current_user_can( SSC_Capabilities::CAP ) ) {
			SSC_Logger::warn( 'dispatch_ajax', 'Solicitud AJAX rechazada: permisos insuficientes.' );
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para realizar esta acción.', 'super-sheep-copy' ) ), 403 );
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';

		switch ( $action ) {
			case 'ssc_create_backup':
				$this->handle_create_backup();
				break;
			case 'ssc_get_backup_status':
				$this->handle_get_backup_status();
				break;
			case 'ssc_delete_backup':
				$this->handle_delete_backup();
				break;
			case 'ssc_restore_backup':
				$this->handle_restore_backup();
				break;
			case 'ssc_get_restore_status':
				$this->handle_get_restore_status();
				break;
			case 'ssc_get_manifest':
				$this->handle_get_manifest();
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Acción desconocida.', 'super-sheep-copy' ) ), 400 );
		}
	}

	// ── Handlers AJAX ─────────────────────────────────────────────────────────

	/**
	 * Inicia la creación de un nuevo respaldo.
	 *
	 * Envía el job_id al navegador inmediatamente (cerrando la conexión HTTP vía
	 * fastcgi_finish_request) y ejecuta el respaldo en segundo plano. El cliente
	 * debe hacer polling a ssc_get_backup_status para conocer el resultado.
	 *
	 * @return void
	 */
	private function handle_create_backup(): void {
		// Rechazar si ya hay un respaldo en curso antes de enviar respuesta.
		if ( get_transient( 'ssc_backup_running' ) ) {
			$this->send_json_clean( false, array( 'message' => __( 'Ya hay un respaldo en curso. Inténtalo de nuevo en unos minutos.', 'super-sheep-copy' ) ) );
		}

		$label  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$job_id = substr( md5( uniqid( 'ssc_', true ) ), 0, 16 );

		// Pre-asignar el lock anti-concurrencia ANTES de enviar la respuesta HTTP.
		// Esto cierra la ventana de condición de carrera donde dos peticiones
		// simultáneas podrían pasar el check inicial antes de que start() llame
		// a acquire_lock(). El método acquire_lock() reconoce y acepta este lock.
		set_transient( 'ssc_backup_running', $job_id, SSC_Backup_Manager::LOCK_TTL );

		// Estado inicial en el transient para que el primer poll tenga algo que leer.
		set_transient(
			'ssc_backup_state_' . $job_id,
			array(
				'status'  => 'running',
				'message' => __( 'Iniciando respaldo…', 'super-sheep-copy' ),
				'percent' => 0,
			),
			SSC_Backup_Manager::STATE_TTL
		);

		// Enviar job_id al navegador y cerrar la conexión HTTP ahora.
		// PHP sigue ejecutando el respaldo en segundo plano.
		$this->send_json_and_continue( true, array( 'job_id' => $job_id ) );

		// ── Desde aquí la respuesta HTTP ya fue enviada ───────────────────────
		// Reconnect to DB — fastcgi_finish_request() can close the MySQL socket.
		global $wpdb;
		$wpdb->check_connection( false );

		ob_start();
		$manager = new SSC_Backup_Manager();
		$result  = $manager->start( $label, 'manual', $job_id );
		$stray   = ob_get_clean();
		if ( $stray ) {
			SSC_Logger::warn( 'create_backup', 'Salida inesperada durante el respaldo: ' . substr( wp_strip_all_tags( $stray ), 0, 300 ) );
		}

		if ( is_wp_error( $result ) ) {
			SSC_Logger::error( 'create_backup', $result->get_error_message() );
		}

		die();
	}

	/**
	 * Devuelve el estado actual de un job de respaldo en curso.
	 *
	 * @return void
	 */
	private function handle_get_backup_status(): void {
		$job_id = isset( $_POST['job_id'] ) ? sanitize_key( $_POST['job_id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => __( 'job_id requerido.', 'super-sheep-copy' ) ), 400 );
		}

		$state = get_transient( 'ssc_backup_state_' . $job_id );
		if ( false === $state ) {
			wp_send_json_error( array( 'message' => __( 'Job no encontrado o expirado.', 'super-sheep-copy' ) ), 404 );
		}

		wp_send_json_success( $state );
	}

	/**
	 * Elimina un respaldo existente.
	 *
	 * @return void
	 */
	private function handle_delete_backup(): void {
		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $filename ) {
			wp_send_json_error( array( 'message' => __( 'Nombre de archivo requerido.', 'super-sheep-copy' ) ), 400 );
		}

		$result = SSC_Security::delete_backup_file( $filename );
		if ( is_wp_error( $result ) ) {
			SSC_Logger::error( 'delete_backup', $result->get_error_message(), $filename );
			wp_send_json_error( array( 'message' => __( 'No se pudo eliminar el respaldo. Revisa el log.', 'super-sheep-copy' ) ) );
		}

		SSC_Logger::info( 'delete_backup', 'Respaldo eliminado.', $filename );
		wp_send_json_success( array( 'message' => __( 'Respaldo eliminado correctamente.', 'super-sheep-copy' ) ) );
	}

	/**
	 * Inicia la restauración de un respaldo existente.
	 *
	 * Envía el job_id al navegador inmediatamente (cerrando la conexión HTTP vía
	 * fastcgi_finish_request) y ejecuta la restauración en segundo plano. El cliente
	 * debe hacer polling a ssc_get_restore_status para conocer el resultado.
	 *
	 * @return void
	 */
	private function handle_restore_backup(): void {
		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $filename ) {
			$this->send_json_clean( false, array( 'message' => __( 'Nombre de archivo requerido.', 'super-sheep-copy' ) ) );
		}

		// Rechazar si ya hay una restauración en curso antes de enviar respuesta.
		if ( get_transient( 'ssc_restore_running' ) ) {
			$this->send_json_clean( false, array( 'message' => __( 'Ya hay una restauración en curso.', 'super-sheep-copy' ) ) );
		}

		// Verificar que el archivo existe antes de responder.
		$zip_path = SSC_Security::resolve_backup_path( $filename );
		if ( is_wp_error( $zip_path ) ) {
			$this->send_json_clean( false, array( 'message' => __( 'Archivo de respaldo no encontrado.', 'super-sheep-copy' ) ) );
		}

		$job_id = substr( md5( uniqid( 'ssc_restore_', true ) ), 0, 16 );

		// Estado inicial en el transient para que el primer poll tenga algo que leer.
		set_transient(
			'ssc_restore_state_' . $job_id,
			array(
				'status'  => 'running',
				'message' => __( 'Iniciando restauración…', 'super-sheep-copy' ),
				'percent' => 0,
			),
			SSC_Restore_Manager::STATE_TTL
		);

		// Enviar job_id al navegador y cerrar la conexión HTTP ahora.
		// PHP sigue ejecutando la restauración en segundo plano.
		$this->send_json_and_continue( true, array( 'job_id' => $job_id ) );

		// ── Desde aquí la respuesta HTTP ya fue enviada ───────────────────────
		// Reconnect to DB — fastcgi_finish_request() can close the MySQL socket.
		global $wpdb;
		$wpdb->check_connection( false );

		ob_start();
		$manager = new SSC_Restore_Manager();
		$result  = $manager->start( $filename, false, $job_id );
		$stray   = ob_get_clean();
		if ( $stray ) {
			SSC_Logger::warn( 'restore_backup', 'Salida inesperada durante la restauración: ' . substr( wp_strip_all_tags( $stray ), 0, 300 ) );
		}

		if ( is_wp_error( $result ) ) {
			SSC_Logger::error( 'restore_backup', $result->get_error_message(), $filename );
		}

		die();
	}

	/**
	 * Devuelve el estado actual de un job de restauración en curso.
	 *
	 * @return void
	 */
	private function handle_get_restore_status(): void {
		$job_id = isset( $_POST['job_id'] ) ? sanitize_key( $_POST['job_id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => __( 'job_id requerido.', 'super-sheep-copy' ) ), 400 );
		}

		$state = get_transient( 'ssc_restore_state_' . $job_id );
		if ( false === $state ) {
			// Devolver 200 con success=false (no 404) para que el JS pueda distinguir
			// entre "transient aún no existe / fue reemplazado por el import de DB"
			// y un error de red real. El polling JS usa notFoundCount para este caso.
			wp_send_json_error( array( 'message' => __( 'Job no encontrado o expirado.', 'super-sheep-copy' ) ) );
		}

		wp_send_json_success( $state );
	}

	/**
	 * Devuelve el manifest.json de un respaldo.
	 *
	 * @return void
	 */
	private function handle_get_manifest(): void {
		$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $filename ) {
			wp_send_json_error( array( 'message' => __( 'Nombre de archivo requerido.', 'super-sheep-copy' ) ), 400 );
		}

		$path = SSC_Security::resolve_backup_path( $filename );
		if ( is_wp_error( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Archivo no encontrado.', 'super-sheep-copy' ) ), 404 );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo abrir el archivo ZIP.', 'super-sheep-copy' ) ) );
		}

		$manifest_raw = $zip->getFromName( 'manifest.json' );
		$zip->close();

		if ( false === $manifest_raw ) {
			wp_send_json_error( array( 'message' => __( 'El archivo no contiene manifest.json.', 'super-sheep-copy' ) ) );
		}

		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) ) {
			wp_send_json_error( array( 'message' => __( 'El manifest.json no es válido.', 'super-sheep-copy' ) ) );
		}

		wp_send_json_success( $manifest );
	}

	// ── Helpers: respuesta JSON ───────────────────────────────────────────────

	/**
	 * Envía una respuesta JSON al navegador, cierra la conexión HTTP y permite
	 * que PHP continúe ejecutando en segundo plano.
	 *
	 * Estrategia:
	 *  - fastcgi_finish_request() (PHP-FPM / FastCGI, incluido MAMP): cierra el
	 *    socket HTTP de forma limpia mientras PHP sigue corriendo. Solución ideal
	 *    para el timeout de 30 s de FastCGI que corta respaldos/restauraciones largas.
	 *  - Fallback (mod_php): Content-Length + Connection: close + flush(). Funciona
	 *    en la mayoría de configuraciones simples; no garantizado en todos los setups.
	 *
	 * IMPORTANTE: Esta función NO llama a die(). El caller debe hacerlo al terminar
	 * el trabajo de fondo para que PHP libere recursos correctamente.
	 *
	 * @param bool  $success True → {success:true}.
	 * @param array $data    Payload a incluir en la respuesta.
	 * @return void
	 */
	private function send_json_and_continue( bool $success, array $data ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$envelope = array(
			'success' => $success,
			'data'    => $data,
		);

		$json = wp_json_encode( $envelope );

		header( 'Content-Encoding: identity' );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Length: ' . strlen( $json ) );
		header( 'Connection: close' );
		// Disable Nginx/proxy response buffering so the JSON is forwarded to the
		// browser immediately, before PHP runs the long background task.
		header( 'X-Accel-Buffering: no' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $json;

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// PHP-FPM / FastCGI (MAMP, nginx, etc.): cierra el socket HTTP limpiamente.
			fastcgi_finish_request();
		} else {
			// mod_php: vaciar buffers y esperar que el SO envíe la respuesta.
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			flush();
		}

		// PHP continúa en segundo plano; no interrumpir si el cliente desconecta.
		ignore_user_abort( true );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged
	}

	/**
	 * Envía una respuesta JSON con Content-Length y descarta todos los buffers
	 * de salida activos antes de hacerlo.
	 *
	 * Por qué es necesario:
	 *  - wp_send_json_success/error no fija Content-Length.
	 *  - WordPress dispara do_action('shutdown') después de die(), y cualquier
	 *    plugin/tema enganchado puede emitir HTML o texto de depuración DESPUÉS
	 *    del JSON. Sin Content-Length, jQuery acumula el cuerpo completo, falla
	 *    al parsear JSON y llama a .fail() aunque la operación haya completado.
	 *  - Content-Length le indica al navegador exactamente cuántos bytes leer;
	 *    el resto de la respuesta (shutdown output) es ignorado.
	 *  - Content-Encoding: identity evita que mod_deflate de Apache comprima
	 *    el cuerpo (lo que invalidaría el valor de Content-Length).
	 *
	 * @param bool  $success True → {success:true}, false → {success:false}.
	 * @param array $data    Payload de la respuesta.
	 * @return never
	 */
	private function send_json_clean( bool $success, array $data ): void {
		// 1. Descartar todos los buffers de salida activos (notices, warnings, etc.)
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$envelope = array(
			'success' => $success,
			'data'    => $data,
		);

		$json = wp_json_encode( $envelope );

		// 2. Evitar compresión para que Content-Length sea exacto.
		header( 'Content-Encoding: identity' );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Length: ' . strlen( $json ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $json;
		flush();
		die();
	}

	// ── Handlers admin-post ───────────────────────────────────────────────────

	/**
	 * Descarga segura de un archivo de respaldo.
	 *
	 * @return void
	 */
	public function handle_download_backup(): void {
		// Verificar nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'ssc_action_download' ) ) {
			wp_die( esc_html__( 'Enlace de descarga no válido o expirado.', 'super-sheep-copy' ), 403 );
		}

		// Verificar capacidad.
		if ( ! current_user_can( SSC_Capabilities::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos para descargar respaldos.', 'super-sheep-copy' ), 403 );
		}

		$filename = isset( $_GET['filename'] ) ? sanitize_file_name( rawurldecode( wp_unslash( $_GET['filename'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! $filename ) {
			SSC_Logger::warn( 'download_backup', 'Intento de descarga sin nombre de archivo.' );
			wp_die( esc_html__( 'Nombre de archivo no especificado.', 'super-sheep-copy' ), 400 );
		}

		// Validar la ruta antes de loggear para distinguir éxito de fallo.
		$resolved = SSC_Security::resolve_backup_path( $filename );
		if ( is_wp_error( $resolved ) ) {
			SSC_Logger::warn( 'download_backup', 'Descarga rechazada: ' . $resolved->get_error_message(), $filename );
			wp_die( esc_html__( 'Archivo no encontrado o acceso denegado.', 'super-sheep-copy' ), 404 );
		}

		SSC_Logger::info( 'download_backup', 'Descarga enviada.', $filename );
		SSC_Security::stream_download( $filename );
	}

	/**
	 * Procesa la subida de un ZIP para restaurar.
	 *
	 * @return void
	 */
	public function handle_upload_restore(): void {
		// Verificar nonce.
		if ( ! isset( $_POST['ssc_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ssc_nonce'] ), 'ssc_action_upload_restore' ) ) {
			wp_die( esc_html__( 'Token de seguridad no válido.', 'super-sheep-copy' ), 403 );
		}

		// Verificar capacidad.
		if ( ! current_user_can( SSC_Capabilities::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos para restaurar.', 'super-sheep-copy' ), 403 );
		}

		if ( empty( $_FILES['ssc_zip_file'] ) || UPLOAD_ERR_OK !== $_FILES['ssc_zip_file']['error'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			SSC_Logger::warn( 'upload_restore', 'Error de subida de archivo.' );
			wp_die( esc_html__( 'Error al subir el archivo. Inténtalo de nuevo.', 'super-sheep-copy' ), 400 );
		}

		$file = $_FILES['ssc_zip_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Validar el ZIP subido.
		$validation = SSC_Security::validate_uploaded_zip( $file );
		if ( is_wp_error( $validation ) ) {
			SSC_Logger::warn( 'upload_restore', 'Archivo subido inválido: ' . $validation->get_error_message(), sanitize_file_name( $file['name'] ) );
			wp_die( esc_html( $validation->get_error_message() ), 400 );
		}

		// Mover el archivo al directorio de respaldos con nombre seguro.
		$safe_name    = sanitize_file_name( $file['name'] );
		$timestamp    = gmdate( 'Ymd-His' );
		$hash         = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		$stored_name  = 'uploaded-' . $timestamp . '-' . $hash . '.zip';
		$stored_path  = SSC_BACKUPS_DIR . $stored_name;

		if ( ! move_uploaded_file( $file['tmp_name'], $stored_path ) ) { // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
			SSC_Logger::error( 'upload_restore', 'No se pudo guardar el archivo subido en el directorio de respaldos.', $safe_name );
			wp_die( esc_html__( 'No se pudo guardar el archivo subido.', 'super-sheep-copy' ), 500 );
		}

		SSC_Logger::info( 'upload_restore', 'Archivo subido correctamente. Iniciando restauración.', $stored_name );

		// Iniciar restauración.
		$manager = new SSC_Restore_Manager();
		$result  = $manager->start( $stored_name );

		if ( is_wp_error( $result ) ) {
			SSC_Logger::error( 'upload_restore', $result->get_error_message(), $stored_name );
			wp_safe_redirect( add_query_arg( 'ssc_error', '1', admin_url( 'admin.php?page=ssc-restore' ) ) );
			exit;
		}

		// Guardar job_id en sesión/transient para que la página lo recoja.
		set_transient( 'ssc_restore_job_' . get_current_user_id(), $result, 3600 );

		wp_safe_redirect( add_query_arg( 'ssc_job', $result, admin_url( 'admin.php?page=ssc-restore' ) ) );
		exit;
	}

	/**
	 * Limpia la tabla de auditoría.
	 *
	 * @return void
	 */
	public function handle_clear_log(): void {
		if ( ! isset( $_POST['ssc_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ssc_nonce'] ), 'ssc_action_clear_log' ) ) {
			wp_die( esc_html__( 'Token de seguridad no válido.', 'super-sheep-copy' ), 403 );
		}

		if ( ! current_user_can( SSC_Capabilities::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos para limpiar el log.', 'super-sheep-copy' ), 403 );
		}

		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}ssc_audit_log" ); // phpcs:ignore WordPress.DB

		SSC_Logger::info( 'clear_log', 'Tabla de auditoría limpiada.' );

		wp_safe_redirect( admin_url( 'admin.php?page=ssc-log&ssc_cleared=1' ) );
		exit;
	}
}
