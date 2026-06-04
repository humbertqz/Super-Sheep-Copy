<?php

declare(strict_types=1);

namespace SuperSheepCopy\Support;

interface EnvironmentCheckerInterface
{
    /**
     * @return array<string, array{label:string,value:string,status:string}>
     */
    public function check(): array;
}
