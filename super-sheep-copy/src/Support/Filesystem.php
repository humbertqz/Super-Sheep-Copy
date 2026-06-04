<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- Centralized filesystem wrapper. Call sites use this class so scanner exceptions stay local and documented.

declare(strict_types=1);

namespace SuperSheepCopy\Support;

final class Filesystem
{
    public static function makeDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        return wp_mkdir_p($directory) || is_dir($directory);
    }

    public static function deleteFile(string $path): bool
    {
        if (!is_file($path) && !is_link($path)) {
            return true;
        }

        return wp_delete_file($path);
    }

    public static function move(string $source, string $destination): bool
    {
        if (function_exists('WP_Filesystem')) {
            WP_Filesystem();
        }

        global $wp_filesystem;
        if (isset($wp_filesystem) && is_object($wp_filesystem) && method_exists($wp_filesystem, 'move')) {
            return (bool) $wp_filesystem->move($source, $destination, true);
        }

        return rename($source, $destination);
    }

    public static function removeDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                if (!self::removeDirectory($child)) {
                    return false;
                }
                continue;
            }

            if (!self::deleteFile($child)) {
                return false;
            }
        }

        return rmdir($path);
    }

    public static function ensureProtectedDirectory(string $directory): void
    {
        self::makeDirectory($directory);

        if (!is_dir($directory)) {
            return;
        }

        $index = trailingslashit($directory) . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $htaccess = trailingslashit($directory) . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }
}
