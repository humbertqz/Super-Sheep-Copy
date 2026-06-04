<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/Security.php';

final class InstallerSecurityTest extends TestCase
{
    public function testValidTokenVerifiesAgainstConfigHash(): void
    {
        $security = new \SuperSheepCopyInstaller\Security();

        self::assertTrue($security->verifyToken('plain-token', array(
            'token_hash' => password_hash('plain-token', PASSWORD_DEFAULT),
            'locked' => false,
        )));
    }

    public function testMissingTokenFails(): void
    {
        $security = new \SuperSheepCopyInstaller\Security();

        self::assertFalse($security->verifyToken('', array(
            'token_hash' => password_hash('plain-token', PASSWORD_DEFAULT),
            'locked' => false,
        )));
    }

    public function testInvalidTokenFails(): void
    {
        $security = new \SuperSheepCopyInstaller\Security();

        self::assertFalse($security->verifyToken('wrong-token', array(
            'token_hash' => password_hash('plain-token', PASSWORD_DEFAULT),
            'locked' => false,
        )));
    }

    public function testLockedInstallerFails(): void
    {
        $security = new \SuperSheepCopyInstaller\Security();

        self::assertFalse($security->verifyToken('plain-token', array(
            'token_hash' => password_hash('plain-token', PASSWORD_DEFAULT),
            'locked' => true,
        )));
    }

    public function testLockedCompletedSwapCanShowStatus(): void
    {
        $security = new \SuperSheepCopyInstaller\Security();

        self::assertTrue($security->verifyToken('plain-token', array(
            'token_hash' => password_hash('plain-token', PASSWORD_DEFAULT),
            'locked' => true,
            'database_tables_swapped' => true,
        )));
    }
}
