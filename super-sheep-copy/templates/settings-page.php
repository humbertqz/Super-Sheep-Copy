<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to included view scope.
defined('ABSPATH') || exit;
?>
<?php if ($status === 'settings_saved') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-success">
        <p><?php echo esc_html__('Settings saved.', 'super-sheep-copy'); ?></p>
    </div>
<?php elseif ($status === 'settings_failed') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-error">
        <p><?php echo esc_html__('Settings could not be saved.', 'super-sheep-copy'); ?></p>
    </div>
<?php elseif ($status === 'failed_jobs_cleaned') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-success">
        <p><?php echo esc_html__('Failed backup files cleaned.', 'super-sheep-copy'); ?></p>
    </div>
<?php elseif ($status === 'failed_jobs_cleanup_failed') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-error">
        <p><?php echo esc_html__('Failed backup files could not be cleaned.', 'super-sheep-copy'); ?></p>
    </div>
<?php endif; ?>
<div class="wrap super-sheep-copy">
    <?php
    $page_title = __('Super Sheep Copy Settings', 'super-sheep-copy');
    $page_subtitle = __('Review plugin paths and backup configuration.', 'super-sheep-copy');
    include SUPER_SHEEP_COPY_DIR . 'templates/partials/header.php';
    ?>

    <form method="post">
        <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <div class="super-sheep-copy-panel">
            <h2><?php echo esc_html__('Backup Defaults', 'super-sheep-copy'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Cache folders', 'super-sheep-copy'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="super_sheep_copy_settings[exclude_cache_files]" value="0" />
                            <input type="checkbox" name="super_sheep_copy_settings[exclude_cache_files]" value="1" <?php checked($settings->excludeCacheFiles()); ?> />
                            <?php echo esc_html__('Exclude cache folders', 'super-sheep-copy'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Large files', 'super-sheep-copy'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="super_sheep_copy_settings[skip_large_files]" value="0" />
                            <input type="checkbox" name="super_sheep_copy_settings[skip_large_files]" value="1" <?php checked($settings->skipLargeFiles()); ?> />
                            <?php echo esc_html__('Skip very large files', 'super-sheep-copy'); ?>
                        </label>
                        <input type="number" min="10" max="2048" name="super_sheep_copy_settings[large_file_limit_mb]" value="<?php echo esc_attr((string) $settings->largeFileLimitMb()); ?>" class="small-text" />
                        <span><?php echo esc_html__('MB', 'super-sheep-copy'); ?></span>
                        <p class="description"><?php echo esc_html__('Large media, archives, and logs can make backups fail on shared hosting.', 'super-sheep-copy'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Retention', 'super-sheep-copy'); ?></th>
                    <td>
                        <input type="number" min="1" max="20" name="super_sheep_copy_settings[retention_count]" value="<?php echo esc_attr((string) $settings->retentionCount()); ?>" class="small-text" />
                        <span><?php echo esc_html__('successful backups', 'super-sheep-copy'); ?></span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="super-sheep-copy-panel">
            <h2><?php echo esc_html__('Automatic Backups', 'super-sheep-copy'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Schedule', 'super-sheep-copy'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="super_sheep_copy_schedule[enabled]" value="0" />
                            <input type="checkbox" name="super_sheep_copy_schedule[enabled]" value="1" <?php checked($schedule_settings->enabled()); ?> />
                            <?php echo esc_html__('Enable scheduled backups', 'super-sheep-copy'); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html__('Scheduled backups use WP-Cron and may run late on sites with little traffic. For reliable timing, configure a server cron job to call wp-cron.php regularly.', 'super-sheep-copy'); ?>
                        </p>
                        <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
                            <div class="notice notice-warning inline">
                                <p><?php echo esc_html__('WP-Cron is disabled. Scheduled backups require a server cron job that calls wp-cron.php.', 'super-sheep-copy'); ?></p>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Frequency', 'super-sheep-copy'); ?></th>
                    <td>
                        <select name="super_sheep_copy_schedule[frequency]">
                            <option value="daily"<?php echo $schedule_settings->frequency() === 'daily' ? ' selected' : ''; ?>><?php echo esc_html__('Daily', 'super-sheep-copy'); ?></option>
                            <option value="weekly"<?php echo $schedule_settings->frequency() === 'weekly' ? ' selected' : ''; ?>><?php echo esc_html__('Weekly', 'super-sheep-copy'); ?></option>
                            <option value="monthly"<?php echo $schedule_settings->frequency() === 'monthly' ? ' selected' : ''; ?>><?php echo esc_html__('Monthly', 'super-sheep-copy'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Time', 'super-sheep-copy'); ?></th>
                    <td>
                        <input type="time" name="super_sheep_copy_schedule[time_of_day]" value="<?php echo esc_attr($schedule_settings->timeOfDay()); ?>" />
                        <p class="description"><?php echo esc_html__('Weekly backups run on Monday. Monthly backups run on the 1st.', 'super-sheep-copy'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Next run', 'super-sheep-copy'); ?></th>
                    <td><?php echo esc_html($next_run_label); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Last scheduled run', 'super-sheep-copy'); ?></th>
                    <td>
                        <?php
                        $last_schedule_run = $schedule_settings->lastStatus() !== ''
                            ? $schedule_settings->lastStatus() . ': ' . $schedule_settings->lastMessage()
                            : __('No scheduled backup has run yet.', 'super-sheep-copy');
                        echo esc_html($last_schedule_run);
                        ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="super-sheep-copy-panel">
            <h2><?php echo esc_html__('Storage & Cleanup', 'super-sheep-copy'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Backup storage', 'super-sheep-copy'); ?></th>
                    <td><input type="text" class="regular-text" value="<?php echo esc_attr($backup_storage_path); ?>" readonly></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Current storage used', 'super-sheep-copy'); ?></th>
                    <td><?php echo esc_html($backup_storage_used); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Failed backup files', 'super-sheep-copy'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="super_sheep_copy_settings[auto_clean_failed_jobs]" value="0" />
                            <input type="checkbox" name="super_sheep_copy_settings[auto_clean_failed_jobs]" value="1" <?php checked($settings->autoCleanFailedJobs()); ?> />
                            <?php echo esc_html__('Auto-clean failed backup files after 24 hours', 'super-sheep-copy'); ?>
                        </label>
                    </td>
                </tr>
            </table>
        </div>

        <div class="super-sheep-copy-panel">
            <h2><?php echo esc_html__('Diagnostics', 'super-sheep-copy'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Debug logging', 'super-sheep-copy'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="super_sheep_copy_settings[debug_logging]" value="0" />
                            <input type="checkbox" name="super_sheep_copy_settings[debug_logging]" value="1" <?php checked($settings->debugLogging()); ?> />
                            <?php echo esc_html__('Enable debug logging', 'super-sheep-copy'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Last backup', 'super-sheep-copy'); ?></th>
                    <td><?php echo esc_html($last_backup_summary); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Diagnostic report', 'super-sheep-copy'); ?></th>
                    <td>
                        <button class="button" type="submit" name="super_sheep_copy_action" value="download_diagnostics"><?php echo esc_html__('Download diagnostic report', 'super-sheep-copy'); ?></button>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button class="button button-primary" type="submit" name="super_sheep_copy_action" value="save_settings"><?php echo esc_html__('Save Settings', 'super-sheep-copy'); ?></button>
        </p>
    </form>

    <form method="post">
        <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <input type="hidden" name="super_sheep_copy_action" value="clean_failed_jobs" />
        <button class="button" type="submit"><?php echo esc_html__('Clean failed backup files', 'super-sheep-copy'); ?></button>
    </form>

    <?php include SUPER_SHEEP_COPY_DIR . 'templates/partials/footer.php'; ?>
</div>
