# Super Sheep Copy Start Dev Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first testable Phase 1 foundation for `guide.md`: robust URL/data replacement, manifest generation, file scanning, and a backup archive skeleton.

**Architecture:** Keep destructive restore work out of the active WordPress runtime. Put pure, WordPress-free logic in `shared/`, WordPress orchestration in `src/Backup/`, and keep tests focused on behavior that can run with PHPUnit without a full WordPress install. This plan intentionally starts with the safest reusable components before database import, file replacement, rollback, or full installer restore.

**Tech Stack:** PHP 7.4+, WordPress plugin scaffold, Composer PSR-4 autoloading, PHPUnit 9.6, ZipArchive when available.

---

## Scope Check

`guide.md` describes a full backup and destructive cross-site restore product. That is too large for one reliable implementation plan. This first plan covers only the start-dev slice:

- Serialization-safe value replacement.
- URL variant replacement for plain, escaped, encoded, JSON, and serialized values.
- Backup manifest value object/builder.
- File scanner with default exclusions and symlink reporting.
- Archive writer skeleton that creates the package shape from the guide.
- Admin backup page wiring for a manifest preview only.

Future plans should cover database export/import chunking, standalone installer restore, rollback, file URL replacement, WP-CLI, resumable jobs, and integration test sites.

## Current Repo Notes

- Plugin root: `super-sheep-copy/`
- PHPUnit command: `cd super-sheep-copy && ./vendor/bin/phpunit`
- Lint command: `cd super-sheep-copy && composer run lint`
- This workspace currently has no `.git` directory at the repo root or plugin root. Before executing commit steps, initialize or attach the intended VCS workspace.

## File Structure

- Modify `super-sheep-copy/composer.json`
  - Add `SuperSheepCopy\Backup\` namespace only if classes are placed outside existing `SuperSheepCopy\` mapping. The current `SuperSheepCopy\` => `src/` mapping already covers `src/Backup`.
- Modify `super-sheep-copy/shared/Urls/UrlReplacementEngine.php`
  - Replace exact source URL variants in plain strings, escaped strings, and URL-encoded strings.
- Modify `super-sheep-copy/shared/Urls/UrlReplacementEngineInterface.php`
  - Keep existing `replace()` and `countReplacements()` contract.
- Create `super-sheep-copy/shared/Urls/StructuredValueReplacer.php`
  - Detect serialized PHP, JSON, and plain strings; delegate string replacement to `UrlReplacementEngine`.
- Create `super-sheep-copy/shared/Urls/StructuredValueReplacementResult.php`
  - Return replaced value plus replacement count and detected format.
- Modify `super-sheep-copy/shared/Serialization/SerializationWalker.php`
  - Keep recursive array/object walking behavior; avoid WordPress dependencies.
- Create `super-sheep-copy/src/Backup/Manifest.php`
  - Immutable manifest data with `toArray()`.
- Create `super-sheep-copy/src/Backup/ManifestBuilder.php`
  - Build manifest from explicit source metadata.
- Create `super-sheep-copy/src/Backup/FileScanner.php`
  - Scan a root path, exclude unsafe/noisy paths, record symlinks without following external targets.
- Create `super-sheep-copy/src/Backup/ScannedFile.php`
  - Value object for scanner output.
- Create `super-sheep-copy/src/Backup/ArchiveWriter.php`
  - Create ZIP package shape containing `manifest.json`, `checksums.json`, `logs/backup.log`, and selected files.
- Modify `super-sheep-copy/src/Admin/BackupPage.php`
  - Build a manifest preview for display.
- Modify `super-sheep-copy/templates/backup-page.php`
  - Show manifest preview and sensitive-data warning using escaped output.
- Create tests:
  - `super-sheep-copy/tests/Unit/StructuredValueReplacerTest.php`
  - `super-sheep-copy/tests/Unit/ManifestBuilderTest.php`
  - `super-sheep-copy/tests/Unit/FileScannerTest.php`
  - `super-sheep-copy/tests/Unit/ArchiveWriterTest.php`

---

### Task 0: Prepare Execution Workspace

**Files:**
- Inspect: `super-sheep-copy/composer.json`
- Inspect: `super-sheep-copy/phpunit.xml.dist`

- [ ] **Step 1: Confirm PHP dependencies load**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit --version
```

Expected: prints PHPUnit 9.x version.

- [ ] **Step 2: Run the current tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: current unit tests pass before changes.

