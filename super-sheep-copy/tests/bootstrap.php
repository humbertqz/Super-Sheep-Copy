<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefixes = array(
            'SuperSheepCopy\\Shared\\' => dirname(__DIR__) . '/shared/',
            'SuperSheepCopy\\' => dirname(__DIR__) . '/src/',
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

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/ssc-test-site/');
}

if (!defined('SUPER_SHEEP_COPY_VERSION')) {
    define('SUPER_SHEEP_COPY_VERSION', '0.1.1');
}

if (!defined('SUPER_SHEEP_COPY_DIR')) {
    define('SUPER_SHEEP_COPY_DIR', dirname(__DIR__) . '/');
}

if (!defined('SUPER_SHEEP_COPY_URL')) {
    define('SUPER_SHEEP_COPY_URL', 'https://example.com/wp-content/plugins/super-sheep-copy/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/ssc-test-content');
}

$GLOBALS['ssc_test_options'] = array();
$GLOBALS['ssc_test_redirect'] = null;
$GLOBALS['ssc_test_current_user_can'] = true;
$GLOBALS['ssc_test_nonce_valid'] = true;
$GLOBALS['ssc_test_actions'] = array();
$GLOBALS['ssc_test_json_response'] = null;
$GLOBALS['ssc_test_site_url'] = 'https://example.com';
$GLOBALS['ssc_test_home_url'] = 'https://example.com';
$GLOBALS['ssc_test_bloginfo_version'] = '6.5';
$GLOBALS['ssc_test_max_upload_size'] = 67108864;
$GLOBALS['ssc_test_is_multisite'] = false;
$GLOBALS['ssc_test_stylesheet'] = 'twentytwentyfour';
$GLOBALS['ssc_test_mu_plugins'] = array();
$GLOBALS['ssc_test_deleted_files'] = array();
$GLOBALS['ssc_test_scheduled_events'] = array();

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, int $decimals = 0): string
    {
        return number_format((float) $number, $decimals);
    }
}

if (!function_exists('wp_max_upload_size')) {
    function wp_max_upload_size(): int
    {
        return (int) $GLOBALS['ssc_test_max_upload_size'];
    }
}

if (!function_exists('size_format')) {
    function size_format($bytes, int $decimals = 0): string
    {
        $bytes = (float) $bytes;
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $unit_index = 0;

        while ($bytes >= 1024 && $unit_index < count($units) - 1) {
            $bytes /= 1024;
            $unit_index++;
        }

        return number_format($bytes, $decimals) . ' ' . $units[$unit_index];
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return (bool) $GLOBALS['ssc_test_current_user_can'];
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string $message): void
    {
        throw new RuntimeException($message);
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['ssc_test_actions'][$hook_name][] = compact('callback', 'priority', 'accepted_args');
        return true;
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, bool $display = true): string
    {
        $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text = null): void
    {
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html((string) ($text ?: 'Save Changes')) . '</button></p>';
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, ?int $status_code = null): void
    {
        $GLOBALS['ssc_test_json_response'] = array(
            'success' => true,
            'data' => $data,
            'status_code' => $status_code,
        );

        throw new RuntimeException('wp_send_json_success');
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, ?int $status_code = null): void
    {
        $GLOBALS['ssc_test_json_response'] = array(
            'success' => false,
            'data' => $data,
            'status_code' => $status_code,
        );

        throw new RuntimeException('wp_send_json_error');
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action)
    {
        return (bool) $GLOBALS['ssc_test_nonce_valid'] ? 1 : false;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action, string $name, bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce" />';
        if ($display) {
            echo $field;
        }

        return $field;
    }
}

if (!function_exists('site_url')) {
    function site_url(): string
    {
        return (string) $GLOBALS['ssc_test_site_url'];
    }
}

if (!function_exists('home_url')) {
    function home_url(): string
    {
        return (string) $GLOBALS['ssc_test_home_url'];
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        return $show === 'version' ? (string) $GLOBALS['ssc_test_bloginfo_version'] : '';
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return (bool) $GLOBALS['ssc_test_is_multisite'];
    }
}

if (!function_exists('get_stylesheet')) {
    function get_stylesheet(): string
    {
        return (string) $GLOBALS['ssc_test_stylesheet'];
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['ssc_test_options']) ? $GLOBALS['ssc_test_options'][$name] : $default;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $name, $value = '', string $deprecated = '', $autoload = null): bool
    {
        $GLOBALS['ssc_test_add_option_calls'][] = array($name, $value, $deprecated, $autoload);
        if (array_key_exists($name, $GLOBALS['ssc_test_options'])) {
            return false;
        }

        $GLOBALS['ssc_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('maybe_serialize')) {
    function maybe_serialize($value): string
    {
        return is_array($value) || is_object($value) ? serialize($value) : (string) $value;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        $GLOBALS['ssc_test_cache_deletes'][] = array($key, $group);

        return true;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, $value, bool $autoload = true): bool
    {
        $GLOBALS['ssc_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        if (!array_key_exists($name, $GLOBALS['ssc_test_options'])) {
            return false;
        }

        unset($GLOBALS['ssc_test_options'][$name]);

        return true;
    }
}

if (!function_exists('get_mu_plugins')) {
    function get_mu_plugins(): array
    {
        return (array) $GLOBALS['ssc_test_mu_plugins'];
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . http_build_query($args);
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        $GLOBALS['ssc_test_redirect'] = $location;
        return true;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = array(), bool $wp_error = false): bool
    {
        $GLOBALS['ssc_test_scheduled_events'][$hook] = array(
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
        );

        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = array())
    {
        unset($args);

        return isset($GLOBALS['ssc_test_scheduled_events'][$hook]['timestamp'])
            ? $GLOBALS['ssc_test_scheduled_events'][$hook]['timestamp']
            : false;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = array(), bool $wp_error = false): int
    {
        unset($args, $wp_error);

        if (!isset($GLOBALS['ssc_test_scheduled_events'][$hook])) {
            return 0;
        }

        unset($GLOBALS['ssc_test_scheduled_events'][$hook]);

        return 1;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, bool $create_dir = true): array
    {
        return array('basedir' => sys_get_temp_dir() . '/ssc-test-uploads');
    }
}

if (!function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): bool
    {
        $GLOBALS['ssc_test_deleted_files'][] = $file;
        return is_file($file) || is_link($file) ? unlink($file) : false;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0777, true) || is_dir($target);
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        $callback = '',
        string $icon_url = '',
        $position = null
    ): string {
        $hook = 'toplevel_page_' . $menu_slug;
        $GLOBALS['ssc_test_menu_pages'][] = compact('page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'icon_url', 'position', 'hook');
        if ($callback !== '') {
            $GLOBALS['ssc_test_admin_page_callbacks'][$hook][] = $callback;
        }

        return $hook;
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page(
        string $parent_slug,
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        $callback = '',
        $position = null
    ): string {
        $hook = $parent_slug === $menu_slug ? 'toplevel_page_' . $menu_slug : $parent_slug . '_page_' . $menu_slug;
        $GLOBALS['ssc_test_submenu_pages'][] = compact('parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'position', 'hook');
        if ($callback !== '') {
            $GLOBALS['ssc_test_admin_page_callbacks'][$hook][] = $callback;
        }

        return $hook;
    }
}
