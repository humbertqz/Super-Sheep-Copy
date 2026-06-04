<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\ManifestBuilder;

final class ManifestBuilderTest extends TestCase
{
    public function testBuildsManifestArray(): void
    {
        $builder = new ManifestBuilder('0.1.0', '1');

        $manifest = $builder->build(array(
            'source_site_url' => 'https://website.com',
            'source_home_url' => 'https://website.com',
            'wordpress_version' => '6.5.0',
            'php_version' => '8.2.0',
            'database_version' => '8.0',
            'table_prefix' => 'wp_',
            'is_multisite' => false,
            'active_theme' => 'twentytwentyfour',
            'active_plugins' => array('akismet/akismet.php'),
            'must_use_plugins' => array(),
            'created_at' => '2026-05-15T12:00:00+00:00',
            'file_count' => 12,
            'database_table_count' => 9,
            'archive_size' => 1024,
            'checksums' => array('manifest.json' => 'abc123'),
            'exclusions' => array('.git', 'node_modules'),
            'environment' => array('zip' => true),
        ));

        self::assertSame('Super Sheep Copy', $manifest->toArray()['project']);
        self::assertSame('0.1.0', $manifest->toArray()['plugin_version']);
        self::assertSame('1', $manifest->toArray()['backup_format_version']);
        self::assertSame('https://website.com', $manifest->toArray()['source_site_url']);
        self::assertFalse($manifest->toArray()['is_multisite']);
        self::assertSame(12, $manifest->toArray()['file_count']);
    }
}