- [ ] **Step 3: Confirm VCS state**

Run:

```bash
git status --short
```

Expected in the current workspace: `fatal: not a git repository`. If execution happens inside a real git worktree, expected output is either empty or a list of existing user changes that must not be reverted.

- [ ] **Step 4: Create VCS checkpoint if git is available**

Run only in a real git worktree:

```bash
git add guide.md super-sheep-copy
git commit -m "chore: baseline super sheep copy scaffold"
```

Expected: one baseline commit. If no git repository exists, record this checkpoint in the execution notes and continue without commit commands.

---

### Task 1: URL Variant Replacement

**Files:**
- Modify: `super-sheep-copy/shared/Urls/UrlReplacementEngine.php`
- Test: `super-sheep-copy/tests/Unit/UrlReplacementEngineTest.php`

- [ ] **Step 1: Extend the failing test**

Replace `super-sheep-copy/tests/Unit/UrlReplacementEngineTest.php` with:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Shared\Urls\UrlReplacementEngine;

final class UrlReplacementEngineTest extends TestCase
{
    public function testReplacesPlainUrl(): void
    {
        $engine = new UrlReplacementEngine();

        self::assertSame(
            'Visit http://localhost:8888/copysite/page',
            $engine->replace('Visit https://website.com/page', 'https://website.com', 'http://localhost:8888/copysite')
        );
        self::assertSame(1, $engine->countReplacements('Visit https://website.com/page', 'https://website.com'));
    }

    public function testReplacesUrlVariants(): void
    {
        $engine = new UrlReplacementEngine();
        $input = implode("\n", array(
            'https://website.com/page',
            'http://website.com/page',
            'https://www.website.com/page',
            'http://www.website.com/page',
            '//website.com/page',
            'https:\/\/website.com\/page',
            'https%3A%2F%2Fwebsite.com%2Fpage',
        ));

        $result = $engine->replace($input, 'https://website.com', 'http://localhost:8888/copysite');

        self::assertStringContainsString('http://localhost:8888/copysite/page', $result);
        self::assertStringContainsString('http:\/\/localhost:8888\/copysite\/page', $result);
        self::assertStringContainsString('http%3A%2F%2Flocalhost%3A8888%2Fcopysite%2Fpage', $result);
        self::assertStringNotContainsString('website.com', $result);
    }

