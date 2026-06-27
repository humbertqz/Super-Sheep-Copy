<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup\Package;

use RuntimeException;

final class PackageWriterFactory
{
    /** @var PackageWriterInterface[] */
    private array $writers;

    /**
     * @param PackageWriterInterface[]|null $writers
     */
    public function __construct(?array $writers = null)
    {
        $this->writers = $writers ?? array(
            new ZipPackageWriter(),
            new CliZipPackageWriter(),
            new TarGzPackageWriter(),
            new PclZipPackageWriter(),
            new DirectoryPackageWriter(),
        );
    }

    public function bestAvailable(): PackageWriterInterface
    {
        foreach ($this->writers as $writer) {
            if ($writer->isAvailable()) {
                return $writer;
            }
        }

        throw new RuntimeException('No backup package writer is available.');
    }

    public function bestAvailableForCurrentEnvironment(): PackageWriterInterface
    {
        return $this->bestAvailable();
    }

    public function bestAvailableForMaxExecutionTime(int $max_execution_time): PackageWriterInterface
    {
        return $this->bestAvailable();
    }

    /**
     * @return PackageWriterInterface[]
     */
    public function availableWriters(): array
    {
        return $this->writers;
    }
}
