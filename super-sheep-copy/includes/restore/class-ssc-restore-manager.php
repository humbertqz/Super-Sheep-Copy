<?php
/**
 * Orquestador del proceso de restauración completo.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Restore_Manager
 *
 * Coordina los pasos de la restauración siguiendo §6.2:
 *  1. Validación del ZIP (checksum, manifest, versión).
 *  2. Snapshot de seguridad (respaldo automático del estado actual).
 *  3. Modo mantenimiento (.maintenance).
 *  4. Extracción de archivos a tmp → deploy a ABSPATH.
 *  5. Importación de database.sql (con reescritura de prefijo si difiere).
 *  6. Reescritura serialize-safe de URLs (si el dominio cambió).
 *  7. Flush de rewrite rules + limpieza de cache.
 *  8. Eliminar .maintenance.
 *  9. Forzar logout de todas las sesiones.
 *
 * El progreso se persiste en el transient `ssc_restore_state_<job_id>`.
 */
class SSC_Restore_Manager {

	/**
	 * TTL del lock anti-concurrencia de restauraciones.
	 */
	const LOCK_TTL = 1800;

	/**
	 * TTL del transient de estado del job.
	 */
	const STATE_TTL = 3600;

	/**
	 * ID del job actual.
	 *
	 * @var string
	 */
	private string $job_id = '';

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Inicia el proceso de restauración.
	 *
	 * @param string $filename             Nombre del archivo ZIP (solo nombre, sin ruta).
	 * @param bool   $overwrite_wp_config  Si sobreescribir wp-config.php (por defecto false).
	 * @param string $job_id               ID de job pre-generado (opcional). Si se omite se
	 *                                     genera uno nuevo. Pasar un ID externo permite escribir
	 *                                     el estado inicial antes de enviar la respuesta HTTP.
	 * @return string|WP_Error Job ID o WP_Error.
	 */
	public function start( string $filename, bool $overwrite_wp_config = false, string $job_id = '' ) {
		$this->job_id = $job_id ?: $this->generate_job_id();

		wp_raise_memory_limit( 'admin' );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged

		// Verificar que solo hay una restauración en curso.
		if ( get_transient( 'ssc_restore_running' ) ) {
			return new WP_Error( 'restore_running', __( 'Ya hay una restauración en curso.', 'super-sheep-copy' ) );
		}
		set_transient( 'ssc_restore_running', $this->job_id, self::LOCK_TTL );

		$this->update_state( 'running', __( 'Iniciando restauración…', 'super-sheep-copy' ), 0 );

		// Establecer contexto de job para agrupar todos los logs de esta restauración.
		SSC_Logger::set_job_context( $this->job_id );

		SSC_Logger::info( 'restore_manager', 'Restauración iniciada.', $filename );
		do_action( 'ssc_restore_started', $this->job_id );

		// Resolver ruta del ZIP.
		$zip_path = SSC_Security::resolve_backup_path( $filename );
		if ( is_wp_error( $zip_path ) ) {
			$this->fail( $zip_path->get_error_message() );
			return $zip_path;
		}

		// 1. Validar ZIP + manifest.
		$this->update_state( 'running', __( 'Validando archivo de respaldo…', 'super-sheep-copy' ), 5 );
		$manifest_data = $this->validate_backup( $zip_path, $filename );
		if ( is_wp_error( $manifest_data ) ) {
			$this->fail( $manifest_data->get_error_message() );
			return $manifest_data;
		}

		// 2. Snapshot de seguridad del estado actual.
		$this->update_state( 'running', __( 'Creando respaldo de seguridad del estado actual…', 'super-sheep-copy' ), 10 );
		$snapshot_result = $this->create_safety_snapshot();
		if ( is_wp_error( $snapshot_result ) ) {
			// No es crítico; advertir y continuar.
			SSC_Logger::warn( 'restore_manager', 'No se pudo crear snapshot: ' . $snapshot_result->get_error_message() );
		}

		// 3. Modo mantenimiento.
		$this->enable_maintenance_mode();

		$tmp_dir = WP_CONTENT_DIR . '/uploads/ssc-tmp-' . $this->job_id;

		// Capturar URL y credenciales de BD del sitio destino ANTES de que la restauración
		// de archivos sobreescriba wp-config.php. Si se capturaran después, reflejarían los
		// valores del backup (servidor de origen), no los del servidor destino.
		$current_url  = rtrim( get_site_url(), '/' );
		$current_path = rtrim( ABSPATH, '/' );
		$current_db   = $this->capture_db_credentials();

		try {
			// 4. Extracción de archivos.
			$this->update_state( 'running', __( 'Extrayendo archivos…', 'super-sheep-copy' ), 20 );
			$files_restore = new SSC_Files_Restore( $zip_path, $tmp_dir, $overwrite_wp_config );
			$result        = $files_restore->run();
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}

			// 4a. Reescribir wp-config.php restaurado: URL constants, SSL flags y credenciales DB.
			// Las constantes PHP prevalecen sobre la DB; sin este paso el sitio queda con
			// los valores del servidor de origen (HTTPS incorrecto, BD inaccesible, etc.).
			if ( $overwrite_wp_config ) {
				$this->rewrite_wp_config_constants( $current_url, $current_db );
			}

			// 4b-pre. Eliminar drop-ins de caché con rutas absolutas del servidor origen.
			// Archivos como advanced-cache.php y advanced-headers.php son auto-generados
			// por plugins de caché y contienen rutas absolutas hardcodeadas del servidor
			// de producción. En el servidor destino esas rutas no existen y PHP lanza un
			// Fatal Error al intentar cargar WordPress. Los plugins regeneran estos archivos
			// en la primera carga del admin, por lo que eliminarlos es seguro.
			$this->purge_cache_dropins();

			// 4b. Preparar reemplazo diferido del propio plugin SSC si el respaldo incluye una versión distinta.
			$self_update_result = SSC_Self_Updater::stage_from_zip( $zip_path, $this->job_id );
			if ( is_wp_error( $self_update_result ) ) {
				SSC_Logger::warn( 'restore_manager', 'No se pudo preparar el reemplazo del plugin SSC: ' . $self_update_result->get_error_message() );
			} elseif ( $self_update_result ) {
				SSC_Logger::info( 'restore_manager', 'Reemplazo del plugin SSC programado para la siguiente carga del admin.' );
			}

			// 5. Importar DB.
			$this->update_state( 'running', __( 'Importando base de datos…', 'super-sheep-copy' ), 55 );
			$result = $this->import_database( $zip_path, $manifest_data );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}

