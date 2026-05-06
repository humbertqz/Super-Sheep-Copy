<?php
/**
 * Vista: Listado de respaldos — diseño rediseñado.
 *
 * @package Full_Site_Backup
 *
 * @var SSC_List_Table $table Instancia de la tabla de respaldos (ya preparada).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Datos del listado ─────────────────────────────────────────────────────────
$items      = $table->items;
$total      = count( $items );
$total_size = 0;
$last_mtime = 0;

foreach ( $items as $item ) {
	$total_size += (int) $item['size'];
	if ( (int) $item['date'] > $last_mtime ) {
		$last_mtime = (int) $item['date'];
	}
}

// Tiempo relativo del último respaldo.
$last_str = '';
if ( $last_mtime > 0 ) {
	$diff = time() - $last_mtime;
	if ( $diff < 60 ) {
		$last_str = __( 'Hace un momento', 'super-sheep-copy' );
	} elseif ( $diff < 3600 ) {
		/* translators: %d: minutes */
		$last_str = sprintf( __( 'Hace %d min', 'super-sheep-copy' ), intdiv( $diff, 60 ) );
	} elseif ( $diff < 86400 ) {
		/* translators: %d: hours */
		$last_str = sprintf( __( 'Hace %dh', 'super-sheep-copy' ), intdiv( $diff, 3600 ) );
	} else {
		/* translators: %d: days */
		$last_str = sprintf( __( 'Hace %d días', 'super-sheep-copy' ), intdiv( $diff, 86400 ) );
	}
} else {
	$last_str = __( 'Nunca', 'super-sheep-copy' );
}
?>
<div class="wrap ssc-wrap ssc-plugin-page ssc-backups-page">

	<!-- Zona de avisos dinámicos -->
	<div id="ssc-notices">
		<?php
		// Resultado de bulk delete.
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['ssc_bulk_deleted'] ) ) {
			$deleted = absint( $_GET['ssc_bulk_deleted'] );
			$errors  = absint( $_GET['ssc_bulk_errors'] ?? 0 );
			if ( $deleted > 0 && 0 === $errors ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( sprintf(
						/* translators: %d number of deleted backups */
						_n( '%d respaldo eliminado correctamente.', '%d respaldos eliminados correctamente.', $deleted, 'super-sheep-copy' ),
						$deleted
					) )
				);
			} elseif ( $deleted > 0 ) {
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					esc_html( sprintf(
						/* translators: 1: deleted 2: errors */
						__( '%1$d respaldo(s) eliminado(s); %2$d no se pudieron eliminar.', 'super-sheep-copy' ),
						$deleted,
						$errors
					) )
				);
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'No se pudo eliminar ningún respaldo.', 'super-sheep-copy' ) . '</p></div>';
			}
		}
		// phpcs:enable

		$notice = get_transient( 'ssc_admin_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'ssc_admin_notice_' . get_current_user_id() );
			$cls = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				esc_attr( $cls ),
				esc_html( $notice['message'] )
			);
		}
		?>
	</div>

	<!-- ── Cabecera de página ─────────────────────────────────────────────── -->
	<div class="ssc-page-header">
		<div class="ssc-page-header__info">
			<div class="ssc-brand">
				<img
					src="<?php echo esc_url( SSC_PLUGIN_URL . 'assets/images/super-sheep-copy-logo.png' ); ?>"
					alt="Super Sheep Copy"
					class="ssc-brand-logo">
				<div class="ssc-brand-text">
					<h1 class="ssc-page-title"><?php esc_html_e( 'Super Sheep Copy', 'super-sheep-copy' ); ?></h1>
					<p class="ssc-page-subtitle"><?php esc_html_e( 'Archivos + base de datos · Restauración con reescritura de URLs', 'super-sheep-copy' ); ?></p>
				</div>
			</div>
		</div>
		<button type="button" id="ssc-create-backup" class="ssc-btn-create">
			<svg class="ssc-btn-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
				<path d="M10 3v10M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M3 15h14" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
			</svg>
			<?php esc_html_e( 'Crear respaldo ahora', 'super-sheep-copy' ); ?>
		</button>
	</div>

	<!-- ── Barra de estadísticas ──────────────────────────────────────────── -->
	<div class="ssc-stats-bar">
		<div class="ssc-stat-card">
			<span class="ssc-stat-value"><?php echo esc_html( (string) $total ); ?></span>
			<span class="ssc-stat-label"><?php esc_html_e( 'Respaldos', 'super-sheep-copy' ); ?></span>
		</div>
		<div class="ssc-stat-card">
			<span class="ssc-stat-value"><?php echo esc_html( $total_size > 0 ? size_format( $total_size ) : '—' ); ?></span>
			<span class="ssc-stat-label"><?php esc_html_e( 'Espacio total', 'super-sheep-copy' ); ?></span>
		</div>
		<div class="ssc-stat-card">
			<span class="ssc-stat-value"><?php echo esc_html( $last_str ); ?></span>
			<span class="ssc-stat-label"><?php esc_html_e( 'Último respaldo', 'super-sheep-copy' ); ?></span>
		</div>
	</div>

	<!-- ── Barra de progreso (oculta hasta que se inicia una operación) ───── -->
	<div id="ssc-progress-wrap" class="ssc-progress-section" style="display:none;" aria-live="polite">
		<div class="ssc-progress-section__inner">
			<div class="ssc-progress-header">
				<span class="ssc-progress-op-label"><?php esc_html_e( 'Operación en curso', 'super-sheep-copy' ); ?></span>
				<span class="ssc-progress-dots"><span></span><span></span><span></span></span>
			</div>
			<div class="ssc-progress-bar-outer">
				<div class="ssc-progress-bar-inner" id="ssc-progress-bar" style="width:0%"></div>
			</div>
			<p id="ssc-progress-label" class="ssc-progress-step"><?php esc_html_e( 'Iniciando…', 'super-sheep-copy' ); ?></p>
		</div>
	</div>

	<!-- ── Contenido principal ────────────────────────────────────────────── -->
	<?php if ( empty( $items ) ) : ?>

		<div class="ssc-empty-state">
			<div class="ssc-empty-icon" aria-hidden="true">
				<svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
					<rect x="8" y="14" width="48" height="36" rx="4" stroke="currentColor" stroke-width="2"/>
					<path d="M8 24h48" stroke="currentColor" stroke-width="2"/>
					<circle cx="16" cy="19" r="2" fill="currentColor"/>
					<circle cx="22" cy="19" r="2" fill="currentColor"/>
					<path d="M32 36v-8M28 32l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</div>
			<h2 class="ssc-empty-title"><?php esc_html_e( 'Sin respaldos todavía', 'super-sheep-copy' ); ?></h2>
			<p class="ssc-empty-text"><?php esc_html_e( 'Crea tu primer respaldo completo. Incluirá archivos y base de datos, listos para restaurar en cualquier momento.', 'super-sheep-copy' ); ?></p>
			<button type="button" id="ssc-create-backup-empty" class="ssc-btn-create ssc-btn-create--sm">
				<svg class="ssc-btn-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
					<path d="M10 3v10M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M3 15h14" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
				</svg>
				<?php esc_html_e( 'Crear primer respaldo', 'super-sheep-copy' ); ?>
			</button>
		</div>

	<?php else : ?>

		<div class="ssc-list-section">

			<!-- Barra de herramientas de la lista -->
			<div class="ssc-list-toolbar">
				<label class="ssc-check-all-label">
					<input type="checkbox" id="ssc-select-all" class="ssc-checkbox">
					<span><?php esc_html_e( 'Seleccionar todo', 'super-sheep-copy' ); ?></span>
				</label>
				<button type="button" id="ssc-bulk-delete" class="ssc-bulk-btn" style="display:none;">
					<svg viewBox="0 0 16 16" fill="none" aria-hidden="true">
						<path d="M4 4h8M6 4V3h4v1M5.5 4l.5 9h4l.5-9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<?php esc_html_e( 'Eliminar seleccionados', 'super-sheep-copy' ); ?>
				</button>
				<span class="ssc-list-count">
					<?php
					echo esc_html( sprintf(
						/* translators: %d: number of backups */
						_n( '%d respaldo', '%d respaldos', $total, 'super-sheep-copy' ),
						$total
					) );
					?>
				</span>
			</div>

			<!-- Lista de respaldos -->
			<div class="ssc-backup-list" id="ssc-backup-list">

				<?php foreach ( $items as $item ) :
					$download_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'   => 'ssc_download_backup',
								'filename' => rawurlencode( $item['filename'] ),
							),
							admin_url( 'admin-post.php' )
						),
						'ssc_action_download'
					);

					$date_fmt = wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $item['date'] );

					switch ( $item['type'] ) {
						case 'pre-restore':
							$badge_class = 'ssc-badge-auto';
							$badge_label = __( 'Automático', 'super-sheep-copy' );
							$badge_title = __( 'Creado automáticamente antes de una restauración.', 'super-sheep-copy' );
							break;
						case 'scheduled':
							$badge_class = 'ssc-badge-sched';
							$badge_label = __( 'Programado', 'super-sheep-copy' );
							$badge_title = __( 'Creado por el programador automático.', 'super-sheep-copy' );
							break;
						default:
							$badge_class = 'ssc-badge-manual';
							$badge_label = __( 'Manual', 'super-sheep-copy' );
							$badge_title = __( 'Creado manualmente por un usuario.', 'super-sheep-copy' );
					}
				?>
				<div class="ssc-backup-card ssc-card-type--<?php echo esc_attr( $item['type'] ); ?>"
					data-filename="<?php echo esc_attr( $item['filename'] ); ?>">

					<!-- Checkbox -->
					<label class="ssc-card-checkbox" title="<?php esc_attr_e( 'Seleccionar', 'super-sheep-copy' ); ?>">
						<input type="checkbox" class="ssc-checkbox ssc-card-check-input ssc-item-check"
							name="backup[]"
							value="<?php echo esc_attr( $item['filename'] ); ?>">
					</label>

					<!-- Cuerpo de la tarjeta -->
					<div class="ssc-card-body">
						<div class="ssc-card-row-top">
							<span class="ssc-pill <?php echo esc_attr( $badge_class ); ?>"
								title="<?php echo esc_attr( $badge_title ); ?>">
								<?php echo esc_html( $badge_label ); ?>
							</span>
							<span class="ssc-card-filename"><?php echo esc_html( $item['filename'] ); ?></span>
							<?php if ( $item['label'] ) : ?>
							<span class="ssc-card-label-tag"><?php echo esc_html( $item['label'] ); ?></span>
							<?php endif; ?>
						</div>
						<div class="ssc-card-row-meta">
							<span class="ssc-meta-item">
								<svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M7 4.5V7l2 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
								<?php echo esc_html( $date_fmt ); ?>
							</span>
							<span class="ssc-meta-sep">·</span>
							<span class="ssc-meta-item">
								<svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><rect x="2" y="4" width="10" height="7" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M5 4V3a2 2 0 0 1 4 0v1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
								<?php echo esc_html( size_format( $item['size'] ) ); ?>
							</span>
							<?php if ( $item['checksum'] ) : ?>
							<span class="ssc-meta-sep">·</span>
							<span class="ssc-meta-item ssc-meta-checksum">
								<svg viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M2 7l3.5 3.5L12 4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
								<?php echo esc_html( $item['checksum'] ); ?>
							</span>
							<?php endif; ?>
						</div>
					</div>

					<!-- Acciones -->
					<div class="ssc-card-actions">
						<a href="<?php echo esc_url( $download_url ); ?>"
							class="ssc-action-btn ssc-action-download"
							title="<?php esc_attr_e( 'Descargar', 'super-sheep-copy' ); ?>">
							<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 2v8M4.5 7l3.5 3 3.5-3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 12.5h10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
							<span><?php esc_html_e( 'Descargar', 'super-sheep-copy' ); ?></span>
						</a>

						<button type="button"
							class="ssc-action-btn ssc-action-restore ssc-restore-btn"
							data-filename="<?php echo esc_attr( $item['filename'] ); ?>"
							title="<?php esc_attr_e( 'Restaurar este respaldo', 'super-sheep-copy' ); ?>">
							<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.5 8A4.5 4.5 0 1 0 5 4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M3.5 2.5v2.5H6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<span><?php esc_html_e( 'Restaurar', 'super-sheep-copy' ); ?></span>
						</button>

						<button type="button"
							class="ssc-action-btn ssc-action-info ssc-manifest-btn"
							data-filename="<?php echo esc_attr( $item['filename'] ); ?>"
							title="<?php esc_attr_e( 'Ver manifest', 'super-sheep-copy' ); ?>">
							<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.4"/><path d="M8 7v4M8 5.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
						</button>

						<button type="button"
							class="ssc-action-btn ssc-action-delete ssc-delete-btn"
							data-filename="<?php echo esc_attr( $item['filename'] ); ?>"
							title="<?php esc_attr_e( 'Eliminar', 'super-sheep-copy' ); ?>">
							<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 4h10M6 4V3h4v1M5.5 4l.5 8.5h4l.5-8.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</button>
					</div>

				</div>
				<?php endforeach; ?>

			</div><!-- .ssc-backup-list -->
		</div><!-- .ssc-list-section -->

	<?php endif; ?>

