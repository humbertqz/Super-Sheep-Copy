<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

spl_autoload_register(static function (string $class): void {
    if ($class !== 'SuperSheepCopyInstaller\\LegacyZeroDateDefaultDetector') {
        return;
    }

    $path = dirname(__DIR__, 2) . '/installer/restore-engine/LegacyZeroDateDefaultDetector.php';
    if (is_file($path)) {
        require_once $path;
    }
});

final class LegacyZeroDateDefaultDetectorTest extends TestCase
{
    public function testDetectsZeroDateDefaultsOnlyInCreateTableStatements(): void
    {
        self::assertTrue(class_exists(\SuperSheepCopyInstaller\LegacyZeroDateDefaultDetector::class));

        $detector = new \SuperSheepCopyInstaller\LegacyZeroDateDefaultDetector();

        self::assertTrue($detector->requiresCompatibility(array(
            'actions.sql' => "CREATE TABLE `wp_actionscheduler_actions` (`scheduled_date_gmt` datetime DEFAULT '0000-00-00 00:00:00');",
        )));
        self::assertFalse($detector->requiresCompatibility(array(
            'posts.sql' => "INSERT INTO `wp_posts` (`post_content`) VALUES ('DEFAULT \\'0000-00-00 00:00:00\\'');",
        )));
    }
}
