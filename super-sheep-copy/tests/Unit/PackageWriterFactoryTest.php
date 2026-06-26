<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperSheepCopy\Backup\Package\PackageWriterFactory;
use SuperSheepCopy\Backup\Package\PackageWriterInterface;

final class PackageWriterFactoryTest extends TestCase
{
    public function testSelectsFirstAvailableWriter(): void
    {
        $factory = new PackageWriterFactory(array(
            new FakePackageWriter('zip', '.zip', false),
            new FakePackageWriter('tar.gz', '.tar.gz', true),
            new FakePackageWriter('directory', '', true),
        ));

        self::assertSame('tar.gz', $factory->bestAvailable()->format());
    }

    public function testSelectsDirectoryBeforeTarGzOnShortExecutionTimeHostsWithoutZip(): void
    {
        $factory = new PackageWriterFactory(array(
            new FakePackageWriter('zip', '.zip', false),
            new FakePackageWriter('tar.gz', '.tar.gz', true),
            new FakePackageWriter('directory', '', true),
        ));

        self::assertSame('directory', $factory->bestAvailableForMaxExecutionTime(30)->format());
    }

    public function testKeepsTarGzFallbackWhenExecutionTimeIsLongEnough(): void
    {
        $factory = new PackageWriterFactory(array(
            new FakePackageWriter('zip', '.zip', false),
            new FakePackageWriter('tar.gz', '.tar.gz', true),
            new FakePackageWriter('directory', '', true),
        ));

        self::assertSame('tar.gz', $factory->bestAvailableForMaxExecutionTime(120)->format());
    }

    public function testAlwaysPrefersZipWhenAvailableOnShortExecutionTimeHosts(): void
    {
        $factory = new PackageWriterFactory(array(
            new FakePackageWriter('zip', '.zip', true),
            new FakePackageWriter('tar.gz', '.tar.gz', true),
            new FakePackageWriter('directory', '', true),
        ));

        self::assertSame('zip', $factory->bestAvailableForMaxExecutionTime(30)->format());
    }

    public function testThrowsWhenNoWriterIsAvailable(): void
    {
        $factory = new PackageWriterFactory(array(new FakePackageWriter('zip', '.zip', false)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No backup package writer is available.');

        $factory->bestAvailable();
    }
}

final class FakePackageWriter implements PackageWriterInterface
{
    private string $format;
    private string $extension;
    private bool $available;

    public function __construct(string $format, string $extension, bool $available)
    {
        $this->format = $format;
        $this->extension = $extension;
        $this->available = $available;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function open(string $package_path): void
    {
    }

    public function addFile(string $source_path, string $entry_path): void
    {
    }

    public function addString(string $entry_path, string $contents): void
    {
    }

    public function close(): void
    {
    }
}
