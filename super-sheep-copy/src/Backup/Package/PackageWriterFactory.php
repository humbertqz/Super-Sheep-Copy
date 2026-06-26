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
            new TarGzPackageWriter(),
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
        $max_execution_time = ini_get('max_execution_time');

        return $this->bestAvailableForMaxExecutionTime(is_numeric($max_execution_time) ? (int) $max_execution_time : 0);
    }

    public function bestAvailableForMaxExecutionTime(int $max_execution_time): PackageWriterInterface
    {
        $zip = $this->availableWriterByFormat('zip');
        if ($zip !== null) {
            return $zip;
        }

        if ($max_execution_time > 0 && $max_execution_time <= 30) {
            $directory = $this->availableWriterByFormat('directory');
            if ($directory !== null) {
                return $directory;
            }
        }

        return $this->bestAvailable();
    }

    /**
     * @return PackageWriterInterface[]
     */
    public function availableWriters(): array
    {
        return $this->writers;
    }

    private function availableWriterByFormat(string $format): ?PackageWriterInterface
    {
        foreach ($this->writers as $writer) {
            if ($writer->format() === $format && $writer->isAvailable()) {
                return $writer;
            }
        }

        return null;
    }
}
