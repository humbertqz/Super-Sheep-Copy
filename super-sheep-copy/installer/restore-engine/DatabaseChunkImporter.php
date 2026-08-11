<?php
// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_report,WordPress.DB.RestrictedClasses.mysql__mysqli -- Standalone installer connects before WordPress is available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use InvalidArgumentException;

class DatabaseChunkImporter
{
    /**
     * @param array<string,mixed> $credentials
     * @param list<array{name:string,charset?:string,collation?:string,chunks:list<string>}> $tables
     * @param array<string,string> $chunks
     * @param array<string,string> $table_map
     * @param array<string,mixed> $cursor
     * @return array{imported:bool,in_progress:bool,cursor:array<string,mixed>,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    public function importStep(array $credentials, array $tables, array $chunks, array $table_map, SqlTableNameRewriter $rewriter, array $cursor = array(), float $budget_seconds = 10.0): array
    {
        if (!class_exists('\\mysqli')) {
            return $this->stepResult(false, false, $cursor, 0, 0, 0, array('The mysqli extension is not available.'));
        }

        \mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = $this->connect($credentials);
        if ($mysqli->connect_errno !== 0) {
            return $this->stepResult(false, false, $cursor, 0, 0, 0, array('Database connection failed.'));
        }
        $this->setConnectionCharset($mysqli, $credentials);
        $compatibility_warning = $this->configureLegacyZeroDateCompatibility($mysqli, $chunks);
        if ($compatibility_warning !== null) {
            $mysqli->close();

            return $this->stepResult(false, false, $cursor, 0, 0, 0, array($compatibility_warning));
        }
        $table_index = isset($cursor['table_index']) ? max(0, (int) $cursor['table_index']) : 0;
        $chunk_index = isset($cursor['chunk_index']) ? max(0, (int) $cursor['chunk_index']) : 0;
        $statement_index = isset($cursor['statement_index']) ? max(0, (int) $cursor['statement_index']) : 0;
        $statement_count = 0;
        $chunk_count = 0;
        $started = microtime(true);
        $allowed_tables = array_values($table_map);

        while ($table_index < count($tables)) {
            $table = $tables[$table_index];
            $chunk_names = isset($table['chunks']) && is_array($table['chunks']) ? array_values($table['chunks']) : array();
            if ($chunk_index >= count($chunk_names)) {
                $table_index++;
                $chunk_index = 0;
                $statement_index = 0;
                continue;
            }
            $chunk_name = (string) $chunk_names[$chunk_index];
            $sql = $rewriter->rewrite(isset($chunks[$chunk_name]) ? $chunks[$chunk_name] : '', $table_map);
            $statements = $this->splitStatements($sql);
            while ($statement_index < count($statements)) {
                $statement = $this->normalizeCreateTableEncoding($statements[$statement_index], $credentials);
                $this->assertSafeStatement($statement, $allowed_tables);
                if (!$mysqli->query($statement)) {
                    $warning = $this->failedStatementWarning($mysqli, $table['name'], $chunk_name, $statement);
                    $mysqli->close();

                    return $this->stepResult(false, false, compact('table_index', 'chunk_index', 'statement_index'), count($tables), $chunk_count, $statement_count, array($warning));
                }
                ++$statement_index;
                ++$statement_count;
                if (microtime(true) - $started >= max(0.1, $budget_seconds)) {
                    $mysqli->close();

                    return $this->stepResult(false, true, compact('table_index', 'chunk_index', 'statement_index'), count($tables), $chunk_count, $statement_count, array());
                }
            }
            ++$chunk_count;
            ++$chunk_index;
            $statement_index = 0;
        }

        $mysqli->close();

        return $this->stepResult(true, false, array('table_index' => $table_index, 'chunk_index' => 0, 'statement_index' => 0), count($tables), $chunk_count, $statement_count, array());
    }

    /**
     * @param array<string,mixed> $credentials
     * @param list<array{name:string,charset?:string,collation?:string,chunks:list<string>}> $tables
     * @param array<string,string> $chunks
     * @param array<string,string> $table_map
     * @return array{imported:bool,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    public function import(array $credentials, array $tables, array $chunks, array $table_map, SqlTableNameRewriter $rewriter): array
    {
        if (!class_exists('\\mysqli')) {
            return $this->result(false, 0, 0, 0, array('The mysqli extension is not available.'));
        }

        \mysqli_report(MYSQLI_REPORT_OFF);

        $mysqli = $this->connect($credentials);
        if ($mysqli->connect_errno !== 0) {
            return $this->result(false, 0, 0, 0, array('Database connection failed.'));
        }
        $this->setConnectionCharset($mysqli, $credentials);
        $compatibility_warning = $this->configureLegacyZeroDateCompatibility($mysqli, $chunks);
        if ($compatibility_warning !== null) {
            $mysqli->close();

            return $this->result(false, 0, 0, 0, array($compatibility_warning));
        }

        $statement_count = 0;
        $chunk_count = 0;
        $allowed_tables = array_values($table_map);

        foreach ($tables as $table) {
            $source_table = (string) $table['name'];
            $expected_staging_table = isset($table_map[$source_table]) ? (string) $table_map[$source_table] : '';
            $created_staging_table = false;

            foreach ($table['chunks'] as $chunk_name) {
                $sql = $rewriter->rewrite(isset($chunks[$chunk_name]) ? $chunks[$chunk_name] : '', $table_map);

                foreach ($this->splitStatements($sql) as $statement) {
                    $statement = $this->normalizeCreateTableEncoding($statement, $credentials);
                    $this->assertSafeStatement($statement, $allowed_tables);
                    if ($expected_staging_table !== '' && $this->createsTable($statement, $expected_staging_table)) {
                        $created_staging_table = true;
                    }

                    if (!$mysqli->query($statement)) {
                        $warning = $this->failedStatementWarning($mysqli, $table['name'], $chunk_name, $statement);
                        $mysqli->close();

                        return $this->result(false, count($tables), $chunk_count, $statement_count, array($warning));
                    }

                    ++$statement_count;
                }

                ++$chunk_count;
            }

            if ($expected_staging_table !== '' && !$created_staging_table) {
                $mysqli->close();

                return $this->result(false, count($tables), $chunk_count, $statement_count, array(
                    'Database import did not create staging table ' . $expected_staging_table . ' for source table ' . $source_table . '. Check that the backup contains a CREATE TABLE statement for ' . $source_table . '.',
                ));
            }
        }

        $mysqli->close();

        return $this->result(true, count($tables), $chunk_count, $statement_count, array());
    }

    /** @return array<string,mixed> */
    private function stepResult(bool $imported, bool $in_progress, array $cursor, int $table_count, int $chunk_count, int $statement_count, array $warnings): array
    {
        return array('imported' => $imported, 'in_progress' => $in_progress, 'cursor' => $cursor, 'table_count' => $table_count, 'chunk_count' => $chunk_count, 'statement_count' => $statement_count, 'warnings' => $warnings);
    }

