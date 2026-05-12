<?php
/**
 * Menús, submenús y assets del área de administración.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Admin
 *
 * Registra los menús de wp-admin y carga los assets (CSS/JS) únicamente
 * en las páginas propias del plugin.
 */
class SSC_Admin {

	/**
	 * Hook suffixes devueltos por add_menu_page / add_submenu_page.
	 *
	 * @var string[]
	 */
	private array $page_hooks = [];

	/**
	 * Registra los hooks de WordPress necesarios.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu',            array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_init',            array( $this, 'process_bulk_actions' ) );
		add_action( 'admin_init',            array( 'SSC_Self_Updater', 'maybe_apply_pending_swap' ), 5 );
		add_action( 'admin_notices',         array( $this, 'show_self_update_notices' ) );
	}

	/**
	 * Registra las opciones del plugin con la Settings API de WordPress.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'ssc_settings_group',
			'ssc_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitiza y valida el array de opciones antes de guardarlo.
	 *
	 * @param mixed $input Valor enviado desde el formulario.
	 * @return array
	 */
	public function sanitize_settings( $input ): array {
		$clean = array();

		$clean['include_core'] = ! empty( $input['include_core'] ) ? 1 : 0;
		$clean['exclude_logs'] = ! empty( $input['exclude_logs'] ) ? 1 : 0;

		$server_max_mb = (int) ceil( wp_max_upload_size() / MB_IN_BYTES );
		$requested_mb  = isset( $input['max_upload_size'] )
			? max( 1, min( 10240, absint( $input['max_upload_size'] ) ) )
			: 2048;

		if ( $requested_mb > $server_max_mb ) {
			add_settings_error(
				'ssc_settings',
				'ssc_max_upload_exceeds_server',
				sprintf(
					/* translators: 1: requested size, 2: server limit */
					__( 'El tamaño máximo de upload (%1$s MB) supera el límite del servidor (%2$s MB). El límite efectivo será %2$s MB.', 'super-sheep-copy' ),
					$requested_mb,
					$server_max_mb
				),
				'warning'
			);
		}

		$clean['max_upload_size'] = $requested_mb;

		return $clean;
	}

	// ── Menús ─────────────────────────────────────────────────────────────────

