<?php
/**
 * Vista: Log de auditoría — agrupado por operación.
 *
 * @package Full_Site_Backup
 *
 * @var array $summaries  Grupos de entradas indexados por job_id.
 * @var array $ungrouped  Entradas sin job_id (eventos del sistema).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formatea segundos como "1m 23s" o "45s".
 *
 * @param int $secs
 * @return string
 */
function ssc_format_duration( int $secs ): string {
	if ( $secs <= 0 ) {
		return '—';
	}
	$m = intdiv( $secs, 60 );
	$s = $secs % 60;
	return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
}

$has_content = ! empty( $summaries ) || ! empty( $ungrouped );
?>
<div class="wrap ssc-wrap ssc-plugin-page ssc-log-page">

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
					<a href="https://github.com/humbertqz/Super-Sheep-Copy/commits/master/" target="_blank" rel="noopener noreferrer" class="ssc-version-badge">v<?php echo esc_html( SSC_VERSION ); ?></a>
				</h1>
				<p class="ssc-page-subtitle"><?php esc_html_e( 'Log de auditoría · Registro detallado de cada operación', 'super-sheep-copy' ); ?></p>
			</div>
		</div>

		<div class="ssc-page-header__actions">
			<?php if ( $has_content ) : ?>
			<button type="button" id="ssc-expand-all" class="button ssc-btn-sm">
				<?php esc_html_e( 'Expandir todo', 'super-sheep-copy' ); ?>
			</button>
			<button type="button" id="ssc-collapse-all" class="button ssc-btn-sm">
				<?php esc_html_e( 'Colapsar todo', 'super-sheep-copy' ); ?>
			</button>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'ssc_action_clear_log', 'ssc_nonce' ); ?>
				<input type="hidden" name="action" value="ssc_clear_log">
				<button type="submit" class="button ssc-btn-sm ssc-btn-danger"
					onclick="return confirm('<?php esc_attr_e( '¿Eliminar todas las entradas del log?', 'super-sheep-copy' ); ?>')">
					<?php esc_html_e( 'Limpiar log', 'super-sheep-copy' ); ?>
				</button>
			</form>
		</div>
	</div>

	<?php if ( ! $has_content ) : ?>
		<div class="ssc-log-empty">
			<span class="dashicons dashicons-list-view"></span>
			<p><?php esc_html_e( 'No hay entradas en el log todavía.', 'super-sheep-copy' ); ?></p>
		</div>

	<?php else : ?>

		<div class="ssc-log-feed" id="ssc-log-feed">

			<?php foreach ( $summaries as $summary ) :
				$status       = $summary['status']; // success | warning | error
				$op_type      = $summary['type'];   // backup | restore
				$is_error     = ( 'error' === $status );
				$is_warning   = ( 'warning' === $status );
				$type_label   = ( 'restore' === $op_type )
					? esc_html__( 'Restauración', 'super-sheep-copy' )
					: esc_html__( 'Respaldo', 'super-sheep-copy' );
				$status_icon  = $is_error ? '✕' : ( $is_warning ? '!' : '✓' );
				$started_dt   = date_create( $summary['started_at'] );
				$started_fmt  = $started_dt ? $started_dt->format( 'd/m/Y H:i:s' ) : $summary['started_at'];

				$log_json = wp_json_encode( array(
					'job_id'        => $summary['job_id'],
					'operation'     => $summary['type'],
					'status'        => $summary['status'],
					'file'          => $summary['object_name'] ?: null,
					'started_at'    => $summary['started_at'],
					'duration'      => ssc_format_duration( $summary['duration_secs'] ),
					'user'          => $summary['user_login'] ?: null,
					'entries_count' => $summary['entry_count'],
					'errors'        => $summary['error_count'],
					'warnings'      => $summary['warning_count'],
					'entries'       => array_map( function ( $e ) {
						$dt = date_create( $e['created_at'] );
						return array(
							'time'    => $dt ? $dt->format( 'H:i:s' ) : $e['created_at'],
							'action'  => $e['action'],
							'level'   => $e['result'],
							'message' => $e['message'],
						);
					}, $summary['entries'] ),
				), JSON_UNESCAPED_UNICODE );
			?>
			<details class="ssc-op-card ssc-op-card--<?php echo esc_attr( $status ); ?>"
				<?php echo ( $is_error || $is_warning ) ? 'open' : ''; ?>
				data-ssc-log="<?php echo esc_attr( $log_json ); ?>">

				<summary class="ssc-op-card__summary">
					<span class="ssc-op-card__indicator">
						<span class="ssc-op-status-dot ssc-op-status-dot--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_icon ); ?></span>
					</span>

					<span class="ssc-op-card__meta">
						<span class="ssc-op-type-chip ssc-op-type-chip--<?php echo esc_attr( $op_type ); ?>">
							<?php echo esc_html( $type_label ); ?>
						</span>

						<?php if ( $summary['object_name'] ) : ?>
						<code class="ssc-op-card__filename"><?php echo esc_html( $summary['object_name'] ); ?></code>
						<?php endif; ?>
					</span>

					<span class="ssc-op-card__time">
						<span class="ssc-op-card__date"><?php echo esc_html( $started_fmt ); ?></span>
						<span class="ssc-op-card__dur">
							<?php echo esc_html( ssc_format_duration( $summary['duration_secs'] ) ); ?>
						</span>
					</span>

					<span class="ssc-op-card__counts">
						<span class="ssc-count-pill ssc-count-pill--neutral" title="<?php esc_attr_e( 'Entradas', 'super-sheep-copy' ); ?>">
							<?php echo esc_html( $summary['entry_count'] ); ?>
						</span>
						<?php if ( $summary['error_count'] > 0 ) : ?>
						<span class="ssc-count-pill ssc-count-pill--error" title="<?php esc_attr_e( 'Errores', 'super-sheep-copy' ); ?>">
							<?php echo esc_html( $summary['error_count'] ); ?>&nbsp;err
						</span>
						<?php endif; ?>
						<?php if ( $summary['warning_count'] > 0 ) : ?>
						<span class="ssc-count-pill ssc-count-pill--warn" title="<?php esc_attr_e( 'Advertencias', 'super-sheep-copy' ); ?>">
							<?php echo esc_html( $summary['warning_count'] ); ?>&nbsp;warn
						</span>
						<?php endif; ?>
					</span>

					<span class="ssc-op-card__user">
						<?php if ( $summary['user_login'] ) : ?>
						<span class="dashicons dashicons-admin-users" style="font-size:14px;vertical-align:middle;color:#999;"></span>
						<span><?php echo esc_html( $summary['user_login'] ); ?></span>
						<?php endif; ?>
					</span>

					<button type="button" class="ssc-copy-log-btn" title="<?php esc_attr_e( 'Copiar log como JSON', 'super-sheep-copy' ); ?>">
						<span class="ssc-copy-icon"><svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M5 4V3a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
						<span class="ssc-copy-label"><?php esc_html_e( 'Copiar JSON', 'super-sheep-copy' ); ?></span>
					</button>
				</summary>

				<div class="ssc-op-card__body">
					<table class="ssc-entries-table">
						<thead>
							<tr>
								<th class="ssc-col-time"><?php esc_html_e( 'Hora', 'super-sheep-copy' ); ?></th>
								<th class="ssc-col-action"><?php esc_html_e( 'Acción', 'super-sheep-copy' ); ?></th>
								<th class="ssc-col-result"><?php esc_html_e( 'Resultado', 'super-sheep-copy' ); ?></th>
								<th class="ssc-col-message"><?php esc_html_e( 'Mensaje', 'super-sheep-copy' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $summary['entries'] as $entry ) :
								$dt = date_create( $entry['created_at'] );
							?>
							<tr class="ssc-entry ssc-entry--<?php echo esc_attr( $entry['result'] ); ?>">
								<td class="ssc-col-time">
									<code><?php echo esc_html( $dt ? $dt->format( 'H:i:s' ) : $entry['created_at'] ); ?></code>
								</td>
								<td class="ssc-col-action">
									<code><?php echo esc_html( $entry['action'] ); ?></code>
								</td>
								<td class="ssc-col-result">
									<span class="ssc-badge ssc-badge--<?php echo esc_attr( $entry['result'] ); ?>">
										<?php echo esc_html( $entry['result'] ); ?>
									</span>
								</td>
								<td class="ssc-col-message"><?php echo esc_html( $entry['message'] ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="ssc-op-card__footer">
						<span><?php esc_html_e( 'Job ID:', 'super-sheep-copy' ); ?></span>
						<code><?php echo esc_html( $summary['job_id'] ); ?></code>
					</div>
				</div>

			</details>
			<?php endforeach; ?>

			<?php if ( ! empty( $ungrouped ) ) :
				$ungrouped_json = wp_json_encode( array(
					'operation'     => 'system',
					'entries_count' => count( $ungrouped ),
					'entries'       => array_map( function ( $e ) {
						$dt = date_create( $e['created_at'] );
						return array(
							'time'    => $dt ? $dt->format( 'd/m H:i:s' ) : $e['created_at'],
							'action'  => $e['action'],
							'level'   => $e['result'],
							'message' => $e['message'],
						);
					}, $ungrouped ),
				), JSON_UNESCAPED_UNICODE );
			?>
			<details class="ssc-op-card ssc-op-card--system"
				data-ssc-log="<?php echo esc_attr( $ungrouped_json ); ?>">
				<summary class="ssc-op-card__summary">
					<span class="ssc-op-card__indicator">
						<span class="ssc-op-status-dot ssc-op-status-dot--system">·</span>
					</span>
					<span class="ssc-op-card__meta">
						<span class="ssc-op-type-chip ssc-op-type-chip--system">
							<?php esc_html_e( 'Sistema', 'super-sheep-copy' ); ?>
						</span>
					</span>
					<span class="ssc-op-card__time"></span>
					<span class="ssc-op-card__counts">
						<span class="ssc-count-pill ssc-count-pill--neutral">
							<?php echo esc_html( count( $ungrouped ) ); ?>
						</span>
					</span>
					<span class="ssc-op-card__user"></span>
					<button type="button" class="ssc-copy-log-btn" title="<?php esc_attr_e( 'Copiar log como JSON', 'super-sheep-copy' ); ?>">
						<span class="ssc-copy-icon"><svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M5 4V3a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
						<span class="ssc-copy-label"><?php esc_html_e( 'Copiar JSON', 'super-sheep-copy' ); ?></span>
					</button>
				</summary>

				<div class="ssc-op-card__body">
					<table class="ssc-entries-table">
						<thead>
							<tr>
								<th class="ssc-col-time"><?php esc_html_e( 'Fecha', 'super-sheep-copy' ); ?></th>
								<th class="ssc-col-action"><?php esc_html_e( 'Acción', 'super-sheep-copy' ); ?></th>
								<th class="ssc-col-result"><?php esc_html_e( 'Resultado', 'super-sheep-copy' ); ?></th>
								<th class="ssc-col-message"><?php esc_html_e( 'Mensaje', 'super-sheep-copy' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $ungrouped as $entry ) :
								$dt = date_create( $entry['created_at'] );
							?>
							<tr class="ssc-entry ssc-entry--<?php echo esc_attr( $entry['result'] ); ?>">
								<td class="ssc-col-time">
									<code><?php echo esc_html( $dt ? $dt->format( 'd/m H:i:s' ) : $entry['created_at'] ); ?></code>
								</td>
								<td class="ssc-col-action">
									<code><?php echo esc_html( $entry['action'] ); ?></code>
								</td>
								<td class="ssc-col-result">
									<span class="ssc-badge ssc-badge--<?php echo esc_attr( $entry['result'] ); ?>">
										<?php echo esc_html( $entry['result'] ); ?>
									</span>
								</td>
								<td class="ssc-col-message"><?php echo esc_html( $entry['message'] ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</details>
			<?php endif; ?>

		</div><!-- .ssc-log-feed -->

	<?php endif; ?>
</div>

<script>
(function () {
	var feed = document.getElementById( 'ssc-log-feed' );
	if ( ! feed ) return;

	// Expand / collapse all
	document.getElementById( 'ssc-expand-all' )?.addEventListener( 'click', function () {
		feed.querySelectorAll( 'details' ).forEach( function (d) { d.open = true; } );
	} );
	document.getElementById( 'ssc-collapse-all' )?.addEventListener( 'click', function () {
		feed.querySelectorAll( 'details' ).forEach( function (d) { d.open = false; } );
	} );

	// ── Copy-to-clipboard ─────────────────────────────────────────────────

	var ICON_COPY = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="5" y="5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M5 4V3a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
	var ICON_CHECK = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8l4 4 6-7" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	// Prevent <details> toggle when the click target is the copy button
	feed.querySelectorAll( '.ssc-op-card__summary' ).forEach( function (summary) {
		summary.addEventListener( 'click', function (e) {
			if ( e.target.closest( '.ssc-copy-log-btn' ) ) {
				e.preventDefault();
			}
		} );
	} );

	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch (err) {}
		document.body.removeChild( ta );
	}

	function showCopied( btn ) {
		var iconEl  = btn.querySelector( '.ssc-copy-icon' );
		var labelEl = btn.querySelector( '.ssc-copy-label' );
		btn.classList.add( 'is-copied' );
		if ( iconEl )  iconEl.innerHTML  = ICON_CHECK;
		if ( labelEl ) labelEl.textContent = '✓ Copiado';
		setTimeout( function () {
			btn.classList.remove( 'is-copied' );
			if ( iconEl )  iconEl.innerHTML  = ICON_COPY;
			if ( labelEl ) labelEl.textContent = 'Copiar JSON';
		}, 2000 );
	}

	feed.addEventListener( 'click', function (e) {
		var btn = e.target.closest( '.ssc-copy-log-btn' );
		if ( ! btn ) return;

		var card = btn.closest( '[data-ssc-log]' );
		if ( ! card ) return;

		var raw  = card.getAttribute( 'data-ssc-log' );
		var obj  = JSON.parse( raw );
		var text = JSON.stringify( obj, null, 2 );

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text )
				.then( function () { showCopied( btn ); } )
				.catch( function () { fallbackCopy( text ); showCopied( btn ); } );
		} else {
			fallbackCopy( text );
			showCopied( btn );
		}
	} );
}());
</script>
