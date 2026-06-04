<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

final class ArchiveValidator implements ArchiveValidatorInterface
{
    public function isSafePath(string $path): bool
    {
        return PackagePathGuard::isSafe($path);
    }

    public function validatePackage(string $archive_path): ArchiveValidationResult
    {
        return (new PackageValidator())->validate($archive_path);
    }
}
