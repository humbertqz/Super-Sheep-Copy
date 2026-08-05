<?php

declare(strict_types=1);

namespace SuperSheepCopy\Jobs;

interface RefreshableJobRepositoryInterface
{
    public function refresh(): void;
}
