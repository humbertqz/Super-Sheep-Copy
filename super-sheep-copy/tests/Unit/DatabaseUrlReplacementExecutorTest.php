<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Shared\Serialization\SerializationWalker;
use SuperSheepCopy\Shared\Urls\StructuredValueReplacer;
use SuperSheepCopy\Shared\Urls\UrlReplacementEngine;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/DatabaseUrlReplacementExecutor.php';

final class DatabaseUrlReplacementExecutorTest extends TestCase
{
    public function testUpdatesPlainJsonAndSerializedValues(): void
    {
        $connection = new FakeUrlReplacementMysqli(array(
            'wp_posts' => array(
                array(
                    'ID' => '1',
                    'post_content' => 'Visit https://source.example/page',
                    'post_meta' => '{"url":"https:\/\/source.example\/json"}',
                    'post_settings' => serialize(array('url' => 'https://source.example/serialized')),
                ),
            ),
        ));
        $executor = new \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor($connection);

        $result = $executor->execute(
            array('complete' => true),
            array('source_urls' => array('https://source.example'), 'destination_url' => 'https://destination.example'),
            array('wp_posts' => array('columns' => array('post_content', 'post_meta', 'post_settings'), 'primary_key' => 'ID')),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['scanned_rows']);
        self::assertSame(1, $result['changed_rows']);
        self::assertSame(3, $result['changed_cells']);
        self::assertSame(3, $result['replacement_count']);
        self::assertCount(1, $connection->updates);
        self::assertSame('utf8mb4', $connection->charset);
        self::assertStringContainsString('UPDATE `wp_posts` SET `post_content` = ', $connection->updates[0]);
        self::assertStringContainsString('`post_meta` = ', $connection->updates[0]);
        self::assertStringContainsString('`post_settings` = ', $connection->updates[0]);
        self::assertStringContainsString('WHERE `ID` = ', $connection->updates[0]);
    }

    public function testSkipsUnchangedValues(): void
    {
        $connection = new FakeUrlReplacementMysqli(array(
            'wp_options' => array(array('option_id' => '1', 'option_value' => 'unchanged')),
        ));
        $executor = new \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor($connection);

        $result = $executor->execute(
            array('complete' => true),
            array('source_urls' => array('https://source.example'), 'destination_url' => 'https://destination.example'),
            array('wp_options' => array('columns' => array('option_value'), 'primary_key' => 'option_id')),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['scanned_rows']);
        self::assertSame(0, $result['changed_rows']);
        self::assertSame(0, $result['changed_cells']);
        self::assertSame(array(), $connection->updates);
    }

    public function testUpdatesChangedValuesWithoutPrimaryKeyUsingOriginalSelectedValues(): void
    {
        $connection = new FakeUrlReplacementMysqli(array(
            'wp_legacy_content' => array(array(
                'title' => 'Home',
                'body' => 'Visit https://source.example/page',
            )),
        ));
        $executor = new \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor($connection);

        $result = $executor->execute(
            array('complete' => true),
            array('source_urls' => array('https://source.example'), 'destination_url' => 'https://destination.example'),
            array('wp_legacy_content' => array('columns' => array('title', 'body'), 'primary_key' => '')),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['changed_rows']);
        self::assertSame(1, $result['changed_cells']);
        self::assertCount(1, $connection->updates);
        self::assertStringContainsString("UPDATE `wp_legacy_content` SET `body` = 'Visit https://destination.example/page'", $connection->updates[0]);
        self::assertStringContainsString("WHERE `title` = 'Home' AND `body` = 'Visit https://source.example/page' LIMIT 1", $connection->updates[0]);
    }

    public function testUpdatesMultipleChangedValuesWithoutPrimaryKeyInOneStatement(): void
    {
        $connection = new FakeUrlReplacementMysqli(array(
            'wp_legacy_content' => array(array(
                'title' => 'https://source.example/home',
                'body' => 'Visit https://source.example/page',
            )),
        ));
        $executor = new \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor($connection);

        $result = $executor->execute(
            array('complete' => true),
            array('source_urls' => array('https://source.example'), 'destination_url' => 'https://destination.example'),
            array('wp_legacy_content' => array('columns' => array('title', 'body'), 'primary_key' => '')),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );

        self::assertTrue($result['completed']);
        self::assertSame(1, $result['changed_rows']);
        self::assertSame(2, $result['changed_cells']);
        self::assertCount(1, $connection->updates);
        self::assertStringContainsString("SET `title` = 'https://destination.example/home', `body` = 'Visit https://destination.example/page'", $connection->updates[0]);
        self::assertStringContainsString("WHERE `title` = 'https://source.example/home' AND `body` = 'Visit https://source.example/page' LIMIT 1", $connection->updates[0]);
    }

    public function testRejectsInvalidColumnIdentifier(): void
    {
        $connection = new FakeUrlReplacementMysqli(array());
        $executor = new \SuperSheepCopyInstaller\DatabaseUrlReplacementExecutor($connection);

        $result = $executor->execute(
            array('complete' => true),
            array('source_urls' => array('https://source.example'), 'destination_url' => 'https://destination.example'),
            array('wp_posts' => array('columns' => array('post-content'), 'primary_key' => 'ID')),
            new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker())
        );

        self::assertFalse($result['completed']);
        self::assertSame(array('Invalid database column identifier: post-content'), $result['warnings']);
        self::assertSame(array(), $connection->updates);
    }
}

final class FakeUrlReplacementMysqli
{
    /** @var array<string,list<array<string,string>>> */
    private array $rows;
    /** @var list<string> */
    public array $selects = array();
    /** @var list<string> */
    public array $updates = array();
    public string $charset = '';

    /**
     * @param array<string,list<array<string,string>>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function real_escape_string(string $value): string
    {
        return addslashes($value);
    }

    public function set_charset(string $charset): bool
    {
        $this->charset = $charset;

        return true;
    }

    public function query(string $sql)
    {
        if (strpos($sql, 'SELECT ') === 0) {
            $this->selects[] = $sql;
            if (preg_match('/FROM `([^`]+)`/', $sql, $matches) !== 1) {
                return false;
            }

            return new FakeUrlReplacementResult($this->rows[$matches[1]] ?? array());
        }

        if (strpos($sql, 'UPDATE ') === 0) {
            $this->updates[] = $sql;

            return true;
        }

        return false;
    }

    public function close(): void
    {
    }
}

final class FakeUrlReplacementResult
{
    /** @var list<array<string,string>> */
    private array $rows;

    /**
     * @param list<array<string,string>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function fetch_assoc()
    {
        return array_shift($this->rows);
    }
}
