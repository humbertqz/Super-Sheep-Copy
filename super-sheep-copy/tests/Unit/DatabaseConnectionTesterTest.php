<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseConnectionTester.php';

final class DatabaseConnectionTesterTest extends TestCase
{
    public function testReportsIncompleteCredentialsWithoutSecrets(): void
    {
        $result = (new \SuperSheepCopyInstaller\DatabaseConnectionTester())->test(array(
            'complete' => false,
            'name' => '',
            'user' => '',
            'password' => 'secret',
            'host' => '',
            'port' => 0,
            'socket' => '',
        ));

        self::assertFalse($result['connected']);
        self::assertSame('warning', $result['status']);
        self::assertSame('Database credentials are incomplete.', $result['message']);
        self::assertStringNotContainsString('secret', json_encode($result) ?: '');
    }

    public function testResultNeverIncludesPasswordWhenConnectionCannotBeMade(): void
    {
        $result = (new \SuperSheepCopyInstaller\DatabaseConnectionTester())->test(array(
            'complete' => true,
            'name' => 'missing_db',
            'user' => 'missing_user',
            'password' => 'secret',
            'host' => '127.0.0.1',
            'port' => 65000,
            'socket' => '',
        ));

        self::assertFalse($result['connected']);
        self::assertContains($result['status'], array('warning', 'error'));
        self::assertSame('missing_db', $result['database']);
        self::assertSame('127.0.0.1', $result['host']);
        self::assertStringNotContainsString('secret', json_encode($result) ?: '');
    }
}
