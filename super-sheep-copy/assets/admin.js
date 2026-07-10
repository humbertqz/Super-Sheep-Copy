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

    function showError(row, message) {
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

    function backupErrorMessage(text) {
        var payload;
        try {
            payload = JSON.parse(text);
        } catch (error) {
            return text || '';
        }

        if (payload && payload.data) {
            if (typeof payload.data === 'string') {
                return payload.data;
            }
            if (payload.data.message) {
                return payload.data.message;
            }
            if (payload.data.error) {
                return payload.data.error;
            }
        }

        return '';
    }

    function isTransientStepError(error) {
        var message = error && error.message ? error.message : '';

        return message.indexOf('Request Timeout') !== -1
            || message.indexOf('HTTP 500') !== -1
            || message.indexOf('Failed to fetch') !== -1
            || message.indexOf('NetworkError') !== -1;
    }

    function deleteConfirmationMessage(form) {
        var input = form.querySelector('input[name="job_id"]');
        var jobId = input && input.value ? input.value : 'this backup';

        return 'Delete backup ' + jobId + '?\n\nThis permanently removes the job and all backup files. This cannot be undone.';
    }

    function runStep(row, retry) {
        row = jobRow(row);
        if (!row || typeof window.ajaxurl !== 'string') {
            return;
        }

        hideRetry(row);
        var body = new window.URLSearchParams();
        body.set('action', 'super_sheep_copy_run_backup_step');
        body.set('job_id', row.getAttribute('data-super-sheep-copy-job-id') || '');
        body.set('super_sheep_copy_nonce', nonceValue(row));
        if (retry) {
            body.set('retry', '1');
        }

        window.fetch(window.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.text().then(function (text) {
                if (!response.ok) {
                    var message = backupErrorMessage(text);
                    throw new Error(message || 'Backup step request failed with HTTP ' + response.status + '.');
                }

                return text;
            });
        }).then(function (text) {
            var payload;
            try {
                payload = JSON.parse(text);
            } catch (error) {
                showError(row, 'Unable to parse backup step response.');
                return;
            }

            return payload;
        }).then(function (payload) {
            if (!payload) {
                return;
            }
            if (!payload || !payload.success || !payload.data) {
                showError(row, 'Backup step failed. Use Retry / Continue backup to resume.');
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
                showProgressMessage(row, 'Request timed out. Continuing backup...');
                window.setTimeout(function () {
                    runStep(row, false);
                }, 5000);
                return;
            }

            showError(row, error.message || 'Backup step failed. Use Retry / Continue backup to resume.');
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.getAttribute || !target.getAttribute('data-super-sheep-copy-retry-job')) {
            return;
        }

        var row = target.closest('[data-super-sheep-copy-job-id]');
        if (row) {
            runStep(row, true);
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
