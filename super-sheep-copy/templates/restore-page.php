<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to included view scope.
/**
 * @var array<string, array{label:string,value:string,status:string}> $environment
 * @var string $installer_launch_url
 * @var string $max_upload_size_label
 * @var string $nonce_field
 * @var list<string> $staged_archives
 * @var \SuperSheepCopy\Jobs\Job|null $restore_job
 * @var string $restore_staging_directory
 * @var string $restore_error
 * @var string $status
 */
defined('ABSPATH') || exit;
?>
<div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-warning">
    <p><?php echo esc_html__('Restore starts here by validating a backup package and preparing the standalone installer. The installer handles confirmation, rollback, database restore, URL replacement, and file restore steps.', 'super-sheep-copy'); ?></p>
</div>
<?php if ($status === 'restore_prepared') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-success">
        <p><?php echo esc_html__('Restore package validated and staged successfully.', 'super-sheep-copy'); ?></p>
    </div>
<?php elseif ($status === 'backup_deleted') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-success">
        <p><?php echo esc_html__('Backup package deleted from the restore folder.', 'super-sheep-copy'); ?></p>
    </div>
<?php elseif ($status === 'backup_delete_failed') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-error">
        <p><?php echo esc_html__('Backup package deletion failed.', 'super-sheep-copy'); ?></p>
        <?php if ($restore_error !== '') : ?>
            <p><?php echo esc_html(sprintf(__('Reason: %s', 'super-sheep-copy'), $restore_error)); ?></p>
        <?php endif; ?>
    </div>
<?php elseif ($status === 'restore_failed') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-error">
        <p><?php echo esc_html__('Restore package preparation failed. Confirm the backup file is a valid Super Sheep Copy package.', 'super-sheep-copy'); ?></p>
        <?php if ($restore_error !== '') : ?>
            <p><?php echo esc_html(sprintf(__('Reason: %s', 'super-sheep-copy'), $restore_error)); ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($status === 'installer_prepared') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-success">
        <p><?php echo esc_html__('Standalone installer prepared. Use the launch link below before leaving this page.', 'super-sheep-copy'); ?></p>
    </div>
