<?php
/**
 * Tests para SSC_URL_Rewriter.
 *
 * @package Full_Site_Backup\Tests
 */

declare( strict_types=1 );

namespace SSC\Tests\Unit;

/**
 * @covers \SSC_URL_Rewriter
 */
class UrlRewriterTest extends TestCase {

    private function rewriter(
        string $from = 'https://old.com',
        string $to   = 'https://new.com'
    ): \SSC_URL_Rewriter {
        return new \SSC_URL_Rewriter( $from, $to );
    }

    // ── Cadenas planas ────────────────────────────────────────────────────────

    /** @test */
    public function it_replaces_plain_url_in_string(): void {
        $r = $this->rewriter( 'https://old.com', 'https://new.com' );
        $this->assertSame(
            'Visit https://new.com/page',
            $r->replace_recursive( 'Visit https://old.com/page' )
        );
    }

    /** @test */
    public function it_replaces_http_variant_in_string(): void {
        $r = $this->rewriter( 'https://old.com', 'https://new.com' );
        $this->assertSame(
            'Visit https://new.com/page',
            $r->replace_recursive( 'Visit http://old.com/page' )
        );
    }

    /** @test */
    public function it_replaces_protocol_relative_url(): void {
        $r = $this->rewriter( 'https://old.com', 'https://new.com' );
        $this->assertSame(
            'src="//new.com/img.jpg"',
            $r->replace_recursive( 'src="//old.com/img.jpg"' )
        );
    }

    /** @test */
    public function it_replaces_json_escaped_url(): void {
        $r = $this->rewriter( 'https://old.com', 'https://new.com' );
        $this->assertSame(
            'https:\/\/new.com\/page',
            $r->replace_recursive( 'https:\/\/old.com\/page' )
        );
    }

    // ── Respeto del esquema del destino ───────────────────────────────────────

    /** @test */
    public function destination_http_site_rewrites_https_source_to_http(): void {
        $r = $this->rewriter( 'https://old.com', 'http://new.com' );
        $this->assertSame(
            'http://new.com/page',
            $r->replace_recursive( 'https://old.com/page' )
        );
    }

    /** @test */
    public function destination_http_site_rewrites_http_source_to_http(): void {
        $r = $this->rewriter( 'https://old.com', 'http://new.com' );
        $this->assertSame(
            'http://new.com/page',
            $r->replace_recursive( 'http://old.com/page' )
        );
    }

    /** @test */
    public function destination_https_site_rewrites_http_source_to_https(): void {
        $r = $this->rewriter( 'http://old.com', 'https://new.com' );
        $this->assertSame(
            'https://new.com/page',
            $r->replace_recursive( 'http://old.com/page' )
        );
    }

    // ── Serialización simple ──────────────────────────────────────────────────

    /** @test */
    public function it_replaces_url_in_simple_serialized_string(): void {
        $input    = serialize( 'https://old.com/path' );
        $expected = serialize( 'https://new.com/path' );
        $r        = $this->rewriter();

        $this->assertSame( $expected, $r->replace_recursive( $input ) );
    }

    /** @test */
    public function it_handles_serialized_boolean_false(): void {
        $r = $this->rewriter();
        // b:0; no debe modificarse.
        $this->assertSame( 'b:0;', $r->replace_recursive( 'b:0;' ) );
    }

    /** @test */
    public function it_returns_integers_untouched(): void {
        $r = $this->rewriter();
        $this->assertSame( 42, $r->replace_recursive( 42 ) );
    }

    /** @test */
    public function it_returns_null_untouched(): void {
        $r = $this->rewriter();
        $this->assertNull( $r->replace_recursive( null ) );
    }

    // ── Arrays serializados ───────────────────────────────────────────────────

    /** @test */
    public function it_replaces_url_in_serialized_flat_array(): void {
        $data     = [ 'url' => 'https://old.com', 'title' => 'My Site' ];
        $input    = serialize( $data );
        $r        = $this->rewriter();
        $result   = unserialize( $r->replace_recursive( $input ) );

        $this->assertSame( 'https://new.com', $result['url'] );
        $this->assertSame( 'My Site', $result['title'] );
    }

