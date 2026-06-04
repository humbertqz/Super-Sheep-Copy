<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupMetadataCollector;
use SuperSheepCopy\Support\EnvironmentCheckerInterface;

final class BackupMetadataCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new MetadataWpdbStub();
        $GLOBALS['ssc_test_options'] = array('active_plugins' => array('akismet/akismet.php'));
        $GLOBALS['ssc_test_site_url'] = 'https://source.example';
        $GLOBALS['ssc_test_home_url'] = 'https://home.example';
        $GLOBALS['ssc_test_bloginfo_version'] = '6.5.4';
        $GLOBALS['ssc_test_is_multisite'] = true;
        $GLOBALS['ssc_test_stylesheet'] = 'custom-theme';
        $GLOBALS['ssc_test_mu_plugins'] = array('mu-loader.php' => array('Name' => 'MU Loader'));
    }

    public function testCollectsWordPressBackupMetadata(): void
    {
        $collector = new BackupMetadataCollector(new MetadataEnvironmentChecker());

        $metadata = $collector->collect();

        self::assertSame('https://source.example', $metadata['source_site_url']);
        self::assertSame('https://home.example', $metadata['source_home_url']);
        self::assertSame('6.5.4', $metadata['wordpress_version']);
        self::assertSame(PHP_VERSION, $metadata['php_version']);
        self::assertSame('8.0.36', $metadata['database_version']);
        self::assertSame('wp_', $metadata['table_prefix']);
        self::assertTrue($metadata['is_multisite']);
        self::assertSame('custom-theme', $metadata['active_theme']);
        self::assertSame(array('akismet/akismet.php'), $metadata['active_plugins']);
        self::assertSame(array('mu-loader.php'), $metadata['must_use_plugins']);
        self::assertSame(0, $metadata['file_count']);
        self::assertSame(0, $metadata['database_table_count']);
        self::assertSame(0, $metadata['archive_size']);
        self::assertSame(array(), $metadata['checksums']);
        self::assertSame(array('wp-content/cache', 'wp-content/uploads/super-sheep-copy'), $metadata['exclusions']);
        self::assertSame(array('zip' => array('status' => 'ok')), $metadata['environment']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $metadata['created_at']);
    }
}

final class MetadataWpdbStub
{
    public string $prefix = 'wp_';

    public function db_version(): string
    {
        return '8.0.36';
    }
}

final class MetadataEnvironmentChecker implements EnvironmentCheckerInterface
{
    public function check(): array
    {
        return array('zip' => array('status' => 'ok'));
    }
}
