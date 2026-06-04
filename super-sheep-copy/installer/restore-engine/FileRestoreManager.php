<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Standalone file restore runs before WordPress filesystem APIs are available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use SuperSheepCopy\Shared\Urls\UrlReplacementEngine;

final class FileRestoreManager
{
    private ArchiveValidator $archive_validator;
    private DatabaseBackupTableCleaner $backup_table_cleaner;

    public function __construct(ArchiveValidator $archive_validator, ?DatabaseBackupTableCleaner $backup_table_cleaner = null)
    {
        $this->archive_validator = $archive_validator;
        $this->backup_table_cleaner = $backup_table_cleaner ?: new DatabaseBackupTableCleaner();
    }

    /**
     * @param array<string,mixed> $config
     * @return array{completed:bool,file_count:int,warnings:list<string>}
     */
    public function restore(string $engine_dir, array $config): array
    {
        if (empty($config['restore_confirmed'])) {
            return $this->result(false, 0, array('Restore is not confirmed.'));
        }
        if (empty($config['rollback_prepared'])) {
            return $this->result(false, 0, array('Rollback is not prepared.'));
        }
        if (empty($config['rollback_database_dump'])) {
            return $this->result(false, 0, array('File restore requires a database rollback dump.'));
        }
        if (empty($config['database_url_replacement_completed'])) {
            return $this->result(false, 0, array('File restore requires database URL replacement.'));
        }
        if (!empty($config['file_restore_completed'])) {
            return $this->result(false, 0, array('File restore is already completed.'));
        }

        $archive_path = isset($config['staged_archive_path']) ? (string) $config['staged_archive_path'] : '';
        $validation_status = isset($config['archive_validation_status']) && is_scalar($config['archive_validation_status']) ? (string) $config['archive_validation_status'] : '';
        if ($validation_status !== 'valid') {
            $validation = $this->archive_validator->validatePackage($archive_path);
            if (!$validation->isValid()) {
                foreach ($validation->errors() as $error) {
                    if (strpos($error, 'Unsafe archive entry') === 0) {
                        return $this->result(false, 0, array('Unsafe file restore path.'));
                    }
                }
                return $this->result(false, 0, array('Prepared archive could not be validated.'));
            }
        }

        $engine_dir = rtrim($engine_dir, '/\\');
        $wordpress_root = dirname($engine_dir);
        $staging_dir = $engine_dir . '/file-restore-staging/restore-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        if (!is_dir($staging_dir) && !mkdir($staging_dir, 0777, true) && !is_dir($staging_dir)) {
            return $this->result(false, 0, array('Unable to create file restore staging directory.'));
        }

        $reader = (new PackageReaderFactory())->create($archive_path);
        $files = array();
        foreach ($reader->entries() as $name) {
            if (substr($name, -1) === '/' || strpos($name, 'files/') !== 0) {
                continue;
            }
            if (!$this->archive_validator->isSafePath($name)) {
                return $this->result(false, 0, array('Unsafe file restore path.'));
            }

            $relative = substr($name, 6);
            if ($relative === '' || !$this->archive_validator->isSafePath($relative)) {
                return $this->result(false, 0, array('Unsafe file restore path.'));
            }
            if ($relative === 'wp-config.php') {
                continue;
            }

            $stage_path = $staging_dir . '/' . $relative;
            if (!$this->ensureDirectory(dirname($stage_path))) {
                return $this->result(false, 0, array('Unable to create file restore staging directory.'));
            }

            if (!$reader->copyToFile($name, $stage_path)) {
                return $this->result(false, 0, array('Unable to stage restore file.'));
            }

            $files[$relative] = $stage_path;
        }

        if ($files === array()) {
            return $this->result(false, 0, array('No restorable files were found in the prepared archive.'));
        }

        $file_replacement = $this->replaceUrlsInTextFiles($files, $config);
        $this->prepareRestoreFiles($files, $config);
        foreach ($files as $relative => $stage_path) {
            $destination = $wordpress_root . '/' . $relative;
            if (!$this->ensureDirectory(dirname($destination)) || !copy($stage_path, $destination)) {
                return $this->result(false, 0, array('Unable to restore file.'));
            }
        }

        $config['file_restore_completed'] = true;
        $config['file_restore_completed_at'] = gmdate('c');
        $config['file_restore_file_count'] = count($files);
        $config['file_restore_skipped_files'] = array('wp-config.php');
        $config['file_url_replacement_file_count'] = $file_replacement['file_count'];
        $config['file_url_replacement_count'] = $file_replacement['replacement_count'];
        $backup_table_cleanup = $this->backup_table_cleaner->clean($engine_dir, $config);
        $config['database_backup_tables_cleaned'] = (bool) $backup_table_cleanup['cleaned'];
        $config['database_backup_tables_cleaned_count'] = (int) $backup_table_cleanup['table_count'];
        $config['database_backup_tables_cleanup_warnings'] = $backup_table_cleanup['warnings'];
        $config['locked'] = true;

        if (file_put_contents($engine_dir . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n") === false) {
            return $this->result(false, count($files), array('Unable to update installer config.'));
        }

        return $this->result(true, count($files), array());
    }

