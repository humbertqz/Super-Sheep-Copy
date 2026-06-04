<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementPlanBuilder.php';

final class DatabaseUrlReplacementPlanBuilderTest extends TestCase
{
    public function testBuildsUniqueSourceVariantsForDatabaseTables(): void
    {
        $plan = (new \SuperSheepCopyInstaller\DatabaseUrlReplacementPlanBuilder())->build(
            'https://www.source.example/site/',
            'http://source.example/site',
            'https://destination.example/new-site/',
            array('wp_posts' => 'ssc_tmp_abcd_wp_posts', 'wp_options' => 'ssc_tmp_abcd_wp_options'),
            '2026-05-19T12:00:00+00:00'
        );

        self::assertSame('planned', $plan['status']);
        self::assertSame('https://destination.example/new-site', $plan['destination_url']);
        self::assertSame(2, $plan['table_count']);
        self::assertSame(array('wp_posts', 'wp_options'), $plan['tables']);
        self::assertSame('2026-05-19T12:00:00+00:00', $plan['planned_at']);
        self::assertContains('https://www.source.example/site', $plan['source_urls']);
        self::assertContains('http://www.source.example/site', $plan['source_urls']);
        self::assertContains('https://source.example/site', $plan['source_urls']);
        self::assertContains('http://source.example/site', $plan['source_urls']);
        self::assertSame(count($plan['source_urls']), count(array_unique($plan['source_urls'])));
    }

    public function testRejectsEmptyDestinationUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Destination URL is required for URL replacement planning.');

        (new \SuperSheepCopyInstaller\DatabaseUrlReplacementPlanBuilder())->build(
            'https://source.example',
            '',
            '',
            array('wp_posts' => 'ssc_tmp_abcd_wp_posts'),
            '2026-05-19T12:00:00+00:00'
        );
    }
}
