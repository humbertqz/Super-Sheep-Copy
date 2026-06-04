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
        self::assertStringContainsString('Unable to parse backup step response.', $script);
        self::assertStringContainsString('data-super-sheep-copy-retry-job', $script);
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

    public function testAdminScriptUpdatesCurrentBackupProgressStatus(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');

        self::assertStringContainsString('function updateCurrentProgress(data)', $script);
        self::assertStringContainsString('data-super-sheep-copy-current-progress', $script);
        self::assertStringContainsString('data-super-sheep-copy-current-progress-state', $script);
        self::assertStringContainsString('data-super-sheep-copy-current-progress-message', $script);
        self::assertStringContainsString('updateCurrentProgress(payload.data);', $script);
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
        self::assertStringContainsString('Are you sure you want to delete this backup job and its files?', $script);
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
}
