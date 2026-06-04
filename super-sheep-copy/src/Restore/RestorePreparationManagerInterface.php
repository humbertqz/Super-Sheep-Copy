<?php

declare(strict_types=1);

namespace SuperSheepCopy\Restore;

interface RestorePreparationManagerInterface
{
    /**
     * @param array<string,mixed> $upload
     */
    public function prepare(array $upload): RestorePreparationResult;
}
