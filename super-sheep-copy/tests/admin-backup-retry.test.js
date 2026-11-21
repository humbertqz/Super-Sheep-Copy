'use strict';

// Run with: node tests/admin-backup-retry.test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync(require('node:path').join(__dirname, '../assets/admin.js'), 'utf8');
const html = '<!DOCTYPE html><html><body>503 Service Unavailable</body></html>';
const completed = { status: 200, text: JSON.stringify({ success: true, data: {
    state: 'completed', status: 'completed', message: 'Backup completed.'
} }) };

async function harness(responses, retry = false, useClipboard = true) {
    const attributes = { 'data-super-sheep-copy-job-id': 'backup-123', 'data-super-sheep-copy-job-state': 'packaging_archive' };
    const progress = { textContent: '' };
    const state = { textContent: 'packaging_archive' };
    const button = { style: {} };
    const copyAttributes = {
        'data-copy-confirmation': 'Error details copied.',
        'data-copy-prompt': 'Copy these error details.',
        hidden: ''
    };
    const copyButton = {
        getAttribute: name => copyAttributes[name],
        setAttribute: (name, value) => { copyAttributes[name] = value; },
        removeAttribute: name => { delete copyAttributes[name]; },
        closest: () => row
    };
    const copyStatus = { textContent: '' };
    const copied = [];
    const prompted = [];
    const timers = [];
    const requests = [];
    const events = {};
    const row = {
        cells: [{}, {}, {}, {}],
        classList: { toggle() {}, remove() {} },
        getAttribute: name => attributes[name],
        setAttribute: (name, value) => { attributes[name] = value; },
        querySelector: selector => ({
            'input[name="super_sheep_copy_nonce"]': { value: 'nonce' },
            '[data-super-sheep-copy-job-progress-message]': progress,
            '[data-super-sheep-copy-job-state-label]': state,
            '[data-super-sheep-copy-retry-job]': button,
            '[data-super-sheep-copy-error-report]': copyButton,
            '[data-super-sheep-copy-copy-status]': copyStatus
        }[selector] || null)
    };
    const window = {
        URLSearchParams,
        ajaxurl: '/wp-admin/admin-ajax.php',
        superSheepCopyAdmin: { pluginVersion: '0.1.1' },
        navigator: useClipboard ? { clipboard: { writeText: async report => { copied.push(report); } } } : {},
        prompt: (message, report) => prompted.push({ message, report }),
        setTimeout: (callback, delay) => timers.push({ callback, delay }),
        fetch: async (url, options) => {
            requests.push(options.body);
            const response = responses[Math.min(requests.length - 1, responses.length - 1)];
            if (response instanceof Error) {
                throw response;
            }
            return { ok: response.status < 400, status: response.status, text: async () => response.text };
        }
    };
    const document = {
        readyState: 'loading',
        addEventListener: (name, callback) => { events[name] = callback; },
        querySelectorAll: selector => selector === '[data-super-sheep-copy-job-id]' ? [row] : []
    };
    vm.runInNewContext(script, { document, window });
    if (retry) {
        events.click({ target: { getAttribute: () => 'backup-123', closest: () => row } });
    } else {
        events.DOMContentLoaded();
    }
    const settle = () => new Promise(resolve => setImmediate(resolve));
    await settle();
    return {
        attributes, progress, state, button, timers, requests, settle, copyAttributes, copied, prompted, copyStatus,
        copy: () => events.click({ target: copyButton })
    };
}

(async () => {
    for (const status of [408, 429, 500, 502, 503, 504]) {
        const h = await harness([{ status, text: html }, completed]);
        assert.equal(h.state.textContent, 'packaging_archive');
        assert.equal(h.timers[0].delay, 5000);
        assert.match(h.progress.textContent, /Retrying backup/);
        assert.ok(!h.progress.textContent.includes('<html'));
        h.timers.shift().callback();
        await h.settle();
        assert.equal(h.state.textContent, 'completed');
        assert.equal(h.requests.length, 2);
    }

    const paused = await harness([{ status: 503, text: html }]);
    for (const delay of [5000, 10000, 20000, 30000, 30000, 30000]) {
        const timer = paused.timers.shift();
        assert.equal(timer.delay, delay);
        timer.callback();
        await paused.settle();
    }
    assert.equal(paused.timers.length, 0);
    assert.equal(paused.requests.length, 7);
    assert.equal(paused.attributes['data-super-sheep-copy-job-state'], 'packaging_archive');
    assert.match(paused.progress.textContent, /Use Retry/);
    assert.match(paused.progress.textContent, /PHP error log/);
    assert.equal(paused.button.style.display, '');
    assert.match(paused.copyAttributes['data-super-sheep-copy-error-report'], /BACKUP_REQUEST_HTTP_503/);
    assert.equal(paused.copyAttributes.hidden, undefined);

    const critical = '<p>Ha habido un error crítico en esta web.</p><p><a href="#">Más información</a></p>';
    const fragment = await harness([{ status: 200, text: critical }, completed]);
    assert.match(fragment.progress.textContent, /Retrying backup/);
    assert.ok(!fragment.progress.textContent.includes('<p>'));
    fragment.timers.shift().callback();
    await fragment.settle();
    assert.equal(fragment.state.textContent, 'completed');

    const retry = await harness([new Error('Failed to fetch'), completed], true);
    retry.timers.shift().callback();
    await retry.settle();
    assert.ok(retry.requests.every(body => new URLSearchParams(body).get('retry') === '1'));
    assert.equal(retry.state.textContent, 'completed');

    const denied = await harness([{ status: 403, text: html }]);
    assert.equal(denied.state.textContent, 'failed');
    assert.equal(denied.timers.length, 0);
    assert.match(denied.progress.textContent, /HTTP 403/);
    assert.ok(!denied.progress.textContent.includes('<html'));

    const report = 'Error code: BACKUP_PACKAGING_FAILED\nJob ID: backup-123';
    const failed = { status: 200, text: JSON.stringify({ success: true, data: {
        state: 'failed', status: 'failed', message: 'Check available disk space.', error_report: report
    } }) };
    const failure = await harness([failed]);
    assert.equal(failure.progress.textContent, 'Check available disk space.');
    failure.copy();
    await failure.settle();
    assert.deepEqual(failure.copied, [report]);
    assert.equal(failure.copyStatus.textContent, 'Error details copied.');
    const manualCopy = await harness([failed], false, false);
    manualCopy.copy();
    assert.deepEqual(manualCopy.prompted, [{ message: 'Copy these error details.', report }]);

    const invalidResponse = await harness([{ status: 400, text: 'Private file: /srv/example/wp-config.php' }]);
    assert.ok(!invalidResponse.progress.textContent.includes('wp-config.php'));
    assert.ok(!invalidResponse.copyAttributes['data-super-sheep-copy-error-report'].includes('wp-config.php'));

    process.stdout.write('Backup browser retry checks passed.\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