    /** @test */
    public function it_replaces_url_in_nested_serialized_array(): void {
        $data  = [
            'level1' => [
                'level2' => [ 'url' => 'https://old.com/deep' ],
            ],
        ];
        $input  = serialize( $data );
        $r      = $this->rewriter();
        $result = unserialize( $r->replace_recursive( $input ) );

        $this->assertSame( 'https://new.com/deep', $result['level1']['level2']['url'] );
    }

    /** @test */
    public function it_replaces_url_in_array_with_mixed_types(): void {
        $data  = [
            'enabled' => true,
            'count'   => 5,
            'url'     => 'https://old.com',
            'empty'   => null,
        ];
        $input  = serialize( $data );
        $r      = $this->rewriter();
        $result = unserialize( $r->replace_recursive( $input ) );

        $this->assertTrue( $result['enabled'] );
        $this->assertSame( 5, $result['count'] );
        $this->assertSame( 'https://new.com', $result['url'] );
        $this->assertNull( $result['empty'] );
    }

    /** @test */
    public function serialized_string_lengths_are_correct_after_replacement(): void {
        // Si la URL de destino es más larga, s:N debe actualizarse correctamente.
        $r      = $this->rewriter( 'https://old.com', 'https://much-longer-new-domain.com' );
        $input  = serialize( 'https://old.com/path' );
        $result = $r->replace_recursive( $input );

        // Debe poder deserializarse sin error.
        $unserialized = unserialize( $result );
        $this->assertSame( 'https://much-longer-new-domain.com/path', $unserialized );
    }

    // ── Objetos serializados ──────────────────────────────────────────────────

    /** @test */
    public function it_replaces_url_in_serialized_stdclass_object(): void {
        $obj      = new \stdClass();
        $obj->url = 'https://old.com';
        $obj->name = 'test';
        $input    = serialize( $obj );
        $r        = $this->rewriter();
        $result   = unserialize( $r->replace_recursive( $input ) );

        $this->assertSame( 'https://new.com', $result->url );
        $this->assertSame( 'test', $result->name );
    }

    /** @test */
    public function it_handles_incomplete_class_without_crash(): void {
        // Simula un objeto de clase desconocida (como los que produce WP al
        // deserializar con allowed_classes:false).
        $serialized = 'a:1:{i:0;O:15:"UnknownFooClass":1:{s:3:"url";s:20:"https://old.com/foo";}}';
        $r          = $this->rewriter();

        // No debe lanzar ninguna excepción / error.
        $result = $r->replace_recursive( $serialized );
        $this->assertIsString( $result );
        $this->assertStringContainsString( 'new.com', $result );
    }

    // ── Sin cambios ───────────────────────────────────────────────────────────

    /** @test */
    public function it_does_nothing_when_from_equals_to(): void {
        $r     = $this->rewriter( 'https://same.com', 'https://same.com' );
        $input = 'https://same.com/page';
        $this->assertSame( $input, $r->replace_recursive( $input ) );
    }

    /** @test */
    public function it_does_not_touch_unrelated_urls(): void {
        $r     = $this->rewriter( 'https://old.com', 'https://new.com' );
        $input = 'https://other-site.com/page';
        $this->assertSame( $input, $r->replace_recursive( $input ) );
    }

    // ── Filesystem path replacement ───────────────────────────────────────────

    /** @test */
    public function it_replaces_filesystem_path_when_provided(): void {
        $r = new \SSC_URL_Rewriter(
            'https://old.com',
            'https://new.com',
            '/var/www/old',
            '/var/www/new'
        );
        $this->assertSame(
            '/var/www/new/wp-content/uploads/file.jpg',
            $r->replace_recursive( '/var/www/old/wp-content/uploads/file.jpg' )
        );
    }
}
