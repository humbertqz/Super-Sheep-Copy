<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to included view scope.
/**
 * @var array<string, array{label:string,value:string,status:string}> $environment
 * @var \SuperSheepCopy\Jobs\Job|null $current_job
 * @var string[] $backup_settings_summary
 * @var list<\SuperSheepCopy\Jobs\Job> $jobs
 * @var array<string, string> $job_created_date_labels
 * @var array<string, mixed> $manifest_preview
 * @var string $nonce_field
 * @var string $status
 */
defined('ABSPATH') || exit;
$running_states = array(
    \SuperSheepCopy\Jobs\Job::CREATED => true,
    \SuperSheepCopy\Jobs\Job::EXPORTING_DATABASE => true,
    \SuperSheepCopy\Jobs\Job::SCANNING_FILES => true,
    \SuperSheepCopy\Jobs\Job::PACKAGING_ARCHIVE => true,
);
$performance_metrics = new \SuperSheepCopy\Backup\BackupPerformanceMetrics();
$has_running_job = false;
foreach ($jobs as $job) {
    if (isset($running_states[$job->state()])) {
        $has_running_job = true;
    }
}
?>
<?php if ($status === 'backup_queued') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-success">
        <p><?php echo esc_html__('Backup queued. Keep this page open while background steps finish.', 'super-sheep-copy'); ?></p>
    </div>
<?php elseif ($status === 'backup_failed') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-error">
        <p><?php echo esc_html__('Backup creation failed. Check the latest job state and server logs.', 'super-sheep-copy'); ?></p>
    </div>
<?php elseif ($status === 'job_deleted') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-success">
        <p><?php echo esc_html__('Job deleted.', 'super-sheep-copy'); ?></p>
    </div>
<?php elseif ($status === 'download_failed') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-error">
        <p><?php echo esc_html__('Backup download is not available. Folder package backups stay on the server when ZIP is unavailable.', 'super-sheep-copy'); ?></p>
    </div>
