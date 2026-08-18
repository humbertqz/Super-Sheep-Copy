<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;
use RuntimeException;

final class WpdbDatabaseExporter
{
    private WpdbClientInterface $client;
    private TableSelector $selector;

    public function __construct(WpdbClientInterface $client, TableSelector $selector)
    {
        $this->client = $client;
        $this->selector = $selector;
    }

    /**
     * @return string[]
     */
    public function selectTables(string $prefix, string $mode): array
    {
        return $this->selector->select($this->client->getTables(), $prefix, $mode);
    }

    public function getSchema(string $table): TableSchema
    {
        $this->assertIdentifier($table);

        $create_sql = $this->client->getCreateTableSql($table);
        if ($create_sql === '') {
            throw new RuntimeException('Create table SQL was not found for table: ' . esc_html($table));
        }

        $status = $this->client->getTableStatus($table);

        return new TableSchema(
            $table,
            $create_sql,
            $this->client->getPrimaryKey($table),
            $this->client->getRowCount($table),
            isset($status['Charset']) && is_string($status['Charset']) ? $status['Charset'] : null,
            isset($status['Collation']) && is_string($status['Collation']) ? $status['Collation'] : null
        );
    }

    /**
     * @return string[]
     */
    public function getColumns(string $table): array
    {
        $this->assertIdentifier($table);

        return $this->client->getColumns($table);
    }

    public function getPrimaryKeyUpperBound(TableSchema $schema): ?int
    {
        $primary_key = $schema->primaryKey();
        if ($primary_key === null || $primary_key === '') {
            return null;
        }

        $this->assertIdentifier($schema->name());
        $this->assertIdentifier($primary_key);
        $rows = $this->client->getRows(sprintf(
            'SELECT MAX(`%s`) AS `ssc_max_primary_key` FROM `%s`',
            $primary_key,
            $schema->name()
        ));
        $value = $rows[0]['ssc_max_primary_key'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function buildChunkQuery(ChunkPlan $plan): string
    {
        $this->assertIdentifier($plan->tableName());

        if ($plan->strategy() === ChunkPlan::STRATEGY_PRIMARY_KEY) {
            $primary_key = $plan->primaryKey();
            $this->assertIdentifier((string) $primary_key);
            $upper_bound = $plan->upperBound();

            if ($plan->lastSeenId() === null) {
                if ($upper_bound !== null) {
                    return $this->client->prepare(
                        sprintf('SELECT * FROM `%s` WHERE `%s` <= %%d ORDER BY `%s` ASC LIMIT %%d', $plan->tableName(), $primary_key, $primary_key),
                        array($upper_bound, $plan->limit())
                    );
                }

                return $this->client->prepare(
                    sprintf('SELECT * FROM `%s` ORDER BY `%s` ASC LIMIT %%d', $plan->tableName(), $primary_key),
                    array($plan->limit())
                );
            }

            if ($upper_bound !== null) {
                return $this->client->prepare(
                    sprintf('SELECT * FROM `%s` WHERE `%s` > %%d AND `%s` <= %%d ORDER BY `%s` ASC LIMIT %%d', $plan->tableName(), $primary_key, $primary_key, $primary_key),
                    array($plan->lastSeenId(), $upper_bound, $plan->limit())
                );
            }

            return $this->client->prepare(
                sprintf('SELECT * FROM `%s` WHERE `%s` > %%d ORDER BY `%s` ASC LIMIT %%d', $plan->tableName(), $primary_key, $primary_key),
                array($plan->lastSeenId(), $plan->limit())
            );
        }

        return $this->client->prepare(
            sprintf('SELECT * FROM `%s` LIMIT %%d OFFSET %%d', $plan->tableName()),
            array($plan->limit(), (int) $plan->offset())
        );
    }

    /**
     * @param string[] $columns
     */
    public function fetchRows(ChunkPlan $plan, array $columns): TableRows
    {
        foreach ($columns as $column) {
            $this->assertIdentifier($column);
        }

        return new TableRows($plan->tableName(), $columns, $this->client->getRows($this->buildChunkQuery($plan)));
    }

    private function assertIdentifier(string $identifier): void
    {
        if ($identifier === '' || preg_match('/^[A-Za-z0-9_-]+$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . esc_html($identifier));
        }
    }
}
