<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/RollbackManifestBuilder.php';

final class RollbackManifestBuilderTest extends TestCase
{
    public function testBuildsManifestWithExpectedMetadata(): void
    {
        $manifest = (new \SuperSheepCopyInstaller\RollbackManifestBuilder())->build(
            array(
                'restore_job_id' => 'restore-123',
                'source_site_url' => 'https://source.example',
                'source_home_url' => 'https://source.example/home',
                'staged_archive_basename' => 'restore-123.zip',
                'token_hash' => 'secret-hash',
                'db_password' => 'secret-password',
            ),
            'https://destination.example',
            '/var/www/html',
            array(array('relative_path' => 'wp-config.php', 'rollback_path' => 'files/wp-config.php', 'sha256' => 'abc', 'size' => 123)),
            array('sample warning')
        );

        self::assertSame('Super Sheep Copy', $manifest['project']);
        self::assertSame('rollback', $manifest['type']);
        self::assertSame('https://destination.example', $manifest['destination_url']);
        self::assertSame('/var/www/html', $manifest['wordpress_root']);
        self::assertSame('restore-123', $manifest['restore_job_id']);
        self::assertSame('restore-123.zip', $manifest['staged_archive_basename']);
        self::assertSame('wp-config.php', $manifest['files'][0]['relative_path']);
        self::assertSame(array('sample warning'), $manifest['warnings']);
        self::assertFalse($manifest['database']['included']);
        self::assertSame('', $manifest['database']['dump_path']);
        self::assertSame(0, $manifest['database']['table_count']);
        self::assertSame(array(), $manifest['database']['warnings']);
    }

    public function testExcludesSecrets(): void
    {
        $manifest = (new \SuperSheepCopyInstaller\RollbackManifestBuilder())->build(
            array('token_hash' => 'secret-hash', 'db_password' => 'secret-password', 'staged_archive_path' => '/private/backup.zip'),
            '',
            '/var/www/html',
            array(),
            array()
        );

        $json = json_encode($manifest) ?: '';

        self::assertStringNotContainsString('secret-hash', $json);
        self::assertStringNotContainsString('secret-password', $json);
        self::assertStringNotContainsString('/private/backup.zip', $json);
    }

    public function testIncludesDatabaseRollbackMetadata(): void
    {
        $manifest = (new \SuperSheepCopyInstaller\RollbackManifestBuilder())->build(
            array('restore_job_id' => 'restore-123'),
            'https://destination.example',
            '/var/www/html',
            array(),
            array(),
            array('included' => true, 'dump_path' => 'database/destination.sql', 'table_count' => 2, 'warnings' => array())
        );

        self::assertTrue($manifest['database']['included']);
        self::assertSame('database/destination.sql', $manifest['database']['dump_path']);
        self::assertSame(2, $manifest['database']['table_count']);
    }
}
