<?php
/**
 * Tabla de listado de respaldos (extiende WP_List_Table).
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class SSC_List_Table
 *
 * Muestra los archivos de respaldo disponibles en SSC_BACKUPS_DIR
 * con columnas de nombre, fecha, tamaño, checksum y acciones.
 */
class SSC_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'respaldo', 'super-sheep-copy' ),
				'plural'   => __( 'respaldos', 'super-sheep-copy' ),
				'ajax'     => false,
			)
		);
	}

	/**
	 * Devuelve las columnas de la tabla.
	 *
	 * @return array
	 */
	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox">',
			'name'     => __( 'Nombre', 'super-sheep-copy' ),
			'type'     => __( 'Tipo', 'super-sheep-copy' ),
			'date'     => __( 'Fecha', 'super-sheep-copy' ),
			'size'     => __( 'Tamaño', 'super-sheep-copy' ),
			'checksum' => __( 'Checksum', 'super-sheep-copy' ),
			'actions'  => __( 'Acciones', 'super-sheep-copy' ),
		);
	}

	/**
	 * Columnas ordenables.
	 *
	 * @return array
	 */
	protected function get_sortable_columns(): array {
		return array(
			'name' => array( 'name', false ),
			'date' => array( 'date', true ),
			'size' => array( 'size', false ),
		);
	}

	/**
	 * Bulk actions disponibles.
	 *
	 * @return array
	 */
	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Eliminar', 'super-sheep-copy' ),
		);
	}

	/**
	 * Prepara los items leyendo el directorio de respaldos.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$items = $this->get_backup_files();

		// Ordenar.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'date'; // phpcs:ignore WordPress.Security.NonceVerification
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( $_GET['order'] ) ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification

		usort( $items, function ( $a, $b ) use ( $orderby, $order ) {
			$cmp = 0;
			if ( isset( $a[ $orderby ] ) ) {
				$cmp = $a[ $orderby ] <=> $b[ $orderby ];
			}
			return 'asc' === $order ? $cmp : -$cmp;
		} );

		$this->items = $items;
	}

	/**
	 * Lee y construye la lista de archivos de respaldo.
	 *
	 * @return array
	 */
	private function get_backup_files(): array {
		$items = array();

		if ( ! is_dir( SSC_BACKUPS_DIR ) ) {
			return $items;
		}

		$files = glob( SSC_BACKUPS_DIR . 'backup-*.zip' );
		if ( ! $files ) {
			return $items;
		}

		foreach ( $files as $file ) {
			$filename = basename( $file );
			$mtime    = filemtime( $file );
			$size     = filesize( $file );

			// Leer checksum si existe.
			$sha_file = $file . '.sha256';
			$checksum = '';
			if ( file_exists( $sha_file ) ) {
				$raw      = file_get_contents( $sha_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$checksum = $raw ? substr( trim( $raw ), 0, 8 ) . '…' : '';
			}

			// Leer tipo desde .meta si existe; si no, inferir del nombre.
			$type  = 'manual';
			$label = '';
			$meta_file = $file . '.meta';
			if ( file_exists( $meta_file ) ) {
				$meta_raw = file_get_contents( $meta_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$meta     = $meta_raw ? json_decode( $meta_raw, true ) : array();
				if ( is_array( $meta ) ) {
					$type  = $meta['type']  ?? 'manual';
					$label = $meta['label'] ?? '';
				}
			} elseif ( false !== strpos( $filename, 'pre-restore' ) ) {
				// Fallback para respaldos creados antes de implementar .meta.
				$type = 'pre-restore';
			}

			$items[] = array(
				'filename' => $filename,
				'name'     => $filename,
				'date'     => $mtime,
				'size'     => $size,
				'checksum' => $checksum,
				'type'     => $type,
				'label'    => $label,
			);
		}

		return $items;
	}

	/**
	 * Renderiza la columna checkbox.
	 *
	 * @param array $item Fila de datos.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="backup[]" value="%s">',
			esc_attr( $item['filename'] )
		);
	}

	/**
	 * Renderiza la columna tipo con un badge visual.
	 *
	 * @param array $item Fila de datos.
	 * @return string
	 */
	protected function column_type( $item ): string {
		$type = $item['type'] ?? 'manual';

		switch ( $type ) {
			case 'pre-restore':
				return '<span class="ssc-type-badge ssc-type-badge--auto" title="'
					. esc_attr__( 'Creado automáticamente por el sistema antes de una restauración.', 'super-sheep-copy' )
					. '">'
					. esc_html__( 'Automático', 'super-sheep-copy' )
					. '</span>';

			case 'scheduled':
				return '<span class="ssc-type-badge ssc-type-badge--scheduled" title="'
					. esc_attr__( 'Creado por el programador automático.', 'super-sheep-copy' )
					. '">'
					. esc_html__( 'Programado', 'super-sheep-copy' )
					. '</span>';

			default: // 'manual'
				return '<span class="ssc-type-badge ssc-type-badge--manual" title="'
					. esc_attr__( 'Creado manualmente por un usuario.', 'super-sheep-copy' )
					. '">'
					. esc_html__( 'Manual', 'super-sheep-copy' )
					. '</span>';
		}
	}

	/**
	 * Renderiza la columna nombre con acciones de fila.
	 *
	 * @param array $item Fila de datos.
	 * @return string
	 */
	protected function column_name( $item ): string {
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

		$row_actions = array(
			'download' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $download_url ),
				esc_html__( 'Descargar', 'super-sheep-copy' )
			),
			'restore'  => sprintf(
				'<a href="#" class="ssc-restore-btn" data-filename="%s">%s</a>',
				esc_attr( $item['filename'] ),
				esc_html__( 'Restaurar', 'super-sheep-copy' )
			),
			'manifest' => sprintf(
				'<a href="#" class="ssc-manifest-btn" data-filename="%s">%s</a>',
				esc_attr( $item['filename'] ),
				esc_html__( 'Ver manifest', 'super-sheep-copy' )
			),
			'delete'   => sprintf(
				'<a href="#" class="ssc-delete-btn" data-filename="%s" style="color:#b32d2e;">%s</a>',
				esc_attr( $item['filename'] ),
				esc_html__( 'Eliminar', 'super-sheep-copy' )
			),
		);

		return esc_html( $item['name'] ) . $this->row_actions( $row_actions );
	}

	/**
	 * Renderiza la columna fecha.
	 *
	 * @param array $item Fila de datos.
	 * @return string
	 */
	protected function column_date( $item ): string {
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['date'] ) );
	}

	/**
	 * Renderiza la columna tamaño.
	 *
	 * @param array $item Fila de datos.
	 * @return string
	 */
	protected function column_size( $item ): string {
		return esc_html( size_format( $item['size'] ) );
	}

	/**
	 * Renderiza la columna checksum.
	 *
	 * @param array $item Fila de datos.
	 * @return string
	 */
	protected function column_checksum( $item ): string {
		return $item['checksum'] ? '<code>' . esc_html( $item['checksum'] ) . '</code>' : '—';
	}

	/**
	 * Renderiza la columna acciones (vacía: se usan row_actions).
	 *
	 * @param array $item Fila de datos.
	 * @return string
	 */
	protected function column_actions( $item ): string {
		return '';
	}

	/**
	 * Mensaje cuando no hay items.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No hay respaldos disponibles. Crea uno usando el botón de arriba.', 'super-sheep-copy' );
	}
}
