<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Standalone installer cannot rely on WordPress logging APIs during confirmation writes.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class ConfirmationStore
{
    public function isConfirmed(array $config): bool
    {
        return !empty($config['restore_confirmed']);
    }

    /**
     * @param array<string,mixed> $config
     */
    public function confirm(string $engine_dir, array $config, string $typed_phrase, bool $checkbox_checked, bool $has_blocking_errors): bool
    {
        if (!$checkbox_checked || $typed_phrase !== 'RESTORE' || $has_blocking_errors) {
            return false;
        }

        $config['restore_confirmed'] = true;
        $config['restore_confirmed_at'] = gmdate('c');

        $path = rtrim($engine_dir, '/\\') . '/config.php';
        $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        return file_put_contents($path, $contents) !== false;
    }
}
