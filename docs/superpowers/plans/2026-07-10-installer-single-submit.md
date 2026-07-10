# Installer Single-Submit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every restore-installer action submit once per user click.

**Architecture:** Keep the inline loading indicator. Let browser own form navigation; submit listener only adds working state and never cancels or resubmits form.

**Tech Stack:** PHP 7.2 standalone installer, inline JavaScript, PHPUnit.

---

### Task 1: Remove duplicate custom submission path

**Files:**
- Modify: `super-sheep-copy/installer/restore-engine/Bootstrap.php:73`
- Test: `super-sheep-copy/tests/Unit/InstallerBootstrapTest.php:84`

- [x] **Step 1: Write failing regression test**

Add assertions that generated installer markup keeps its submit listener and working-state call, but does not contain these manual-submission strings:

```php
self::assertStringNotContainsString('event.preventDefault();showInstallerWorkingState(form)', $html);
self::assertStringNotContainsString('window.HTMLFormElement.prototype.submit.call(form);', $html);
```

- [x] **Step 2: Run regression test red**

Run: `composer test -- --filter InstallerBootstrapTest::testInstallerActionFormsShowLoadingStateWhileSubmitting`

Expected: FAIL because current script prevents default and invokes `HTMLFormElement.prototype.submit`.

- [x] **Step 3: Implement minimal browser-native submit behavior**

Replace generated submit-listener body with:

```js
document.addEventListener("submit",function(event){var form=event.target;if(!form||!form.getAttribute||!form.getAttribute("data-ssc-installer-action")){return;}showInstallerWorkingState(form);});
```

- [x] **Step 4: Run regression test green**

Run: `composer test -- --filter InstallerBootstrapTest::testInstallerActionFormsShowLoadingStateWhileSubmitting`

Expected: PASS.

- [x] **Step 5: Run project verification**

Run: `composer test && composer lint && git diff --check`

Expected: all tests pass, lint clean, no whitespace errors.

- [x] **Step 6: Commit**

```bash
git add docs/superpowers/plans/2026-07-10-installer-single-submit.md super-sheep-copy/installer/restore-engine/Bootstrap.php super-sheep-copy/tests/Unit/InstallerBootstrapTest.php
git commit -m "fix: submit restore installer actions once"
```
