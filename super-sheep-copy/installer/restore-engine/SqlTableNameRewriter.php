<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class SqlTableNameRewriter
{
    /**
     * @param array<string,string> $table_map
     */
    public function rewrite(string $sql, array $table_map): string
    {
        if ($table_map === array() || strpos($sql, '`') === false) {
            return $sql;
        }

        $result = '';
        $length = strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];

            if ($char === '\'' || $char === '"') {
                $string = $this->readQuotedString($sql, $offset);
                $result .= $string;
                $offset += strlen($string);
                continue;
            }

            if ($char === '`') {
                $identifier = $this->readBacktickedIdentifier($sql, $offset);
                if (substr($identifier, -1) !== '`') {
                    $result .= $identifier;
                    $offset += strlen($identifier);
                    continue;
                }

                $decoded = str_replace('``', '`', substr($identifier, 1, -1));
                $result .= array_key_exists($decoded, $table_map) ? $this->quoteIdentifier($table_map[$decoded]) : $identifier;
                $offset += strlen($identifier);
                continue;
            }

            $result .= $char;
            ++$offset;
        }

        return $result;
    }

    private function readQuotedString(string $sql, int $offset): string
    {
        $quote = $sql[$offset];
        $length = strlen($sql);
        $position = $offset + 1;

        while ($position < $length) {
            if ($sql[$position] === '\\') {
                $position += 2;
                continue;
            }

            if ($sql[$position] === $quote) {
                if ($position + 1 < $length && $sql[$position + 1] === $quote) {
                    $position += 2;
                    continue;
                }

                return substr($sql, $offset, $position - $offset + 1);
            }

            ++$position;
        }

        return substr($sql, $offset);
    }

    private function readBacktickedIdentifier(string $sql, int $offset): string
    {
        $length = strlen($sql);
        $position = $offset + 1;

        while ($position < $length) {
            if ($sql[$position] === '`') {
                if ($position + 1 < $length && $sql[$position + 1] === '`') {
                    $position += 2;
                    continue;
                }

                return substr($sql, $offset, $position - $offset + 1);
            }

            ++$position;
        }

        return substr($sql, $offset);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
