<?php

/**
 * Tests para SSC_Manifest.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );


namespace SSC\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @covers \SSC_Manifest
 */
class ManifestTest extends TestCase {

    /** @test */
    public function it_creates_manifest_with_required_fields(): void {
        $m    = new \SSC_Manifest();
        $data = $m->to_array();

        $this->assertArrayHasKey( 'plugin_version', $data );
        $this->assertArrayHasKey( 'site_url', $data );
        $this->assertArrayHasKey( 'table_prefix', $data );
        $this->assertArrayHasKey( 'created_at', $data );
        $this->assertSame( SSC_VERSION, $data['plugin_version'] );
    }

    /** @test */
    public function it_serializes_to_valid_json(): void {
        $m    = new \SSC_Manifest();
        $json = $m->to_json();

        $this->assertIsString( $json );
        $decoded = json_decode( $json, true );
        $this->assertIsArray( $decoded );
        $this->assertSame( 0, json_last_error() );
    }

    /** @test */
    public function setters_update_manifest_data(): void {
        $m = new \SSC_Manifest();
        $m->set_file_count( 1234 );
        $m->set_db_size( 5678 );
        $m->set_checksum( 'abc123' );
        $m->set_include_core( false );

        $data = $m->to_array();
        $this->assertSame( 1234, $data['file_count'] );
        $this->assertSame( 5678, $data['db_size_bytes'] );
        $this->assertSame( 'abc123', $data['checksum_sha256'] );
        $this->assertFalse( $data['includes']['core'] );
    }

    /** @test */
    public function parse_returns_array_for_valid_json(): void {
        $json   = json_encode( [
            'plugin_version' => '1.0.0',
            'site_url'       => 'https://example.com',
            'table_prefix'   => 'wp_',
        ] );
        $result = \SSC_Manifest::parse( $json );

        $this->assertIsArray( $result );
        $this->assertSame( 'https://example.com', $result['site_url'] );
    }

    /** @test */
    public function parse_returns_error_for_invalid_json(): void {
        $result = \SSC_Manifest::parse( 'not json at all {{{' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** @test */
    public function parse_returns_error_when_required_field_missing(): void {
        $json   = json_encode( [ 'plugin_version' => '1.0.0' ] ); // falta site_url y table_prefix
        $result = \SSC_Manifest::parse( $json );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /** @test */
    public function parse_rejects_manifest_from_newer_plugin_version(): void {
        $json   = json_encode( [
            'plugin_version' => '999.0.0',
            'site_url'       => 'https://example.com',
            'table_prefix'   => 'wp_',
        ] );
        $result = \SSC_Manifest::parse( $json );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'manifest_version_too_new', $result->get_error_code() );
    }

    /** @test */
    public function parse_accepts_manifest_from_same_version(): void {
        $json   = json_encode( [
            'plugin_version' => SSC_VERSION,
            'site_url'       => 'https://example.com',
            'table_prefix'   => 'wp_',
        ] );
        $result = \SSC_Manifest::parse( $json );
        $this->assertIsArray( $result );
    }

    /** @test */
    public function parse_accepts_manifest_from_older_version(): void {
        $json   = json_encode( [
            'plugin_version' => '0.1.0',
            'site_url'       => 'https://example.com',
            'table_prefix'   => 'wp_',
        ] );
        $result = \SSC_Manifest::parse( $json );
        $this->assertIsArray( $result );
    }
}
