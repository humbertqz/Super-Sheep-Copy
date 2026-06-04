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

    /**
     * @return PackageWriterInterface[]
     */
    public function availableWriters(): array
    {
        return $this->writers;
    }
}
