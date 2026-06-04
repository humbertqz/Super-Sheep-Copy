<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

interface BackupMetadataCollectorInterface
{
    /**
     * @return array<string,mixed>
     */
    public function collect(): array;
}