    /**
     * @return list<string>
     */
    public function splitStatementsForTest(string $sql): array
    {
        return $this->splitStatements($sql);
    }

    /**
     * @param list<string> $allowed_tables
     */
    public function assertSafeStatementForTest(string $statement, array $allowed_tables): void
    {
        $this->assertSafeStatement($statement, $allowed_tables);
    }

    /**
     * @param array<string,mixed> $credentials
     * @return object
     */
    protected function connect(array $credentials)
    {
        return @new \mysqli(
            isset($credentials['host']) ? (string) $credentials['host'] : '',
            isset($credentials['user']) ? (string) $credentials['user'] : '',
            isset($credentials['password']) ? (string) $credentials['password'] : '',
            isset($credentials['name']) ? (string) $credentials['name'] : '',
            isset($credentials['port']) ? (int) $credentials['port'] : 0,
            isset($credentials['socket']) ? (string) $credentials['socket'] : ''
        );
    }

    /**
     * @return list<string>
     */
    protected function splitStatements(string $sql): array
    {
        $statements = array();
        $buffer = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === '\\') {
                    if ($i + 1 < $length) {
                        ++$i;
                        $buffer .= $sql[$i];
                    }
                    continue;
                }

                if ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        ++$i;
                        $buffer .= $sql[$i];
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * @param list<string> $allowed_tables
     */
    protected function assertSafeStatement(string $statement, array $allowed_tables): void
    {
        if (preg_match('/^\\s*(RENAME\\s+TABLE|TRUNCATE(?:\\s+TABLE)?|DELETE\\s+FROM|UPDATE\\s+.+\\s+SET)\\b/is', $statement) === 1) {
            throw new InvalidArgumentException('Unsafe SQL statement for staged import.');
        }

        if (preg_match('/^\\s*DROP\\s+TABLE\\s+(?:IF\\s+EXISTS\\s+)?(.+)$/is', $statement, $matches) === 1) {
            $this->assertAllowedTableList($matches[1], $allowed_tables);

            return;
        }

        if (preg_match('/^\\s*CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?(`(?:``|[^`])+`)/is', $statement, $matches) === 1) {
            $this->assertAllowedTable($this->unquoteIdentifier($matches[1]), $allowed_tables);

            return;
        }

        if (preg_match('/^\\s*INSERT\\s+INTO\\s+(`(?:``|[^`])+`)/is', $statement, $matches) === 1) {
            $this->assertAllowedTable($this->unquoteIdentifier($matches[1]), $allowed_tables);
        }
    }

