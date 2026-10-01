<?php

declare(strict_types=1);

namespace SuperSheepCopy\Backup;

use RuntimeException;
use SuperSheepCopy\Backup\Package\PackageWriterInterface;

final class ManualRestoreResources
{
    public static function addTo(PackageWriterInterface $writer): void
    {
        foreach (array('MANUAL-RESTORE.md', 'build-database.php') as $name) {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/resources/' . $name);
            if ($contents === false) {
                throw new RuntimeException('Missing manual restore resource: ' . $name);
            }
            $writer->addString($name, $contents);
        }
    }
}
