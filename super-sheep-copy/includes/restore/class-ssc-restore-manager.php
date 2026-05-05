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
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

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

		// Capturar la URL del sitio destino ANTES de importar la DB.
		// Tras el import, get_site_url() devolvería la URL del backup y
		// la comparación fallaría, saltándose la reescritura.
		$current_url  = rtrim( get_site_url(), '/' );
		$current_path = rtrim( ABSPATH, '/' );

		try {
			// 4. Extracción de archivos.
			$this->update_state( 'running', __( 'Extrayendo archivos…', 'super-sheep-copy' ), 20 );
			$files_restore = new SSC_Files_Restore( $zip_path, $tmp_dir, $overwrite_wp_config );
			$result        = $files_restore->run();
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}

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
			// El ZIP no tiene DB — solo restaurar archivos está bien.
			SSC_Logger::info( 'restore_manager', 'No se encontró database.sql en el ZIP. Solo se restauran archivos.' );
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

		$backup_prefix  = $manifest_data['table_prefix'] ?? $wpdb->prefix;
		$current_prefix = $wpdb->prefix;

		$db_restore = new SSC_Database_Restore( $sql_tmp, $backup_prefix, $current_prefix );
		$result     = $db_restore->run();

		SSC_Filesystem::delete( $tmp_extract_dir, true );

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
		$site_path = parse_url( $site_url, PHP_URL_PATH );
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
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
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

		SSC_Logger::info( 'restore_manager', 'Transients de lock SSC saneados tras importación de DB.' );
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
			$result = mysqli_query( $dbh, $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $result ) {
				return;
			}
			$rows = array();
			while ( $row = mysqli_fetch_assoc( $result ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
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
				$esc_val = mysqli_real_escape_string( $dbh, $fixed );
				$esc_id  = mysqli_real_escape_string( $dbh, (string) $row['option_id'] );
				mysqli_query( $dbh, "UPDATE `{$wpdb->options}` SET option_value = '{$esc_val}' WHERE option_id = '{$esc_id}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$esc_val = esc_sql( $fixed );
				$esc_id  = esc_sql( (string) $row['option_id'] );
				$wpdb->query( "UPDATE `{$wpdb->options}` SET option_value = '{$esc_val}' WHERE option_id = '{$esc_id}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			}

			++$count;
		}

		if ( $count > 0 ) {
			SSC_Logger::info( 'restore_manager', sprintf( 'Reparación placeholder_escape: %d opción(es) corregidas.', $count ) );
		}
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
