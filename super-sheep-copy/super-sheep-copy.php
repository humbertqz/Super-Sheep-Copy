<?php
/**
 * Plugin Name: Super Sheep Copy
 * Description: Full-site backup and restore tooling scaffold.
 * Version: 0.1.1
 * Author: Humberto Quezada
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: super-sheep-copy
 */

/*
 * Copyright (C) 2026 Super Sheep Copy
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SUPER_SHEEP_COPY_VERSION', '0.1.1');
define('SUPER_SHEEP_COPY_FILE', __FILE__);
define('SUPER_SHEEP_COPY_DIR', plugin_dir_path(__FILE__));
define('SUPER_SHEEP_COPY_URL', plugin_dir_url(__FILE__));

$super_sheep_copy_autoload = SUPER_SHEEP_COPY_DIR . 'vendor/autoload.php';
if (is_readable($super_sheep_copy_autoload)) {
    require_once $super_sheep_copy_autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefixes = array(
            'SuperSheepCopy\\Shared\\' => SUPER_SHEEP_COPY_DIR . 'shared/',
            'SuperSheepCopy\\' => SUPER_SHEEP_COPY_DIR . 'src/',
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

register_activation_hook(__FILE__, array(\SuperSheepCopy\Plugin::class, 'activate'));
register_deactivation_hook(__FILE__, array(\SuperSheepCopy\Plugin::class, 'deactivate'));

add_action('plugins_loaded', static function (): void {
    \SuperSheepCopy\Plugin::instance()->boot();
});
