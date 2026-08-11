<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class LegacyZeroDateDefaultDetector
{
    /**
     * @param array<string,string> $chunks
     */
    public function requiresCompatibility(array $chunks): bool
    {
        foreach ($chunks as $sql) {
            if (preg_match('/(?:^|;)\s*CREATE\s+TABLE\b[^;]*\bDEFAULT\s+\'0000-00-00(?:\s+00:00:00)?\'/is', $sql) === 1) {
                return true;
            }
        }

        return false;
    }
}