	/**
	 * Registra el menú principal y los submenús del plugin.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		$this->page_hooks[] = add_menu_page(
			__( 'Super Sheep Copy', 'super-sheep-copy' ),
			__( 'Respaldos', 'super-sheep-copy' ),
			SSC_Capabilities::CAP,
			'super-sheep-copy',
			array( $this, 'render_backups_page' ),
			'dashicons-backup',
			75
		);

		// El primer submenú duplica el menú principal para nombrar la pestaña.
		add_submenu_page(
			'super-sheep-copy',
			__( 'Respaldos', 'super-sheep-copy' ),
			__( 'Respaldos', 'super-sheep-copy' ),
			SSC_Capabilities::CAP,
			'super-sheep-copy',
			array( $this, 'render_backups_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'super-sheep-copy',
			__( 'Restaurar', 'super-sheep-copy' ),
			__( 'Restaurar', 'super-sheep-copy' ),
			SSC_Capabilities::CAP,
			'ssc-restore',
			array( $this, 'render_restore_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'super-sheep-copy',
			__( 'Ajustes', 'super-sheep-copy' ),
			__( 'Ajustes', 'super-sheep-copy' ),
			SSC_Capabilities::CAP,
			'ssc-settings',
			array( $this, 'render_settings_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'super-sheep-copy',
			__( 'Log', 'super-sheep-copy' ),
			__( 'Log', 'super-sheep-copy' ),
			SSC_Capabilities::CAP,
			'ssc-log',
			array( $this, 'render_log_page' )
		);
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * Carga CSS y JS solo en las páginas del plugin.
	 *
	 * @param string $hook_suffix Sufijo de la página actual.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		// wp-auth-check muestra un modal de login cuando detecta que la sesión expiró.
		// Durante una restauración esto ocurre intencionalmente (la DB importada reemplaza
		// los tokens de sesión) y causaría que el modal de login aparezca antes de que el
		// plugin muestre su propio modal de "restauración completada". Se desactiva en todas
		// las páginas del plugin; el JS del plugin gestiona el re-login post-restauración.
		wp_dequeue_script( 'wp-auth-check' );
		wp_dequeue_style( 'wp-auth-check' );
		remove_action( 'admin_footer', 'wp_auth_check_html' );

		wp_enqueue_style(
			'ssc-admin',
			SSC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SSC_VERSION
		);

		wp_enqueue_script(
			'ssc-admin',
			SSC_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			SSC_VERSION,
			true
		);

		// Detectar si hay un respaldo o restauración en curso para que el JS pueda
		// reanudar el polling automáticamente al cargar la página (o tras un error
		// de red que interrumpió el polling, como el timeout de FastCGI de 30 s).
		// Los transients 'ssc_backup_running' y 'ssc_restore_running' almacenan el
		// job_id mientras la operación está en segundo plano.
		$running_backup_job  = get_transient( 'ssc_backup_running' );
		$running_restore_job = get_transient( 'ssc_restore_running' );

		wp_localize_script(
			'ssc-admin',
			'sscData',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'loginUrl'           => wp_login_url(),
				'nonce'              => wp_create_nonce( 'ssc_ajax_nonce' ),
				// job_id en curso (cadena vacía si no hay ninguno).
				'runningBackupJobId' => $running_backup_job  ? (string) $running_backup_job  : '',
				'runningRestoreJobId'=> $running_restore_job ? (string) $running_restore_job : '',
				'strings' => array(
					// Confirmaciones.
					'confirmDelete'    => __( '¿Eliminar este respaldo? Esta acción no se puede deshacer.', 'super-sheep-copy' ),
					'confirmRestore'   => __( '¿Restaurar este respaldo? El sitio actual será reemplazado.', 'super-sheep-copy' ),
					// Genéricos.
					'error'            => __( 'Ha ocurrido un error. Revisa el log para más detalles.', 'super-sheep-copy' ),
					'done'             => __( '¡Listo!', 'super-sheep-copy' ),
					// Mensaje cuando el polling falla pero el proceso sigue en background.
					'errorPollTimeout' => __( 'No se pudo verificar el estado. El proceso puede seguir ejecutándose en segundo plano. Recarga la página en unos minutos para ver el resultado.', 'super-sheep-copy' ),
					// Mensaje mostrado al reanudar polling al cargar la página.
					'backupInProgress' => __( 'Respaldo en curso, aguarda…', 'super-sheep-copy' ),
					'restoreInProgress'=> __( 'Restauración en curso, aguarda…', 'super-sheep-copy' ),
					// Fases de backup (para barra de progreso simulada).
					'phaseInit'        => __( 'Iniciando respaldo…', 'super-sheep-copy' ),
					'phaseDB'          => __( 'Volcando base de datos…', 'super-sheep-copy' ),
					'phaseFiles'       => __( 'Empaquetando archivos del sitio…', 'super-sheep-copy' ),
					'phaseManifest'    => __( 'Generando manifest…', 'super-sheep-copy' ),
					'phaseChecksum'    => __( 'Calculando checksum…', 'super-sheep-copy' ),
					'phaseWait'        => __( 'Finalizando, por favor espera…', 'super-sheep-copy' ),
					// Fases de restauración.
					'phaseValidate'    => __( 'Validando archivo de respaldo…', 'super-sheep-copy' ),
					'phaseSnapshot'    => __( 'Creando respaldo de seguridad del estado actual…', 'super-sheep-copy' ),
					'phaseExtract'     => __( 'Extrayendo archivos…', 'super-sheep-copy' ),
					'phaseImportDB'    => __( 'Importando base de datos…', 'super-sheep-copy' ),
					'phaseRewrite'     => __( 'Reescribiendo URLs…', 'super-sheep-copy' ),
					'phaseCache'       => __( 'Limpiando cache y reglas de reescritura…', 'super-sheep-copy' ),
				),
			)
		);
	}

	// ── Bulk actions ──────────────────────────────────────────────────────────

	/**
	 * Procesa las bulk actions del listado de respaldos en admin_init,
	 * antes de que se emita cualquier HTML, para poder llamar wp_safe_redirect().
	 *
	 * @return void
	 */
	public function process_bulk_actions(): void {
		// Solo actuar cuando estamos en nuestra página y se envió el formulario.
		$page   = isset( $_REQUEST['page'] ) ? sanitize_key( $_REQUEST['page'] ) : '';
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';

		// WP_List_Table envía la acción seleccionada en 'action' (superior) o 'action2' (inferior).
		if ( '' === $action || '-1' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( $_REQUEST['action2'] ) : '';
		}

		if ( 'super-sheep-copy' !== $page || 'delete' !== $action ) {
			return;
		}

		if ( ! current_user_can( SSC_Capabilities::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para realizar esta acción.', 'super-sheep-copy' ) );
		}

		check_admin_referer( 'bulk-respaldos' );

		$filenames = isset( $_POST['backup'] ) ? (array) wp_unslash( $_POST['backup'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$deleted   = 0;
		$errors    = 0;

		foreach ( $filenames as $raw ) {
			$filename = sanitize_file_name( wp_unslash( $raw ) );
			$result   = SSC_Security::delete_backup_file( $filename );

			if ( is_wp_error( $result ) ) {
				$errors++;
				SSC_Logger::warn( 'admin', 'No se pudo eliminar (bulk): ' . $filename );
			} else {
				$deleted++;
				SSC_Logger::info( 'admin', 'Respaldo eliminado (bulk): ' . $filename );
			}
		}

		$redirect = add_query_arg(
			array(
				'page'             => 'super-sheep-copy',
				'ssc_bulk_deleted' => $deleted,
				'ssc_bulk_errors'  => $errors,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	// ── Vistas ────────────────────────────────────────────────────────────────

	/**
	 * Renderiza la página de listado de respaldos.
	 *
	 * @return void
	 */
	public function render_backups_page(): void {
		if ( ! current_user_can( SSC_Capabilities::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'super-sheep-copy' ) );
		}

		$table = new SSC_List_Table();
		$table->prepare_items();

		require_once SSC_PLUGIN_DIR . 'includes/admin/views/page-backups.php';
	}

	/**
	 * Renderiza la página de restauración.
	 *
	 * @return void
	 */
	public function render_restore_page(): void {
		if ( ! current_user_can( SSC_Capabilities::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'super-sheep-copy' ) );
		}
		require_once SSC_PLUGIN_DIR . 'includes/admin/views/page-restore.php';
	}

	/**
	 * Renderiza la página de ajustes.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( SSC_Capabilities::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'super-sheep-copy' ) );
		}
		require_once SSC_PLUGIN_DIR . 'includes/admin/views/page-settings.php';
	}

	/**
	 * Renderiza la página de log de auditoría.
	 *
	 * @return void
	 */
	public function render_log_page(): void {
		if ( ! current_user_can( SSC_Capabilities::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'super-sheep-copy' ) );
		}

		global $wpdb;
		$table      = $wpdb->prefix . 'ssc_audit_log';
		$raw        = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", 500 ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		// ── Agrupar entradas por job_id ───────────────────────────────────────
		$groups     = array();  // [ job_id => [ entry, ... ] ]  — en orden DESC
		$ungrouped  = array();  // entradas sin job_id (eventos del sistema)

		foreach ( $raw as $entry ) {
			$jid = $entry['job_id'] ?? '';
			if ( '' !== $jid ) {
				$groups[ $jid ][] = $entry;
			} else {
				$ungrouped[] = $entry;
			}
		}

		// ── Construir resumen de cada grupo ───────────────────────────────────
		$summaries = array();
		foreach ( $groups as $jid => $g_entries ) {
			// Entradas en orden DESC: $g_entries[0] = más reciente, end() = más antigua.
			$first_entry = $g_entries[0];
			$last_entry  = end( $g_entries );

			// Tipo de operación: si alguna acción contiene 'restore' → restauración.
			$op_type = 'backup';
			foreach ( $g_entries as $e ) {
				if ( false !== strpos( $e['action'], 'restore' ) || false !== strpos( $e['action'], 'url_rewriter' ) ) {
					$op_type = 'restore';
					break;
				}
			}

			// Estado global: el peor de los result individuales.
			$status = 'success';
			foreach ( $g_entries as $e ) {
				if ( 'error' === $e['result'] ) {
					$status = 'error';
					break;
				}
				if ( 'warning' === $e['result'] ) {
					$status = 'warning';
				}
			}

			// Nombre del archivo: primera entry con object_name no vacío.
			$object_name = '';
			foreach ( $g_entries as $e ) {
				if ( '' !== $e['object_name'] ) {
					$object_name = $e['object_name'];
					break;
				}
			}

			// Contadores.
			$error_count   = 0;
			$warning_count = 0;
			foreach ( $g_entries as $e ) {
				if ( 'error'   === $e['result'] ) { ++$error_count; }
				if ( 'warning' === $e['result'] ) { ++$warning_count; }
			}

			// Duración (en segundos, calculada a partir de los timestamps).
			$duration_secs = 0;
			if ( $first_entry['created_at'] && $last_entry['created_at'] ) {
				$ts_start      = strtotime( $last_entry['created_at'] );
				$ts_end        = strtotime( $first_entry['created_at'] );
				$duration_secs = max( 0, $ts_end - $ts_start );
			}

			$summaries[ $jid ] = array(
				'job_id'        => $jid,
				'type'          => $op_type,
				'status'        => $status,
				'object_name'   => $object_name,
				'started_at'    => $last_entry['created_at'],
				'ended_at'      => $first_entry['created_at'],
				'duration_secs' => $duration_secs,
				'entry_count'   => count( $g_entries ),
				'error_count'   => $error_count,
				'warning_count' => $warning_count,
				'entries'       => array_reverse( $g_entries ), // cronológico para mostrar
				'user_login'    => $last_entry['user_login'] ?? '',
			);
		}

		// Ordenar grupos de más reciente a más antiguo.
		uasort( $summaries, function ( $a, $b ) {
			return strcmp( $b['started_at'], $a['started_at'] );
		} );

		require_once SSC_PLUGIN_DIR . 'includes/admin/views/page-log.php';
	}

	/**
	 * Muestra avisos de administrador relacionados con la autoactualización del plugin SSC.
	 *
	 * @return void
	 */
	public function show_self_update_notices(): void {
		// Aviso de éxito cuando la redirección post-swap incluye el parámetro.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ssc_self_updated'] ) && '1' === $_GET['ssc_self_updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'El plugin Super Sheep Copy ha sido reemplazado con la versión incluida en el respaldo restaurado.', 'super-sheep-copy' ) .
				'</p></div>';
		}

		// Aviso de error si el swap falló.
		if ( get_transient( 'ssc_self_update_failed' ) ) {
			delete_transient( 'ssc_self_update_failed' );
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				esc_html__( 'Super Sheep Copy: no se pudo reemplazar el plugin con la versión del respaldo. Revisa el log para más detalles.', 'super-sheep-copy' ) .
				'</p></div>';
		}
	}
}