    public function testCountsUrlVariants(): void
    {
        $engine = new UrlReplacementEngine();
        $input = 'https://website.com http://website.com https:\/\/website.com https%3A%2F%2Fwebsite.com';

        self::assertSame(4, $engine->countReplacements($input, 'https://website.com'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/UrlReplacementEngineTest.php
```

Expected: FAIL because `http://website.com`, `www`, escaped, and encoded variants are not replaced.

- [ ] **Step 3: Implement variant replacement**

Replace `super-sheep-copy/shared/Urls/UrlReplacementEngine.php` with:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Urls;

final class UrlReplacementEngine implements UrlReplacementEngineInterface
{
    public function replace(string $value, string $from, string $to): string
    {
        if ($from === '') {
            return $value;
        }

        foreach ($this->variantPairs($from, $to) as $source => $destination) {
            $value = str_replace($source, $destination, $value);
        }

        return $value;
    }

    public function countReplacements(string $value, string $from): int
    {
        if ($from === '') {
            return 0;
        }

        $count = 0;
        foreach (array_keys($this->variantPairs($from, $from)) as $source) {
            $count += substr_count($value, $source);
        }

        return $count;
    }

    /**
     * @return array<string,string>
     */
    private function variantPairs(string $from, string $to): array
    {
        $from_parts = parse_url($from);
        $to_parts = parse_url($to);

        if (!isset($from_parts['host'], $to_parts['host'])) {
            return array($from => $to);
        }

        $from_host = (string) $from_parts['host'];
        $to_host = (string) $to_parts['host'];
        $from_path = isset($from_parts['path']) ? rtrim((string) $from_parts['path'], '/') : '';
        $to_path = isset($to_parts['path']) ? rtrim((string) $to_parts['path'], '/') : '';
        $to_scheme = isset($to_parts['scheme']) ? (string) $to_parts['scheme'] : 'https';
        $to_authority = $to_scheme . '://' . $to_host . (isset($to_parts['port']) ? ':' . (string) $to_parts['port'] : '') . $to_path;

        $sources = array_values(array_unique(array(
            'https://' . $from_host . $from_path,
            'http://' . $from_host . $from_path,
            'https://www.' . preg_replace('/^www\./', '', $from_host) . $from_path,
            'http://www.' . preg_replace('/^www\./', '', $from_host) . $from_path,
            '//' . $from_host . $from_path,
        )));

        $pairs = array();
        foreach ($sources as $source) {
            $destination = strpos($source, '//') === 0 ? '//' . $to_host . (isset($to_parts['port']) ? ':' . (string) $to_parts['port'] : '') . $to_path : $to_authority;
            $pairs[$source] = $destination;
            $pairs[str_replace('/', '\/', $source)] = str_replace('/', '\/', $destination);
            $pairs[rawurlencode($source)] = rawurlencode($destination);
        }

        uksort($pairs, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        return $pairs;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/UrlReplacementEngineTest.php
```

Expected: OK.

- [ ] **Step 5: Run all unit tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: OK.

- [ ] **Step 6: Commit**

Run only in a real git worktree:

```bash
git add super-sheep-copy/shared/Urls/UrlReplacementEngine.php super-sheep-copy/tests/Unit/UrlReplacementEngineTest.php
git commit -m "feat: replace source url variants"
```

Expected: commit succeeds.

---

### Task 2: Structured Serialized and JSON Value Replacement

**Files:**
- Create: `super-sheep-copy/shared/Urls/StructuredValueReplacementResult.php`
- Create: `super-sheep-copy/shared/Urls/StructuredValueReplacer.php`
- Test: `super-sheep-copy/tests/Unit/StructuredValueReplacerTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/StructuredValueReplacerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Shared\Serialization\SerializationWalker;
use SuperSheepCopy\Shared\Urls\StructuredValueReplacer;
use SuperSheepCopy\Shared\Urls\UrlReplacementEngine;

final class StructuredValueReplacerTest extends TestCase
{
    public function testReplacesSerializedArrayWithoutCorruptingLengths(): void
    {
        $replacer = new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker());
        $serialized = serialize(array('url' => 'https://website.com/page'));

        $result = $replacer->replace($serialized, 'https://website.com', 'http://localhost:8888/copysite');
        $decoded = unserialize($result->value(), array('allowed_classes' => true));

        self::assertSame('serialized', $result->format());
        self::assertSame(1, $result->replacementCount());
        self::assertSame('http://localhost:8888/copysite/page', $decoded['url']);
    }

    public function testReplacesJsonWithoutEscapingSlashes(): void
    {
        $replacer = new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker());

        $result = $replacer->replace('{"url":"https:\/\/website.com\/page"}', 'https://website.com', 'http://localhost:8888/copysite');

        self::assertSame('json', $result->format());
        self::assertSame('{"url":"http://localhost:8888/copysite/page"}', $result->value());
    }

    public function testFallsBackToPlainString(): void
    {
        $replacer = new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker());

        $result = $replacer->replace('Visit https://website.com/page', 'https://website.com', 'http://localhost:8888/copysite');

        self::assertSame('plain', $result->format());
        self::assertSame('Visit http://localhost:8888/copysite/page', $result->value());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/StructuredValueReplacerTest.php
```

Expected: FAIL with class `StructuredValueReplacer` not found.

- [ ] **Step 3: Add result value object**

Create `super-sheep-copy/shared/Urls/StructuredValueReplacementResult.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Urls;

final class StructuredValueReplacementResult
{
    private string $value;
    private int $replacement_count;
    private string $format;

    public function __construct(string $value, int $replacement_count, string $format)
    {
        $this->value = $value;
        $this->replacement_count = $replacement_count;
        $this->format = $format;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function replacementCount(): int
    {
        return $this->replacement_count;
    }

    public function format(): string
    {
        return $this->format;
    }
}
```

- [ ] **Step 4: Add structured replacer**

Create `super-sheep-copy/shared/Urls/StructuredValueReplacer.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Urls;

use SuperSheepCopy\Shared\Serialization\SerializationWalkerInterface;

final class StructuredValueReplacer
{
    private UrlReplacementEngineInterface $url_replacer;
    private SerializationWalkerInterface $walker;

    public function __construct(UrlReplacementEngineInterface $url_replacer, SerializationWalkerInterface $walker)
    {
        $this->url_replacer = $url_replacer;
        $this->walker = $walker;
    }

    public function replace(string $value, string $from, string $to): StructuredValueReplacementResult
    {
        if ($this->isSerialized($value)) {
            $decoded = unserialize($value, array('allowed_classes' => true));
            $count = 0;
            $replaced = $this->walker->walk($decoded, function (string $item) use ($from, $to, &$count): string {
                $count += $this->url_replacer->countReplacements($item, $from);
                return $this->url_replacer->replace($item, $from, $to);
            });

            return new StructuredValueReplacementResult(serialize($replaced), $count, 'serialized');
        }

        $json = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($json) || is_object($json))) {
            $count = 0;
            $replaced = $this->walker->walk($json, function (string $item) use ($from, $to, &$count): string {
                $count += $this->url_replacer->countReplacements($item, $from);
                return $this->url_replacer->replace($item, $from, $to);
            });

            return new StructuredValueReplacementResult(
                (string) json_encode($replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $count,
                'json'
            );
        }

        return new StructuredValueReplacementResult(
            $this->url_replacer->replace($value, $from, $to),
            $this->url_replacer->countReplacements($value, $from),
            'plain'
        );
    }

    private function isSerialized(string $value): bool
    {
        if ($value === 'b:0;') {
            return true;
        }

        if (!preg_match('/^(a|O|s|i|d|b|N):/', $value)) {
            return false;
        }

        return unserialize($value, array('allowed_classes' => true)) !== false;
    }
}
```

- [ ] **Step 5: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/StructuredValueReplacerTest.php
```

Expected: OK.

- [ ] **Step 6: Run all unit tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: OK.

- [ ] **Step 7: Commit**

Run only in a real git worktree:

```bash
git add super-sheep-copy/shared/Urls super-sheep-copy/tests/Unit/StructuredValueReplacerTest.php
git commit -m "feat: replace urls in structured values"
```

Expected: commit succeeds.

---

### Task 3: Backup Manifest Builder

**Files:**
- Create: `super-sheep-copy/src/Backup/Manifest.php`
- Create: `super-sheep-copy/src/Backup/ManifestBuilder.php`
- Test: `super-sheep-copy/tests/Unit/ManifestBuilderTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/ManifestBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\ManifestBuilder;

final class ManifestBuilderTest extends TestCase
{
    public function testBuildsManifestArray(): void
    {
        $builder = new ManifestBuilder('0.1.0', '1');

        $manifest = $builder->build(array(
            'source_site_url' => 'https://website.com',
            'source_home_url' => 'https://website.com',
            'wordpress_version' => '6.5.0',
            'php_version' => '8.2.0',
            'database_version' => '8.0',
            'table_prefix' => 'wp_',
            'is_multisite' => false,
            'active_theme' => 'twentytwentyfour',
            'active_plugins' => array('akismet/akismet.php'),
            'must_use_plugins' => array(),
            'created_at' => '2026-05-15T12:00:00+00:00',
            'file_count' => 12,
            'database_table_count' => 9,
            'archive_size' => 1024,
            'checksums' => array('manifest.json' => 'abc123'),
            'exclusions' => array('.git', 'node_modules'),
            'environment' => array('zip' => true),
        ));

        self::assertSame('Super Sheep Copy', $manifest->toArray()['project']);
        self::assertSame('0.1.0', $manifest->toArray()['plugin_version']);
        self::assertSame('1', $manifest->toArray()['backup_format_version']);
        self::assertSame('https://website.com', $manifest->toArray()['source_site_url']);
        self::assertFalse($manifest->toArray()['is_multisite']);
        self::assertSame(12, $manifest->toArray()['file_count']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ManifestBuilderTest.php
```

Expected: FAIL with class `ManifestBuilder` not found.

- [ ] **Step 3: Add manifest value object**

Create `super-sheep-copy/src/Backup/Manifest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class Manifest
{
    /**
     * @var array<string,mixed>
     */
    private array $data;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Step 4: Add manifest builder**

Create `super-sheep-copy/src/Backup/ManifestBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class ManifestBuilder
{
    private string $plugin_version;
    private string $backup_format_version;

    public function __construct(string $plugin_version, string $backup_format_version)
    {
        $this->plugin_version = $plugin_version;
        $this->backup_format_version = $backup_format_version;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function build(array $metadata): Manifest
    {
        return new Manifest(array(
            'project' => 'Super Sheep Copy',
            'plugin_version' => $this->plugin_version,
            'backup_format_version' => $this->backup_format_version,
            'source_site_url' => (string) $metadata['source_site_url'],
            'source_home_url' => (string) $metadata['source_home_url'],
            'source_wordpress_version' => (string) $metadata['wordpress_version'],
            'source_php_version' => (string) $metadata['php_version'],
            'source_database_version' => (string) $metadata['database_version'],
            'source_table_prefix' => (string) $metadata['table_prefix'],
            'is_multisite' => (bool) $metadata['is_multisite'],
            'active_theme' => (string) $metadata['active_theme'],
            'active_plugins' => array_values((array) $metadata['active_plugins']),
            'must_use_plugins' => array_values((array) $metadata['must_use_plugins']),
            'created_at' => (string) $metadata['created_at'],
            'file_count' => (int) $metadata['file_count'],
            'database_table_count' => (int) $metadata['database_table_count'],
            'archive_size' => (int) $metadata['archive_size'],
            'checksums' => (array) $metadata['checksums'],
            'exclusions' => array_values((array) $metadata['exclusions']),
            'environment' => (array) $metadata['environment'],
        ));
    }
}
```

- [ ] **Step 5: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ManifestBuilderTest.php
```

Expected: OK.

- [ ] **Step 6: Run all unit tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: OK.

- [ ] **Step 7: Commit**

Run only in a real git worktree:

```bash
git add super-sheep-copy/src/Backup/Manifest.php super-sheep-copy/src/Backup/ManifestBuilder.php super-sheep-copy/tests/Unit/ManifestBuilderTest.php
git commit -m "feat: build backup manifests"
```

Expected: commit succeeds.

---

### Task 4: File Scanner With Exclusions

**Files:**
- Create: `super-sheep-copy/src/Backup/ScannedFile.php`
- Create: `super-sheep-copy/src/Backup/FileScanner.php`
- Test: `super-sheep-copy/tests/Unit/FileScannerTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/FileScannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\FileScanner;

final class FileScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ssc-file-scan-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/wp-content/uploads', 0777, true);
        mkdir($this->root . '/wp-content/cache', 0777, true);
        mkdir($this->root . '/.git', 0777, true);
        file_put_contents($this->root . '/.htaccess', 'RewriteEngine On');
        file_put_contents($this->root . '/wp-content/uploads/image.txt', 'image');
        file_put_contents($this->root . '/wp-content/cache/page.html', 'cache');
        file_put_contents($this->root . '/.git/config', 'git');
        file_put_contents($this->root . '/.DS_Store', 'junk');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testScansFilesAndExcludesNoisyDirectories(): void
    {
        $scanner = new FileScanner();
        $files = $scanner->scan($this->root);
        $paths = array_map(static fn ($file): string => $file->relativePath(), $files);
        sort($paths);

        self::assertSame(array('.htaccess', 'wp-content/uploads/image.txt'), $paths);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/FileScannerTest.php
```

Expected: FAIL with class `FileScanner` not found.

- [ ] **Step 3: Add scanned file value object**

Create `super-sheep-copy/src/Backup/ScannedFile.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

final class ScannedFile
{
    private string $absolute_path;
    private string $relative_path;
    private int $size;
    private bool $symlink;

    public function __construct(string $absolute_path, string $relative_path, int $size, bool $symlink)
    {
        $this->absolute_path = $absolute_path;
        $this->relative_path = $relative_path;
        $this->size = $size;
        $this->symlink = $symlink;
    }

    public function absolutePath(): string
    {
        return $this->absolute_path;
    }

    public function relativePath(): string
    {
        return $this->relative_path;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isSymlink(): bool
    {
        return $this->symlink;
    }
}
```

- [ ] **Step 4: Add file scanner**

Create `super-sheep-copy/src/Backup/FileScanner.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FileScanner
{
    /**
     * @var string[]
     */
    private array $excluded_segments = array(
        '.git',
        '.hg',
        '.svn',
        'node_modules',
        'wp-content/cache',
        'wp-content/uploads/super-sheep-copy',
    );

    /**
     * @var string[]
     */
    private array $excluded_names = array('.DS_Store', 'Thumbs.db');

    /**
     * @return ScannedFile[]
     */
    public function scan(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $files = array();
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($absolute, strlen($root)), '/');
            if ($this->isExcluded($relative)) {
                continue;
            }

            $files[] = new ScannedFile($absolute, $relative, (int) $item->getSize(), $item->isLink());
        }

        usort($files, static function (ScannedFile $a, ScannedFile $b): int {
            return strcmp($a->relativePath(), $b->relativePath());
        });

        return $files;
    }

    private function isExcluded(string $relative): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        $name = basename($relative);
        if (in_array($name, $this->excluded_names, true)) {
            return true;
        }

        foreach ($this->excluded_segments as $segment) {
            if ($relative === $segment || strpos($relative, $segment . '/') === 0) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/FileScannerTest.php
```

Expected: OK.

- [ ] **Step 6: Run all unit tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: OK.

- [ ] **Step 7: Commit**

Run only in a real git worktree:

```bash
git add super-sheep-copy/src/Backup/ScannedFile.php super-sheep-copy/src/Backup/FileScanner.php super-sheep-copy/tests/Unit/FileScannerTest.php
git commit -m "feat: scan backup files with exclusions"
```

Expected: commit succeeds.

---

### Task 5: Archive Writer Skeleton

**Files:**
- Create: `super-sheep-copy/src/Backup/ArchiveWriter.php`
- Test: `super-sheep-copy/tests/Unit/ArchiveWriterTest.php`

- [ ] **Step 1: Write the failing test**

Create `super-sheep-copy/tests/Unit/ArchiveWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\ArchiveWriter;
use SuperSheepCopy\Backup\Manifest;
use SuperSheepCopy\Backup\ScannedFile;
use ZipArchive;

final class ArchiveWriterTest extends TestCase
{
    public function testWritesBackupPackageShape(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $root = sys_get_temp_dir() . '/ssc-archive-' . bin2hex(random_bytes(4));
        mkdir($root . '/source/wp-content/uploads', 0777, true);
        mkdir($root . '/output', 0777, true);
        file_put_contents($root . '/source/wp-content/uploads/file.txt', 'backup file');

        $archive = $root . '/output/backup.zip';
        $writer = new ArchiveWriter();
        $writer->write(
            $archive,
            new Manifest(array('project' => 'Super Sheep Copy')),
            array(new ScannedFile($root . '/source/wp-content/uploads/file.txt', 'wp-content/uploads/file.txt', 11, false)),
            array('wp-content/uploads/file.txt' => 'hash123'),
            'backup started'
        );

        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive));
        self::assertSame('backup file', $zip->getFromName('files/wp-content/uploads/file.txt'));
        self::assertNotFalse($zip->getFromName('manifest.json'));
        self::assertNotFalse($zip->getFromName('checksums.json'));
        self::assertSame('backup started', $zip->getFromName('logs/backup.log'));
        $zip->close();

        $this->removeDirectory($root);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: array(), array('.', '..'));
        foreach ($items as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ArchiveWriterTest.php
```

Expected: FAIL with class `ArchiveWriter` not found, or SKIPPED if ZipArchive is unavailable.

- [ ] **Step 3: Add archive writer**

Create `super-sheep-copy/src/Backup/ArchiveWriter.php`:

```php
<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RuntimeException;
use ZipArchive;

final class ArchiveWriter
{
    /**
     * @param ScannedFile[] $files
     * @param array<string,string> $checksums
     */
    public function write(string $archive_path, Manifest $manifest, array $files, array $checksums, string $log): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is not available.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create backup archive.');
        }

        $zip->addFromString('manifest.json', $manifest->toJson());
        $zip->addFromString('checksums.json', (string) json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('logs/backup.log', $log);

        foreach ($files as $file) {
            if ($file->isSymlink()) {
                continue;
            }
            $zip->addFile($file->absolutePath(), 'files/' . $file->relativePath());
        }

        $zip->close();
    }
}
```

- [ ] **Step 4: Run focused test**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit tests/Unit/ArchiveWriterTest.php
```

Expected: OK, or SKIPPED if ZipArchive is unavailable.

- [ ] **Step 5: Run all unit tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: OK with zero failures.

- [ ] **Step 6: Commit**

Run only in a real git worktree:

```bash
git add super-sheep-copy/src/Backup/ArchiveWriter.php super-sheep-copy/tests/Unit/ArchiveWriterTest.php
git commit -m "feat: write backup archive skeleton"
```

Expected: commit succeeds.

---

### Task 6: Backup Page Manifest Preview

**Files:**
- Modify: `super-sheep-copy/src/Admin/BackupPage.php`
- Modify: `super-sheep-copy/templates/backup-page.php`

- [ ] **Step 1: Inspect current template**

Run:

```bash
cd super-sheep-copy && sed -n '1,220p' templates/backup-page.php
```

Expected: current backup page template output is visible.

- [ ] **Step 2: Modify `BackupPage` to prepare manifest preview**

Update `super-sheep-copy/src/Admin/BackupPage.php` so `render()` contains this code before the `include`:

```php
$manifest_preview = array(
    'project' => 'Super Sheep Copy',
    'plugin_version' => defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.0',
    'backup_format_version' => '1',
    'source_site_url' => function_exists('site_url') ? site_url() : '',
    'source_home_url' => function_exists('home_url') ? home_url() : '',
    'is_multisite' => function_exists('is_multisite') ? is_multisite() : false,
);
```

The final method should read:

```php
public function render(): void
{
    $this->capability->requireManageBackups();
    $environment = $this->environment_checker->check();
    $jobs = $this->jobs->all();
    $manifest_preview = array(
        'project' => 'Super Sheep Copy',
        'plugin_version' => defined('SUPER_SHEEP_COPY_VERSION') ? SUPER_SHEEP_COPY_VERSION : '0.1.0',
        'backup_format_version' => '1',
        'source_site_url' => function_exists('site_url') ? site_url() : '',
        'source_home_url' => function_exists('home_url') ? home_url() : '',
        'is_multisite' => function_exists('is_multisite') ? is_multisite() : false,
    );
    include SUPER_SHEEP_COPY_DIR . 'templates/backup-page.php';
}
```

- [ ] **Step 3: Modify backup template**

Add this block to `super-sheep-copy/templates/backup-page.php` after the page heading:

```php
<div class="notice notice-warning">
    <p><?php esc_html_e('Backups contain sensitive site data including users, password hashes, orders, API keys, and private content. Store backup files securely.', 'super-sheep-copy'); ?></p>
</div>

<h2><?php esc_html_e('Manifest Preview', 'super-sheep-copy'); ?></h2>
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
```

- [ ] **Step 4: Lint changed PHP**

Run:

```bash
cd super-sheep-copy && php -l src/Admin/BackupPage.php && php -l templates/backup-page.php
```

Expected: `No syntax errors detected` for both files.

- [ ] **Step 5: Run all tests**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: OK.

- [ ] **Step 6: Commit**

Run only in a real git worktree:

```bash
git add super-sheep-copy/src/Admin/BackupPage.php super-sheep-copy/templates/backup-page.php
git commit -m "feat: show backup manifest preview"
```

Expected: commit succeeds.

---

### Task 7: Final Verification

**Files:**
- Verify: `super-sheep-copy/shared/Urls/UrlReplacementEngine.php`
- Verify: `super-sheep-copy/shared/Urls/StructuredValueReplacer.php`
- Verify: `super-sheep-copy/src/Backup`
- Verify: `super-sheep-copy/tests/Unit`

- [ ] **Step 1: Run lint**

Run:

```bash
cd super-sheep-copy && composer run lint
```

Expected: every PHP file reports no syntax errors.

- [ ] **Step 2: Run full PHPUnit suite**

Run:

```bash
cd super-sheep-copy && ./vendor/bin/phpunit
```

Expected: OK with no failures. `ArchiveWriterTest` may be skipped only when `ZipArchive` is unavailable.

- [ ] **Step 3: Search for remaining source URL in generated tests only**

Run:

```bash
cd super-sheep-copy && rg "website\\.com" tests shared src
```

Expected: occurrences remain in tests and replacement fixture strings only.

- [ ] **Step 4: Commit final verification note**

Run only in a real git worktree:

```bash
git status --short
```

Expected: empty working tree after the prior task commits.

## Self-Review

- Spec coverage: This plan starts the Phase 1 foundation from `guide.md`: URL replacement variants, serialized/JSON safety, manifest metadata, file scanning/exclusions, archive package shape, and admin backup warning/preview. It deliberately does not implement destructive restore, rollback execution, database chunking, standalone installer import, WP-CLI, or resumable jobs; those need separate plans.
- Placeholder scan: No implementation step relies on unspecified code. Every new class and test has concrete code.
- Type consistency: Class names and namespaces match Composer’s existing `SuperSheepCopy\` and `SuperSheepCopy\Shared\` PSR-4 mappings.

