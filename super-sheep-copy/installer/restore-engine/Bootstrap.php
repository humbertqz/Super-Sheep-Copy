<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Standalone installer uses one-time restore token verification instead of WordPress nonces.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Standalone installer runs outside WordPress and reads raw token-gated form values.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are consumed only after restore token verification and escaped on output.
if (!defined('ABSPATH')) {
    if (PHP_SAPI !== 'cli' && !is_readable(__DIR__ . '/config.php')) {
        exit;
    }
}

$super_sheep_copy_installer_shared_dir = is_dir(__DIR__ . '/shared') ? __DIR__ . '/shared' : dirname(__DIR__, 2) . '/shared';
require_once $super_sheep_copy_installer_shared_dir . '/Serialization/SerializationWalkerInterface.php';
require_once $super_sheep_copy_installer_shared_dir . '/Serialization/SerializationWalker.php';
require_once $super_sheep_copy_installer_shared_dir . '/Urls/UrlReplacementEngineInterface.php';
require_once $super_sheep_copy_installer_shared_dir . '/Urls/UrlReplacementEngine.php';
require_once $super_sheep_copy_installer_shared_dir . '/Urls/StructuredValueReplacementResult.php';
require_once $super_sheep_copy_installer_shared_dir . '/Urls/StructuredValueReplacer.php';
require_once __DIR__ . '/EnvironmentChecker.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/ArchiveValidationResult.php';
require_once __DIR__ . '/PackageReaderInterface.php';
require_once __DIR__ . '/PackagePathGuard.php';
require_once __DIR__ . '/DirectoryPackageReader.php';
require_once __DIR__ . '/ZipPackageReader.php';
require_once __DIR__ . '/TarGzPackageReader.php';
require_once __DIR__ . '/PackageReaderFactory.php';
require_once __DIR__ . '/ArchiveValidator.php';
require_once __DIR__ . '/DestinationDetector.php';
require_once __DIR__ . '/WpConfigReader.php';
require_once __DIR__ . '/DatabaseConnectionTester.php';
require_once __DIR__ . '/PreflightChecker.php';
require_once __DIR__ . '/ConfirmationStore.php';
require_once __DIR__ . '/RollbackFileCollector.php';
require_once __DIR__ . '/RollbackManifestBuilder.php';
require_once __DIR__ . '/RollbackDatabaseDumper.php';
require_once __DIR__ . '/RollbackPreparationManager.php';
require_once __DIR__ . '/DatabaseImportManifestReader.php';
require_once __DIR__ . '/SqlTableNameRewriter.php';
require_once __DIR__ . '/DatabaseChunkImporter.php';
require_once __DIR__ . '/DatabaseImportPreparationManager.php';
require_once __DIR__ . '/DatabaseTableInspector.php';
require_once __DIR__ . '/DatabaseUrlReplacementPlanBuilder.php';
require_once __DIR__ . '/DatabaseTableSwapExecutor.php';
require_once __DIR__ . '/DatabaseTableSwapManager.php';
require_once __DIR__ . '/DatabaseTextColumnInspector.php';
require_once __DIR__ . '/DatabaseUrlReplacementExecutor.php';
require_once __DIR__ . '/DatabaseUrlReplacementManager.php';
require_once __DIR__ . '/DatabaseBackupTableCleaner.php';
require_once __DIR__ . '/FileRestoreManager.php';