<?php endif; ?>
<div class="wrap super-sheep-copy">
    <?php
    $page_title = __('Super Sheep Copy Backup', 'super-sheep-copy');
    $page_subtitle = __('Create and monitor full-site backup packages.', 'super-sheep-copy');
    include SUPER_SHEEP_COPY_DIR . 'templates/partials/header.php';
    ?>

    <div class="super-sheep-copy-backup-dashboard">
        <section class="super-sheep-copy-backup-block super-sheep-copy-backup-block-primary">
            <div class="super-sheep-copy-backup-main">
                <div class="super-sheep-copy-backup-copy">
                    <h2><?php echo esc_html__('Create Backup', 'super-sheep-copy'); ?></h2>
                    <p><?php echo esc_html__('Create a full-site package. The backup runs in small background steps so the admin request stays responsive.', 'super-sheep-copy'); ?></p>
                </div>
                <form class="super-sheep-copy-backup-action" method="post">
                    <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <input type="hidden" name="super_sheep_copy_action" value="create_backup" />
                    <button class="button button-primary" type="submit"><?php echo esc_html__('Create Backup', 'super-sheep-copy'); ?></button>
                </form>
            </div>
            <?php if ($backup_settings_summary !== array()) : ?>
                <ul class="super-sheep-copy-settings-summary super-sheep-copy-settings-summary-compact">
                    <?php foreach ($backup_settings_summary as $summary_item) : ?>
                        <li><?php echo esc_html($summary_item); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="super-sheep-copy-backup-safety">
                <p><?php echo esc_html__('Backups contain sensitive site data including users, password hashes, orders, API keys, and private content. Store backup files securely.', 'super-sheep-copy'); ?></p>
            </div>
        </section>

    </div>

    <div class="super-sheep-copy-panel">
        <div class="super-sheep-copy-jobs-header">
            <h2><?php echo esc_html__('Jobs', 'super-sheep-copy'); ?></h2>
            <div class="super-sheep-copy-running-summary" data-super-sheep-copy-running-summary<?php echo $has_running_job ? '' : ' hidden'; ?>>
                <span class="super-sheep-copy-running-dot" aria-hidden="true"></span>
                <strong><?php echo esc_html__('Backup running', 'super-sheep-copy'); ?></strong>
                <span><?php echo esc_html__('Keep this page open while steps finish.', 'super-sheep-copy'); ?></span>
            </div>
        </div>
        <?php if ($jobs === array()) : ?>
            <p><?php echo esc_html__('No backup or restore jobs have been created.', 'super-sheep-copy'); ?></p>
        <?php else : ?>
            <table class="widefat striped super-sheep-copy-jobs-table">
                <thead>
                <tr>
                    <th><?php echo esc_html__('Job', 'super-sheep-copy'); ?></th>
                    <th><?php echo esc_html__('Created', 'super-sheep-copy'); ?></th>
                    <th><?php echo esc_html__('Status', 'super-sheep-copy'); ?></th>
                    <th><?php echo esc_html__('Progress', 'super-sheep-copy'); ?></th>
                    <th><?php echo esc_html__('Archive', 'super-sheep-copy'); ?></th>
                    <th><?php echo esc_html__('Actions', 'super-sheep-copy'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job) : ?>
                    <?php
                    $payload = $job->payload();
                    $created_date_label = isset($job_created_date_labels[$job->id()]) ? $job_created_date_labels[$job->id()] : '';
                    $is_running_job = isset($running_states[$job->state()]);
                    $progress_message = isset($payload['message']) && is_scalar($payload['message']) ? (string) $payload['message'] : '';
                    $performance_summary = $performance_metrics->summary($payload, $job->state());
                    $package_format = isset($payload['package_format']) && is_scalar($payload['package_format']) ? (string) $payload['package_format'] : '';
                    $is_directory_package = $package_format === 'directory';
                    $has_downloadable_archive = $job->state() === \SuperSheepCopy\Jobs\Job::COMPLETED && isset($payload['archive_path']) && !$is_directory_package;
                    $archive_size = isset($payload['archive_size']) ? (int) $payload['archive_size'] : 0;
                    if ($archive_size >= 1073741824) {
                        $archive_size_label = number_format_i18n($archive_size / 1073741824, 1) . ' GB';
                    } elseif ($archive_size >= 1048576) {
                        $archive_size_label = number_format_i18n($archive_size / 1048576, 1) . ' MB';
                    } elseif ($archive_size >= 1024) {
                        $archive_size_label = number_format_i18n($archive_size / 1024, 1) . ' KB';
                    } elseif ($archive_size > 0) {
                        $archive_size_label = number_format_i18n($archive_size) . ' B';
                    } else {
                        $archive_size_label = '';
                    }
                    $validation_status = isset($payload['archive_validation_status']) && is_scalar($payload['archive_validation_status']) ? (string) $payload['archive_validation_status'] : '';
                    if ($validation_status === 'valid') {
                        $validation_label = esc_html__('Valid', 'super-sheep-copy');
                    } elseif ($validation_status === 'invalid') {
                        $validation_errors = isset($payload['archive_validation_errors']) && is_array($payload['archive_validation_errors']) ? implode(' ', array_map('strval', $payload['archive_validation_errors'])) : '';
                        $validation_label = trim(esc_html__('Invalid', 'super-sheep-copy') . ' ' . $validation_errors);
                    } else {
                        $validation_label = '';
                    }
                    ?>
                    <tr class="super-sheep-copy-job-row<?php echo $is_running_job ? ' is-running' : ''; ?>" data-super-sheep-copy-job-id="<?php echo esc_attr($job->id()); ?>" data-super-sheep-copy-job-state="<?php echo esc_attr($job->state()); ?>">
                        <td>
                            <strong><?php echo esc_html($job->id()); ?></strong>
                            <small><?php echo esc_html($job->type()); ?></small>
                        </td>
                        <td>
                            <?php if ($created_date_label !== '') : ?>
                                <span class="super-sheep-copy-job-date"><?php echo esc_html($created_date_label); ?></span>
                            <?php else : ?>
                                <span class="super-sheep-copy-muted"><?php echo esc_html__('Unknown', 'super-sheep-copy'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="super-sheep-copy-status-pill" data-super-sheep-copy-job-state-label><?php echo esc_html($job->state()); ?></span>
                        </td>
                        <td>
                            <div class="super-sheep-copy-progress-stack">
                                <span data-super-sheep-copy-job-progress-message><?php echo esc_html($progress_message); ?></span>
                                <?php if ($performance_summary !== '') : ?>
                                    <small><?php echo esc_html($performance_summary); ?></small>
                                <?php endif; ?>
                                <div class="super-sheep-copy-progress-bar" data-super-sheep-copy-progress-bar<?php echo $is_running_job ? '' : ' hidden'; ?> role="progressbar" aria-label="<?php echo esc_attr(__('Backup step running', 'super-sheep-copy')); ?>">
                                    <span></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($archive_size_label !== '') : ?>
                                <strong><?php echo esc_html($archive_size_label); ?></strong>
                            <?php else : ?>
                                <span class="super-sheep-copy-muted"><?php echo esc_html__('Not ready', 'super-sheep-copy'); ?></span>
                            <?php endif; ?>
                            <?php if ($validation_label !== '') : ?>
                                <small><?php echo esc_html($validation_label); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="super-sheep-copy-job-actions">
                            <?php if ($job->type() === 'backup') : ?>
                                <?php if ($is_directory_package && $job->state() === \SuperSheepCopy\Jobs\Job::COMPLETED) : ?>
                                    <span class="super-sheep-copy-muted"><?php echo esc_html__('Folder package on server', 'super-sheep-copy'); ?></span>
                                <?php else : ?>
                                    <form method="post" data-super-sheep-copy-download-job="<?php echo esc_attr($job->id()); ?>"<?php echo $has_downloadable_archive ? '' : ' hidden'; ?>>
                                        <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <input type="hidden" name="super_sheep_copy_action" value="download_backup" />
                                        <input type="hidden" name="job_id" value="<?php echo esc_attr($job->id()); ?>" />
                                        <button class="button button-primary super-sheep-copy-icon-button" type="submit" aria-label="<?php echo esc_attr(__('Download backup', 'super-sheep-copy')); ?>" title="<?php echo esc_attr(__('Download backup', 'super-sheep-copy')); ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-cloud-download" aria-hidden="true" focusable="false">
                                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                <path d="M19 18a3.5 3.5 0 0 0 0 -7h-1a5 4.5 0 0 0 -11 -2a4.6 4.4 0 0 0 -2.1 8.4" />
                                                <path d="M12 13l0 9" />
                                                <path d="M9 19l3 3l3 -3" />
                                            </svg>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($job->state() !== \SuperSheepCopy\Jobs\Job::COMPLETED) : ?>
                                <button
                                    class="button"
                                    type="button"
                                    data-super-sheep-copy-retry-job="<?php echo esc_attr($job->id()); ?>"
                                    <?php if ($job->state() !== \SuperSheepCopy\Jobs\Job::FAILED) : ?>
                                        style="display:none"
                                    <?php endif; ?>
                                ><?php echo esc_html__('Retry / Continue backup', 'super-sheep-copy'); ?></button>
                            <?php endif; ?>
                            <form method="post" data-super-sheep-copy-delete-job>
                                <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <input type="hidden" name="super_sheep_copy_action" value="delete_job" />
                                <input type="hidden" name="job_id" value="<?php echo esc_attr($job->id()); ?>" />
                                <button class="button super-sheep-copy-icon-button" type="submit" aria-label="<?php echo esc_attr(__('Delete', 'super-sheep-copy')); ?>" title="<?php echo esc_attr(__('Delete', 'super-sheep-copy')); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-trash" aria-hidden="true" focusable="false">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                        <path d="M4 7l16 0" />
                                        <path d="M10 11l0 6" />
                                        <path d="M14 11l0 6" />
                                        <path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" />
                                        <path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" />
                                    </svg>
                                </button>
                            </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <section class="super-sheep-copy-backup-block super-sheep-copy-backup-details-block">
        <h2><?php echo esc_html__('Backup Details', 'super-sheep-copy'); ?></h2>
        <div class="super-sheep-copy-detail-grid">
            <details class="super-sheep-copy-workflow-details">
                <summary><?php echo esc_html__('Manifest preview', 'super-sheep-copy'); ?></summary>
                <table class="widefat striped">
                    <tbody>
                    <?php foreach ($manifest_preview as $key => $value) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html((string) $key); ?></th>
                            <td><?php echo esc_html(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>

            <details class="super-sheep-copy-workflow-details">
                <summary><?php echo esc_html__('Environment checks', 'super-sheep-copy'); ?></summary>
                <table class="widefat striped">
                    <tbody>
                    <?php foreach ($environment as $check) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($check['label']); ?></th>
                            <td><?php echo esc_html($check['value']); ?></td>
                            <td><?php echo esc_html($check['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </div>
    </section>

    <?php include SUPER_SHEEP_COPY_DIR . 'templates/partials/footer.php'; ?>
</div>
