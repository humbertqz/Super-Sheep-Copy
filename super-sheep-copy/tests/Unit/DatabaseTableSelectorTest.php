<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\Database\TableSelector;

final class DatabaseTableSelectorTest extends TestCase
{
    public function testSelectsPrefixedTablesInInputOrder(): void
    {
        $selector = new TableSelector();

        self::assertSame(
            array('wp_posts', 'wp_options', 'wp_woocommerce_orders'),
            $selector->select(
                array('wp_posts', 'other_table', 'wp_options', 'custom_logs', 'wp_woocommerce_orders'),
                'wp_',
                TableSelector::MODE_PREFIXED
            )
        );
    }

    public function testSelectsCoreTablesOnly(): void
    {
        $selector = new TableSelector();

        self::assertSame(
            array('wp_options', 'wp_posts', 'wp_postmeta', 'wp_users'),
            $selector->select(
                array('wp_options', 'wp_posts', 'wp_woocommerce_orders', 'wp_postmeta', 'wp_users'),
                'wp_',
                TableSelector::MODE_CORE
            )
        );
    }

    public function testSelectsAllTablesInInputOrder(): void
    {
        $selector = new TableSelector();

        self::assertSame(
            array('wp_posts', 'custom_logs', 'other_table'),
            $selector->select(array('wp_posts', 'custom_logs', 'other_table'), 'wp_', TableSelector::MODE_ALL)
        );
    }

    public function testRejectsEmptyPrefixForPrefixedMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Table prefix is required.');

        (new TableSelector())->select(array('wp_posts'), '', TableSelector::MODE_PREFIXED);
    }

    public function testRejectsUnknownMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown table selection mode.');

        (new TableSelector())->select(array('wp_posts'), 'wp_', 'recent');
    }
}