    /**
     * @param list<string> $allowed_tables
     */
    private function assertAllowedTableList(string $table_list, array $allowed_tables): void
    {
        foreach (explode(',', $table_list) as $table) {
            if (preg_match('/^\\s*(`(?:``|[^`])+`)\\s*$/', $table, $matches) !== 1) {
                throw new InvalidArgumentException('Unsafe SQL statement for staged import.');
            }

            $this->assertAllowedTable($this->unquoteIdentifier($matches[1]), $allowed_tables);
        }
    }

    /**
     * @param list<string> $allowed_tables
     */
    private function assertAllowedTable(string $table, array $allowed_tables): void
    {
        if (!in_array($table, $allowed_tables, true)) {
            throw new InvalidArgumentException('Unsafe SQL statement for staged import.');
        }
    }

    private function unquoteIdentifier(string $identifier): string
    {
        return str_replace('``', '`', substr($identifier, 1, -1));
    }

    private function createsTable(string $statement, string $expected_table): bool
    {
        if (preg_match('/^\\s*CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?(`(?:``|[^`])+`)/is', $statement, $matches) !== 1) {
            return false;
        }

        return $this->unquoteIdentifier($matches[1]) === $expected_table;
    }

    private function failedStatementWarning(object $mysqli, string $table_name, string $chunk_name, string $statement): string
    {
        $error = property_exists($mysqli, 'error') ? (string) $mysqli->error : '';
        $errno = property_exists($mysqli, 'errno') ? (int) $mysqli->errno : 0;
        $mysql_error = $error !== '' ? ' MySQL ' . $errno . ': ' . $error : '';
        $snippet = preg_replace('/\\s+/', ' ', trim($statement));
        $snippet = $snippet === null ? '' : substr($snippet, 0, 220);

        $warning = sprintf(
            'Database import statement failed for %s in table %s.%s Statement: %s',
            $chunk_name,
            $table_name,
            $mysql_error,
            $snippet
        );

        if ($errno === 2006) {
            $warning .= ' The failed statement is ' . strlen($statement) . ' bytes; check max_allowed_packet or the MySQL error log if the server cannot reconnect.';
        }

        return $warning;
    }

