<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone installer runs before WordPress filesystem APIs are available.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class RollbackFileCollector
{
    /**
     * @return array{files:list<array{relative_path:string,rollback_path:string,sha256:string,size:int}>,warnings:list<string>}
     */
    public function collect(string $wordpress_root, string $rollback_directory): array
    {
        $source = rtrim($wordpress_root, '/\\') . '/wp-config.php';
        $target_relative = 'files/wp-config.php';
        $target = rtrim($rollback_directory, '/\\') . '/' . $target_relative;

        if (!is_readable($source)) {
            return array('files' => array(), 'warnings' => array('wp-config.php is not readable.'));
        }

        $target_dir = dirname($target);
        if (!is_dir($target_dir) && !mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
            return array('files' => array(), 'warnings' => array('Unable to create rollback files directory.'));
        }

        if (!copy($source, $target)) {
            return array('files' => array(), 'warnings' => array('Unable to copy wp-config.php.'));
        }

        return array(
            'files' => array(array(
                'relative_path' => 'wp-config.php',
                'rollback_path' => $target_relative,
                'sha256' => hash_file('sha256', $target) ?: '',
                'size' => (int) filesize($target),
            )),
            'warnings' => array(),
        );
    }
}
