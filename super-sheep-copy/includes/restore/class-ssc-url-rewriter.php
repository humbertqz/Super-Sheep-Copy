<?php
/**
 * Reescritura serialize-safe de URLs en la base de datos.
 *
 * @package Full_Site_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SSC_URL_Rewriter
 *
 * Reemplaza URLs (y opcionalmente rutas de sistema de archivos) en la base
 * de datos de forma segura con datos serializados en PHP.
 *
 * Un str_replace() directo sobre columnas serializadas rompe las longitudes
 * `s:N:"..."` y deja los datos corruptos. Esta clase:
 *  1. Lee cada columna objetivo por lotes (500 filas).
 *  2. Para cada valor, aplica ssc_replace_recursive() que deserializa,
 *     reemplaza en profundidad y vuelve a serializar.
 *  3. Solo escribe de vuelta si el valor cambió.
 *  4. Registra cuántos reemplazos hizo por tabla.
 */
class SSC_URL_Rewriter {

	/**
	 * Filas a procesar por lote.
	 */
	const BATCH_SIZE = 500;

	/**
	 * URL de origen (sin trailing slash).
	 *
	 * @var string
	 */
	private string $from_url;

	/**
	 * URL de destino (sin trailing slash).
	 *
	 * @var string
	 */
	private string $to_url;

	/**
	 * Ruta de sistema de archivos de origen (opcional).
	 *
	 * @var string
	 */
	private string $from_path;

	/**
	 * Ruta de sistema de archivos de destino (opcional).
	 *
	 * @var string
	 */
	private string $to_path;

	/**
	 * Contadores de reemplazos por tabla.
	 *
	 * @var array<string, int>
	 */
	private array $counts = array();

	/**
	 * Constructor.
	 *
	 * @param string $from_url  URL de origen  (ej. 'https://old.com').
	 * @param string $to_url    URL de destino (ej. 'https://new.com').
	 * @param string $from_path Ruta FS de origen  (ej. '/var/www/old/') — opcional.
	 * @param string $to_path   Ruta FS de destino (ej. '/var/www/new/') — opcional.
	 */
	public function __construct(
		string $from_url,
		string $to_url,
		string $from_path = '',
		string $to_path   = ''
	) {
		$this->from_url  = rtrim( $from_url, '/' );
		$this->to_url    = rtrim( $to_url, '/' );
		$this->from_path = rtrim( $from_path, '/' );
		$this->to_path   = rtrim( $to_path, '/' );
	}

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Ejecuta la reescritura completa en todas las tablas objetivo.
	 *
	 * @return array Contadores de reemplazos por tabla.
	 */
	public function run(): array {
		if ( $this->from_url === $this->to_url ) {
			SSC_Logger::info( 'url_rewriter', 'URLs origen y destino son iguales. No se realizaron reemplazos.' );
			return array();
		}

		SSC_Logger::info( 'url_rewriter', sprintf( 'Iniciando reescritura: %s → %s', $this->from_url, $this->to_url ) );

		// Actualizar siteurl y home directamente.
		$this->update_siteurl_and_home();

		// Procesar tablas estándar.
		$targets = $this->get_default_targets();
		foreach ( $targets as $target ) {
			$this->process_table( $target['table'], $target['id_col'], $target['value_col'] );
		}

		SSC_Logger::info( 'url_rewriter', 'Reescritura completada. Totales: ' . wp_json_encode( $this->counts ) );

		return $this->counts;
	}

	// ── Siteurl / Home ────────────────────────────────────────────────────────

	/**
	 * Actualiza siteurl y home en wp_options directamente.
	 *
	 * @return void
	 */
	private function update_siteurl_and_home(): void {
		global $wpdb;

		foreach ( array( 'siteurl', 'home' ) as $option_name ) {
			$current = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					$option_name
				)
			);

