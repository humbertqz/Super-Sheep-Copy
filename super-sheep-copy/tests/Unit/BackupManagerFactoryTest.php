<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Backup\BackupManagerFactory;
use SuperSheepCopy\Backup\BackupRunnerInterface;
use SuperSheepCopy\Jobs\OptionJobRepository;

final class BackupManagerFactoryTest extends TestCase
{
    public function testCreatesBackupRunner(): void
    {
        $factory = new BackupManagerFactory(new OptionJobRepository(), new FactoryWpdbStub());

        self::assertInstanceOf(BackupRunnerInterface::class, $factory->create());
    }
}

final class FactoryWpdbStub
{
    public string $prefix = 'wp_';
}
