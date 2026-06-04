<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/installer/restore-engine/ConfirmationStore.php';

final class ConfirmationStoreTest extends TestCase
{
    private string $engine;

    protected function setUp(): void
    {
        $this->engine = sys_get_temp_dir() . '/ssc-confirmation-store-' . bin2hex(random_bytes(4));
        mkdir($this->engine, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->engine . '/*') ?: array() as $file) {
            unlink($file);
        }
        rmdir($this->engine);
    }

    public function testRejectsMissingCheckbox(): void
    {
        $store = new \SuperSheepCopyInstaller\ConfirmationStore();
        $config = $this->config();
        $this->writeConfig($config);

        self::assertFalse($store->confirm($this->engine, $config, 'RESTORE', false, false));
        self::assertFalse($store->isConfirmed(require $this->engine . '/config.php'));
    }

    public function testRejectsWrongTypedPhrase(): void
    {
        $store = new \SuperSheepCopyInstaller\ConfirmationStore();
        $config = $this->config();
        $this->writeConfig($config);

        self::assertFalse($store->confirm($this->engine, $config, 'restore', true, false));
        self::assertFalse($store->isConfirmed(require $this->engine . '/config.php'));
    }

    public function testRejectsBlockingPreflightErrors(): void
    {
        $store = new \SuperSheepCopyInstaller\ConfirmationStore();
        $config = $this->config();
        $this->writeConfig($config);

        self::assertFalse($store->confirm($this->engine, $config, 'RESTORE', true, true));
        self::assertFalse($store->isConfirmed(require $this->engine . '/config.php'));
    }

    public function testWritesConfirmationFieldsAndPreservesConfig(): void
    {
        $store = new \SuperSheepCopyInstaller\ConfirmationStore();
        $config = $this->config();
        $this->writeConfig($config);

        self::assertTrue($store->confirm($this->engine, $config, 'RESTORE', true, false));

        $updated = require $this->engine . '/config.php';
        self::assertTrue($store->isConfirmed($updated));
        self::assertSame('restore-123', $updated['restore_job_id']);
        self::assertSame('hash', $updated['token_hash']);
        self::assertArrayHasKey('restore_confirmed_at', $updated);
    }

    private function config(): array
    {
        return array(
            'restore_job_id' => 'restore-123',
            'token_hash' => 'hash',
            'staged_archive_path' => '/tmp/backup.zip',
            'locked' => false,
        );
    }

    private function writeConfig(array $config): void
    {
        file_put_contents($this->engine . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
    }
}
