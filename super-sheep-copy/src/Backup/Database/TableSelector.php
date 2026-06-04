<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Database;

use InvalidArgumentException;

final class TableSelector
{
    public const MODE_PREFIXED = 'prefixed';
    public const MODE_CORE = 'core';
    public const MODE_ALL = 'all';

    /**
     * @var string[]
     */
    private array $core_suffixes = array(
        'commentmeta',
        'comments',
        'links',
        'options',
        'postmeta',
        'posts',
        'term_relationships',
        'term_taxonomy',
        'termmeta',
        'terms',
        'usermeta',
        'users',
    );

    /**
     * @param string[] $tables
     * @return string[]
     */
    public function select(array $tables, string $prefix, string $mode): array
    {
        if (!in_array($mode, array(self::MODE_PREFIXED, self::MODE_CORE, self::MODE_ALL), true)) {
            throw new InvalidArgumentException('Unknown table selection mode.');
        }

        if (($mode === self::MODE_PREFIXED || $mode === self::MODE_CORE) && $prefix === '') {
            throw new InvalidArgumentException('Table prefix is required.');
        }

        if ($mode === self::MODE_ALL) {
            return array_values($tables);
        }

        $selected = array();
        $core_tables = array();
        foreach ($this->core_suffixes as $suffix) {
            $core_tables[] = $prefix . $suffix;
        }

        foreach ($tables as $table) {
            if ($mode === self::MODE_PREFIXED && strpos($table, $prefix) === 0) {
                $selected[] = $table;
                continue;
            }

            if ($mode === self::MODE_CORE && in_array($table, $core_tables, true)) {
                $selected[] = $table;
            }
        }

        return $selected;
    }
}