</div><!-- .ssc-backups-page -->

<!-- ── Modal: restauración completada ─────────────────────────────────────── -->
<div id="ssc-restore-done-modal" class="ssc-modal" hidden aria-modal="true" role="alertdialog" aria-labelledby="ssc-restore-done-title">
	<div class="ssc-modal-backdrop"></div>
	<div class="ssc-modal-dialog ssc-restore-done-dialog">
		<div class="ssc-modal-header">
			<h3 id="ssc-restore-done-title" class="ssc-modal-title">
				<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6" stroke="#059669" stroke-width="1.5"/><path d="M5 8l2 2 4-4" stroke="#059669" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
				<?php esc_html_e( 'Restauración completada', 'super-sheep-copy' ); ?>
			</h3>
		</div>
		<div class="ssc-modal-body ssc-restore-done-body">
			<p><?php esc_html_e( 'El sitio ha sido restaurado correctamente. Por seguridad, tu sesión ha sido cerrada.', 'super-sheep-copy' ); ?></p>
			<p><?php esc_html_e( 'Necesitarás autenticarte de nuevo para continuar trabajando en el administrador.', 'super-sheep-copy' ); ?></p>
			<div class="ssc-restore-done-actions">
				<a id="ssc-restore-done-login" href="<?php echo esc_url( wp_login_url() ); ?>" class="ssc-btn-success">
					<?php esc_html_e( 'Ir al inicio de sesión', 'super-sheep-copy' ); ?>
				</a>
			</div>
		</div>
	</div>
</div>

<!-- ── Modal de manifest ──────────────────────────────────────────────────── -->
<div id="ssc-manifest-modal" class="ssc-modal" hidden aria-modal="true" role="dialog" aria-labelledby="ssc-modal-title">
	<div class="ssc-modal-backdrop" id="ssc-modal-backdrop"></div>
	<div class="ssc-modal-dialog">
		<div class="ssc-modal-header">
			<h3 id="ssc-modal-title" class="ssc-modal-title">
				<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="3" y="2" width="10" height="12" rx="1.5" stroke="currentColor" stroke-width="1.4"/><path d="M6 6h4M6 9h4M6 12h2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
				<?php esc_html_e( 'Manifest del respaldo', 'super-sheep-copy' ); ?>
			</h3>
			<button type="button" class="ssc-modal-close" id="ssc-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'super-sheep-copy' ); ?>">
				<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
			</button>
		</div>
		<div class="ssc-modal-body">
			<pre id="ssc-manifest-content" class="ssc-manifest-pre"></pre>
		</div>
	</div>
</div>