    /**
     * @param array<string,string> $files
     */
    private function prepareRestoreFiles(array $files, array $config): void
    {
        $destination_path = $this->destinationPathFromConfig($config);

        foreach ($files as $relative => $path) {
            if ($relative !== '.htaccess') {
                continue;
            }

            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                continue;
            }

            $updated = $this->removeManagedHtaccessBlocks($contents);
            $updated = $this->updateWordPressHtaccessPaths($updated, $destination_path);
            if ($updated !== $contents) {
                file_put_contents($path, $updated);
            }
        }
    }

    private function removeManagedHtaccessBlocks(string $contents): string
    {
        $markers = array(
            'rlrssslReallySimpleSSL',
            'Really Simple SSL',
            'Really Simple Auto Prepend File',
            'Really Simple Security',
        );
        $changed = false;

        foreach ($markers as $marker) {
            $quoted = preg_quote($marker, '#');
            $updated = (string) preg_replace(
                '#(?:^|\R)\#\s*BEGIN\s+' . $quoted . '[^\r\n]*(?:\R.*?)*?\R\#\s*END\s+' . $quoted . '[^\r\n]*(?:\R|$)#i',
                "\n",
                $contents
            );
            if ($updated !== $contents) {
                $changed = true;
                $contents = $updated;
            }
        }

        return $changed ? trim($contents) . "\n" : $contents;
    }

    private function updateWordPressHtaccessPaths(string $contents, string $destination_path): string
    {
        if ($destination_path === '') {
            return $contents;
        }

        $destination_base = rtrim($destination_path, '/') . '/';
        $destination_index = $destination_base . 'index.php';

        $updated = (string) preg_replace(
            '#^RewriteBase\s+/\S*#m',
            'RewriteBase ' . $destination_base,
            $contents
        );
        $updated = (string) preg_replace(
            '#^RewriteRule\s+\.\s+/\S*index\.php\s+\[L\]#m',
            'RewriteRule . ' . $destination_index . ' [L]',
            $updated
        );

        return $updated;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function destinationPathFromConfig(array $config): string
    {
        $plan = isset($config['database_url_replacement_plan']) && is_array($config['database_url_replacement_plan'])
            ? $config['database_url_replacement_plan']
            : array();
        $destination_url = isset($plan['destination_url']) && is_scalar($plan['destination_url']) ? (string) $plan['destination_url'] : '';
        if ($destination_url === '') {
            return '';
        }

        $path = parse_url($destination_url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        return '/' . trim($path, '/') . '/';
    }

    private function ensureDirectory(string $directory): bool
    {
        return is_dir($directory) || mkdir($directory, 0777, true) || is_dir($directory);
    }

    /**
     * @param array<string,string> $files
     * @param array<string,mixed> $config
     * @return array{file_count:int,replacement_count:int}
     */
    private function replaceUrlsInTextFiles(array $files, array $config): array
    {
        $plan = isset($config['database_url_replacement_plan']) && is_array($config['database_url_replacement_plan'])
            ? $config['database_url_replacement_plan']
            : array();
        $source_urls = isset($plan['source_urls']) && is_array($plan['source_urls']) ? $this->stringList($plan['source_urls']) : array();
        $destination_url = isset($plan['destination_url']) && is_scalar($plan['destination_url']) ? (string) $plan['destination_url'] : '';
        if ($source_urls === array() || $destination_url === '' || !class_exists(UrlReplacementEngine::class)) {
            return array('file_count' => 0, 'replacement_count' => 0);
        }

        $engine = new UrlReplacementEngine();
        $file_count = 0;
        $replacement_count = 0;
        foreach ($files as $relative => $path) {
            if (!$this->isTextFile($relative, $path)) {
                continue;
            }

            $contents = file_get_contents($path);
            if (!is_string($contents) || strpos($contents, "\0") !== false) {
                continue;
            }

            $updated = $contents;
            $file_replacements = 0;
            foreach ($source_urls as $source_url) {
                $file_replacements += $engine->countReplacements($updated, $source_url);
                $updated = $engine->replace($updated, $source_url, $destination_url);
            }

            if ($updated === $contents) {
                continue;
            }

            if (file_put_contents($path, $updated) !== false) {
                ++$file_count;
                $replacement_count += $file_replacements;
            }
        }

        return array('file_count' => $file_count, 'replacement_count' => $replacement_count);
    }

    private function isTextFile(string $relative, string $path): bool
    {
        $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
        $text_extensions = array(
            'css',
            'htaccess',
            'htm',
            'html',
            'js',
            'json',
            'md',
            'php',
            'svg',
            'txt',
            'xml',
            'yml',
            'yaml',
        );

        return in_array($extension, $text_extensions, true)
            && is_readable($path)
            && filesize($path) !== false
            && (int) filesize($path) <= 10485760;
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private function stringList($values): array
    {
        if (!is_array($values)) {
            return array();
        }

        $strings = array();
        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * @param list<string> $warnings
     * @return array{completed:bool,file_count:int,warnings:list<string>}
     */
    private function result(bool $completed, int $file_count, array $warnings): array
    {
        return array(
            'completed' => $completed,
            'file_count' => $file_count,
            'warnings' => $warnings,
        );
    }
}
