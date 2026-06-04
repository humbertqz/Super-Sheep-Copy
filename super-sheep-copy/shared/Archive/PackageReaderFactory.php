<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

final class PackageReaderFactory
{
    public function create(string $package_path): PackageReaderInterface
    {
        if (is_dir($package_path)) {
            return new DirectoryPackageReader($package_path);
        }

        $lower = strtolower($package_path);
        if (substr($lower, -7) === '.tar.gz' || substr($lower, -4) === '.tar') {
            return new TarGzPackageReader($package_path);
        }

        return new ZipPackageReader($package_path);
    }
}
