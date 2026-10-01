<?php

declare(strict_types=1);

// Standalone recovery tool: no WordPress, plugin autoloader, or database required.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this tool from the command line.');
}

$root = __DIR__;
$output = null;
$temporary = null;
try {
    $read_json = static function (string $path): array {
        $text = file_get_contents($path);
        $data = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($data)) {
            throw new RuntimeException('Invalid or missing JSON: ' . basename($path));
        }
        return $data;
    };
    $safe_path = static function (string $entry) use ($root): string {
        if (preg_match('#^(files|database)/#', $entry) !== 1 || strpos($entry, '\\') !== false || strpos($entry, "\0") !== false
            || in_array('..', explode('/', $entry), true)) {
            throw new RuntimeException('Unsafe backup path: ' . $entry);
        }
        $path = realpath($root . '/' . $entry);
        if ($path === false || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            throw new RuntimeException('Missing backup file: ' . $entry);
        }
        return $path;
    };
    if (file_exists($root . '/database.sql') || is_link($root . '/database.sql')) {
        throw new RuntimeException('database.sql already exists. Move it before rebuilding.');
    }
    $checksums = $read_json($root . '/checksums.json');
    if ($checksums === array()) {
        throw new RuntimeException('No backup checksums found.');
    }
    foreach ($checksums as $entry => $checksum) {
        if (!is_string($entry) || !is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1
            || hash_file('sha256', $safe_path($entry)) !== $checksum) {
            throw new RuntimeException('Checksum verification failed: ' . (string) $entry);
        }
    }
    if (!isset($checksums['database/tables.json'])) {
        throw new RuntimeException('Missing database manifest checksum.');
    }
    $manifest = $read_json($root . '/manifest.json');
    $database = $read_json($safe_path('database/tables.json'));
    $tables = $database['tables'] ?? null;
    if (!is_array($tables) || $tables === array()) {
        throw new RuntimeException('No database tables found.');
    }
    $charset = $manifest['source_database_charset'] ?? 'utf8mb4';
    if (!is_string($charset) || preg_match('/^[a-zA-Z0-9_]+$/', $charset) !== 1) {
        throw new RuntimeException('Invalid source database charset.');
    }
    $chunks = array();
    foreach ($tables as $table) {
        if (!is_array($table) || empty($table['chunks']) || !is_array($table['chunks'])) {
            throw new RuntimeException('A table has no SQL chunks.');
        }
        foreach ($table['chunks'] as $chunk) {
            if (!is_string($chunk) || preg_match('/^[A-Za-z0-9_-]+\.part[0-9]+\.sql$/', $chunk) !== 1) {
                throw new RuntimeException('Invalid SQL chunk filename.');
            }
            $entry = 'database/chunks/' . $chunk;
            if (!isset($checksums[$entry]) || isset($chunks[$entry])) {
                throw new RuntimeException('Missing checksum or repeated SQL chunk: ' . $entry);
            }
            $chunks[$entry] = $safe_path($entry);
        }
    }
    $temporary = tempnam($root, '.database-');
    $output = is_string($temporary) ? fopen($temporary, 'wb') : false;
    if ($output === false) {
        throw new RuntimeException('Unable to create SQL output.');
    }
    $write = static function (string $text) use ($output): void {
        if (fwrite($output, $text) !== strlen($text)) {
            throw new RuntimeException('Unable to write complete SQL output.');
        }
    };
    $write("-- Super Sheep Copy manual recovery dump\nSET @SSC_OLD_SQL_MODE=@@SESSION.SQL_MODE;\nSET @SSC_OLD_FOREIGN_KEY_CHECKS=@@SESSION.FOREIGN_KEY_CHECKS;\nSET @SSC_OLD_CHARACTER_SET_CLIENT=@@SESSION.CHARACTER_SET_CLIENT;\nSET @SSC_OLD_CHARACTER_SET_RESULTS=@@SESSION.CHARACTER_SET_RESULTS;\nSET @SSC_OLD_COLLATION_CONNECTION=@@SESSION.COLLATION_CONNECTION;\nSET NAMES " . $charset . ";\nSET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET SESSION FOREIGN_KEY_CHECKS=0;\n");
    foreach ($chunks as $entry => $path) {
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('Unable to read SQL chunk: ' . $entry);
        }
        try {
            $hash = hash_init('sha256');
            while (!feof($input)) {
                $buffer = fread($input, 1048576);
                if ($buffer === false) {
                    throw new RuntimeException('Unable to read complete SQL chunk: ' . $entry);
                }
                hash_update($hash, $buffer);
                $write($buffer);
            }
            if (hash_final($hash) !== $checksums[$entry]) {
                throw new RuntimeException('SQL chunk changed during assembly: ' . $entry);
            }
            $write("\n");
        } finally {
            fclose($input);
        }
    }
    $write("SET SESSION FOREIGN_KEY_CHECKS=@SSC_OLD_FOREIGN_KEY_CHECKS;\nSET SESSION SQL_MODE=@SSC_OLD_SQL_MODE;\nSET SESSION CHARACTER_SET_CLIENT=@SSC_OLD_CHARACTER_SET_CLIENT;\nSET SESSION CHARACTER_SET_RESULTS=@SSC_OLD_CHARACTER_SET_RESULTS;\nSET SESSION COLLATION_CONNECTION=@SSC_OLD_COLLATION_CONNECTION;\n");
    if (!fclose($output)) {
        throw new RuntimeException('Unable to finalize SQL output.');
    }
    $output = null;
    if (!rename($temporary, $root . '/database.sql')) {
        throw new RuntimeException('Unable to publish SQL output.');
    }
    $temporary = null;
    fwrite(STDOUT, "Verified backup files and created database.sql. Import into an empty database.\n");
} catch (Throwable $error) {
    if (is_resource($output)) {
        fclose($output);
    }
    if (is_string($temporary) && is_file($temporary)) {
        unlink($temporary);
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
