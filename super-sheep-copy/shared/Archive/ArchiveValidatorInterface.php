<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Archive;

interface ArchiveValidatorInterface
{
    public function isSafePath(string $path): bool;

    public function validatePackage(string $archive_path): ArchiveValidationResult;
}
