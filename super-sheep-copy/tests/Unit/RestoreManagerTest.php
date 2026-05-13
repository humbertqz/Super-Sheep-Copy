<?php

/**
 * Tests para SSC_Restore_Manager — saneamiento post-restore.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );


namespace SSC\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @covers \SSC_Restore_Manager
 */
class RestoreManagerTest extends TestCase {

	private string $work_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->work_dir = $this->tmp_dir( '_restore_manager' );
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->work_dir );
		parent::tearDown();
	}

	/**
	 * Invoca strip_https_redirect_rules_from_htaccess() via Reflection.
	 */
	private function strip_htaccess( string $content ): string {
		$manager = new \SSC_Restore_Manager();
		$ref     = new \ReflectionMethod( $manager, 'strip_https_redirect_rules_from_htaccess' );
		return $ref->invoke( $manager, $content );
	}

	/** @test */
	public function it_strips_really_simple_ssl_htaccess_block_for_http_destinations(): void {
		$input = "# BEGIN WordPress\n# END WordPress\n"
			. "# BEGIN Really Simple SSL\n"
			. "RewriteEngine On\n"
			. "RewriteCond %{HTTPS} !=on\n"
			. "RewriteRule ^(.*)$ https://example.com/$1 [R=301,L]\n"
			. "# END Really Simple SSL\n"
			. "# custom keep\n";

		$result = $this->strip_htaccess( $input );

		$this->assertStringContainsString( '# BEGIN WordPress', $result );
		$this->assertStringContainsString( '# custom keep', $result );
		$this->assertStringNotContainsString( 'Really Simple SSL', $result );
		$this->assertStringNotContainsString( 'https://example.com', $result );
	}

	/** @test */
	public function it_strips_generic_https_rewrite_rule(): void {
		$input = "RewriteEngine On\n"
			. "RewriteCond %{HTTPS} off\n"
			. "RewriteRule ^ https://example.com%{REQUEST_URI} [R=301,L]\n"
			. "RewriteRule . /wp-test/index.php [L]\n";

		$result = $this->strip_htaccess( $input );

		$this->assertStringNotContainsString( 'RewriteCond %{HTTPS} off', $result );
		$this->assertStringNotContainsString( 'https://example.com', $result );
		$this->assertStringContainsString( 'RewriteRule . /wp-test/index.php [L]', $result );
	}

	/** @test */
	public function it_strips_redirect_to_https_directive(): void {
		$input = "Redirect 301 / https://example.com/\n# keep\n";
		$result = $this->strip_htaccess( $input );

		$this->assertStringNotContainsString( 'Redirect 301', $result );
		$this->assertStringContainsString( '# keep', $result );
	}

	/** @test */
	public function it_rewrites_wp_config_password_as_valid_php_literal(): void {
		$config_path = ABSPATH . 'wp-config.php';
		$original    = file_exists( $config_path ) ? file_get_contents( $config_path ) : null;

		file_put_contents(
			$config_path,
			"<?php\n"
			. "define( 'DB_NAME', 'old_db' );\n"
			. "define( 'DB_USER', 'old_user' );\n"
			. "define( 'DB_PASSWORD', \"old\" );\n"
			. "define( 'DB_HOST', 'old_host' );\n"
			. "\$table_prefix = 'wp_';\n"
		);

		$password = 'root"L~2mX^B';
		$manager  = new \SSC_Restore_Manager();
		$ref      = new \ReflectionMethod( $manager, 'rewrite_wp_config_constants' );

		try {
			$ref->invoke(
				$manager,
				'http://localhost:8888/wp-test',
				array(
					'host'     => 'localhost',
					'name'     => 'local_db',
					'user'     => 'root',
					'password' => $password,
					'charset'  => 'utf8mb4',
					'collate'  => '',
					'prefix'   => 'wp_',
				)
			);

			$rewritten = file_get_contents( $config_path );
			$this->assertStringContainsString( "define( 'DB_PASSWORD', 'root\"L~2mX^B' );", $rewritten );
			$this->assertStringNotContainsString( '"root"L~2mX^B"', $rewritten );
			$this->assertMatchesRegularExpression( '/define\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"]root"L~2mX\^B[\'"]\s*\)\s*;/', $rewritten );
		} finally {
			if ( null === $original ) {
				@unlink( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			} else {
				file_put_contents( $config_path, $original );
			}
		}
	}

	/** @test */
	public function it_rewrites_wp_config_plain_root_password(): void {
		$config_path = ABSPATH . 'wp-config.php';
		$original    = file_exists( $config_path ) ? file_get_contents( $config_path ) : null;

		file_put_contents(
			$config_path,
			"<?php\n"
			. "define( 'DB_NAME', 'old_db' );\n"
			. "define( 'DB_USER', 'old_user' );\n"
			. "define( 'DB_PASSWORD', 'production-password' );\n"
			. "define( 'DB_HOST', 'old_host' );\n"
			. "\$table_prefix = 'wp_';\n"
		);

		$manager = new \SSC_Restore_Manager();
		$ref     = new \ReflectionMethod( $manager, 'rewrite_wp_config_constants' );

		try {
			$ref->invoke(
				$manager,
				'http://localhost:8888/wp-test',
				array(
					'host'     => 'localhost',
					'name'     => 'local_db',
					'user'     => 'root',
					'password' => 'root',
					'charset'  => 'utf8mb4',
					'collate'  => '',
					'prefix'   => 'wp_',
				)
			);

			$rewritten = file_get_contents( $config_path );
			$this->assertStringContainsString( "define( 'DB_PASSWORD', 'root' );", $rewritten );
			$this->assertStringNotContainsString( 'production-password', $rewritten );
		} finally {
			if ( null === $original ) {
				@unlink( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			} else {
				file_put_contents( $config_path, $original );
			}
		}
	}

	/** @test */
	public function it_validates_restored_wordpress_options(): void {
		$original_wpdb   = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->make_options_wpdb(
			array(
				'siteurl'        => 'http://localhost:8888/wp-test',
				'home'           => 'http://localhost:8888/wp-test',
				'template'       => 'backup-theme',
				'stylesheet'     => 'backup-theme',
				'active_plugins' => serialize( array( 'plugin/plugin.php' ) ),
			)
		);

		try {
			$manager = new \SSC_Restore_Manager();
			$ref     = new \ReflectionMethod( $manager, 'validate_restored_wordpress_options' );
			$this->assertTrue( $ref->invoke( $manager ) );
		} finally {
			$GLOBALS['wpdb'] = $original_wpdb;
		}
	}

	/** @test */
	public function it_fails_validation_when_theme_options_are_missing(): void {
		$original_wpdb   = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->make_options_wpdb(
			array(
				'siteurl'        => 'http://localhost:8888/wp-test',
				'home'           => 'http://localhost:8888/wp-test',
				'active_plugins' => serialize( array() ),
			)
		);

		try {
			$manager = new \SSC_Restore_Manager();
			$ref     = new \ReflectionMethod( $manager, 'validate_restored_wordpress_options' );
			$result  = $ref->invoke( $manager );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'restored_options_missing', $result->get_error_code() );
		} finally {
			$GLOBALS['wpdb'] = $original_wpdb;
		}
	}

	private function make_options_wpdb( array $options ): object {
		return new class( $options ) {
			public string $prefix = 'wp_';
			public string $options = 'wp_options';
			public string $users = 'wp_users';
			public ?object $dbh = null;
			public string $last_error = '';
			private array $values;

			public function __construct( array $options ) {
				$this->values = $options;
			}

			public function prepare( string $query, ...$args ): string {
				return $query . ' /* ' . ( $args[0] ?? '' ) . ' */';
			}

			public function get_var( string $query ) {
				if ( false !== strpos( $query, 'COUNT(*) FROM wp_users' ) ) {
					return $this->values['__user_count'] ?? 0;
				}
				foreach ( $this->values as $name => $value ) {
					if ( false !== strpos( $query, '/* ' . $name . ' */' ) ) {
						return $value;
					}
				}
				return null;
			}

			public function insert( ...$args ): int {
				return 1;
			}

			public function check_connection( bool $allow_bail = true ): bool {
				return true;
			}
		};
	}

	/** @test */
	public function safety_snapshot_uses_chunked_backup_lifecycle(): void {
		$backup = new class() extends \SSC_Backup_Manager {
			public bool $run_to_completion_called = false;
			public bool $start_called = false;
			public array $args = [];

			public function run_to_completion( string $label = '', string $type = 'manual', string $job_id = '' ) {
				$this->run_to_completion_called = true;
				$this->args = array( $label, $type, $job_id );
				return 'snapshot_job';
			}

			public function start( string $label = '', string $type = 'manual', string $job_id = '' ) {
				$this->start_called = true;
				return new \WP_Error( 'unexpected_start', 'start() should not be used for safety snapshots.' );
			}
		};

		$manager = new class( $backup ) extends \SSC_Restore_Manager {
			private \SSC_Backup_Manager $backup;

			public function __construct( \SSC_Backup_Manager $backup ) {
				$this->backup = $backup;
			}

			protected function create_backup_manager(): \SSC_Backup_Manager {
				return $this->backup;
			}

			public function expose_create_safety_snapshot() {
				$ref = new \ReflectionMethod( \SSC_Restore_Manager::class, 'create_safety_snapshot' );
				return $ref->invoke( $this );
			}
		};

		$result = $manager->expose_create_safety_snapshot();

		$this->assertSame( 'snapshot_job', $result );
		$this->assertTrue( $backup->run_to_completion_called );
		$this->assertFalse( $backup->start_called );
		$this->assertStringStartsWith( 'pre-restore-', $backup->args[0] );
		$this->assertSame( 'pre-restore', $backup->args[1] );
	}

	/** @test */
	public function it_fails_validation_when_manifest_theme_does_not_match_restored_db(): void {
		$original_wpdb   = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->make_options_wpdb(
			array(
				'siteurl'        => 'http://localhost:8888/wp-test',
				'home'           => 'http://localhost:8888/wp-test',
				'template'       => 'local-theme',
				'stylesheet'     => 'local-theme',
				'active_plugins' => serialize( array( 'plugin/plugin.php' ) ),
				'__user_count'   => 2,
			)
		);

		try {
			$manager = new \SSC_Restore_Manager();
			$ref     = new \ReflectionMethod( $manager, 'validate_restored_wordpress_options' );
			$result  = $ref->invoke(
				$manager,
				array(
					'db_snapshot' => array(
						'template'       => 'backup-theme',
						'stylesheet'     => 'backup-theme',
						'active_plugins' => array( 'plugin/plugin.php' ),
						'user_count'     => 2,
					),
				)
			);
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'restored_theme_mismatch', $result->get_error_code() );
		} finally {
			$GLOBALS['wpdb'] = $original_wpdb;
		}
	}

	/** @test */
	public function it_fails_validation_when_manifest_user_count_does_not_match_restored_db(): void {
		$original_wpdb   = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->make_options_wpdb(
			array(
				'siteurl'        => 'http://localhost:8888/wp-test',
				'home'           => 'http://localhost:8888/wp-test',
				'template'       => 'backup-theme',
				'stylesheet'     => 'backup-theme',
				'active_plugins' => serialize( array( 'plugin/plugin.php' ) ),
				'__user_count'   => 1,
			)
		);

		try {
			$manager = new \SSC_Restore_Manager();
			$ref     = new \ReflectionMethod( $manager, 'validate_restored_wordpress_options' );
			$result  = $ref->invoke(
				$manager,
				array(
					'db_snapshot' => array(
						'template'       => 'backup-theme',
						'stylesheet'     => 'backup-theme',
						'active_plugins' => array( 'plugin/plugin.php' ),
						'user_count'     => 2,
					),
				)
			);
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'restored_users_mismatch', $result->get_error_code() );
		} finally {
			$GLOBALS['wpdb'] = $original_wpdb;
		}
	}
}