final class Bootstrap
{
    public static function run(): void
    {
        $engine_dir = self::engineDirectory();
        $config = self::loadConfig($engine_dir);
        $security = new Security();
        $token = self::requestToken($security);
        $verified = $security->verifyToken($token, $config);

        self::sendHtmlHeader();
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Super Sheep Copy Installer</title>';
        echo '<style>body{background:#f0f2f3;color:#1d2327;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:32px}';
        echo 'body>h1{margin:0 auto 18px;max-width:980px}.status{border:1px solid #c3c4c7;margin:10px 0;padding:12px}.warning{background:#fcf9e8;border-color:#dba617}.ok{background:#edfaef;border-color:#00a32a}.error{background:#fcf0f1;border-color:#d63638}';
        echo '.ssc-installer-workflow{background:#fff;border:1px solid #c3c4c7;margin:0 auto;max-width:980px}.ssc-installer-workflow-header{background:#f6f7f7;border-bottom:1px solid #c3c4c7;padding:20px 24px}.ssc-installer-workflow-header h2{font-size:26px;line-height:1.2;margin:0 0 6px}.ssc-installer-workflow-header p{color:#50575e;margin:0}';
        echo '.ssc-installer-step{display:grid;gap:16px;grid-template-columns:38px minmax(0,1fr);padding:20px 24px;position:relative}.ssc-installer-step+.ssc-installer-step{border-top:1px solid #dcdcde}.ssc-installer-step:before{background:#dcdcde;bottom:-20px;content:"";left:42px;position:absolute;top:58px;width:2px}.ssc-installer-step:last-child:before{display:none}';
        echo '.ssc-installer-step-number{align-items:center;background:#dcdcde;border-radius:50%;color:#1d2327;display:flex;font-weight:700;height:38px;justify-content:center;position:relative;width:38px;z-index:1}.ssc-installer-step h2{font-size:19px;line-height:1.3;margin:5px 0 10px}.ssc-installer-step.is-current{background:#f6fbff;box-shadow:inset 5px 0 0 #2271b1}.ssc-installer-step.is-current .ssc-installer-step-number{background:#2271b1;color:#fff}.ssc-installer-step.is-complete .ssc-installer-step-number{background:#00a32a;color:#fff}.ssc-installer-step.is-pending h2,.ssc-installer-step.is-pending .status{opacity:.78}';
        echo '.ssc-installer-action{align-items:center;display:flex;flex-wrap:wrap;gap:10px;margin:12px 0 0}.ssc-installer-action p{margin:0}.ssc-installer-action button{min-height:36px}.ssc-installer-action button[disabled]{cursor:progress;opacity:.75}.ssc-installer-step.is-working{background:#f6fbff;box-shadow:inset 5px 0 0 #2271b1}.ssc-installer-step.is-working .ssc-installer-step-number{background:#2271b1;color:#fff}.ssc-installer-loading{align-items:center;color:#2271b1;display:flex;font-weight:700;gap:10px;margin:2px 0 0}.ssc-installer-loading[hidden]{display:none}.ssc-installer-loading-bar{background:#dcdcde;border-radius:999px;display:block;height:6px;overflow:hidden;position:relative;width:180px}.ssc-installer-loading-bar span{animation:ssc-installer-progress 1.15s ease-in-out infinite;background:linear-gradient(90deg,#2271b1,#00a32a);border-radius:999px;height:100%;left:-45%;position:absolute;top:0;width:45%}@keyframes ssc-installer-progress{0%{left:-45%}100%{left:100%}}.ssc-installer-grid{display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr))}.ssc-complete-panel{background:#083f2f;color:#f5fff9;margin:18px auto 0;max-width:980px;padding:24px}.ssc-complete-panel h2{font-size:28px;line-height:1.15;margin:0 0 8px}.ssc-complete-panel p{margin:0 0 12px}.ssc-complete-actions{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0}.ssc-complete-actions a{background:#f5fff9;color:#083f2f;display:inline-block;font-weight:700;padding:10px 14px;text-decoration:none}.ssc-complete-actions a.secondary{background:transparent;border:1px solid #8ee6bd;color:#f5fff9}.ssc-complete-panel ul{margin:12px 0 0;padding-left:20px}@media(max-width:700px){body{padding:16px}.ssc-installer-step{grid-template-columns:32px minmax(0,1fr);padding:16px}.ssc-installer-step-number{height:32px;width:32px}.ssc-installer-step:before{left:31px}.ssc-installer-grid{grid-template-columns:1fr}.ssc-installer-action,.ssc-installer-action button{align-items:stretch;flex-direction:column;width:100%}.ssc-installer-loading{width:100%}.ssc-installer-loading-bar{width:100%}.ssc-complete-actions{flex-direction:column}.ssc-complete-actions a{text-align:center}}</style>';
        echo '<script>function showInstallerWorkingState(form){var step=form.closest?form.closest(".ssc-installer-step"):null;var button=form.querySelector("button[type=\"submit\"]");var loading=form.querySelector("[data-ssc-installer-loading]");if(step){step.classList.add("is-working");}if(button){button.textContent="Working...";button.disabled=true;}if(loading){loading.removeAttribute("hidden");}}document.addEventListener("submit",function(event){var form=event.target;if(!form||!form.getAttribute||!form.getAttribute("data-ssc-installer-action")){return;}event.preventDefault();showInstallerWorkingState(form);var submit=function(){window.HTMLFormElement.prototype.submit.call(form);};if(window.requestAnimationFrame){window.requestAnimationFrame(function(){window.setTimeout(submit,250);});}else{window.setTimeout(submit,250);}});</script>';
        echo '</head><body>';
        echo '<h1>Super Sheep Copy Installer</h1>';

        if (!$verified) {
            echo '<div class="status warning"><strong>Restore token required.</strong> Enter the token generated by the WordPress admin restore page.</div>';
            echo '<form method="get"><p><input type="password" name="token" autocomplete="off" /></p><p><button type="submit">Unlock Installer</button></p></form>';
            echo '</body></html>';
            return;
        }

        if (!empty($config['file_restore_completed'])) {
            $destination_url = (new DestinationDetector())->detect($_SERVER);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- completionPanel() escapes dynamic values and returns trusted installer markup.
            echo self::completionPanel($destination_url);
            echo '</body></html>';
            return;
        }

        $archive_validator = new ArchiveValidator();
        $database_tester = new DatabaseConnectionTester();
        $wp_config = new WpConfigReader();
        $preflight = new PreflightChecker(new EnvironmentChecker(), new DestinationDetector(), $wp_config, $archive_validator, $database_tester);
        $checks = $preflight->run($config, $_SERVER, $engine_dir);
        $has_blocking_errors = PreflightChecker::hasBlockingErrors($checks);
        $confirmation = new ConfirmationStore();
        $confirmation_message = '';
        if (self::requestMethod() === 'POST' && isset($_POST['confirm_restore'])) {
            $confirmed = $confirmation->confirm(
                $engine_dir,
                $config,
                self::postString('restore_confirmation'),
                isset($_POST['restore_warning_accepted']),
                $has_blocking_errors
            );
            if ($confirmed) {
                $config = self::loadConfig($engine_dir);
                $confirmation_message = 'Restore confirmation recorded.';
            } else {
                $confirmation_message = 'Confirmation was not accepted.';
            }
        }

        $rollback_message = '';
        if (self::requestMethod() === 'POST' && isset($_POST['prepare_rollback'])) {
            if ($has_blocking_errors) {
                $rollback_message = 'Rollback preparation blocked by preflight errors.';
            } elseif (empty($config['restore_confirmed'])) {
                $rollback_message = 'Rollback requires restore confirmation.';
            } else {
                $rollback = new RollbackPreparationManager(
                    new RollbackFileCollector(),
                    new RollbackManifestBuilder(),
                    new DestinationDetector(),
                    $wp_config,
                    $database_tester,
                    new RollbackDatabaseDumper()
                );
                $rollback_result = $rollback->prepare($engine_dir, $config, $_SERVER);
                if ($rollback_result['prepared']) {
                    $config = self::loadConfig($engine_dir);
                    $rollback_message = 'Rollback prepared.';
                } else {
                    $rollback_message = 'Rollback preparation failed.';
                }
            }
        }

        $database_import_message = '';
        if (self::requestMethod() === 'POST' && isset($_POST['stage_database_import'])) {
            $manager = new DatabaseImportPreparationManager(
                $wp_config,
                $database_tester,
                new DatabaseImportManifestReader(),
                new SqlTableNameRewriter(),
                new DatabaseChunkImporter(),
                new DatabaseTableInspector()
            );
            $import_result = $manager->stage($engine_dir, $config, $_SERVER);
            if ($import_result['staged']) {
                $config = self::loadConfig($engine_dir);
                $database_import_message = 'Database import staged.';
            } else {
                $database_import_message = isset($import_result['warnings'][0]) ? $import_result['warnings'][0] : 'Database import staging failed.';
            }
        }

        $table_swap_message = '';
        if (self::requestMethod() === 'POST' && isset($_POST['swap_database_tables'])) {
            $manager = new DatabaseTableSwapManager(
                $wp_config,
                $database_tester,
                new DatabaseTableInspector(),
                new DatabaseUrlReplacementPlanBuilder(),
                new DatabaseTableSwapExecutor()
            );
            $swap_result = $manager->swap($engine_dir, $config, $_SERVER);
            $config = self::loadConfig($engine_dir);
            if ($swap_result['swapped']) {
                $table_swap_message = 'Database tables swapped.';
            } else {
                $table_swap_message = isset($swap_result['warnings'][0]) ? $swap_result['warnings'][0] : 'Database table swap failed.';
            }
        }

        $url_replacement_message = '';
        if (self::requestMethod() === 'POST' && isset($_POST['replace_database_urls'])) {
            $manager = new DatabaseUrlReplacementManager(
                $wp_config,
                $database_tester,
                new DatabaseTextColumnInspector(),
                new DatabaseUrlReplacementExecutor()
            );
            $replacement_result = $manager->replace($engine_dir, $config);
            $config = self::loadConfig($engine_dir);
            if ($replacement_result['completed']) {
                $url_replacement_message = 'Database URLs replaced.';
            } else {
                $url_replacement_message = isset($replacement_result['warnings'][0]) ? $replacement_result['warnings'][0] : 'Database URL replacement failed.';
            }
        }

        $file_restore_message = '';
        if (self::requestMethod() === 'POST' && isset($_POST['restore_files'])) {
            $manager = new FileRestoreManager($archive_validator);
            $file_result = $manager->restore($engine_dir, $config);
            $config = self::loadConfig($engine_dir);
            if ($file_result['completed']) {
                $file_restore_message = 'Files restored.';
                $destination_url = (new DestinationDetector())->detect($_SERVER);
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- completionPanel() escapes dynamic values and returns trusted installer markup.
                echo self::completionPanel($destination_url);
                echo '</body></html>';
                return;
            } else {
                $file_restore_message = isset($file_result['warnings'][0]) ? $file_result['warnings'][0] : 'File restore failed.';
            }
        }

        $archive_path = isset($config['staged_archive_path']) ? (string) $config['staged_archive_path'] : '';
        $validation = self::cachedArchiveValidation($config);
        if (!$validation instanceof ArchiveValidationResult) {
            $validation = $archive_validator->validatePackage($archive_path);
        }
        if (!$validation->isValid()) {
            echo '<div class="status error">Prepared archive could not be validated. Restore execution is unavailable.</div>';
            echo '</body></html>';
            return;
        }

        $manifest = $validation->manifest();
        $destination_url = (new DestinationDetector())->detect($_SERVER);
        $confirmed = $confirmation->isConfirmed($config);
        $step_states = self::installerStepStates($config, $has_blocking_errors, $confirmed);

        echo '<div class="ssc-installer-workflow">';
        echo '<div class="ssc-installer-workflow-header"><h2>Restore Workflow</h2><p>Work through each step in order. Only the current step needs action.</p></div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- openInstallerStep() escapes dynamic values and returns trusted installer markup.
        echo self::openInstallerStep(1, 'Review preflight', $step_states['preflight']);
        echo '<h2>Restore Preview</h2>';
        echo '<div class="ssc-installer-grid">';
        echo '<div class="status ok"><strong>Source:</strong> ' . esc_html((string) ($manifest['source_site_url'] ?? $config['source_site_url'] ?? '')) . '</div>';
        echo '<div class="status ok"><strong>Destination:</strong> ' . esc_html($destination_url) . '</div>';
        echo '<div class="status ok"><strong>Archive entries:</strong> ' . esc_html((string) $validation->entryCount()) . '</div>';
        echo '<div class="status ok"><strong>Database entries:</strong> ' . esc_html((string) $validation->databaseEntryCount()) . '</div>';
        echo '</div>';

        echo '<h2>Preflight Checks</h2>';
        foreach ($checks as $check) {
            $class = $check['status'] === 'error' ? 'error' : ($check['status'] === 'ok' ? 'ok' : 'warning');
            echo '<div class="status ' . esc_attr($class) . '"><strong>' . esc_html($check['label']) . ':</strong> ' . esc_html($check['value']);
            if ($check['message'] !== '') {
                echo '<br><span>' . esc_html($check['message']) . '</span>';
            }
            echo '</div>';
        }
        echo '</section>';

        if ($confirmation_message !== '') {
            echo '<div class="status ' . ($confirmed ? 'ok' : 'warning') . '">' . esc_html($confirmation_message) . '</div>';
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- openInstallerStep() escapes dynamic values and returns trusted installer markup.
        echo self::openInstallerStep(2, 'Confirm restore', $step_states['confirm']);
        echo '<h2>Restore Confirmation</h2>';
        if ($confirmed) {
            echo '<div class="status ok">Restore confirmation recorded. Continue through rollback, database, URL, and file restore steps below.</div>';
        } elseif ($has_blocking_errors) {
            echo '<div class="status error">Resolve blocking preflight errors before confirming restore.</div>';
        } else {
            echo '<div class="status warning">This confirmation gate is required before future restore execution. No destructive action runs in this milestone.</div>';
            echo '<form class="ssc-installer-action" method="post" data-ssc-installer-action>';
            echo '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
            echo '<input type="hidden" name="confirm_restore" value="1">';
            echo '<p><label><input type="checkbox" name="restore_warning_accepted" value="1"> I understand this restore will replace the destination site in a later step.</label></p>';
            echo '<p><label>Type RESTORE to confirm <input type="text" name="restore_confirmation" autocomplete="off"></label></p>';
            echo '<p><button type="submit">Confirm Restore Intent</button></p>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- loadingIndicator() returns static trusted installer markup.
            echo self::loadingIndicator();
            echo '</form>';
        }
        echo '</section>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- openInstallerStep() escapes dynamic values and returns trusted installer markup.
        echo self::openInstallerStep(3, 'Prepare rollback', $step_states['rollback']);
        echo '<h2>Rollback Preparation</h2>';
        if ($rollback_message !== '') {
            echo '<div class="status ' . (!empty($config['rollback_prepared']) ? 'ok' : 'warning') . '">' . esc_html($rollback_message) . '</div>';
        }
        if (empty($config['restore_confirmed'])) {
            echo '<div class="status warning">Rollback requires restore confirmation.</div>';
        } elseif (!empty($config['rollback_prepared'])) {
            echo '<div class="status ok">Rollback prepared. Continue to database import.</div>';
            $database_dump = isset($config['rollback_database_dump']) ? (string) $config['rollback_database_dump'] : '';
            $database_count = isset($config['rollback_database_table_count']) ? (string) $config['rollback_database_table_count'] : '0';
            if ($database_dump !== '') {
                echo '<div class="status ok"><strong>Database rollback:</strong> ' . esc_html($database_count) . ' tables dumped.</div>';
            } else {
                echo '<div class="status warning"><strong>Database rollback:</strong> Database dump was skipped. Restore execution remains unavailable.</div>';
            }
        } elseif ($has_blocking_errors) {
            echo '<div class="status error">Resolve blocking preflight errors before preparing rollback.</div>';
        } else {
            echo '<div class="status warning">Prepare rollback artifact before any future destructive restore step.</div>';
            echo '<form class="ssc-installer-action" method="post" data-ssc-installer-action>';
            echo '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
            echo '<input type="hidden" name="prepare_rollback" value="1">';
            echo '<p><button type="submit">Prepare Rollback</button></p>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- loadingIndicator() returns static trusted installer markup.
            echo self::loadingIndicator();
            echo '</form>';
        }
        echo '</section>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- openInstallerStep() escapes dynamic values and returns trusted installer markup.
        echo self::openInstallerStep(4, 'Import database', $step_states['database_import']);
        echo '<h2>Database Import</h2>';
        if ($database_import_message !== '') {
            echo '<div class="status ' . (!empty($config['database_import_staged']) ? 'ok' : 'warning') . '">' . esc_html($database_import_message) . '</div>';
        }
        if (empty($config['restore_confirmed'])) {
            echo '<div class="status warning">Database import requires restore confirmation.</div>';
        } elseif (empty($config['rollback_prepared'])) {
            echo '<div class="status warning">Database import requires rollback preparation.</div>';
        } elseif (empty($config['rollback_database_dump'])) {
            echo '<div class="status warning">Database import requires database rollback dump.</div>';
        } elseif (!empty($config['database_import_staged'])) {
            echo '<div class="status ok">Database import staged. '
                . esc_html((string) ($config['database_import_table_count'] ?? 0)) . ' tables, '
                . esc_html((string) ($config['database_import_chunk_count'] ?? 0)) . ' chunks imported. Continue to table swap.</div>';
        } else {
            echo '<div class="status warning">Import backup database chunks into isolated staging tables. Destination tables will not be replaced.</div>';
            echo '<form class="ssc-installer-action" method="post" data-ssc-installer-action>';
            echo '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
            echo '<input type="hidden" name="stage_database_import" value="1">';
            echo '<p><button type="submit">Import Database to Staging</button></p>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- loadingIndicator() returns static trusted installer markup.
            echo self::loadingIndicator();
            echo '</form>';
        }
        echo '</section>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- openInstallerStep() escapes dynamic values and returns trusted installer markup.
        echo self::openInstallerStep(5, 'Swap database tables', $step_states['database_swap']);
        echo '<h2>Database Table Swap</h2>';
        if ($table_swap_message !== '') {
            echo '<div class="status ' . (!empty($config['database_tables_swapped']) ? 'ok' : 'warning') . '">' . esc_html($table_swap_message) . '</div>';
        }
        if (empty($config['restore_confirmed'])) {
            echo '<div class="status warning">Database table swap requires restore confirmation.</div>';
        } elseif (empty($config['rollback_prepared'])) {
            echo '<div class="status warning">Database table swap requires rollback preparation.</div>';
        } elseif (empty($config['rollback_database_dump'])) {
            echo '<div class="status warning">Database table swap requires database rollback dump.</div>';
        } elseif (empty($config['database_import_staged'])) {
            echo '<div class="status warning">Database table swap requires staged database import.</div>';
        } elseif (!empty($config['database_tables_swap_pending'])) {
            echo '<div class="status warning">Database table swap is pending or failed. Installer is locked.</div>';
        } elseif (!empty($config['database_tables_swapped'])) {
            echo '<div class="status ok">Database tables swapped. '
                . esc_html((string) ($config['database_swap_table_count'] ?? 0))
                . ' tables replaced.</div>';
            if (!empty($config['database_url_replacement_plan'])) {
                $plan = is_array($config['database_url_replacement_plan']) ? $config['database_url_replacement_plan'] : array();
                $plan_status = isset($plan['status']) ? (string) $plan['status'] : 'recorded';
                echo '<div class="status ok">URL replacement plan status: ' . esc_html($plan_status) . '.</div>';
            }
        } else {
            echo '<div class="status warning">Swap staged database tables into destination table names.</div>';
            echo '<form class="ssc-installer-action" method="post" data-ssc-installer-action>';
            echo '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
            echo '<input type="hidden" name="swap_database_tables" value="1">';
            echo '<p><button type="submit">Swap Database Tables</button></p>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- loadingIndicator() returns static trusted installer markup.
            echo self::loadingIndicator();
            echo '</form>';
        }
        echo '</section>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- openInstallerStep() escapes dynamic values and returns trusted installer markup.
        echo self::openInstallerStep(6, 'Replace URLs', $step_states['url_replacement']);
        echo '<h2>Database URL Replacement</h2>';
        if ($url_replacement_message !== '') {
            echo '<div class="status ' . (!empty($config['database_url_replacement_completed']) ? 'ok' : 'warning') . '">' . esc_html($url_replacement_message) . '</div>';
        }
        if (empty($config['restore_confirmed'])) {
            echo '<div class="status warning">Database URL replacement requires restore confirmation.</div>';
        } elseif (empty($config['rollback_prepared'])) {
            echo '<div class="status warning">Database URL replacement requires rollback preparation.</div>';
        } elseif (empty($config['rollback_database_dump'])) {
            echo '<div class="status warning">Database URL replacement requires database rollback dump.</div>';
        } elseif (empty($config['database_tables_swapped'])) {
            echo '<div class="status warning">Database URL replacement requires swapped database tables.</div>';
        } elseif (!empty($config['database_url_replacement_completed'])) {
            echo '<div class="status ok">Database URLs replaced. '
                . esc_html((string) ($config['database_url_replacement_table_count'] ?? 0)) . ' tables scanned. '
                . esc_html((string) ($config['database_url_replacement_changed_rows'] ?? 0)) . ' rows changed. '
                . esc_html((string) ($config['database_url_replacement_changed_cells'] ?? 0)) . ' cells changed. '
                . esc_html((string) ($config['database_url_replacement_count'] ?? 0)) . ' replacements.</div>';
        } elseif (empty($config['database_url_replacement_plan']) || !is_array($config['database_url_replacement_plan'])) {
            echo '<div class="status warning">Database URL replacement requires a recorded URL replacement plan.</div>';
        } else {
            echo '<div class="status warning">Replace source URLs in swapped database tables.</div>';
            echo '<form class="ssc-installer-action" method="post" data-ssc-installer-action>';
            echo '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
            echo '<input type="hidden" name="replace_database_urls" value="1">';
            echo '<p><button type="submit">Replace Database URLs</button></p>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- loadingIndicator() returns static trusted installer markup.
            echo self::loadingIndicator();
            echo '</form>';
        }
        echo '</section>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- openInstallerStep() escapes dynamic values and returns trusted installer markup.
        echo self::openInstallerStep(7, 'Restore files', $step_states['file_restore']);
        echo '<h2>File Restore</h2>';
        if ($file_restore_message !== '') {
            echo '<div class="status ' . (!empty($config['file_restore_completed']) ? 'ok' : 'warning') . '">' . esc_html($file_restore_message) . '</div>';
        }
        if (empty($config['restore_confirmed'])) {
            echo '<div class="status warning">File restore requires restore confirmation.</div>';
        } elseif (empty($config['rollback_prepared'])) {
            echo '<div class="status warning">File restore requires rollback preparation.</div>';
        } elseif (empty($config['rollback_database_dump'])) {
            echo '<div class="status warning">File restore requires database rollback dump.</div>';
        } elseif (empty($config['database_url_replacement_completed'])) {
            echo '<div class="status warning">File restore requires database URL replacement.</div>';
        } elseif (!empty($config['file_restore_completed'])) {
            echo '<div class="status ok">Files restored. '
                . esc_html((string) ($config['file_restore_file_count'] ?? 0)) . ' files copied. wp-config.php preserved.</div>';
        } else {
            echo '<div class="status warning">Restore archive files into the destination site. wp-config.php will be preserved.</div>';
            echo '<form class="ssc-installer-action" method="post" data-ssc-installer-action>';
            echo '<input type="hidden" name="token" value="' . esc_attr($token) . '">';
            echo '<input type="hidden" name="restore_files" value="1">';
            echo '<p><button type="submit">Restore Files</button></p>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- loadingIndicator() returns static trusted installer markup.
            echo self::loadingIndicator();
            echo '</form>';
        }
        echo '</section></div>';
        echo '</body></html>';
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,string>
     */
    private static function installerStepStates(array $config, bool $has_blocking_errors, bool $confirmed): array
    {
        return array(
            'preflight' => $has_blocking_errors ? 'is-current' : 'is-complete',
            'confirm' => $confirmed ? 'is-complete' : ($has_blocking_errors ? 'is-pending' : 'is-current'),
            'rollback' => !empty($config['rollback_prepared']) ? 'is-complete' : ($confirmed && !$has_blocking_errors ? 'is-current' : 'is-pending'),
            'database_import' => !empty($config['database_import_staged']) ? 'is-complete' : (!empty($config['rollback_prepared']) && !empty($config['rollback_database_dump']) ? 'is-current' : 'is-pending'),
            'database_swap' => !empty($config['database_tables_swapped']) ? 'is-complete' : (!empty($config['database_import_staged']) && empty($config['database_tables_swap_pending']) ? 'is-current' : 'is-pending'),
            'url_replacement' => !empty($config['database_url_replacement_completed']) ? 'is-complete' : (!empty($config['database_tables_swapped']) && !empty($config['database_url_replacement_plan']) && is_array($config['database_url_replacement_plan']) ? 'is-current' : 'is-pending'),
            'file_restore' => !empty($config['file_restore_completed']) ? 'is-complete' : (!empty($config['database_url_replacement_completed']) ? 'is-current' : 'is-pending'),
        );
    }

    private static function openInstallerStep(int $number, string $title, string $state): string
    {
        return '<section class="ssc-installer-step ' . esc_attr($state) . '"><div class="ssc-installer-step-number">' . esc_html((string) $number) . '</div><div><h2>' . esc_html($title) . '</h2>';
    }

    private static function completionPanel(string $destination_url): string
    {
        $site_url = rtrim($destination_url, '/');
        $admin_url = $site_url . '/wp-admin/';

        return '<div class="ssc-complete-panel"><h2>Restore complete</h2>'
            . '<p>Your restored site is ready for review at ' . esc_html($site_url) . '.</p>'
            . '<div class="ssc-complete-actions">'
            . '<a href="' . esc_url($site_url) . '">Open restored site</a>'
            . '<a class="secondary" href="' . esc_url($admin_url) . '">Open WordPress admin</a>'
            . '</div>'
            . '<ul><li>Review the frontend.</li><li>Log into WordPress admin if prompted.</li><li>Delete or lock installer files after verifying the site.</li></ul></div>';
    }

    private static function loadingIndicator(): string
    {
        return '<div class="ssc-installer-loading" data-ssc-installer-loading hidden><span class="ssc-installer-loading-bar"><span></span></span><strong>Working...</strong></div>';
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function cachedArchiveValidation(array $config): ?ArchiveValidationResult
    {
        $status = isset($config['archive_validation_status']) && is_scalar($config['archive_validation_status']) ? (string) $config['archive_validation_status'] : '';
        if ($status === '') {
            return null;
        }

        $errors = isset($config['archive_validation_errors']) && is_array($config['archive_validation_errors'])
            ? array_map('strval', $config['archive_validation_errors'])
            : array();
        $manifest = array(
            'project' => 'Super Sheep Copy',
            'source_site_url' => isset($config['source_site_url']) ? (string) $config['source_site_url'] : '',
            'source_home_url' => isset($config['source_home_url']) ? (string) $config['source_home_url'] : '',
        );
        $entry_count = isset($config['archive_entry_count']) ? (int) $config['archive_entry_count'] : 0;
        $database_entry_count = isset($config['database_entry_count']) ? (int) $config['database_entry_count'] : 0;

        return new ArchiveValidationResult($status === 'valid', $errors, $manifest, $entry_count, $database_entry_count);
    }

    private static function engineDirectory(): string
    {
        return isset($GLOBALS['ssc_installer_engine_dir']) && is_string($GLOBALS['ssc_installer_engine_dir'])
            ? $GLOBALS['ssc_installer_engine_dir']
            : __DIR__;
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadConfig(string $engine_dir): array
    {
        $path = rtrim($engine_dir, '/\\') . '/config.php';
        if (!is_readable($path)) {
            return array();
        }

        $config = require $path;

        return is_array($config) ? $config : array();
    }

    private static function requestMethod(): string
    {
        return isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : ($_POST === array() ? 'GET' : 'POST');
    }

    private static function requestToken(Security $security): string
    {
        $token = $security->requestToken();
        if ($token !== '') {
            return $token;
        }

        return isset($_POST['token']) && is_string($_POST['token']) ? (string) $_POST['token'] : '';
    }

    private static function postString(string $key): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? (string) $_POST[$key] : '';
    }

    private static function sendHtmlHeader(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
    }
}

if (!function_exists(__NAMESPACE__ . '\\esc_html')) {
    function esc_html($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists(__NAMESPACE__ . '\\esc_attr')) {
    function esc_attr($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists(__NAMESPACE__ . '\\esc_url')) {
    function esc_url($value): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }

        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}
