<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Installer staging writes controlled files outside WP_Filesystem prompts.

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

use RuntimeException;
use SuperSheepCopy\Jobs\Job;
use SuperSheepCopy\Jobs\JobRepositoryInterface;

final class InstallerPreparationManager implements InstallerPreparationManagerInterface
{
    private const ENGINE_DIR = 'ssc-restore-engine';

    private string $source_installer_directory;
    private string $wordpress_root;
    private string $restore_staging_directory;
    private string $site_url;
    private JobRepositoryInterface $jobs;

    public function __construct(
        string $source_installer_directory,
        string $wordpress_root,
        string $restore_staging_directory,
        string $site_url,
        JobRepositoryInterface $jobs
    ) {
        $this->source_installer_directory = rtrim($source_installer_directory, '/\\');
        $this->wordpress_root = rtrim($wordpress_root, '/\\');
        $this->restore_staging_directory = rtrim($restore_staging_directory, '/\\');
        $this->site_url = rtrim($site_url, '/');
        $this->jobs = $jobs;
    }

    public function prepare(string $restore_job_id): InstallerPreparationResult
    {
        $job = $this->jobs->find($restore_job_id);
        if (!$job instanceof Job) {
            throw new RuntimeException('Restore job was not found.');
        }

        if ($job->type() !== 'restore' || $job->state() !== Job::COMPLETED) {
            throw new RuntimeException('Restore job is not ready for installer preparation.');
        }

        $payload = $job->payload();
        $staged_archive = isset($payload['staged_archive']) ? (string) $payload['staged_archive'] : '';
        if (!$this->isSafeBasename($staged_archive)) {
            throw new RuntimeException('Restore archive basename is invalid.');
        }

        $archive_path = $this->restore_staging_directory . '/' . $staged_archive;
        if (!is_readable($archive_path)) {
            throw new RuntimeException('Staged restore archive is not readable.');
        }

        $this->deployInstaller();

        $token = bin2hex(random_bytes(32));
        $installer_url = $this->site_url . '/installer.php';
        $launch_url = $installer_url . '?token=' . rawurlencode($token);
        $source_site_url = isset($payload['source_site_url']) ? (string) $payload['source_site_url'] : '';
        $source_home_url = isset($payload['source_home_url']) ? (string) $payload['source_home_url'] : '';
        $active_theme = isset($payload['active_theme']) && is_scalar($payload['active_theme']) ? (string) $payload['active_theme'] : '';
        $active_plugins = isset($payload['active_plugins']) && is_array($payload['active_plugins']) ? array_values(array_map('strval', $payload['active_plugins'])) : array();
        $must_use_plugins = isset($payload['must_use_plugins']) && is_array($payload['must_use_plugins']) ? array_values(array_map('strval', $payload['must_use_plugins'])) : array();
        $source_table_prefix = isset($payload['source_table_prefix']) && is_scalar($payload['source_table_prefix']) ? (string) $payload['source_table_prefix'] : '';
        $archive_entry_count = isset($payload['archive_entry_count']) ? (int) $payload['archive_entry_count'] : 0;
        $database_entry_count = isset($payload['database_entry_count']) ? (int) $payload['database_entry_count'] : 0;
        $prepared_at = gmdate('c');

        $this->writeConfig(array(
            'restore_job_id' => $job->id(),
            'staged_archive_path' => $archive_path,
            'staged_archive_basename' => $staged_archive,
            'source_site_url' => $source_site_url,
            'source_home_url' => $source_home_url,
            'active_theme' => $active_theme,
            'active_plugins' => $active_plugins,
            'must_use_plugins' => $must_use_plugins,
            'source_table_prefix' => $source_table_prefix,
            'archive_validation_status' => 'valid',
            'archive_validation_errors' => array(),
            'archive_entry_count' => $archive_entry_count,
            'database_entry_count' => $database_entry_count,
            'token_hash' => password_hash($token, PASSWORD_DEFAULT),
            'token_created_at' => $prepared_at,
            'locked' => false,
        ));

        $payload['installer_prepared'] = true;
        $payload['installer_url'] = $installer_url;
        $payload['installer_engine_dir'] = self::ENGINE_DIR;
        $payload['installer_prepared_at'] = $prepared_at;
        unset($payload['installer_token'], $payload['token_hash']);
        $this->jobs->save(new Job($job->id(), $job->type(), $job->state(), $payload));

        return new InstallerPreparationResult(
            $job->id(),
            $installer_url,
            $launch_url,
            $token,
            self::ENGINE_DIR,
            $staged_archive,
            $source_site_url,
            $source_home_url
        );
    }

    private function deployInstaller(): void
    {
        $source_installer = $this->source_installer_directory . '/installer.php';
        $source_engine = $this->source_installer_directory . '/restore-engine';
        if (!is_readable($source_installer) || !is_dir($source_engine)) {
            throw new RuntimeException('Installer source files are missing.');
        }

        if (!is_dir($this->wordpress_root) && !mkdir($this->wordpress_root, 0777, true) && !is_dir($this->wordpress_root)) {
            throw new RuntimeException('Unable to create WordPress root directory.');
        }

        $target_installer = $this->wordpress_root . '/installer.php';
        $target_engine = $this->wordpress_root . '/' . self::ENGINE_DIR;
        $this->ensureDirectory($target_engine);
        $this->copyDirectory($source_engine, $target_engine);
        $this->copySharedDependencies($target_engine);

        $contents = file_get_contents($source_installer);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read installer source.');
        }

        $contents = str_replace(
            array('/restore-engine/Bootstrap.php', '/restore-engine/config.php'),
            array('/' . self::ENGINE_DIR . '/Bootstrap.php', '/' . self::ENGINE_DIR . '/config.php'),
            $contents
        );
        if (file_put_contents($target_installer, $contents) === false) {
            throw new RuntimeException('Unable to write installer file.');
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeConfig(array $config): void
    {
        $path = $this->wordpress_root . '/' . self::ENGINE_DIR . '/config.php';
        $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write installer config.');
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $items = array_diff(scandir($source) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $source_path = $source . '/' . $item;
            $target_path = $target . '/' . $item;
            if (is_dir($source_path)) {
                $this->copyDirectory($source_path, $target_path);
                continue;
            }
            if (!copy($source_path, $target_path)) {
                throw new RuntimeException('Unable to copy installer engine file.');
            }
        }
    }

    private function copySharedDependencies(string $target_engine): void
    {
        $source_shared = dirname($this->source_installer_directory) . '/shared';
        if (!is_dir($source_shared)) {
            throw new RuntimeException('Shared installer dependencies are missing.');
        }

        $this->copyDirectory($source_shared, $target_engine . '/shared');
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create installer directory.');
        }
    }

    private function isSafeBasename(string $basename): bool
    {
        $lower = strtolower($basename);

        return $basename !== ''
            && basename($basename) === $basename
            && strpos($basename, "\0") === false
            && (
                substr($lower, -4) === '.zip'
                || substr($lower, -4) === '.tar'
                || substr($lower, -7) === '.tar.gz'
                || strpos($basename, '.') === false
            );
    }
}
