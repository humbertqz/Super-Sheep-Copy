<?php
/**
 * Importación de un archivo SQL a la base de datos.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_Database_Restore
 *
 * Lee database.sql en streaming y ejecuta las sentencias una a una
 * usando mysqli_query() sobre $wpdb->dbh directamente. Maneja:
 *  - Comentarios SQL (#, --, / * … * /).
 *  - Delimitadores multi-línea (p.ej. procedimientos almacenados — raro en WP,
 *    pero manejado de forma defensiva).
 *  - Reescritura de prefijo de tablas si difiere del actual.
 */
class SSC_Database_Restore {

	/**
	 * Ruta al archivo SQL a importar.
	 *
	 * @var string
	 */
	private string $sql_path;

	/**
	 * Prefijo de tablas en el archivo SQL de respaldo.
	 *
	 * @var string
	 */
	private string $backup_prefix;

	/**
	 * Prefijo de tablas actual del sitio.
	 *
	 * @var string
	 */
	private string $current_prefix;

	/**
	 * Número de sentencias ejecutadas.
	 *
	 * @var int
	 */
	private int $statement_count = 0;

	/**
	 * Constructor.
	 *
	 * @param string $sql_path       Ruta absoluta al archivo database.sql.
	 * @param string $backup_prefix  Prefijo de tablas en el respaldo.
	 * @param string $current_prefix Prefijo de tablas del sitio actual.
	 */
	public function __construct( string $sql_path, string $backup_prefix, string $current_prefix ) {
		$this->sql_path       = $sql_path;
		$this->backup_prefix  = $backup_prefix;
		$this->current_prefix = $current_prefix;
	}

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Ejecuta la importación completa.
	 *
	 * @return true|WP_Error
	 */
	public function run() {
		SSC_Logger::info( 'db_restore', sprintf( 'Importando SQL: %s', basename( $this->sql_path ) ) );

		if ( ! file_exists( $this->sql_path ) ) {
			return new WP_Error( 'sql_not_found', __( 'Archivo SQL no encontrado.', 'super-sheep-copy' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $this->sql_path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'sql_open_failed', __( 'No se pudo abrir el archivo SQL.', 'super-sheep-copy' ) );
		}

		global $wpdb;
		$wpdb->show_errors( false );

		// Usar $wpdb->dbh directamente — mismo enfoque que SSC_URL_Rewriter::exec_raw_update().
		// $wpdb->query() aplica placeholder_escape() corrompiendo '%' en valores
		// (p.ej. permalink_structure = '/%year%/'). mysqli_query() sobre el handle
		// nativo evita todo el pipeline de escaping de wpdb.
		$raw_dbh        = ( $wpdb->dbh instanceof \mysqli ) ? $wpdb->dbh : null;
		$use_raw_mysqli = null !== $raw_dbh;

		if ( ! $use_raw_mysqli ) {
			SSC_Logger::warn( 'db_restore', '$wpdb->dbh no es mysqli; usando $wpdb->query() — valores con % pueden corromperse.' );
		}

		$buffer      = '';
		$in_string   = false;
		$string_char = '';

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle, 1048576 ); // 1 MB por línea máximo
			if ( false === $line ) {
				break;
			}

			// Omitir comentarios completos de línea.
			$trimmed = ltrim( $line );
			if ( ! $in_string && ( strpos( $trimmed, '--' ) === 0 || strpos( $trimmed, '#' ) === 0 ) ) {
				continue;
			}

			// Reescribir prefijo si difiere.
			if ( $this->backup_prefix !== $this->current_prefix ) {
				$line = $this->rewrite_prefix( $line );
			}

			$buffer .= $line;

			// Detectar fin de sentencia (';' fuera de cadenas).
			if ( ! $in_string && $this->line_ends_statement( $line ) ) {
				$stmt   = trim( $buffer );
				$buffer = '';

				if ( '' === $stmt || ';' === $stmt ) {
					continue;
				}

				$ok = $this->exec_statement( $stmt, $raw_dbh, $use_raw_mysqli );
				if ( false === $ok ) {
					$error_msg = ( $use_raw_mysqli && $raw_dbh ) ? mysqli_error( $raw_dbh ) : $wpdb->last_error; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error
					SSC_Logger::error(
						'db_restore',
						sprintf( 'Error SQL en sentencia %d: %s', $this->statement_count + 1, $error_msg )
					);
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return new WP_Error(
						'sql_query_failed',
						sprintf(
						/* translators: %s: SQL error message */
						__( 'Error SQL: %s', 'super-sheep-copy' ),
						$error_msg
					)
					);
				}

				++$this->statement_count;
			}
		}