			// 5b. Eliminar transients de lock SSC que puedan haber venido en la DB del
			//     respaldo (p.ej. ssc_backup_running de la web de origen). Si se dejan,
			//     al volver a loguearse el JS los detecta y muestra la barra de progreso
			//     de un respaldo/restauración que nunca existió en este sitio.
			$this->purge_stale_ssc_locks();

			// 5c. Validar que la importación dejó opciones críticas de WordPress.
			$result = $this->validate_restored_wordpress_options( $manifest_data );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}

			// 6. Reescritura de URLs si el dominio o el esquema cambió.
			$backup_url = rtrim( $manifest_data['site_url'] ?? '', '/' );

			// Comparar también el esquema: http://x !== https://x requiere reescritura.
			if ( $backup_url && $backup_url !== $current_url ) {
				$this->update_state( 'running', __( 'Reescribiendo URLs…', 'super-sheep-copy' ), 80 );

				$backup_path = rtrim( $manifest_data['abspath'] ?? '', '/' );

				$rewriter = new SSC_URL_Rewriter(
					$backup_url,
					$current_url,
					$backup_path !== $current_path ? $backup_path : '',
					$backup_path !== $current_path ? $current_path : ''
				);
				$counts = $rewriter->run();
				SSC_Logger::info( 'restore_manager', 'Reescritura de URLs completada: ' . wp_json_encode( $counts ) );
			}

			// 6b. Reparar corrupción placeholder_escape() en toda la tabla de opciones.
			//     Algunos backups traen '{sha256hash}' en lugar de '%' en valores como
			//     permalink_structure o datos serializados de plugins (Yoast SEO, etc.).
			$this->repair_placeholder_escape_corruption();

			// 6c. En restauraciones hacia HTTP, desactivar restos de plugins/reglas SSL
			//     que pueden forzar redirecciones a HTTPS del sitio de origen.
			$this->cleanup_http_destination_ssl_forcing( $current_url );

		} catch ( \Throwable $e ) {
			// Captura tanto \Exception como \Error (p.ej. TypeError, Error de propiedad).
			$this->disable_maintenance_mode();
			$this->release_lock();
			$this->fail( $e->getMessage() );
			do_action( 'ssc_restore_failed', $this->job_id, $e->getMessage() );
			return new WP_Error( 'restore_failed', $e->getMessage() );
		}

		// 7. Flush y limpieza de cache.
		$this->update_state( 'running', __( 'Limpiando cache y reglas de reescritura…', 'super-sheep-copy' ), 90 );
		$this->regenerate_htaccess_and_rules();
		wp_cache_flush();
		// Reescribir el transient de estado después del flush: en sitios con object cache
		// (Redis/Memcached) wp_cache_flush() vacía la caché, borrando el transient. Sin
		// este write, los polls vuelven a recibir "not found" hasta que se escribe 'completed'.
		$this->update_state( 'running', __( 'Limpiando cache y reglas de reescritura…', 'super-sheep-copy' ), 90 );

		// 8. Desactivar modo mantenimiento.
		$this->disable_maintenance_mode();

		// Liberar el lock y marcar como completado ANTES de destruir sesiones.
		// Si se hiciera al revés, el cliente detecta la sesión inválida (recibe -1/403),
		// muestra el modal de "completado" y el usuario vuelve a loguearse; pero si el
		// lock todavía existe al cargar la página, el JS reanuda el polling y no termina.
		$this->release_lock();

		$this->update_state_raw( array(
			'status'  => 'completed',
			'message' => __( 'Restauración completada. Se han cerrado todas las sesiones activas por seguridad.', 'super-sheep-copy' ),
			'percent' => 100,
		) );

		// 9. Forzar logout de todas las sesiones (al final, con lock ya liberado).
		$this->destroy_all_sessions();

		SSC_Logger::info( 'restore_manager', 'Restauración completada.', $filename );
		do_action( 'ssc_restore_completed', $this->job_id, $filename );

		SSC_Logger::clear_job_context();
		return $this->job_id;
	}

	// ── Validación ────────────────────────────────────────────────────────────

	/**
	 * Valida el ZIP de respaldo: checksum, estructura, manifest y versión.
	 *
	 * @param string $zip_path Ruta absoluta al ZIP.
	 * @param string $filename Nombre del archivo (para buscar .sha256).
	 * @return array|WP_Error Datos del manifest o WP_Error.
	 */
	private function validate_backup( string $zip_path, string $filename ) {
		// Verificar checksum si existe.
		$sha_path = $zip_path . '.sha256';
		if ( file_exists( $sha_path ) ) {
			$expected = trim( file_get_contents( $sha_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$actual   = hash_file( 'sha256', $zip_path );
			if ( $expected !== $actual ) {
				return new WP_Error(
					'checksum_mismatch',
					__( 'El checksum del archivo no coincide. El ZIP puede estar corrupto.', 'super-sheep-copy' )
				);
			}
		}

		// Abrir ZIP.
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'zip_invalid', __( 'El archivo ZIP no es válido o está corrupto.', 'super-sheep-copy' ) );
		}

		// Leer manifest.
		$manifest_raw = $zip->getFromName( 'manifest.json' );
		$zip->close();

		if ( false === $manifest_raw ) {
			return new WP_Error( 'manifest_missing', __( 'El ZIP no contiene manifest.json.', 'super-sheep-copy' ) );
		}

		$manifest = SSC_Manifest::parse( $manifest_raw );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		return $manifest;
	}

	// ── Snapshot de seguridad ─────────────────────────────────────────────────

	/**
	 * Crea un respaldo automático del estado actual antes de sobreescribir.
	 *
	 * Guarda y restaura el contexto de job activo: el backup interno usa su propio
	 * job_id, y al terminar recuperamos el job_id de la restauración padre para que
	 * los logs posteriores sigan agrupados correctamente.
	 *
	 * @return string|WP_Error Job ID del snapshot o WP_Error.
	 */
	private function create_safety_snapshot() {
		$restore_context = SSC_Logger::get_job_context(); // guardar job_id de la restauración
		$label           = 'pre-restore-' . gmdate( 'Ymd-His' );
		$manager         = new SSC_Backup_Manager();
		$result          = $manager->start( $label, 'pre-restore' );
		SSC_Logger::set_job_context( $restore_context ); // restaurar tras el backup interno
		return $result;
	}

	// ── Importación de DB ─────────────────────────────────────────────────────

	/**
	 * Extrae database.sql del ZIP y lo importa.
	 *
	 * @param string $zip_path      Ruta al ZIP.
	 * @param array  $manifest_data Datos del manifest.
	 * @return true|WP_Error
	 */
	private function import_database( string $zip_path, array $manifest_data ) {
		global $wpdb;

		// Unique subdir per job — extractTo() writes 'database.sql' using the entry's
		// original name, so we control the final path via the target directory.
		$tmp_extract_dir = sys_get_temp_dir() . '/ssc_restore_' . $this->job_id;
		$sql_tmp         = $tmp_extract_dir . '/database.sql';

		// Check entry exists before extracting — avoids allocating memory for the content.
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'zip_open_failed', __( 'No se pudo abrir el ZIP para leer la DB.', 'super-sheep-copy' ) );
		}

		$has_db = false !== $zip->statName( 'database.sql' );
		$zip->close();

		if ( ! $has_db ) {
			if ( ! empty( $manifest_data['includes']['database'] ) ) {
				return new WP_Error( 'database_missing', __( 'El respaldo declara base de datos pero no contiene database.sql.', 'super-sheep-copy' ) );
			}
			SSC_Logger::info( 'restore_manager', 'No se encontró database.sql en el ZIP; el manifest indica restauración sin DB.' );
			return true;
		}

		wp_mkdir_p( $tmp_extract_dir );

		// extractTo() streams entry to disk — no full-file string in memory.
		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::RDONLY );
		$extracted = $zip->extractTo( $tmp_extract_dir, 'database.sql' );
		$zip->close();

		if ( ! $extracted || ! file_exists( $sql_tmp ) ) {
			SSC_Filesystem::delete( $tmp_extract_dir, true );
			return new WP_Error( 'db_extract_failed', __( 'No se pudo extraer database.sql del ZIP.', 'super-sheep-copy' ) );
		}

		$sql_size = (int) filesize( $sql_tmp );
		if ( $sql_size <= 0 ) {
			SSC_Filesystem::delete( $tmp_extract_dir, true );
			return new WP_Error( 'db_empty', __( 'database.sql está vacío; se cancela la restauración de DB.', 'super-sheep-copy' ) );
		}

		$backup_prefix  = $manifest_data['table_prefix'] ?? $wpdb->prefix;
		$current_prefix = $wpdb->prefix;

		$db_restore = new SSC_Database_Restore( $sql_tmp, $backup_prefix, $current_prefix );
		$result     = $db_restore->run();

		SSC_Filesystem::delete( $tmp_extract_dir, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $db_restore->get_statement_count() <= 0 ) {
			return new WP_Error( 'db_no_statements', __( 'database.sql no ejecutó ninguna sentencia; se cancela la restauración.', 'super-sheep-copy' ) );
		}

		return $result;
	}

	// ── Rewrite rules y .htaccess ────────────────────────────────────────────

	/**
	 * Reescribe el bloque WordPress en .htaccess con el RewriteBase correcto
	 * para la ubicación destino.
	 *
	 * Por qué NO tocamos rewrite_rules:
	 *  La opción rewrite_rules del backup ya contiene las reglas correctas para
	 *  el sitio restaurado (post types, taxonomías, estructura de permalinks).
	 *  Los patrones son relativos (no contienen dominio ni subdirectorio) —
	 *  Apache aplica el RewriteBase antes de compararlos.
	 *
	 *  Si borrásemos rewrite_rules y llamásemos flush_rewrite_rules(), las nuevas
	 *  reglas se generarían con el estado en memoria de WordPress *antes* de la
	 *  restauración (el código del sitio original), no con el código del sitio
	 *  restaurado. Eso produce reglas incompletas o incorrectas y provoca 404 en
	 *  todas las páginas excepto la portada.
	 *
	 *  Solución: dejar rewrite_rules intacta (ya es correcta), y solo reescribir
	 *  .htaccess para ajustar el RewriteBase al nuevo host/ruta.
	 *
	 * Nota: RewriteBase se calcula desde siteurl (dónde está instalado WP),
	 * NO desde home (que puede ser diferente si WP está en subdirectorio).
	 *
	 * @return void
	 */
	private function regenerate_htaccess_and_rules(): void {
		global $wpdb;

		// Leer siteurl de la DB restaurada.
		$site_url = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				'siteurl'
			)
		);

		if ( ! $site_url ) {
			SSC_Logger::warn( 'restore_manager', 'No se pudo leer siteurl de la DB; omitiendo regeneración de .htaccess.' );
			return;
		}

		// Calcular RewriteBase desde el path de siteurl.
		//   https://example.com/           → /
		//   https://example.com/wordpress/ → /wordpress/
		$site_path = wp_parse_url( $site_url, PHP_URL_PATH );
		if ( ! $site_path || '/' === $site_path ) {
			$site_path = '/';
		} else {
			$site_path = trailingslashit( $site_path );
		}

		// Sanitize: allow only safe URL path chars before embedding in .htaccess.
		// Blocks newline injection (e.g. siteurl = "http://x/wp/\nRewriteRule ...").
		$site_path = preg_replace( '/[^a-zA-Z0-9\/_\-.]/', '', $site_path );
		if ( '' === $site_path || '/' !== $site_path[0] ) {
			$site_path = '/';
		}

		// Construir el bloque WordPress estándar.
		$wp_block = "# BEGIN WordPress\n"
			. "# The directives (lines) between \"BEGIN WordPress\" and \"END WordPress\" are\n"
			. "# dynamically generated, and should only be modified via WordPress filters.\n"
			. "# Any changes to the directives between these markers will be overwritten.\n"
			. "<IfModule mod_rewrite.c>\n"
			. "RewriteEngine On\n"
			. "RewriteBase {$site_path}\n"
			. "RewriteRule ^index\\.php$ - [L]\n"
			. "RewriteCond %{REQUEST_FILENAME} !-f\n"
			. "RewriteCond %{REQUEST_FILENAME} !-d\n"
			. "RewriteRule . {$site_path}index.php [L]\n"
			. "</IfModule>\n"
			. "# END WordPress\n";

		// Escribir .htaccess con PHP puro (sin depender de misc.php).
		$htaccess_path = rtrim( ABSPATH, '/\\' ) . '/.htaccess';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$existing = file_exists( $htaccess_path ) ? file_get_contents( $htaccess_path ) : '';

		$marker_pattern = '/# BEGIN WordPress.*?# END WordPress\h*\v?/s';

		if ( preg_match( $marker_pattern, $existing ) ) {
			$new_content = preg_replace( $marker_pattern, $wp_block, $existing );
		} else {
			$new_content = $wp_block . ( $existing !== '' ? "\n" . $existing : '' );
		}

		$site_scheme = wp_parse_url( $site_url, PHP_URL_SCHEME );
		if ( 'http' === $site_scheme ) {
			$new_content = $this->strip_https_redirect_rules_from_htaccess( $new_content );
		}

		// Strip php_value auto_prepend_file / auto_append_file directives pointing to
		// non-existent paths (written by caching plugins with production server paths).
		// On Apache/mod_php these take effect at request start — "Unknown on line 0".
		$new_content = preg_replace_callback(
			'/^\s*php_value\s+(auto_(?:prepend|append)_file)\s+(\S+)/mi',
			function ( $m ) {
				$path = trim( $m[2], "\"' \t" );
				if ( '' === $path || ! file_exists( $path ) ) {
					return ''; // remove broken directive
				}
				return $m[0];
			},
			$new_content
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $htaccess_path, $new_content );

		if ( false !== $written ) {
			SSC_Logger::info(
				'restore_manager',
				sprintf( '.htaccess regenerado correctamente (RewriteBase: %s).', $site_path )
			);
		} else {
			SSC_Logger::warn(
				'restore_manager',
				sprintf( '.htaccess no se pudo escribir en %s — verifica permisos.', $htaccess_path )
			);
		}
	}

	// ── Modo mantenimiento ────────────────────────────────────────────────────

	/**
	 * Crea el archivo .maintenance en ABSPATH.
	 *
	 * @return void
	 */
	private function enable_maintenance_mode(): void {
		$file    = ABSPATH . '.maintenance';
		$content = '<?php $upgrading = ' . time() . '; ?>';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, $content );
		SSC_Logger::info( 'restore_manager', 'Modo mantenimiento activado.' );
	}

	/**
	 * Elimina el archivo .maintenance de ABSPATH.
	 *
	 * @return void
	 */
	private function disable_maintenance_mode(): void {
		$file = ABSPATH . '.maintenance';
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		SSC_Logger::info( 'restore_manager', 'Modo mantenimiento desactivado.' );
	}

	// ── Sesiones ──────────────────────────────────────────────────────────────

	/**
	 * Destruye todos los tokens de autenticación para forzar re-login.
	 *
	 * @return void
	 */
	private function destroy_all_sessions(): void {
		// Incrementar el contador global de sesiones invalida todos los cookies.
		$users = get_users( array( 'fields' => 'ID' ) );
		foreach ( $users as $user_id ) {
			$manager = WP_Session_Tokens::get_instance( $user_id );
			$manager->destroy_all();
		}
		SSC_Logger::info( 'restore_manager', 'Todas las sesiones de usuario han sido cerradas.' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Elimina transients de lock SSC que puedan provenir de la base de datos del respaldo.
	 *
	 * La DB importada puede contener ssc_backup_running (o ssc_restore_running) de la web
	 * de origen. Si se dejan intactos, el JS los detectará al cargar la página de admin
	 * y mostrará indefinidamente una barra de progreso de una operación inexistente.
	 *
	 * Se mantiene el lock de la restauración actual (ssc_restore_running = $this->job_id)
	 * ya que el proceso todavía está en curso cuando se invoca este método.
	 *
	 * @return void
	 */
	private function purge_stale_ssc_locks(): void {
		// Eliminar lock de respaldo (siempre es basura después de un import).
		delete_transient( 'ssc_backup_running' );

		// Eliminar lock de restauración solo si corresponde a otro job (nunca el propio).
		$existing_restore_lock = get_transient( 'ssc_restore_running' );
		if ( $existing_restore_lock && $existing_restore_lock !== $this->job_id ) {
			delete_transient( 'ssc_restore_running' );
		}

		// Asegurarse de que el lock de la restauración actual apunta a este job_id.
		set_transient( 'ssc_restore_running', $this->job_id, self::LOCK_TTL );

		// El import de DB reemplazó la tabla de opciones, borrando el transient de estado
		// (la DB del respaldo no lo tenía). Sin esta línea, los polls del cliente reciben
		// "job no encontrado" y MAX_NOT_FOUND dispara el modal de "completado" de forma
		// prematura, antes de que PHP haya desactivado el modo mantenimiento.
		$this->update_state( 'running', __( 'Importando base de datos…', 'super-sheep-copy' ), 60 );

		SSC_Logger::info( 'restore_manager', 'Transients de lock SSC saneados tras importación de DB.' );
	}

	/**
	 * Verifica que la DB restaurada contiene opciones base de WordPress.
	 *
	 * @return true|WP_Error
	 */
	private function validate_restored_wordpress_options( array $manifest_data = array() ) {
		global $wpdb;

		$required = array(
			'siteurl',
			'home',
			'template',
			'stylesheet',
			'active_plugins',
		);

		$found = array();
		foreach ( $required as $option_name ) {
			$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					$option_name
				)
			);
			if ( null !== $value ) {
				$found[ $option_name ] = $value;
			}
		}

		$missing = array();
		foreach ( $required as $option_name ) {
			if ( ! array_key_exists( $option_name, $found ) ) {
				$missing[] = $option_name;
			}
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'restored_options_missing',
				sprintf(
					/* translators: %s: comma-separated missing option names */
					__( 'La base de datos restaurada no contiene opciones críticas de WordPress: %s', 'super-sheep-copy' ),
					implode( ', ', $missing )
				)
			);
		}

		if ( '' === (string) $found['template'] || '' === (string) $found['stylesheet'] ) {
			return new WP_Error(
				'restored_theme_missing',
				__( 'La base de datos restaurada no define el tema activo.', 'super-sheep-copy' )
			);
		}

		if ( isset( $manifest_data['db_snapshot'] ) && is_array( $manifest_data['db_snapshot'] ) ) {
			$snapshot = $manifest_data['db_snapshot'];

			foreach ( array( 'template', 'stylesheet' ) as $key ) {
				if ( isset( $snapshot[ $key ] ) && '' !== (string) $snapshot[ $key ] && (string) $snapshot[ $key ] !== (string) $found[ $key ] ) {
					return new WP_Error(
						'restored_theme_mismatch',
						sprintf(
							/* translators: 1: option name, 2: expected value, 3: actual value */
							__( 'La opción de tema restaurada no coincide (%1$s esperado: %2$s, actual: %3$s).', 'super-sheep-copy' ),
							$key,
							(string) $snapshot[ $key ],
							(string) $found[ $key ]
						)
					);
				}
			}

			if ( isset( $snapshot['active_plugins'] ) && is_array( $snapshot['active_plugins'] ) ) {
				$expected_plugins = array_values( $snapshot['active_plugins'] );
				$actual_plugins   = maybe_unserialize( $found['active_plugins'] );
				$actual_plugins   = is_array( $actual_plugins ) ? array_values( $actual_plugins ) : array();

				sort( $expected_plugins );
				sort( $actual_plugins );

				if ( $expected_plugins !== $actual_plugins ) {
					return new WP_Error(
						'restored_plugins_mismatch',
						sprintf(
							/* translators: 1: expected plugin count, 2: actual plugin count */
							__( 'La lista de plugins activos restaurada no coincide (esperados: %1$d, actuales: %2$d).', 'super-sheep-copy' ),
							count( $expected_plugins ),
							count( $actual_plugins )
						)
					);
				}
			}

			if ( isset( $snapshot['user_count'] ) && (int) $snapshot['user_count'] > 0 ) {
				$user_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( (int) $snapshot['user_count'] !== $user_count ) {
					return new WP_Error(
						'restored_users_mismatch',
						sprintf(
							/* translators: 1: expected user count, 2: actual user count */
							__( 'La tabla de usuarios restaurada no coincide (esperados: %1$d, actuales: %2$d).', 'super-sheep-copy' ),
							(int) $snapshot['user_count'],
							$user_count
						)
					);
				}
			}
		}

		SSC_Logger::info(
			'restore_manager',
			sprintf(
				'DB restaurada validada. Tema: template=%s, stylesheet=%s. Plugins activos: %d.',
				(string) $found['template'],
				(string) $found['stylesheet'],
				count( (array) maybe_unserialize( $found['active_plugins'] ) )
			)
		);

		return true;
	}

	/**
	 * Limpia opciones y plugins conocidos que fuerzan HTTPS al restaurar hacia HTTP.
	 *
	 * @param string $current_url URL del sitio destino.
	 * @return void
	 */
	private function cleanup_http_destination_ssl_forcing( string $current_url ): void {
		if ( 'http://' !== substr( $current_url, 0, 7 ) ) {
			return;
		}

		$this->disable_known_ssl_redirect_plugins();
		$this->disable_known_ssl_redirect_options();
		SSC_Logger::info( 'restore_manager', 'Limpieza de redirecciones SSL aplicada para destino HTTP.' );
	}

	/**
	 * Desactiva plugins cuyo propósito principal es forzar SSL/HTTPS.
	 *
	 * @return void
	 */
	private function disable_known_ssl_redirect_plugins(): void {
		global $wpdb;

		$known_ssl_plugins = array(
			'easy-https-redirection/https-redirection.php',
			'http-https-remover/http-https-remover.php',
			'really-simple-ssl/rlrsssl-really-simple-ssl.php',
			'really-simple-ssl/really-simple-ssl.php',
			'really-simple-security/really-simple-security.php',
			'ssl-insecure-content-fixer/ssl-insecure-content-fixer.php',
			'wordpress-https/wordpress-https.php',
			'wp-force-ssl/wp-force-ssl.php',
		);

		$active = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				'active_plugins'
			)
		);

		if ( is_string( $active ) && '' !== $active ) {
			$plugins = maybe_unserialize( $active );
			if ( is_array( $plugins ) ) {
				$filtered = array_values( array_diff( $plugins, $known_ssl_plugins ) );
				if ( $filtered !== $plugins ) {
					$this->raw_update_option( 'active_plugins', serialize( $filtered ) );
					SSC_Logger::info( 'restore_manager', 'Plugins SSL desactivados para destino HTTP: ' . implode( ', ', array_values( array_diff( $plugins, $filtered ) ) ) );
				}
			}
		}

		$sitewide = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				'active_sitewide_plugins'
			)
		);

		if ( is_string( $sitewide ) && '' !== $sitewide ) {
			$plugins = maybe_unserialize( $sitewide );
			if ( is_array( $plugins ) ) {
				$filtered = $plugins;
				foreach ( $known_ssl_plugins as $plugin_file ) {
					unset( $filtered[ $plugin_file ] );
				}
				if ( $filtered !== $plugins ) {
					$this->raw_update_option( 'active_sitewide_plugins', serialize( $filtered ) );
				}
			}
		}
	}

	/**
	 * Desactiva flags comunes de plugins SSL cuando el destino es HTTP.
	 *
	 * @return void
	 */
	private function disable_known_ssl_redirect_options(): void {
		$false_options = array(
			'rlrsssl_force_ssl',
			'rlrsssl_htaccess_warning_shown',
			'rsssl_ssl_enabled',
			'rsssl_redirect',
			'wp_force_ssl_enable_ssl',
			'wp_force_ssl_fix_content',
			'wp_force_ssl_hsts',
		);

		foreach ( $false_options as $option_name ) {
			$this->raw_update_option( $option_name, '0' );
		}

		$this->patch_serialized_option_booleans(
			'rlrsssl_options',
			array(
				'htaccess_redirect',
				'javascript_redirect',
				'mixed_content_fixer',
				'redirect',
				'ssl_enabled',
			)
		);
	}

	/**
	 * Pone en false claves booleanas dentro de una opción serializada.
	 *
	 * @param string $option_name Nombre de opción.
	 * @param array  $keys        Claves a desactivar.
	 * @return void
	 */
	private function patch_serialized_option_booleans( string $option_name, array $keys ): void {
		global $wpdb;

		$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		if ( ! is_string( $raw ) || '' === $raw ) {
			return;
		}

		$value = maybe_unserialize( $raw );
		if ( ! is_array( $value ) ) {
			return;
		}

		$changed = false;
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $value ) && false !== $value[ $key ] && 0 !== $value[ $key ] && '0' !== $value[ $key ] ) {
				$value[ $key ] = false;
				$changed       = true;
			}
		}

		if ( $changed ) {
			$this->raw_update_option( $option_name, serialize( $value ) );
		}
	}

	/**
	 * Actualiza option_value sin pasar por prepare/update para preservar %.
	 *
	 * @param string $option_name Nombre de opción.
	 * @param string $value       Valor crudo.
	 * @return void
	 */
	private function raw_update_option( string $option_name, string $value ): void {
		global $wpdb;

		$dbh = $wpdb->dbh;
		if ( $dbh instanceof \mysqli ) {
			$esc_val  = mysqli_real_escape_string( $dbh, $value ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
			$esc_name = mysqli_real_escape_string( $dbh, $option_name ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
			mysqli_query( $dbh, "UPDATE `{$wpdb->options}` SET option_value = '{$esc_val}' WHERE option_name = '{$esc_name}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions.mysql_mysqli_query, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return;
		}

		$esc_val  = esc_sql( $value );
		$esc_name = esc_sql( $option_name );
		$wpdb->query( "UPDATE `{$wpdb->options}` SET option_value = '{$esc_val}' WHERE option_name = '{$esc_name}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Elimina reglas .htaccess que fuerzan HTTPS cuando el destino es HTTP.
	 *
	 * @param string $content Contenido de .htaccess.
	 * @return string
	 */
	private function strip_https_redirect_rules_from_htaccess( string $content ): string {
		// Bloques marcados por plugins SSL comunes.
		$content = preg_replace(
			'/#\s*BEGIN[^\r\n]*(?:Really Simple SSL|Really_Simple_SSL|rlrsssl|SSL|HTTPS)[^\r\n]*\R.*?#\s*END[^\r\n]*(?:Really Simple SSL|Really_Simple_SSL|rlrsssl|SSL|HTTPS)[^\r\n]*\R?/is',
			'',
			$content
		);

		$lines  = preg_split( '/\R/', $content );
		$output = array();
		$count  = count( $lines );

		for ( $i = 0; $i < $count; $i++ ) {
			$line = $lines[ $i ];

			if ( preg_match( '/^\s*RewriteCond\s+%\{HTTPS\}\s+(?:!=on|off|!on)\s*$/i', $line ) ) {
				$buffer = array( $line );
				$j      = $i + 1;
				while ( $j < $count && preg_match( '/^\s*RewriteCond\s+/i', $lines[ $j ] ) ) {
					$buffer[] = $lines[ $j ];
					$j++;
				}

				if ( $j < $count && preg_match( '#^\s*RewriteRule\s+.+https://#i', $lines[ $j ] ) ) {
					$i = $j;
					continue;
				}

				foreach ( $buffer as $kept ) {
					$output[] = $kept;
				}
				$i = $j - 1;
				continue;
			}

			if ( preg_match( '#^\s*Redirect(?:Match)?\s+\d{3}\s+.+https://#i', $line ) ) {
				continue;
			}

			$output[] = $line;
		}

		return trim( implode( "\n", $output ) ) . "\n";
	}

	/**
	 * Repara la corrupción de placeholder_escape() en toda la tabla de opciones.
	 *
	 * WordPress almacena internamente '%' como '{sha256(dbuser+dbpassword)}' para
	 * evitar que vsprintf() confunda los valores de usuario con especificadores de
	 * formato. Si el dump de la BD de origen fue creado con ese valor ya corrompido
	 * (p.ej. en opciones como permalink_structure o valores serializados de plugins
	 * como Yoast SEO), el import lo reproduce fielmente y las opciones quedan con
	 * '{64hexchars}' en lugar de '%'.
	 *
	 * Estrategia de reparación:
	 *  - Leer todas las opciones cuyo valor contenga '{'.
	 *  - Detectar el patrón {[0-9a-f]{64}} (siempre 64 hexadecimales).
	 *  - Reemplazar '{hash}' → '%' sobre el valor RAW (sin deserializar).
	 *  - Esto funciona tanto para cadenas planas como para datos serializados,
	 *    ya que la corrupción original tampoco actualizó los contadores s:N:,
	 *    de modo que la operación inversa los restaura correctamente.
	 *
	 * @return void
	 */
	private function repair_placeholder_escape_corruption(): void {
		global $wpdb;

		$dbh     = $wpdb->dbh;
		$use_raw = $dbh instanceof \mysqli;

		// Patrón exacto de placeholder_escape(): {64 hexadecimales en minúscula}.
		$hash_pattern = '/\{[0-9a-f]{64}\}/';

		// Pre-filtro SQL: solo opciones cuyo valor contiene '{'.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$sql = "SELECT option_id, option_name, option_value FROM `{$wpdb->options}` WHERE option_value LIKE '%{%'";

		if ( $use_raw ) {
			$result = mysqli_query( $dbh, $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions.mysql_mysqli_query
			if ( ! $result ) {
				return;
			}
			$rows = array();
			while ( $row = mysqli_fetch_assoc( $result ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition, WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_assoc
				$rows[] = $row;
			}
		} else {
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		}

		$count = 0;
		foreach ( (array) $rows as $row ) {
			$value = $row['option_value'];

			if ( ! preg_match( $hash_pattern, $value ) ) {
				continue;
			}

			// Reemplazar en el valor RAW: correcto tanto para cadenas planas
			// como para serializadas (la corrupción original tampoco tocó s:N:).
			$fixed = preg_replace( $hash_pattern, '%', $value );

			SSC_Logger::warn(
				'restore_manager',
				sprintf(
					'placeholder_escape en "%s": %s → %s',
					$row['option_name'],
					substr( $value, 0, 120 ),
					substr( $fixed, 0, 120 )
				)
			);

			if ( $use_raw ) {
				$esc_val = mysqli_real_escape_string( $dbh, $fixed ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
				$esc_id  = mysqli_real_escape_string( $dbh, (string) $row['option_id'] ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
				mysqli_query( $dbh, "UPDATE `{$wpdb->options}` SET option_value = '{$esc_val}' WHERE option_id = '{$esc_id}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions.mysql_mysqli_query
			} else {
				$esc_val = esc_sql( $fixed );
				$esc_id  = esc_sql( (string) $row['option_id'] );
				$wpdb->query( "UPDATE `{$wpdb->options}` SET option_value = '{$esc_val}' WHERE option_id = '{$esc_id}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter
			}

			++$count;
		}

		if ( $count > 0 ) {
			SSC_Logger::info( 'restore_manager', sprintf( 'Reparación placeholder_escape: %d opción(es) corregidas.', $count ) );
		}
	}

	/**
	 * Elimina drop-ins de caché y limpia directivas auto_prepend_file en .user.ini.
	 *
	 * advanced-cache.php, advanced-headers.php y object-cache.php son generados
	 * automáticamente por plugins de caché (WP Rocket, W3 Total Cache, LiteSpeed, etc.)
	 * con rutas absolutas del servidor de origen. En el servidor destino esas rutas no
	 * existen y PHP lanza un Fatal Error antes de que WordPress arranque.
	 *
	 * Igualmente, algunos plugins de caché escriben `auto_prepend_file` en .user.ini
	 * (procesado por PHP-FPM antes de cualquier script). Si el archivo referenciado no
	 * existe en el servidor destino, PHP muestra "Unknown on line 0" y detiene la carga.
	 *
	 * Los plugins regeneran drop-ins y .user.ini en la primera carga del admin.
	 *
	 * @return void
	 */
	private function purge_cache_dropins(): void {
		$wp_content = rtrim( WP_CONTENT_DIR, '/\\' );
		$abspath    = rtrim( ABSPATH, '/\\' );

		// ── Drop-ins PHP ──────────────────────────────────────────────────────────
		$drop_ins = array(
			'advanced-cache.php',
			'advanced-headers.php',
			'object-cache.php',
		);

		$deleted = array();

		foreach ( $drop_ins as $filename ) {
			$path = $wp_content . '/' . $filename;
			if ( ! file_exists( $path ) ) {
				continue;
			}
			if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
				$deleted[] = $filename;
			} else {
				SSC_Logger::warn( 'restore_manager', 'No se pudo eliminar drop-in de caché: ' . $filename );
			}
		}

		if ( ! empty( $deleted ) ) {
			SSC_Logger::info(
				'restore_manager',
				'Drop-ins de caché eliminados (se regenerarán automáticamente): ' . implode( ', ', $deleted )
			);
		}

		// ── .user.ini: limpiar auto_prepend_file con rutas inexistentes ───────────
		// PHP-FPM procesa .user.ini antes de cualquier script; si la ruta apunta al
		// servidor de origen, produce "Failed opening required ... in Unknown on line 0".
		$ini_dirs = array_unique( array( $abspath, $wp_content ) );

		foreach ( $ini_dirs as $dir ) {
			$ini_path = $dir . '/.user.ini';
			if ( ! file_exists( $ini_path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$ini_content = file_get_contents( $ini_path );
			if ( false === $ini_content || false === strpos( $ini_content, 'auto_prepend_file' ) ) {
				continue;
			}

			// Strip auto_prepend_file/auto_append_file lines pointing to non-existent paths.
			$patched = preg_replace_callback(
				'/^\s*(auto_(?:prepend|append)_file)\s*=\s*(.+)$/m',
				function ( $m ) {
					$path = trim( $m[2], "\"' \t" );
					if ( '' === $path || ! file_exists( $path ) ) {
						return ''; // remove broken directive
					}
					return $m[0]; // keep valid one
				},
				$ini_content
			);

			if ( $patched !== $ini_content ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $ini_path, $patched );
				SSC_Logger::info(
					'restore_manager',
					'auto_prepend_file eliminado de .user.ini en: ' . $dir
				);
			}
		}
	}

	/**
	 * Captura las credenciales de base de datos del sitio destino ANTES de restaurar archivos.
	 *
	 * wp-config.php del backup sobreescribirá el local; guardar las credenciales aquí
	 * permite restaurarlas en el wp-config.php recién extraído.
	 *
	 * @return array{host:string,name:string,user:string,password:string,prefix:string,charset:string,collate:string}
	 */
	private function capture_db_credentials(): array {
		global $wpdb;
		return array(
			'host'     => defined( 'DB_HOST' )    ? DB_HOST    : '',
			'name'     => defined( 'DB_NAME' )    ? DB_NAME    : '',
			'user'     => defined( 'DB_USER' )    ? DB_USER    : '',
			'password' => defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '',
			'charset'  => defined( 'DB_CHARSET' ) ? DB_CHARSET  : 'utf8mb4',
			'collate'  => defined( 'DB_COLLATE' ) ? DB_COLLATE  : '',
			'prefix'   => $wpdb->prefix,
		);
	}

	/**
	 * Reescribe wp-config.php restaurado para que funcione en el servidor destino.
	 *
	 * Aplica en orden:
	 *  1. Credenciales de BD (DB_HOST/NAME/USER/PASSWORD/CHARSET/COLLATE, $table_prefix).
	 *  2. Constantes de URL (WP_HOME, WP_SITEURL, WP_CONTENT_URL).
	 *  3. Flags SSL (FORCE_SSL_ADMIN, FORCE_SSL → false cuando el destino es HTTP).
	 *
	 * @param string $current_url URL del sitio destino (sin trailing slash).
	 * @param array  $current_db  Credenciales capturadas por capture_db_credentials().
	 * @return void
	 */
	private function rewrite_wp_config_constants( string $current_url, array $current_db ): void {
		$config_path = rtrim( ABSPATH, '/\\' ) . '/wp-config.php';

		if ( ! file_exists( $config_path ) || ! is_readable( $config_path ) ) {
			SSC_Logger::warn( 'restore_manager', 'wp-config.php no encontrado o no legible; se omite reescritura de constantes.' );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $config_path );
		if ( false === $content ) {
			SSC_Logger::warn( 'restore_manager', 'No se pudo leer wp-config.php para reescribir constantes.' );
			return;
		}

		$is_http = ( 'http://' === substr( $current_url, 0, 7 ) );
		$changes = array();

		// ── 1. Credenciales de BD ─────────────────────────────────────────────────
		// Restaurar los valores del servidor destino para que WordPress pueda conectarse
		// a la BD local aunque wp-config.php provenga del servidor de producción.
		$db_map = array(
			'DB_HOST'     => $current_db['host'],
			'DB_NAME'     => $current_db['name'],
			'DB_USER'     => $current_db['user'],
			'DB_PASSWORD' => $current_db['password'],
			'DB_CHARSET'  => $current_db['charset'],
			'DB_COLLATE'  => $current_db['collate'],
		);

			foreach ( $db_map as $const => $dest_value ) {
				if ( '' === $dest_value && in_array( $const, array( 'DB_CHARSET', 'DB_COLLATE' ), true ) ) {
					continue; // skip empty optional constants
				}
				$content = preg_replace_callback(
					'/(\bdefine\s*\(\s*)([\'"]' . preg_quote( $const, '/' ) . '[\'"])(\s*,\s*)(.*?)(\s*\)\s*;?)/i',
					function ( $m ) use ( $const, $dest_value, &$changes ) {
						if ( $m[4] !== $this->php_string_literal( $dest_value ) ) {
							$changes[] = sprintf( "%s actualizado", $const );
						}
						return $m[1] . $m[2] . $m[3] . $this->php_string_literal( $dest_value ) . $m[5];
					},
					$content
				);
			}

		// $table_prefix is a variable, not a constant — match the assignment.
			if ( ! empty( $current_db['prefix'] ) ) {
				$content = preg_replace_callback(
					'/(\$table_prefix\s*=\s*)(.*?)(\s*;)/i',
					function ( $m ) use ( $current_db, &$changes ) {
						if ( $m[2] !== $this->php_string_literal( $current_db['prefix'] ) ) {
							$changes[] = sprintf( "\$table_prefix: '%s' → '%s'", $m[2], $current_db['prefix'] );
						}
						return $m[1] . $this->php_string_literal( $current_db['prefix'] ) . $m[3];
					},
					$content
				);
		}

			// ── 2. Constantes de URL ──────────────────────────────────────────────────
			foreach ( array( 'WP_HOME', 'WP_SITEURL', 'WP_CONTENT_URL' ) as $const ) {
				$content = preg_replace_callback(
					'/(\bdefine\s*\(\s*)([\'"]' . preg_quote( $const, '/' ) . '[\'"])(\s*,\s*)(.*?)(\s*\)\s*;?)/i',
					function ( $m ) use ( $const, $current_url, &$changes ) {
						$old_url = $this->parse_php_scalar_literal( $m[4] );

						if ( 'WP_CONTENT_URL' === $const ) {
							$parsed  = wp_parse_url( $old_url );
						$subpath = isset( $parsed['path'] ) ? $parsed['path'] : '/wp-content';
						$new_url = rtrim( $current_url, '/' ) . $subpath;
					} else {
						$new_url = $current_url;
					}

					if ( $old_url !== $new_url ) {
							$changes[] = sprintf( "%s: '%s' → '%s'", $const, $old_url, $new_url );
						}

						return $m[1] . $m[2] . $m[3] . $this->php_string_literal( $new_url ) . $m[5];
					},
					$content
				);
		}

		// ── 3. Flags SSL ──────────────────────────────────────────────────────────
		if ( $is_http ) {
			foreach ( array( 'FORCE_SSL_ADMIN', 'FORCE_SSL' ) as $const ) {
				$patched = preg_replace_callback(
					'/(\bdefine\s*\(\s*[\'"]' . preg_quote( $const, '/' ) . '[\'"]\s*,\s*)(true)(\s*\))/i',
					function ( $m ) use ( $const, &$changes ) {
						$changes[] = sprintf( '%s: true → false', $const );
						return $m[1] . 'false' . $m[3];
					},
					$content
				);
				if ( null !== $patched ) {
					$content = $patched;
				}
			}
		}

		if ( empty( $changes ) ) {
			SSC_Logger::info( 'restore_manager', 'wp-config.php: sin constantes que actualizar.' );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $config_path, $content );

		if ( false !== $written ) {
			SSC_Logger::info(
				'restore_manager',
				'wp-config.php actualizado: ' . implode( ', ', $changes )
			);
		} else {
			SSC_Logger::warn(
				'restore_manager',
				'No se pudo escribir wp-config.php — cambios no aplicados. Intentados: ' . implode( ', ', $changes )
			);
		}
	}

	/**
	 * Devuelve una cadena segura para insertar como literal PHP.
	 *
	 * @param string $value Valor crudo.
	 * @return string Literal PHP válido.
	 */
	private function php_string_literal( string $value ): string {
		return var_export( $value, true );
	}

	/**
	 * Intenta leer un literal escalar PHP simple.
	 *
	 * @param string $literal Literal capturado desde wp-config.php.
	 * @return string Valor interpretado o fallback sin comillas.
	 */
	private function parse_php_scalar_literal( string $literal ): string {
		$literal = trim( $literal );
		if ( preg_match( '/^([\'"])(.*)\1$/s', $literal, $m ) ) {
			return stripcslashes( $m[2] );
		}

		return trim( $literal, "\"' \t" );
	}

	/**
	 * Genera un ID de job único.
	 *
	 * @return string
	 */
	private function generate_job_id(): string {
		return substr( md5( uniqid( 'ssc_restore_', true ) ), 0, 16 );
	}

	/**
	 * Actualiza el transient de estado.
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
	 * Escribe el transient de estado con un array arbitrario.
	 *
	 * @param array $state Datos de estado.
	 * @return void
	 */
	private function update_state_raw( array $state ): void {
		set_transient( 'ssc_restore_state_' . $this->job_id, $state, self::STATE_TTL );
	}

	/**
	 * Marca el job como fallido.
	 *
	 * @param string $message Mensaje de error.
	 * @return void
	 */
	private function fail( string $message ): void {
		$this->update_state( 'failed', $message, 0 );
		SSC_Logger::error( 'restore_manager', $message );
		SSC_Logger::clear_job_context();
		$this->disable_maintenance_mode();
		$this->release_lock();
	}

	/**
	 * Libera el lock de restauración.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		delete_transient( 'ssc_restore_running' );
	}
}
