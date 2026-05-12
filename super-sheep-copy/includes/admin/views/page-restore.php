<?php
/**
 * Vista: Restaurar respaldo.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap ssc-wrap ssc-plugin-page ssc-restore-page">

	<!-- WP notices anchor: keeps admin_notices above the branded header -->
	<h1 class="screen-reader-text"><?php esc_html_e( 'Super Sheep Copy', 'super-sheep-copy' ); ?></h1>

	<!-- Page header -->
	<div class="ssc-page-header">
		<div class="ssc-brand">
			<img src="<?php echo esc_url( SSC_PLUGIN_URL . 'assets/images/super-sheep-copy-logo.png' ); ?>"
				alt="Super Sheep Copy"
				class="ssc-brand-logo">
			<div class="ssc-brand-text">
				<h1 class="ssc-page-title">
					<?php esc_html_e( 'Super Sheep Copy', 'super-sheep-copy' ); ?>
					<span class="ssc-version-badge">v<?php echo esc_html( SSC_VERSION ); ?></span>
				</h1>
				<p class="ssc-page-subtitle"><?php esc_html_e( 'Restaurar · Importa un ZIP para restaurar tu sitio', 'super-sheep-copy' ); ?></p>
			</div>
		</div>
	</div>

	<div class="ssc-notice ssc-notice--warning">
		<p><strong><?php esc_html_e( '⚠ Advertencia:', 'super-sheep-copy' ); ?></strong>
		<?php esc_html_e( 'La restauración sobrescribirá el contenido actual del sitio. Se creará un respaldo automático previo a la restauración.', 'super-sheep-copy' ); ?></p>
	</div>

	<!-- Instrucciones -->
	<div class="ssc-restore-guide">
		<h3 class="ssc-restore-guide__title">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd"/></svg>
			<?php esc_html_e( 'Cómo restaurar tu sitio', 'super-sheep-copy' ); ?>
		</h3>

		<ol class="ssc-restore-steps">
			<li class="ssc-restore-step">
				<span class="ssc-restore-step__num">1</span>
				<div class="ssc-restore-step__content">
					<strong><?php esc_html_e( 'Obtén el archivo .zip de respaldo', 'super-sheep-copy' ); ?></strong>
					<p><?php esc_html_e( 'Ve a la página Respaldos, selecciona el respaldo que deseas utilizar y descárgalo. El archivo debe haber sido generado por este plugin y tiene extensión .zip.', 'super-sheep-copy' ); ?></p>
				</div>
			</li>
			<li class="ssc-restore-step">
				<span class="ssc-restore-step__num">2</span>
				<div class="ssc-restore-step__content">
					<strong><?php esc_html_e( 'Selecciona el archivo en el formulario', 'super-sheep-copy' ); ?></strong>
					<p><?php esc_html_e( 'Haz clic en el campo de archivo de abajo y elige el .zip desde tu equipo. El tamaño máximo permitido es el configurado en tu servidor.', 'super-sheep-copy' ); ?></p>
				</div>
			</li>
			<li class="ssc-restore-step">
				<span class="ssc-restore-step__num">3</span>
				<div class="ssc-restore-step__content">
					<strong><?php esc_html_e( 'Inicia la restauración', 'super-sheep-copy' ); ?></strong>
					<p><?php esc_html_e( 'Pulsa "Subir y restaurar". Antes de sobrescribir cualquier dato, el plugin creará automáticamente un respaldo de seguridad del estado actual del sitio.', 'super-sheep-copy' ); ?></p>
				</div>
			</li>
			<li class="ssc-restore-step">
				<span class="ssc-restore-step__num">4</span>
				<div class="ssc-restore-step__content">
					<strong><?php esc_html_e( 'Espera a que finalice el proceso', 'super-sheep-copy' ); ?></strong>
					<p><?php esc_html_e( 'La barra de progreso mostrará el avance. Al terminar, se cerrarán todas las sesiones activas por seguridad y deberás volver a iniciar sesión.', 'super-sheep-copy' ); ?></p>
				</div>
			</li>
		</ol>

		<div class="ssc-restore-guide__requirements">
			<span class="ssc-restore-guide__req-label"><?php esc_html_e( 'Requisitos del archivo:', 'super-sheep-copy' ); ?></span>
			<ul>
				<li><?php esc_html_e( 'Extensión .zip generado por Super Sheep Copy', 'super-sheep-copy' ); ?></li>
				<li><?php esc_html_e( 'Debe contener manifest.json válido en su interior', 'super-sheep-copy' ); ?></li>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: max upload size */
							__( 'Peso máximo: %s (límite del servidor)', 'super-sheep-copy' ),
							size_format( wp_max_upload_size() )
						)
					);
					?>
				</li>
			</ul>
		</div>
	</div>

	<!-- Método alternativo: FTP / panel de hosting -->
	<?php
	$ssc_backups_abs  = defined( 'SSC_BACKUPS_DIR' ) ? SSC_BACKUPS_DIR : WP_CONTENT_DIR . '/uploads/ssc-backups/';
	$ssc_backups_rel  = 'wp-content/uploads/ssc-backups/';
	?>
	<div class="ssc-restore-guide ssc-restore-guide--ftp">
		<h3 class="ssc-restore-guide__title">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6ZM14.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM2 12a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-2Zm12.5 3a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/></svg>
			<?php esc_html_e( 'Subir via FTP o panel de hosting', 'super-sheep-copy' ); ?>
		</h3>

		<p class="ssc-restore-guide__intro">
			<?php esc_html_e( 'Si el archivo es demasiado grande para subirlo desde el navegador, puedes cargarlo directamente al servidor usando un cliente FTP (FileZilla, Cyberduck) o el administrador de archivos de tu hosting (cPanel, Plesk, DirectAdmin).', 'super-sheep-copy' ); ?>
		</p>

		<ol class="ssc-restore-steps">
			<li class="ssc-restore-step">
				<span class="ssc-restore-step__num">1</span>
				<div class="ssc-restore-step__content">
					<strong><?php esc_html_e( 'Conéctate al servidor', 'super-sheep-copy' ); ?></strong>
					<p><?php esc_html_e( 'Abre tu cliente FTP o el administrador de archivos de tu panel de hosting e inicia sesión con tus credenciales.', 'super-sheep-copy' ); ?></p>
				</div>
			</li>
			<li class="ssc-restore-step">
				<span class="ssc-restore-step__num">2</span>
				<div class="ssc-restore-step__content">
					<strong><?php esc_html_e( 'Navega a la carpeta de respaldos', 'super-sheep-copy' ); ?></strong>
					<p><?php esc_html_e( 'Dirígete a la carpeta donde el plugin almacena los respaldos. A continuación se muestran las rutas de tu instalación:', 'super-sheep-copy' ); ?></p>
					<div class="ssc-path-box">
						<div class="ssc-path-row">
							<span class="ssc-path-row__label"><?php esc_html_e( 'Ruta absoluta (servidor):', 'super-sheep-copy' ); ?></span>
							<code class="ssc-path-row__code"><?php echo esc_html( $ssc_backups_abs ); ?></code>
						</div>
						<div class="ssc-path-row">
							<span class="ssc-path-row__label"><?php esc_html_e( 'Ruta relativa (desde la raíz de WordPress):', 'super-sheep-copy' ); ?></span>
							<code class="ssc-path-row__code"><?php echo esc_html( $ssc_backups_rel ); ?></code>
						</div>
					</div>
				</div>
			</li>
			<li class="ssc-restore-step">
				<span class="ssc-restore-step__num">3</span>
				<div class="ssc-restore-step__content">
					<strong><?php esc_html_e( 'Sube el archivo .zip a esa carpeta', 'super-sheep-copy' ); ?></strong>
					<p><?php esc_html_e( 'Copia o arrastra el archivo .zip de respaldo directamente dentro de esa carpeta. No es necesario descomprimirlo.', 'super-sheep-copy' ); ?></p>
				</div>
			</li>
			<li class="ssc-restore-step">
				<span class="ssc-restore-step__num">4</span>
				<div class="ssc-restore-step__content">
					<strong><?php esc_html_e( 'Ve a la página Respaldos', 'super-sheep-copy' ); ?></strong>
					<p><?php esc_html_e( 'El archivo aparecerá automáticamente en la lista de respaldos disponibles. Desde allí podrás iniciar la restauración con un solo clic.', 'super-sheep-copy' ); ?></p>
				</div>
			</li>
		</ol>
	</div>

	<!-- Subir archivo -->
	<h2><?php esc_html_e( 'Subir archivo de respaldo', 'super-sheep-copy' ); ?></h2>
	<form method="post" enctype="multipart/form-data" id="ssc-upload-form"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssc_action_upload_restore', 'ssc_nonce' ); ?>
		<input type="hidden" name="action" value="ssc_upload_restore">

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="ssc-zip-file"><?php esc_html_e( 'Archivo .zip', 'super-sheep-copy' ); ?></label>
				</th>
				<td>
					<input type="file" id="ssc-zip-file" name="ssc_zip_file" accept=".zip" required>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: max upload size */
								__( 'Máximo: %s. Solo archivos .zip generados por Super Sheep Copy.', 'super-sheep-copy' ),
								size_format( wp_max_upload_size() )
							)
						);
						?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Subir y restaurar', 'super-sheep-copy' ), 'primary', 'ssc-submit-upload' ); ?>
	</form>

	<!-- Barra de progreso (oculta) -->
	<div id="ssc-restore-progress-wrap" style="display:none;" class="ssc-progress-wrap" aria-live="polite">
		<div class="ssc-progress-bar-outer">
			<div class="ssc-progress-bar-inner" id="ssc-restore-progress-bar" style="width:0%"></div>
		</div>
		<p id="ssc-restore-progress-label"><?php esc_html_e( 'Iniciando restauración…', 'super-sheep-copy' ); ?></p>
		<button id="ssc-restore-cancel-btn" type="button" class="button ssc-cancel-restore-btn" style="display:none;">
			<?php esc_html_e( 'Cancelar restauración', 'super-sheep-copy' ); ?>
		</button>
	</div>
</div>