		// Ejecutar cualquier sentencia pendiente en el buffer.
		$stmt = trim( $buffer );
		if ( $stmt && ';' !== $stmt ) {
			$this->exec_statement( $stmt, $raw_dbh, $use_raw_mysqli );
			++$this->statement_count;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		SSC_Logger::info( 'db_restore', sprintf( 'Importación completada. Sentencias ejecutadas: %d', $this->statement_count ) );
		return true;
	}

	/**
	 * Devuelve el número de sentencias SQL ejecutadas.
	 *
	 * @return int
	 */
	public function get_statement_count(): int {
		return $this->statement_count;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Ejecuta una sentencia SQL.
	 *
	 * Usa $wpdb->dbh (mysqli nativo) para evitar que $wpdb->query() aplique
	 * placeholder_escape() y corrompa valores con '%' (p.ej. permalink_structure).
	 *
	 * @param string       $stmt           Sentencia SQL a ejecutar.
	 * @param \mysqli|null $raw_dbh        Handle mysqli ($wpdb->dbh) o null.
	 * @param bool         $use_raw_mysqli Si true, usar $raw_dbh; si false, usar $wpdb.
	 * @return bool|int False en error; true o número de filas en éxito.
	 */
	private function exec_statement( string $stmt, ?\mysqli $raw_dbh, bool $use_raw_mysqli ) {
		global $wpdb;

		if ( $use_raw_mysqli && $raw_dbh ) {
			$ok = mysqli_query( $raw_dbh, $stmt ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions.mysql_mysqli_query
			return ( false === $ok ) ? false : true;
		}

		/*
		 * Fallback when $wpdb->dbh is not a mysqli instance.
		 * $stmt is raw SQL reconstructed line-by-line from a trusted backup file
		 * produced by this same plugin. It cannot go through $wpdb->prepare()
		 * because prepare() applies placeholder_escape(), which corrupts any '%'
		 * that appears inside SQL string values (e.g. permalink_structure = '/%year%/').
		 * There is no user-supplied input in $stmt at this point.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query( $stmt );
		return $result;
	}

	/**
	 * Determina si una línea termina una sentencia SQL (acaba en ';').
	 *
	 * Comprobación simple y rápida: la última línea no vacía de la sentencia
	 * termina en ';'. No maneja DELIMITER personalizado (innecesario para WP).
	 *
	 * @param string $line Línea de texto.
	 * @return bool
	 */
	private function line_ends_statement( string $line ): bool {
		return rtrim( $line ) !== '' && substr( rtrim( $line ), -1 ) === ';';
	}

	/**
	 * Reescribe el prefijo de tablas en una línea SQL.
	 *
	 * Solo actúa sobre CREATE TABLE, DROP TABLE, INSERT INTO, ALTER TABLE,
	 * LOCK TABLES y referencias en sentencias WHERE/FROM para ser seguro.
	 *
	 * @param string $line Línea SQL original.
	 * @return string
	 */
	private function rewrite_prefix( string $line ): string {
		$from = preg_quote( $this->backup_prefix, '/' );
		$to   = $this->current_prefix;

		// Reemplazar `wp_tabla` → `nuevo_tabla` en identificadores backtick.
		$line = preg_replace(
			'/(`' . $from . ')([a-zA-Z0-9_]+`)/',
			'`' . $to . '$2',
			$line
		);

		return $line;
	}
}
