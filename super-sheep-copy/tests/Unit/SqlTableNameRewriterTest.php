<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/SqlTableNameRewriter.php';

final class SqlTableNameRewriterTest extends TestCase
{
    public function testRewritesBacktickedTableIdentifiersOnly(): void
    {
        $sql = "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (`ID` bigint);\nINSERT INTO `wp_posts` (`post_title`) VALUES ('wp_posts stays in string'), ('`wp_posts` stays backticked in string');\n";

        $rewritten = (new \SuperSheepCopyInstaller\SqlTableNameRewriter())->rewrite($sql, array('wp_posts' => 'ssc_tmp_abcd_wp_posts'));

        self::assertStringContainsString('DROP TABLE IF EXISTS `ssc_tmp_abcd_wp_posts`;', $rewritten);
        self::assertStringContainsString('CREATE TABLE `ssc_tmp_abcd_wp_posts`', $rewritten);
        self::assertStringContainsString('INSERT INTO `ssc_tmp_abcd_wp_posts`', $rewritten);
        self::assertStringContainsString("'wp_posts stays in string'", $rewritten);
        self::assertStringContainsString("'`wp_posts` stays backticked in string'", $rewritten);
    }

    public function testEscapesBackticksInReplacementIdentifier(): void
    {
        $rewritten = (new \SuperSheepCopyInstaller\SqlTableNameRewriter())->rewrite('CREATE TABLE `wp_posts` (`ID` bigint);', array('wp_posts' => 'tmp`posts'));

        self::assertStringContainsString('`tmp``posts`', $rewritten);
    }
}