    /**
     * @param array<string,string> $chunks
     */
    private function configureLegacyZeroDateCompatibility(object $mysqli, array $chunks): ?string
    {
        if (!(new LegacyZeroDateDefaultDetector())->requiresCompatibility($chunks)) {
            return null;
        }

        $result = $mysqli->query('SELECT @@SESSION.sql_mode');
        if ($result === false || !method_exists($result, 'fetch_row')) {
            return 'Database import requires legacy zero-date compatibility, but the installer could not inspect this connection\'s SQL mode.';
        }

        $row = $result->fetch_row();
        $mode = isset($row[0]) && is_scalar($row[0]) ? (string) $row[0] : '';
        $modes = array_filter(array_map('trim', explode(',', $mode)), static function (string $item): bool {
            return $item !== '' && $item !== 'NO_ZERO_DATE' && $item !== 'NO_ZERO_IN_DATE';
        });
        $session_mode = implode(',', $modes);
        $statement = "SET SESSION sql_mode = '" . str_replace("'", "''", $session_mode) . "'";
        if (!$mysqli->query($statement)) {
            return 'Database import requires legacy zero-date compatibility, but the installer could not update this connection\'s SQL mode.';
        }

        return null;
    }

    /**
     * @param list<string> $warnings
     * @return array{imported:bool,table_count:int,chunk_count:int,statement_count:int,warnings:list<string>}
     */
    private function result(bool $imported, int $table_count, int $chunk_count, int $statement_count, array $warnings): array
    {
        return array(
            'imported' => $imported,
            'table_count' => $table_count,
            'chunk_count' => $chunk_count,
            'statement_count' => $statement_count,
            'warnings' => $warnings,
        );
    }

    /**
     * @param array<string,mixed> $credentials
     */
    private function setConnectionCharset(object $mysqli, array $credentials): void
    {
        $charset = $this->destinationCharset($credentials);

        if (method_exists($mysqli, 'set_charset')) {
            $mysqli->set_charset($charset);
        }
    }

    /**
     * @param array<string,mixed> $credentials
     */
    private function normalizeCreateTableEncoding(string $statement, array $credentials): string
    {
        if (preg_match('/^\\s*CREATE\\s+TABLE\\b/i', $statement) !== 1) {
            return $statement;
        }

        $charset = $this->destinationCharset($credentials);
        $collation = $this->destinationCollation($credentials);

        $statement = preg_replace('/\\b(?:DEFAULT\\s+)?(?:CHARSET|CHARACTER\\s+SET)\\s*=\\s*[A-Za-z0-9_]+/i', 'DEFAULT CHARSET=' . $charset, $statement);
        $statement = preg_replace('/\\bCHARACTER\\s+SET\\s+[A-Za-z0-9_]+/i', 'CHARACTER SET ' . $charset, $statement);
        $statement = $statement === null ? '' : $statement;

        if ($collation !== '') {
            $statement = preg_replace('/\\bCOLLATE\\s*=\\s*[A-Za-z0-9_]+/i', 'COLLATE=' . $collation, $statement);
            $statement = preg_replace('/\\bCOLLATE\\s+[A-Za-z0-9_]+/i', 'COLLATE ' . $collation, $statement === null ? '' : $statement);

            return $statement === null ? '' : $statement;
        }

        $statement = preg_replace('/\\s+COLLATE\\s*=\\s*[A-Za-z0-9_]+/i', '', $statement);
        $statement = preg_replace('/\\s+COLLATE\\s+[A-Za-z0-9_]+/i', '', $statement === null ? '' : $statement);

        return $statement === null ? '' : $statement;
    }

    /**
     * @param array<string,mixed> $credentials
     */
    private function destinationCharset(array $credentials): string
    {
        $charset = isset($credentials['charset']) && is_scalar($credentials['charset']) ? (string) $credentials['charset'] : '';
        $charset = $charset !== '' ? $charset : 'utf8mb4';

        return preg_match('/^[A-Za-z0-9_]+$/', $charset) === 1 ? $charset : 'utf8mb4';
    }

    /**
     * @param array<string,mixed> $credentials
     */
    private function destinationCollation(array $credentials): string
    {
        $collation = isset($credentials['collate']) && is_scalar($credentials['collate']) ? (string) $credentials['collate'] : '';

        return preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1 ? $collation : '';
    }
}
