<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DestinationDetector.php';

final class DestinationDetectorTest extends TestCase
{
    public function testDetectsRootHttpUrl(): void
    {
        $detector = new \SuperSheepCopyInstaller\DestinationDetector();

        self::assertSame('http://example.com', $detector->detect(array(
            'HTTP_HOST' => 'example.com',
            'SCRIPT_NAME' => '/installer.php',
        )));
    }

    public function testDetectsHttpsSubdirectoryUrl(): void
    {
        $detector = new \SuperSheepCopyInstaller\DestinationDetector();

        self::assertSame('https://example.com/subsite', $detector->detect(array(
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'SCRIPT_NAME' => '/subsite/installer.php',
        )));
    }

    public function testDetectsForwardedHttps(): void
    {
        $detector = new \SuperSheepCopyInstaller\DestinationDetector();

        self::assertSame('https://example.com', $detector->detect(array(
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'SERVER_NAME' => 'example.com',
            'SCRIPT_NAME' => '/installer.php',
        )));
    }

    public function testReturnsEmptyStringWhenHostIsMissing(): void
    {
        $detector = new \SuperSheepCopyInstaller\DestinationDetector();

        self::assertSame('', $detector->detect(array('SCRIPT_NAME' => '/installer.php')));
    }
}
