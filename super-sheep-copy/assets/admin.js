(function () {
    'use strict';

    var runningStates = {
        created: true,
        exporting_database: true,
        scanning_files: true,
        packaging_archive: true,
        validating_backup: true
    };

    function setHidden(element, hidden) {
        if (!element) {
            return;
        }

        if (hidden) {
            element.setAttribute('hidden', '');
            return;
        }

        element.removeAttribute('hidden');
    }

    function nextJobRow() {
        var rows = document.querySelectorAll('[data-super-sheep-copy-job-id]');
        for (var index = 0; index < rows.length; index++) {
            if (runningStates[rows[index].getAttribute('data-super-sheep-copy-job-state')]) {
                return rows[index];
            }
        }

        return null;
    }

    function jobRow(row) {
        return row && row.querySelector ? row : nextJobRow();
    }

    function nonceValue(row) {
        var input = row.querySelector('input[name="super_sheep_copy_nonce"]') || document.querySelector('input[name="super_sheep_copy_nonce"]');

        return input ? input.value : '';
    }

    function retryButton(row) {
        return row.querySelector('[data-super-sheep-copy-retry-job]');
    }

    function showRetry(row) {
        var button = retryButton(row);
        if (button) {
            button.style.display = '';
        }
    }

    function hideRetry(row) {
        var button = retryButton(row);
        if (button) {
            button.style.display = 'none';
        }
    }

    function setErrorReport(row, report) {
        var button = row.querySelector('[data-super-sheep-copy-error-report]');
        if (button) {
            button.setAttribute('data-super-sheep-copy-error-report', report);
            setHidden(button, !report);
            row.querySelector('[data-super-sheep-copy-copy-status]').textContent = '';
        }
    }

    function requestErrorReport(row, code) {
        var state = row.getAttribute('data-super-sheep-copy-job-state');
        return [
            'Error code: ' + code,
            'Job ID: ' + row.getAttribute('data-super-sheep-copy-job-id'),
            'Failed step: ' + (runningStates[state] ? state : 'unknown'),
            'Time (UTC): ' + new Date().toISOString(),
            'Plugin version: ' + ((window.superSheepCopyAdmin || {}).pluginVersion || 'unknown')
        ].join('\n');
    }

    function updateRunningIndicators() {
        var rows = document.querySelectorAll('[data-super-sheep-copy-job-id]');
        var hasRunningJob = false;
        for (var index = 0; index < rows.length; index++) {
            var row = rows[index];
            var isRunning = !!runningStates[row.getAttribute('data-super-sheep-copy-job-state')];
            row.classList.toggle('is-running', isRunning);
            setHidden(row.querySelector('[data-super-sheep-copy-progress-bar]'), !isRunning);
            if (isRunning) {
                hasRunningJob = true;
            }
        }

        var summaries = document.querySelectorAll('[data-super-sheep-copy-running-summary]');
        for (var summaryIndex = 0; summaryIndex < summaries.length; summaryIndex++) {
            setHidden(summaries[summaryIndex], !hasRunningJob);
        }
    }

    function showError(row, message, code) {
        setErrorReport(row, requestErrorReport(row, code));
        row.setAttribute('data-super-sheep-copy-job-state', 'failed');
        row.classList.remove('is-running');
        if (row.cells.length >= 4) {
            var stateLabel = row.querySelector('[data-super-sheep-copy-job-state-label]');
            var progressMessage = row.querySelector('[data-super-sheep-copy-job-progress-message]');
            if (stateLabel) {
                stateLabel.textContent = 'failed';
            } else {
                row.cells[2].textContent = 'failed';
            }
            if (progressMessage) {
                progressMessage.textContent = message;
            } else {
                row.cells[3].textContent = message;
            }
        }
        showRetry(row);
        updateRunningIndicators();
    }

    function showProgressMessage(row, message) {
        if (row.cells.length >= 4) {
            var progressMessage = row.querySelector('[data-super-sheep-copy-job-progress-message]');
            if (progressMessage) {
                progressMessage.textContent = message;
            } else {
                row.cells[3].textContent = message;
            }
        }
        updateRunningIndicators();
    }

    function updateRow(row, data) {
        setErrorReport(row, data.error_report || '');
        row.setAttribute('data-super-sheep-copy-job-state', data.state);
        row.classList.toggle('is-running', !!runningStates[data.state]);
        if (row.cells.length >= 4) {
            var stateLabel = row.querySelector('[data-super-sheep-copy-job-state-label]');
            var progressMessage = row.querySelector('[data-super-sheep-copy-job-progress-message]');
            if (stateLabel) {
                stateLabel.textContent = data.state;
            } else {
                row.cells[2].textContent = data.state;
            }
            if (progressMessage) {
                progressMessage.textContent = data.message || '';
            } else {
                row.cells[3].textContent = data.message || '';
            }
        }
        setHidden(row.querySelector('[data-super-sheep-copy-progress-bar]'), !runningStates[data.state]);
        setHidden(row.querySelector('[data-super-sheep-copy-download-job]'), data.state !== 'completed');
        if (data.status !== 'failed') {
            hideRetry(row);
        } else {
            showRetry(row);
        }
        updateRunningIndicators();
    }

    function isTransientStepError(error) {
        var message = error && error.message ? error.message : '';

        return [408, 429, 500, 502, 503, 504].indexOf(error && error.status) !== -1
            || (error && error.status === 200 && error.htmlResponse)
            || (error && error.timeoutResponse)
            || message.indexOf('Failed to fetch') !== -1
            || message.indexOf('NetworkError') !== -1;
    }

    function backupEndpoint() {
        if (window.superSheepCopyAdmin && typeof window.superSheepCopyAdmin.ajaxUrl === 'string') {
            return window.superSheepCopyAdmin.ajaxUrl;
        }

        return typeof window.ajaxurl === 'string' ? window.ajaxurl : '';
    }

    function deleteConfirmationMessage(form) {
        var input = form.querySelector('input[name="job_id"]');
        var jobId = input && input.value ? input.value : 'this backup';

        return 'Delete backup ' + jobId + '?\n\nThis permanently removes the job and all backup files. This cannot be undone.';
    }

    function runStep(row, retry, failures) {
        row = jobRow(row);
        var endpoint = backupEndpoint();
        if (!row || endpoint === '') {
            return;
        }

        hideRetry(row);
        setErrorReport(row, '');
        failures = failures || 0;
        var body = new window.URLSearchParams();
        body.set('action', 'super_sheep_copy_run_backup_step');
        body.set('job_id', row.getAttribute('data-super-sheep-copy-job-id') || '');
        body.set('super_sheep_copy_nonce', nonceValue(row));
        if (retry) {
            body.set('retry', '1');
        }

        window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.text().then(function (text) {
                var htmlResponse = /^\s*<(?:!doctype\b|[a-z][a-z0-9-]*(?:\s|>))/i.test(text);
                if (!response.ok || htmlResponse) {
                    var requestError = new Error('Backup step request failed with HTTP ' + response.status + '.');
                    requestError.status = response.status;
                    requestError.htmlResponse = htmlResponse;
                    requestError.timeoutResponse = /Request Timeout|HTTP 500/i.test(text);
                    requestError.reportCode = response.ok ? 'BACKUP_REQUEST_UNEXPECTED_RESPONSE' : 'BACKUP_REQUEST_HTTP_' + response.status;
                    throw requestError;
                }

                return text;
            });
        }).then(function (text) {
            var payload;
            try {
                payload = JSON.parse(text);
            } catch (error) {
                showError(row, 'WordPress returned an unreadable response. Use Retry / Continue backup to resume.', 'BACKUP_REQUEST_UNEXPECTED_RESPONSE');
                return;
            }

            if (!payload || !payload.success || !payload.data) {
                showError(row, 'Backup step failed. Use Retry / Continue backup to resume.', 'BACKUP_REQUEST_FAILED');
                return;
            }

            updateRow(row, payload.data);

            if (payload.data.status !== 'completed' && payload.data.status !== 'failed') {
                window.setTimeout(runStep, 100);
            } else if (nextJobRow()) {
                window.setTimeout(runStep, 100);
            }
        }).catch(function (error) {
            if (isTransientStepError(error)) {
                if (failures >= 6) {
                    setErrorReport(row, requestErrorReport(row, error.reportCode || 'BACKUP_REQUEST_CONNECTION_FAILED'));
                    showProgressMessage(row, error.htmlResponse
                        ? 'WordPress returned an error page. Check the PHP error log for this backup. Use Retry / Continue backup to resume.'
                        : 'The server is still unavailable. Use Retry / Continue backup to resume.');
                    showRetry(row);
                    return;
                }
                showProgressMessage(row, 'The server is busy or the request timed out. Retrying backup...');
                window.setTimeout(function () {
                    runStep(row, retry, failures + 1);
                }, Math.min(30000, 5000 * Math.pow(2, failures)));
                return;
            }

            var message = error.status ? error.message + ' ' : '';
            var nextStep = [401, 403, 404].indexOf(error.status) !== -1
                ? 'Reload the Backup page before retrying.'
                : 'Use Retry / Continue backup to resume.';
            showError(row, message + nextStep, error.reportCode || 'BACKUP_REQUEST_FAILED');
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.getAttribute) {
            return;
        }

        if (target.getAttribute('data-super-sheep-copy-retry-job')) {
            runStep(target.closest('[data-super-sheep-copy-job-id]'), true);
            return;
        }

        var report = target.getAttribute('data-super-sheep-copy-error-report');
        if (!report) {
            return;
        }
        var copyManually = function () {
            window.prompt(target.getAttribute('data-copy-prompt'), report);
        };
        var clipboard = window.navigator && window.navigator.clipboard;
        if (clipboard && clipboard.writeText) {
            clipboard.writeText(report).then(function () {
                target.closest('[data-super-sheep-copy-job-id]').querySelector('[data-super-sheep-copy-copy-status]').textContent =
                    target.getAttribute('data-copy-confirmation');
            }, copyManually);
        } else {
            copyManually();
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.hasAttribute || !form.hasAttribute('data-super-sheep-copy-delete-job')) {
            return;
        }

        if (!window.confirm(deleteConfirmationMessage(form))) {
            event.preventDefault();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            updateRunningIndicators();
            runStep();
        });
    } else {
        updateRunningIndicators();
        runStep();
    }
}());
