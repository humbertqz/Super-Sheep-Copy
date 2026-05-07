<?php
/**
 * Stubs mínimos de clases de WordPress necesarios para los tests unitarios.
 * Se cargan antes que las clases del plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes de formato de resultado de $wpdb (WordPress las define globalmente).
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) ) { define( 'ARRAY_N', 'ARRAY_N' ); }
if ( ! defined( 'OBJECT'   ) ) { define( 'OBJECT',   'OBJECT'   ); }

/**
 * Stub de $wpdb para evitar "Call to a member function on null" en SSC_Logger.
 * Se asigna al global antes de cargar las clases del plugin.
 */
if ( ! isset( $GLOBALS['wpdb'] ) ) {
    $GLOBALS['wpdb'] = new class() {
        public string $prefix = 'wp_';
        public ?\mysqli $dbh  = null;
        public string $options    = 'wp_options';
        public string $posts      = 'wp_posts';
        public string $postmeta   = 'wp_postmeta';
        public string $usermeta   = 'wp_usermeta';
        public string $termmeta   = 'wp_termmeta';
        public string $comments   = 'wp_comments';
        public string $commentmeta = 'wp_commentmeta';
        public string $sitemeta   = 'wp_sitemeta';
        public string $blogs      = 'wp_blogs';
        public string $last_error = '';

        public function insert( ...$args ): int  { return 1; }
        public function update( ...$args ): int  { return 1; }
        public function get_var( ...$args )       { return null; }
        public function get_col( ...$args ): array { return []; }
        public function get_row( ...$args )       { return null; }
        public function get_results( ...$args ): array { return []; }
        public function query( ...$args )         { return true; }
        public function prepare( string $q, ...$args ): string { return $q; }
        public function show_errors( bool $v = true ): void {}
        public function check_connection( bool $allow_bail = true ): bool { return true; }
        public function _real_escape( string $s ): string { return addslashes( $s ); }
        public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
    };
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;

        public function __construct( string $code = '', string $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string    { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
}

if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
    class WP_Filesystem_Base {
        public function delete( string $path, bool $recursive = false ): bool {
            if ( $recursive && is_dir( $path ) ) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ( $it as $item ) {
                    $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink
                }
                return @rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            }
            return @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        }
        public function put_contents( string $path, string $content, int $mode = 0 ): bool {
            return false !== file_put_contents( $path, $content );
        }
        public function get_contents( string $path ) {
            return file_get_contents( $path );
        }
    }
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
    function WP_Filesystem(): bool {
        global $wp_filesystem;
        if ( ! isset( $wp_filesystem ) ) {
            $wp_filesystem = new \WP_Filesystem_Base();
        }
        return true;
    }
}

if ( ! class_exists( 'WP_Session_Tokens' ) ) {
    class WP_Session_Tokens {
        public static function get_instance( int $user_id ): static {
            return new static();
        }
        public function destroy_all(): void {}
    }
}

if ( ! class_exists( 'SSC_Self_Updater' ) ) {
    class SSC_Self_Updater {
        public static function get_plugin_zip_prefix(): string {
            $rel = str_replace( '\\', '/', str_replace( ABSPATH, '', SSC_PLUGIN_DIR ) );
            return ltrim( $rel, '/' );
        }
    }
}

if ( ! function_exists( 'is_serialized' ) ) {
    /**
     * Verifica si un valor es una cadena serializada de PHP.
     * Implementación simplificada equivalente a la de WordPress.
     */
    function is_serialized( $data, bool $strict = true ): bool {
        if ( ! is_string( $data ) ) {
            return false;
        }
        $data = trim( $data );
        if ( 'N;' === $data ) {
            return true;
        }
        if ( strlen( $data ) < 4 ) {
            return false;
        }
        if ( ':' !== $data[1] ) {
            return false;
        }
        if ( $strict ) {
            $lastc = substr( $data, -1 );
            if ( ';' !== $lastc && '}' !== $lastc ) {
                return false;
            }
        } else {
            $semicolon = strpos( $data, ';' );
            $brace     = strpos( $data, '}' );
            if ( false === $semicolon && false === $brace ) {
                return false;
            }
            if ( false !== $semicolon && $semicolon < 3 ) {
                return false;
            }
            if ( false !== $brace && $brace < 4 ) {
                return false;
            }
        }
        $token = $data[0];
        switch ( $token ) {
            case 's':
                if ( $strict ) {
                    if ( '"' !== substr( $data, -2, 1 ) ) {
                        return false;
                    }
                } elseif ( ! str_contains( $data, '"' ) ) {
                    return false;
                }
            // Fall-through is intentional.
            case 'a':
            case 'O':
            case 'E':
                return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
            case 'b':
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';
                return (bool) preg_match( "/^{$token}:[0-9.E+-]+;{$end}/", $data );
        }
        return false;
    }
}
