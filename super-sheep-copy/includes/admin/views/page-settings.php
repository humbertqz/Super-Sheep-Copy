<?php
/**
 * Vista: Ajustes del plugin.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = wp_parse_args(
	(array) get_option( 'ssc_settings', array() ),
	array(
		'include_core'    => 1,
		'exclude_logs'    => 1,
		'max_upload_size' => 2048, // MB
	)
);

$server_max_bytes = wp_max_upload_size();
$server_max_mb    = (int) ceil( $server_max_bytes / MB_IN_BYTES );
$plugin_max_mb    = (int) $settings['max_upload_size'];
$effective_mb     = min( $plugin_max_mb, $server_max_mb );
$exceeds_server   = $plugin_max_mb > $server_max_mb;
?>
<div class="wrap ssc-wrap ssc-plugin-page ssc-settings-page">

	<!-- Page header -->
	<div class="ssc-page-header">
		<div class="ssc-brand">
			<img src="<?php echo esc_url( SSC_PLUGIN_URL . 'assets/images/super-sheep-copy-logo.png' ); ?>"
				alt="Super Sheep Copy"
				class="ssc-brand-logo">
			<div class="ssc-brand-text">
				<h1 class="ssc-page-title"><?php esc_html_e( 'Super Sheep Copy', 'super-sheep-copy' ); ?></h1>
				<p class="ssc-page-subtitle"><?php esc_html_e( 'Ajustes · Configura las opciones del plugin', 'super-sheep-copy' ); ?></p>
			</div>
		</div>
	</div>

	<?php settings_errors( 'ssc_settings' ); ?>

	<form method="post" action="options.php" class="ssc-settings-form">
		<?php settings_fields( 'ssc_settings_group' ); ?>

		<!-- Card: Respaldo -->
		<div class="ssc-settings-card">
			<div class="ssc-settings-card__header">
				<span class="ssc-settings-card__icon">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z"/><path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z"/></svg>
				</span>
				<div>
					<h2 class="ssc-settings-card__title"><?php esc_html_e( 'Opciones de respaldo', 'super-sheep-copy' ); ?></h2>
					<p class="ssc-settings-card__desc"><?php esc_html_e( 'Controla qué se incluye al crear un nuevo respaldo.', 'super-sheep-copy' ); ?></p>
				</div>
			</div>

			<div class="ssc-settings-card__body">
				<div class="ssc-settings-field">
					<div class="ssc-settings-field__info">
						<label class="ssc-settings-field__label" for="ssc-include-core">
							<?php esc_html_e( 'Incluir núcleo de WordPress', 'super-sheep-copy' ); ?>
						</label>
						<p class="ssc-settings-field__desc"><?php esc_html_e( 'Añade wp-admin/ y wp-includes/ al ZIP. Recomendado para restauraciones en entornos vacíos.', 'super-sheep-copy' ); ?></p>
					</div>
					<label class="ssc-toggle">
						<input type="checkbox" id="ssc-include-core" name="ssc_settings[include_core]" value="1"
							<?php checked( 1, $settings['include_core'] ); ?>>
						<span class="ssc-toggle__track">
							<span class="ssc-toggle__thumb"></span>
						</span>
					</label>
				</div>

				<div class="ssc-settings-field ssc-settings-field--last">
					<div class="ssc-settings-field__info">
						<label class="ssc-settings-field__label" for="ssc-exclude-logs">
							<?php esc_html_e( 'Excluir logs y temporales grandes', 'super-sheep-copy' ); ?>
						</label>
						<p class="ssc-settings-field__desc"><?php esc_html_e( 'Omite archivos .log y .tmp de gran tamaño para reducir el peso del ZIP.', 'super-sheep-copy' ); ?></p>
					</div>
					<label class="ssc-toggle">
						<input type="checkbox" id="ssc-exclude-logs" name="ssc_settings[exclude_logs]" value="1"
							<?php checked( 1, $settings['exclude_logs'] ); ?>>
						<span class="ssc-toggle__track">
							<span class="ssc-toggle__thumb"></span>
						</span>
					</label>
				</div>
			</div>
		</div>

		<!-- Card: Importación -->
		<div class="ssc-settings-card">
			<div class="ssc-settings-card__header">
				<span class="ssc-settings-card__icon">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" style="transform:scaleY(-1);transform-origin:center"/><path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z"/></svg>
				</span>
				<div>
					<h2 class="ssc-settings-card__title"><?php esc_html_e( 'Importación de respaldos', 'super-sheep-copy' ); ?></h2>
					<p class="ssc-settings-card__desc"><?php esc_html_e( 'Límites aplicados al subir un .zip desde la página Restaurar.', 'super-sheep-copy' ); ?></p>
				</div>
			</div>

			<div class="ssc-settings-card__body">
				<div class="ssc-settings-field ssc-settings-field--last ssc-settings-field--block">
					<div class="ssc-settings-field__info">
						<label class="ssc-settings-field__label" for="ssc-max-upload-size">
							<?php esc_html_e( 'Tamaño máximo de upload', 'super-sheep-copy' ); ?>
						</label>
						<p class="ssc-settings-field__desc"><?php esc_html_e( 'Peso máximo permitido al importar un ZIP de respaldo desde el navegador.', 'super-sheep-copy' ); ?></p>
					</div>

					<div class="ssc-upload-size-control">
						<div class="ssc-number-input-wrap">
							<input type="number" id="ssc-max-upload-size" name="ssc_settings[max_upload_size]"
								value="<?php echo esc_attr( $plugin_max_mb ); ?>"
								min="1" max="<?php echo esc_attr( $server_max_mb ); ?>"
								class="ssc-number-input">
							<span class="ssc-number-input__unit">MB</span>
						</div>

						<div class="ssc-upload-size-meta">
							<div class="ssc-size-row">
								<span class="ssc-size-row__label"><?php esc_html_e( 'Límite del servidor (PHP):', 'super-sheep-copy' ); ?></span>
								<span class="ssc-size-badge ssc-size-badge--server">
									<?php echo esc_html( size_format( $server_max_bytes ) ); ?>
								</span>
							</div>
							<div class="ssc-size-row">
								<span class="ssc-size-row__label"><?php esc_html_e( 'Límite efectivo:', 'super-sheep-copy' ); ?></span>
								<span class="ssc-size-badge <?php echo $exceeds_server ? 'ssc-size-badge--warn' : 'ssc-size-badge--ok'; ?>">
									<?php echo esc_html( size_format( $effective_mb * MB_IN_BYTES ) ); ?>
								</span>
							</div>
						</div>

						<?php if ( $exceeds_server ) : ?>
						<div class="ssc-settings-inline-notice ssc-settings-inline-notice--warn">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: configured limit, 2: effective limit */
									__( 'El valor configurado supera el límite del servidor (%1$s). El límite efectivo será %2$s.', 'super-sheep-copy' ),
									size_format( $server_max_bytes ),
									size_format( $effective_mb * MB_IN_BYTES )
								)
							);
							?>
						</div>
						<?php endif; ?>

						<p class="ssc-settings-field__hint">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: php.ini directives */
									__( 'El límite del servidor está determinado por las directivas %s de PHP y no puede superarse desde WordPress.', 'super-sheep-copy' ),
									'upload_max_filesize / post_max_size'
								)
							);
							?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<!-- Footer / Save -->
		<div class="ssc-settings-footer">
			<?php submit_button( __( 'Guardar ajustes', 'super-sheep-copy' ), 'primary', 'ssc-save-settings', false ); ?>
		</div>

	</form>
</div>
