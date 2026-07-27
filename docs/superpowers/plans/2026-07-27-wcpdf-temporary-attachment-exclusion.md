# WCPDF Temporary Attachment Exclusion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Exclude WP Overnight's temporary generated email attachments from file scanning without excluding persistent invoice archives or unrelated attachment directories.

**Architecture:** Add one anchored, segment-aware path predicate to `FileScanner` and call it from the scanner's shared exclusion method. Both full and resumable scans already use that method, so one rule covers both paths while leaving checksum and archive failure behavior unchanged.

**Tech Stack:** PHP 7.4+, WordPress filesystem paths, PHPUnit 9.6

---

## File Structure

- Modify `super-sheep-copy/tests/Unit/FileScannerTest.php`: cover the temporary path, persistent archive sibling, unrelated attachment directory, and both scanner APIs.
- Modify `super-sheep-copy/src/Backup/FileScanner.php`: recognize the narrowly scoped WCPDF temporary attachment path.

### Task 1: Define the WCPDF Exclusion Boundary with Failing Tests

**Files:**

- Modify: `super-sheep-copy/tests/Unit/FileScannerTest.php:34`

- [ ] **Step 1: Add a fixture helper for temporary and persistent PDFs**

Add this method before `removeDirectory()`:

```php
private function createWcpdfFiles(): void
{
    mkdir($this->root . '/wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/attachments', 0777, true);
    mkdir($this->root . '/wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/archive', 0777, true);
    mkdir($this->root . '/wp-content/uploads/customer-documents/attachments', 0777, true);

    file_put_contents(
        $this->root . '/wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/attachments/invoice-8370.pdf',
        'temporary invoice'
    );
    file_put_contents(
        $this->root . '/wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/archive/invoice-8370.pdf',
        'persistent invoice'
    );
    file_put_contents(
        $this->root . '/wp-content/uploads/customer-documents/attachments/invoice-8370.pdf',
        'customer document'
    );
}
```

- [ ] **Step 2: Add the full-scan regression test**

Insert this test after `testScansFilesAndExcludesNoisyDirectories()`:

```php
public function testScanExcludesOnlyWcpdfTemporaryAttachments(): void
{
    $this->createWcpdfFiles();

    $paths = array_map(
        static fn ($file): string => $file->relativePath(),
        (new FileScanner())->scan($this->root)
    );

    self::assertNotContains(
        'wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/attachments/invoice-8370.pdf',
        $paths
    );
    self::assertContains(
        'wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/archive/invoice-8370.pdf',
        $paths
    );
    self::assertContains(
        'wp-content/uploads/customer-documents/attachments/invoice-8370.pdf',
        $paths
    );
}
```

- [ ] **Step 3: Add the resumable-scan regression test**

Insert this test after the full-scan regression test:

```php
public function testScanStepExcludesWcpdfTemporaryAttachments(): void
{
    $this->createWcpdfFiles();
    $payload = array();
    $scanner = new FileScanner();

    while (empty($payload['file_scan_complete'])) {
        $payload = $scanner->scanStep($this->root, $payload, 1);
    }

    $paths = array_map(static function (array $file): string {
        return (string) $file['relative_path'];
    }, $payload['scanned_files']);

    self::assertNotContains(
        'wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/attachments/invoice-8370.pdf',
        $paths
    );
    self::assertContains(
        'wp-content/uploads/wpo_wcpdf_3fd2be178174c22c46a531a81aaee8ce/archive/invoice-8370.pdf',
        $paths
    );
}
```

- [ ] **Step 4: Run the focused test and verify RED**

Run:

```bash
cd super-sheep-copy
vendor/bin/phpunit tests/Unit/FileScannerTest.php
```

Expected: the two new tests fail because the temporary
`attachments/invoice-8370.pdf` path is still present. Existing scanner tests
pass.

### Task 2: Implement the Narrow Path Exclusion

**Files:**

- Modify: `super-sheep-copy/src/Backup/FileScanner.php:257`
- Test: `super-sheep-copy/tests/Unit/FileScannerTest.php`

- [ ] **Step 1: Route WCPDF temporary paths through the shared exclusion**

In `FileScanner::isExcluded()`, after the excluded-name check and before the
existing segment loop, add:

```php
if ($this->isWcpdfTemporaryAttachment($relative)) {
    return true;
}
```

- [ ] **Step 2: Add the anchored path predicate**

Add this method immediately before `isExcluded()`:

```php
private function isWcpdfTemporaryAttachment(string $relative): bool
{
    return preg_match(
        '#^wp-content/uploads/wpo_wcpdf_[^/]+/attachments(?:/|$)#',
        $relative
    ) === 1;
}
```

The expression requires:

- the path to begin at `wp-content/uploads`;
- a non-empty randomized suffix after `wpo_wcpdf_`;
- `attachments` as a complete directory segment.

- [ ] **Step 3: Run the focused test and verify GREEN**

Run:

```bash
cd super-sheep-copy
vendor/bin/phpunit tests/Unit/FileScannerTest.php
```

Expected: all scanner tests pass. The WCPDF temporary PDF is absent, while the
WCPDF archive PDF and unrelated attachment PDF remain present.

- [ ] **Step 4: Check the focused diff**

Run:

```bash
git diff --check
git diff -- super-sheep-copy/src/Backup/FileScanner.php super-sheep-copy/tests/Unit/FileScannerTest.php
```

Expected: the whitespace check produces no output, and the diff contains only
the focused predicate and its regression coverage.

- [ ] **Step 5: Commit the tested fix**

```bash
git add super-sheep-copy/src/Backup/FileScanner.php super-sheep-copy/tests/Unit/FileScannerTest.php
git commit -m "fix: exclude temporary WCPDF attachments"
```

### Task 3: Verify the Complete Plugin

**Files:**

- No source changes expected.

- [ ] **Step 1: Run the complete PHPUnit suite**

Run:

```bash
cd super-sheep-copy
vendor/bin/phpunit
```

Expected: the complete suite passes with zero failures and zero errors.

- [ ] **Step 2: Run PHP syntax validation**

Run:

```bash
cd super-sheep-copy
composer lint
```

Expected: every PHP file reports `No syntax errors detected` and the command
exits with status 0.

- [ ] **Step 3: Verify branch state**

Run:

```bash
git diff HEAD~1 --check
git status --short
```

Expected: `git diff --check` produces no output and the working tree is clean.
