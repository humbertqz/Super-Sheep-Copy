<?php
/**
 * Generación del manifest.json del respaldo.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Manifest
 *
 * Construye y serializa el manifest.json que se incluye en cada ZIP.
 * El manifest documenta el entorno en el que se generó el respaldo y
 * es utilizado por SSC_Restore_Manager para validar la compatibilidad.
 */
class SSC_Manifest {

	/**
	 * Datos del manifest.
	 *
	 * @var array
	 */
	private array $data = array();

	/**
	 * Constructor.
	 *
	 * Inicializa el manifest con los datos del entorno actual.
	 */
	public function __construct() {
		global $wpdb;

		$this->data = array(
			'plugin_version' => SSC_VERSION,
			'created_at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'site_url'       => get_site_url(),
			'home_url'       => get_home_url(),
			'abspath'        => ABSPATH,
			'table_prefix'   => $wpdb->prefix,
			'charset'        => DB_CHARSET,
			'collate'        => DB_COLLATE,
			'includes'       => array(
				'files'    => true,
				'database' => true,
				'core'     => true,
			),
			'file_count'      => 0,
			'db_size_bytes'   => 0,
			'checksum_sha256' => '',
		);
	}

	// ── Setters ───────────────────────────────────────────────────────────────

	/**
	 * Actualiza el número de archivos empaquetados.
	 *
	 * @param int $count Número de archivos.
	 * @return void
	 */
	public function set_file_count( int $count ): void {
		$this->data['file_count'] = $count;
	}

	/**
	 * Actualiza el tamaño del dump SQL.
	 *
	 * @param int $bytes Tamaño en bytes.
	 * @return void
	 */
	public function set_db_size( int $bytes ): void {
		$this->data['db_size_bytes'] = $bytes;
	}

	/**
	 * Actualiza el checksum SHA-256 del ZIP final.
	 *
	 * @param string $hash Hash SHA-256 en hexadecimal.
	 * @return void
	 */
	public function set_checksum( string $hash ): void {
		$this->data['checksum_sha256'] = $hash;
	}

	/**
	 * Actualiza el flag de inclusión de núcleo WP.
	 *
	 * @param bool $include True si se incluyeron wp-admin/ y wp-includes/.
	 * @return void
	 */
	public function set_include_core( bool $include ): void {
		$this->data['includes']['core'] = $include;
	}

	// ── Serialización ─────────────────────────────────────────────────────────

	/**
	 * Serializa el manifest a JSON.
	 *
	 * @return string JSON con pretty-print.
	 */
	public function to_json(): string {
		return wp_json_encode( $this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Devuelve los datos del manifest como array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return $this->data;
	}

	// ── Lectura ───────────────────────────────────────────────────────────────

	/**
	 * Parsea un manifest.json leído de un ZIP.
	 *
	 * @param string $json_string Contenido JSON.
	 * @return array|WP_Error
	 */
	public static function parse( string $json_string ) {
		$data = json_decode( $json_string, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_manifest_json', __( 'El manifest.json no es un JSON válido.', 'super-sheep-copy' ) );
		}

		$required = array( 'plugin_version', 'site_url', 'table_prefix' );
		foreach ( $required as $key ) {
			if ( empty( $data[ $key ] ) ) {
				return new WP_Error(
					'manifest_missing_field',
					sprintf(
					/* translators: %s: required field name */
					__( 'manifest.json no contiene el campo requerido: %s', 'super-sheep-copy' ),
					$key
				)
				);
			}
		}

		// Rechazar manifests de versiones mayores del plugin.
		if ( version_compare( $data['plugin_version'], SSC_VERSION, '>' ) ) {
			return new WP_Error(
				'manifest_version_too_new',
				sprintf(
					/* translators: 1: manifest version, 2: current version */
					__( 'El respaldo fue creado con la versión %1$s del plugin, mayor a la actual (%2$s). Actualiza el plugin antes de restaurar.', 'super-sheep-copy' ),
					$data['plugin_version'],
					SSC_VERSION
				)
			);
		}

		return $data;
	}
}