<?php elseif ($status === 'installer_failed') : ?>
    <div class="super-sheep-copy-admin-notice super-sheep-copy-admin-notice-error">
        <p><?php echo esc_html__('Installer preparation failed. Check server permissions and try again.', 'super-sheep-copy'); ?></p>
        <?php if ($restore_error !== '') : ?>
            <p><?php echo esc_html(sprintf(__('Reason: %s', 'super-sheep-copy'), $restore_error)); ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<div class="wrap super-sheep-copy">
    <?php
    $page_title = __('Super Sheep Copy Restore', 'super-sheep-copy');
    $page_subtitle = __('Validate backup packages and prepare restore tooling.', 'super-sheep-copy');
    include SUPER_SHEEP_COPY_DIR . 'templates/partials/header.php';
    $has_restore_job = $restore_job instanceof \SuperSheepCopy\Jobs\Job;
    $payload = $has_restore_job ? $restore_job->payload() : array();
    $installer_ready = $installer_launch_url !== '';
    $current_step = $installer_ready ? 4 : ($has_restore_job ? 3 : 1);
    $step_class = static function (int $step) use ($current_step): string {
        if ($step < $current_step) {
            return 'is-complete';
        }

        if ($step === $current_step) {
            return 'is-current';
        }

        return 'is-pending';
    };
    ?>
    <div class="super-sheep-copy-restore-workflow">
        <div class="super-sheep-copy-workflow-header">
            <div>
                <h2><?php echo esc_html__('Site Restore Workflow', 'super-sheep-copy'); ?></h2>
                <p><?php echo esc_html__('Follow each step in order. The destination site is not changed until you confirm inside the standalone installer.', 'super-sheep-copy'); ?></p>
            </div>
        </div>

        <div class="super-sheep-copy-workflow-steps">
            <section class="super-sheep-copy-workflow-step <?php echo esc_attr($step_class(1)); ?>">
                <div class="super-sheep-copy-workflow-number">1</div>
                <div class="super-sheep-copy-workflow-content">
                    <h3><?php echo esc_html__('Select backup', 'super-sheep-copy'); ?></h3>
                    <?php if ($has_restore_job) : ?>
                        <p><?php echo esc_html__('Backup selected and staged for validation.', 'super-sheep-copy'); ?></p>
                    <?php else : ?>
                        <p><?php echo esc_html__('Choose one backup source. Upload a smaller package here, or use a large package already placed in the restore folder.', 'super-sheep-copy'); ?></p>
                        <div class="super-sheep-copy-backup-source-grid">
                            <div class="super-sheep-copy-backup-source-card">
                                <div class="super-sheep-copy-backup-source-heading">
                                    <span class="super-sheep-copy-backup-source-kicker"><?php echo esc_html__('Option A', 'super-sheep-copy'); ?></span>
                                    <h4><?php echo esc_html__('Upload backup package', 'super-sheep-copy'); ?></h4>
                                </div>
                                <p><?php
                                /* translators: %s: Maximum upload size label. */
                                echo esc_html(sprintf(__('Best for backups up to %s.', 'super-sheep-copy'), $max_upload_size_label));
                                ?></p>
                                <form class="super-sheep-copy-source-form" method="post" enctype="multipart/form-data">
                                    <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <input type="hidden" name="super_sheep_copy_action" value="prepare_restore" />
                                    <input type="file" name="super_sheep_copy_restore_archive" accept=".zip,.tar,.tar.gz,application/zip,application/gzip,application/x-tar" />
                                    <button class="button button-primary" type="submit"><?php echo esc_html__('Validate Backup', 'super-sheep-copy'); ?></button>
                                </form>
                            </div>

                            <div class="super-sheep-copy-backup-source-card">
                                <div class="super-sheep-copy-backup-source-heading">
                                    <span class="super-sheep-copy-backup-source-kicker"><?php echo esc_html__('Option B', 'super-sheep-copy'); ?></span>
                                    <h4><?php echo esc_html__('Use package already in restore folder', 'super-sheep-copy'); ?></h4>
                                </div>
                                <p><?php echo esc_html__('Best for large backups uploaded by FTP/SFTP.', 'super-sheep-copy'); ?></p>
                                <p class="super-sheep-copy-restore-folder"><?php echo esc_html__('Restore folder:', 'super-sheep-copy'); ?> <code><?php echo esc_html($restore_staging_directory); ?></code></p>
                                <?php if ($staged_archives !== array()) : ?>
                                    <div class="super-sheep-copy-staged-archive-list">
                                <?php foreach ($staged_archives as $staged_archive) : ?>
                                            <div class="super-sheep-copy-staged-archive-row">
                                                <span class="super-sheep-copy-staged-archive-name"><?php echo esc_html($staged_archive); ?></span>
                                                <div class="super-sheep-copy-staged-archive-actions">
                                                    <form method="post">
                                                        <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                        <input type="hidden" name="super_sheep_copy_action" value="prepare_restore" />
                                                        <input type="hidden" name="super_sheep_copy_staged_archive" value="<?php echo esc_attr($staged_archive); ?>" />
                                                        <button class="button" type="submit"><?php echo esc_html__('Use this backup', 'super-sheep-copy'); ?></button>
                                                    </form>
                                                    <form method="post">
                                                        <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                        <input type="hidden" name="super_sheep_copy_action" value="delete_staged_archive" />
                                                        <input type="hidden" name="super_sheep_copy_staged_archive" value="<?php echo esc_attr($staged_archive); ?>" />
                                                        <button class="button button-link-delete" type="submit" aria-label="<?php
                                                        /* translators: %s: Staged backup archive filename. */
                                                        echo esc_attr(sprintf(__('Delete %s', 'super-sheep-copy'), $staged_archive));
                                                        ?>"><?php echo esc_html__('Delete', 'super-sheep-copy'); ?></button>
                                                    </form>
                                                </div>
                                            </div>
                                <?php endforeach; ?>
                                    </div>
                                <?php else : ?>
                                    <div class="super-sheep-copy-staged-archive-empty"><?php echo esc_html__('No FTP/SFTP uploaded backup packages found yet.', 'super-sheep-copy'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="super-sheep-copy-workflow-step <?php echo esc_attr($step_class(2)); ?>">
                <div class="super-sheep-copy-workflow-number">2</div>
                <div class="super-sheep-copy-workflow-content">
                    <h3><?php echo esc_html__('Validate backup', 'super-sheep-copy'); ?></h3>
                    <?php if ($has_restore_job) : ?>
                        <p><?php echo esc_html__('Backup is validated. Restore has not started.', 'super-sheep-copy'); ?></p>
                        <details class="super-sheep-copy-workflow-details" open>
                            <summary><?php echo esc_html__('Validated Backup Details', 'super-sheep-copy'); ?></summary>
                            <table class="widefat striped">
                                <tbody>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Source site URL', 'super-sheep-copy'); ?></th>
                                        <td><?php echo esc_html(isset($payload['source_site_url']) ? (string) $payload['source_site_url'] : ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Source home URL', 'super-sheep-copy'); ?></th>
                                        <td><?php echo esc_html(isset($payload['source_home_url']) ? (string) $payload['source_home_url'] : ''); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Archive entries', 'super-sheep-copy'); ?></th>
                                        <td><?php echo esc_html((string) (isset($payload['archive_entry_count']) ? (int) $payload['archive_entry_count'] : 0)); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Database entries', 'super-sheep-copy'); ?></th>
                                        <td><?php echo esc_html((string) (isset($payload['database_entry_count']) ? (int) $payload['database_entry_count'] : 0)); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </details>
                    <?php else : ?>
                        <p><?php echo esc_html__('Validation runs after you click Validate Backup.', 'super-sheep-copy'); ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="super-sheep-copy-workflow-step <?php echo esc_attr($step_class(3)); ?>">
                <div class="super-sheep-copy-workflow-number">3</div>
                <div class="super-sheep-copy-workflow-content">
                    <h3><?php echo esc_html__('Prepare installer', 'super-sheep-copy'); ?></h3>
                    <?php if ($installer_ready) : ?>
                        <p><?php echo esc_html__('Standalone installer is ready.', 'super-sheep-copy'); ?></p>
                    <?php elseif ($has_restore_job) : ?>
                        <p><?php echo esc_html__('Create the secure standalone installer before opening restore controls.', 'super-sheep-copy'); ?></p>
                        <form class="super-sheep-copy-workflow-action" method="post">
                            <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <input type="hidden" name="super_sheep_copy_action" value="prepare_installer" />
                            <input type="hidden" name="super_sheep_copy_restore_job_id" value="<?php echo esc_attr($restore_job->id()); ?>" />
                            <button type="submit" class="button button-primary button-hero"><?php echo esc_html__('Prepare Standalone Installer', 'super-sheep-copy'); ?></button>
                        </form>
                    <?php else : ?>
                        <p><?php echo esc_html__('Available after backup validation.', 'super-sheep-copy'); ?></p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="super-sheep-copy-workflow-step <?php echo esc_attr($step_class(4)); ?>">
                <div class="super-sheep-copy-workflow-number">4</div>
                <div class="super-sheep-copy-workflow-content">
                    <h3><?php echo esc_html__('Open installer', 'super-sheep-copy'); ?></h3>
                    <?php if ($installer_ready) : ?>
                        <p><?php echo esc_html__('Confirm restore in standalone installer, then run rollback, database, URL, and file restore steps.', 'super-sheep-copy'); ?></p>
                        <p class="super-sheep-copy-workflow-action"><a class="button button-primary button-hero" href="<?php echo esc_attr($installer_launch_url); ?>"><?php echo esc_html__('Open Standalone Installer', 'super-sheep-copy'); ?></a></p>
                        <p><code><?php echo esc_html($installer_launch_url); ?></code></p>
                    <?php else : ?>
                        <p><?php echo esc_html__('Available after installer preparation.', 'super-sheep-copy'); ?></p>
                    <?php endif; ?>
                </div>
            </section>
        </div>

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

    <?php include SUPER_SHEEP_COPY_DIR . 'templates/partials/footer.php'; ?>
</div>
