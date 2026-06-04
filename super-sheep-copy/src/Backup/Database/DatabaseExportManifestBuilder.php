<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

final class DatabaseExportManifestBuilder
{
    /**
     * @param TableSchema[] $schemas
     * @param array<string, ChunkPlan[]> $plans_by_table
     * @return array{format_version:string,table_count:int,tables:array<int,array<string,mixed>>}
     */
    public function build(array $schemas, array $plans_by_table): array
    {
        $tables = array();

        foreach ($schemas as $schema) {
            $plans = isset($plans_by_table[$schema->name()]) ? $plans_by_table[$schema->name()] : array();
            $chunks = array();
            $strategy = null;

            foreach ($plans as $plan) {
                $chunks[] = $plan->fileName();
                if ($strategy === null) {
                    $strategy = $plan->strategy();
                }
            }

            $tables[] = array(
                'name' => $schema->name(),
                'row_count' => $schema->rowCount(),
                'primary_key' => $schema->primaryKey(),
                'charset' => $schema->charset(),
                'collation' => $schema->collation(),
                'pagination_strategy' => $strategy,
                'chunks' => $chunks,
            );
        }

        return array(
            'format_version' => '1',
            'table_count' => count($tables),
            'tables' => $tables,
        );
    }
}
