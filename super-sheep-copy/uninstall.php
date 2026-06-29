<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$super_sheep_copy_autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($super_sheep_copy_autoload)) {
    require_once $super_sheep_copy_autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefixes = array(
            'SuperSheepCopy\\Shared\\' => __DIR__ . '/shared/',
            'SuperSheepCopy\\' => __DIR__ . '/src/',
        );

        foreach ($prefixes as $prefix => $base_dir) {
            $length = strlen($prefix);
            if (strncmp($prefix, $class, $length) !== 0) {
                continue;
            }

            $relative = substr($class, $length);
            $file = $base_dir . str_replace('\\', '/', $relative) . '.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
    });
}

\SuperSheepCopy\Plugin::uninstall();
