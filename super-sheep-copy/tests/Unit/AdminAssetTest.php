<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminAssetTest extends TestCase
{
    public function testAdminScriptShowsAjaxErrorsAndHandlesBadResponses(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');

        self::assertStringContainsString('showError(row,', $script);
        self::assertStringContainsString('response.ok', $script);
        self::assertStringContainsString('response.text().then(function (text)', $script);
        self::assertStringContainsString('backupErrorMessage(text)', $script);
        self::assertStringContainsString('Unable to parse backup step response.', $script);
        self::assertStringContainsString('data-super-sheep-copy-retry-job', $script);
    }

    public function testAdminScriptRetriesTransientBackupStepTimeouts(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');

        self::assertStringContainsString('function isTransientStepError(error)', $script);
        self::assertStringContainsString('Request timed out. Continuing backup...', $script);
        self::assertStringContainsString('window.setTimeout(function () {', $script);
        self::assertStringContainsString('runStep(row, false);', $script);
    }

    public function testAdminScriptNormalizesRunStepCallbackArgumentsBeforeQueryingRows(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');

        self::assertStringContainsString('function jobRow(row)', $script);
        self::assertStringContainsString('row && row.querySelector ? row : nextJobRow()', $script);
        self::assertStringContainsString('row = jobRow(row);', $script);
    }

    public function testAdminScriptRefreshesRunningJobIndicatorsAfterAjaxUpdates(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');

        self::assertStringContainsString('function updateRunningIndicators()', $script);
        self::assertStringContainsString('data-super-sheep-copy-running-summary', $script);
        self::assertStringContainsString('data-super-sheep-copy-progress-bar', $script);
        self::assertStringContainsString('is-running', $script);
        self::assertStringContainsString('updateRunningIndicators();', $script);
    }

    public function testAdminScriptDoesNotTargetRemovedCurrentBackupProgressSection(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');

        self::assertStringNotContainsString('function updateCurrentProgress(data)', $script);
        self::assertStringNotContainsString('data-super-sheep-copy-current-progress', $script);
        self::assertStringNotContainsString('updateCurrentProgress(payload.data);', $script);
    }

    public function testAdminScriptShowsDownloadActionWhenBackupCompletes(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');

        self::assertStringContainsString('data-super-sheep-copy-download-job', $script);
        self::assertStringContainsString("setHidden(row.querySelector('[data-super-sheep-copy-download-job]'), data.state !== 'completed')", $script);
    }

    public function testAdminScriptUsesShortBackupStepDelay(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');

        self::assertStringContainsString('window.setTimeout(runStep, 100)', $script);
        self::assertStringNotContainsString('window.setTimeout(runStep, 500)', $script);
    }

    public function testAdminScriptConfirmsBackupDeleteSubmissions(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');

        self::assertStringContainsString('data-super-sheep-copy-delete-job', $script);
        self::assertStringContainsString('function deleteConfirmationMessage(form)', $script);
        self::assertStringContainsString('Delete backup ', $script);
        self::assertStringContainsString('This permanently removes the job and all backup files. This cannot be undone.', $script);
        self::assertStringContainsString("form.hasAttribute('data-super-sheep-copy-delete-job')", $script);
        self::assertStringContainsString('window.confirm', $script);
        self::assertStringContainsString('event.preventDefault();', $script);
    }

    public function testAdminStylesUnifyRestoreWorkflow(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.css');

        self::assertStringContainsString('.super-sheep-copy-restore-workflow', $styles);
        self::assertStringContainsString('.super-sheep-copy-workflow-step', $styles);
        self::assertStringContainsString('.super-sheep-copy-workflow-action', $styles);
    }

    public function testAdminStylesKeepBackupDetailsAlignedWithMainSections(): void
    {
        $styles = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.css');

        self::assertStringContainsString('.super-sheep-copy .super-sheep-copy-backup-details-block', $styles);
        self::assertStringContainsString('max-width: 960px;', $styles);
        self::assertStringContainsString('margin: 16px 0;', $styles);
    }
}