			if ( $current ) {
				$updated = $this->replace_all_forms( $current );
				if ( $updated !== $current ) {
					$this->exec_raw_update( $wpdb->options, 'option_value', $updated, 'option_name', $option_name );
					$this->counts[ $wpdb->options . '.' . $option_name ] = 1;
				}
			}
		}
	}

	// ── Procesamiento por tabla ───────────────────────────────────────────────

	/**
	 * Procesa una tabla entera en lotes.
	 *
	 * @param string $table     Nombre de la tabla.
	 * @param string $id_col    Nombre de la columna PK (para paginación).
	 * @param string $value_col Nombre de la columna a reemplazar.
	 * @return void
	 */
	private function process_table( string $table, string $id_col, string $value_col ): void {
		global $wpdb;

		// Verificar que la tabla existe.
		$table_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		if ( ! $table_exists ) {
			return;
		}

		$count  = 0;
		$offset = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `%1s`, `%1s` FROM `%1s` ORDER BY `%1s` LIMIT %d OFFSET %d",
					$id_col,
					$value_col,
					$table,
					$id_col,
					self::BATCH_SIZE,
					$offset
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$original = $row[ $value_col ];
				if ( null === $original || '' === $original ) {
					continue;
				}

				$replaced = $this->replace_recursive( $original );

				if ( $replaced !== $original ) {
					$this->exec_raw_update( $table, $value_col, $replaced, $id_col, $row[ $id_col ] );
					++$count;
				}
			}

			$offset += self::BATCH_SIZE;
		} while ( count( (array) $rows ) === self::BATCH_SIZE );

		if ( $count > 0 ) {
			$this->counts[ $table . '.' . $value_col ] = $count;
			SSC_Logger::info( 'url_rewriter', sprintf( '%s.%s: %d reemplazos.', $table, $value_col, $count ) );
		}
	}

	// ── Raw update helper ────────────────────────────────────────────────────

	/**
	 * Ejecuta un UPDATE directo para evitar que placeholder_escape() de WordPress 6.x
	 * corrompa valores con '%' (p.ej. permalink_structure = '/%year%/...').
	 *
	 * $wpdb->update() llama internamente a prepare() → add_placeholder_escape() → vsprintf,
	 * y el '{hash}' queda almacenado literalmente en MySQL en lugar de restaurarse a '%'.
	 *
	 * Solución: usar mysqli_query() cuando esté disponible, y $wpdb->query() con esc_sql()
	 * como fallback. $wpdb->query() pasa el SQL directo a MySQL sin placeholder_escape().
	 *
	 * @param string $table     Nombre de la tabla.
	 * @param string $value_col Columna a actualizar.
	 * @param string $new_value Nuevo valor.
	 * @param string $id_col    Columna PK.
	 * @param mixed  $id_value  Valor de la PK.
	 * @return void
	 */
	private function exec_raw_update( string $table, string $value_col, string $new_value, string $id_col, $id_value ): void {
		global $wpdb;
		$dbh     = $wpdb->dbh;
		$use_raw = $dbh instanceof \mysqli;

		if ( $use_raw ) {
			$esc_val = mysqli_real_escape_string( $dbh, $new_value );
			$esc_id  = mysqli_real_escape_string( $dbh, (string) $id_value );
			mysqli_query( $dbh, "UPDATE `{$table}` SET `{$value_col}` = '{$esc_val}' WHERE `{$id_col}` = '{$esc_id}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			// Fallback: esc_sql() + $wpdb->query() — bypasses prepare()/placeholder_escape().
			$esc_val = esc_sql( $new_value );
			$esc_id  = esc_sql( (string) $id_value );
			$wpdb->query( "UPDATE `{$table}` SET `{$value_col}` = '{$esc_val}' WHERE `{$id_col}` = '{$esc_id}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		}
	}

	// ── Reemplazo recursivo (serialize-safe) ──────────────────────────────────

	/**
	 * Reemplaza URLs en un valor de forma recursiva y serialize-safe.
	 *
	 * Si el valor es una cadena serializada PHP, la deserializa,
	 * procesa en profundidad y la vuelve a serializar.
	 *
	 * @param mixed $data Valor a procesar.
	 * @return mixed Valor con URLs reemplazadas.
	 */
	public function replace_recursive( $data ) {
		if ( is_string( $data ) ) {
			// Detectar si es una cadena serializada.
			if ( $this->is_serialized( $data ) ) {
				$unserialized = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions

				// Si se pudo deserializar, procesar y re-serializar.
				if ( false !== $unserialized || 'b:0;' === $data ) {
					// Si el resultado es un __PHP_Incomplete_Class (objeto cuya clase
					// no existe en este contexto), no podemos iterar sus propiedades
					// de forma segura. En ese caso re-serializar la cadena original
					// con reemplazo directo sobre el payload serializado.
					if ( is_object( $unserialized ) && $unserialized instanceof __PHP_Incomplete_Class ) {
						return $this->replace_serialized_string_safe( $data );
					}
					return serialize( $this->replace_recursive( $unserialized ) );
				}
			}

			// Cadena plana: aplicar todos los formatos de URL.
			return $this->replace_all_forms( $data );
		}

		if ( is_array( $data ) ) {
			$result = array();
			foreach ( $data as $k => $v ) {
				$result[ $k ] = $this->replace_recursive( $v );
			}
			return $result;
		}

		if ( is_object( $data ) ) {
			// Guardia extra: __PHP_Incomplete_Class u objetos con propiedades
			// privadas/protegidas (nombres con null-bytes) no son accesibles
			// con get_object_vars() y lanzan un Error fatal.
			if ( $data instanceof __PHP_Incomplete_Class ) {
				// No podemos procesar de forma segura; devolver sin cambios.
				return $data;
			}

			// Para objetos normales, usar get_object_vars() que solo devuelve
			// propiedades públicas visibles desde este contexto.
			$clone = clone $data;
			$vars  = @get_object_vars( $clone ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $vars ) ) {
				foreach ( $vars as $k => $v ) {
					// Saltamos claves con null-bytes (props privadas/protegidas serializadas).
					if ( strpos( $k, "\0" ) !== false ) {
						continue;
					}
					$clone->{$k} = $this->replace_recursive( $v );
				}
			}
			return $clone;
		}

		// int, float, bool, null — devolver sin tocar.
		return $data;
	}

	/**
	 * Reemplaza URLs dentro de una cadena serializada sin deserializarla.
	 *
	 * Se usa como fallback cuando el valor contiene objetos de clases
	 * desconocidas (__PHP_Incomplete_Class) que no podemos deserializar
	 * de forma segura. Actualiza los contadores de longitud `s:N:` tras
	 * cada sustitución para mantener la integridad de la serialización.
	 *
	 * @param string $serialized Cadena serializada original.
	 * @return string Cadena con URLs sustituidas y longitudes actualizadas.
	 */
	private function replace_serialized_string_safe( string $serialized ): string {
		$from_noscheme = preg_replace( '#^https?://#', '', $this->from_url );
		$to_noscheme   = preg_replace( '#^https?://#', '', $this->to_url );

		// Usar el esquema del sitio destino para ambas variantes de origen.
		preg_match( '#^(https?)://#', $this->to_url, $m );
		$to_scheme = $m[1] ?? 'https';

		$pairs = array(
			'https://' . $from_noscheme => $to_scheme . '://' . $to_noscheme,
			'http://'  . $from_noscheme => $to_scheme . '://' . $to_noscheme,
		);

		foreach ( $pairs as $search => $replace ) {
			if ( $search === $replace ) {
				continue;
			}
			// Reemplazar dentro de segmentos s:N:"..." actualizando N.
			$serialized = $this->regex_replace_in_serialized( $serialized, $search, $replace );
		}

		// Rutas de sistema de archivos.
		if ( $this->from_path && $this->to_path && $this->from_path !== $this->to_path ) {
			$serialized = $this->regex_replace_in_serialized( $serialized, $this->from_path, $this->to_path );
		}

		return $serialized;
	}

	/**
	 * Sustituye $search por $replace dentro de los segmentos `s:N:"..."` de una
	 * cadena serializada, recalculando N para que coincida con la longitud real.
	 *
	 * @param string $serialized Cadena serializada.
	 * @param string $search     Texto a buscar.
	 * @param string $replace    Texto de sustitución.
	 * @return string
	 */
	private function regex_replace_in_serialized( string $serialized, string $search, string $replace ): string {
		$len_diff = strlen( $replace ) - strlen( $search );
		if ( 0 === $len_diff && $search === $replace ) {
			return $serialized;
		}

		// Patrón: s:<longitud>:"<contenido>";
		// Capturamos el contenido entre comillas y sustituimos si contiene $search.
		return preg_replace_callback(
			'/s:(\d+):"(.*?)";/s',
			function ( $matches ) use ( $search, $replace ) {
				$content = $matches[2];
				if ( strpos( $content, $search ) === false ) {
					return $matches[0]; // Sin cambios.
				}
				$new_content = str_replace( $search, $replace, $content );
				return 's:' . strlen( $new_content ) . ':"' . $new_content . '";';
			},
			$serialized
		);
	}

	// ── Variantes de URL ──────────────────────────────────────────────────────

	/**
	 * Aplica el reemplazo en todas las formas conocidas de la URL:
	 * https://, http://, //, URL-encoded y con slashes escapados.
	 *
	 * El esquema de destino (http vs https) se toma siempre de $this->to_url,
	 * por lo que si el sitio destino usa http, tanto "https://origen" como
	 * "http://origen" se reescriben a "http://destino".
	 *
	 * @param string $value Cadena donde buscar.
	 * @return string
	 */
	private function replace_all_forms( string $value ): string {
		// Extraer host+path y esquema de origen y destino.
		$from_noscheme = preg_replace( '#^https?://#', '', $this->from_url );
		$to_noscheme   = preg_replace( '#^https?://#', '', $this->to_url );

		// Esquema canónico del destino: respeta http o https del sitio actual.
		preg_match( '#^(https?)://#', $this->to_url, $m );
		$to_scheme = $m[1] ?? 'https';

		$to_escaped    = str_replace( '/', '\/', $to_noscheme );
		$from_escaped  = str_replace( '/', '\/', $from_noscheme );

		$replacements = array(
			// Ambos esquemas de origen → esquema canónico del destino.
			'https://' . $from_noscheme  => $to_scheme . '://' . $to_noscheme,
			'http://'  . $from_noscheme  => $to_scheme . '://' . $to_noscheme,
			// Protocol-relative.
			'//' . $from_noscheme        => '//' . $to_noscheme,
			// JSON/JS con slashes escapados.
			'https:\/\/' . $from_escaped => $to_scheme . ':\/\/' . $to_escaped,
			'http:\/\/'  . $from_escaped => $to_scheme . ':\/\/' . $to_escaped,
			// URL-encoded.
			'https%3A%2F%2F' . rawurlencode( $from_noscheme ) => $to_scheme . '%3A%2F%2F' . rawurlencode( $to_noscheme ),
			'http%3A%2F%2F'  . rawurlencode( $from_noscheme ) => $to_scheme . '%3A%2F%2F' . rawurlencode( $to_noscheme ),
		);

		// Reemplazar de más específico a más genérico.
		foreach ( $replacements as $search => $replace ) {
			if ( $search !== $replace ) {
				$value = str_replace( $search, $replace, $value );
			}
		}

		// Rutas de sistema de archivos (opcional).
		if ( $this->from_path && $this->to_path && $this->from_path !== $this->to_path ) {
			$value = str_replace( $this->from_path, $this->to_path, $value );
		}

		return $value;
	}

	// ── Detección de serialización ────────────────────────────────────────────

	/**
	 * Detecta si una cadena es un valor serializado PHP.
	 *
	 * Basado en la implementación de WordPress (is_serialized) pero más
	 * estricto para evitar falsos positivos costosos.
	 *
	 * @param string $data Cadena a comprobar.
	 * @return bool
	 */
	private function is_serialized( string $data ): bool {
		// Debe empezar por uno de los tipos serializados de PHP.
		if ( ! preg_match( '/^(s:|i:|d:|b:|a:|O:|C:|N;)/', $data ) ) {
			return false;
		}

		// Delegar a is_serialized de WordPress si está disponible.
		if ( function_exists( 'is_serialized' ) ) {
			return is_serialized( $data );
		}

		// Fallback: intentar un unserialize rápido.
		$test = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		return false !== $test || 'b:0;' === $data;
	}

	// ── Tablas objetivo ───────────────────────────────────────────────────────

	/**
	 * Devuelve la lista de tablas/columnas a procesar por defecto.
	 *
	 * @return array Array de arrays con keys 'table', 'id_col', 'value_col'.
	 */
	private function get_default_targets(): array {
		global $wpdb;

		$targets = array(
			array( 'table' => $wpdb->options,      'id_col' => 'option_id',    'value_col' => 'option_value' ),
			array( 'table' => $wpdb->posts,         'id_col' => 'ID',           'value_col' => 'post_content' ),
			array( 'table' => $wpdb->posts,         'id_col' => 'ID',           'value_col' => 'post_excerpt' ),
			array( 'table' => $wpdb->postmeta,      'id_col' => 'meta_id',      'value_col' => 'meta_value' ),
			array( 'table' => $wpdb->usermeta,      'id_col' => 'umeta_id',     'value_col' => 'meta_value' ),
			array( 'table' => $wpdb->termmeta,      'id_col' => 'meta_id',      'value_col' => 'meta_value' ),
			array( 'table' => $wpdb->comments,      'id_col' => 'comment_ID',   'value_col' => 'comment_content' ),
			array( 'table' => $wpdb->commentmeta,   'id_col' => 'meta_id',      'value_col' => 'meta_value' ),
		);

		// Multisite.
		if ( is_multisite() ) {
			$targets[] = array( 'table' => $wpdb->sitemeta, 'id_col' => 'meta_id',  'value_col' => 'meta_value' );
			$targets[] = array( 'table' => $wpdb->blogs,    'id_col' => 'blog_id',  'value_col' => 'domain' );
		}

		/**
		 * Permite añadir tablas/columnas adicionales (p.ej. tablas de WooCommerce).
		 *
		 * @param array $targets Array de targets ['table','id_col','value_col'].
		 */
		return apply_filters( 'ssc_url_rewriter_targets', $targets );
	}

	// ── Getters ───────────────────────────────────────────────────────────────

	/**
	 * Devuelve los contadores de reemplazos.
	 *
	 * @return array
	 */
	public function get_counts(): array {
		return $this->counts;
	}
}
