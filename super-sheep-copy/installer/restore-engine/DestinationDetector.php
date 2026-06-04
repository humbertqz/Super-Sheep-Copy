<?php

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

final class DestinationDetector
{
    /**
     * @param array<string,mixed> $server
     */
    public function detect(array $server): string
    {
        $host = isset($server['HTTP_HOST']) ? (string) $server['HTTP_HOST'] : '';
        if ($host === '' && isset($server['SERVER_NAME'])) {
            $host = (string) $server['SERVER_NAME'];
        }

        if ($host === '') {
            return '';
        }

        $https = isset($server['HTTPS']) && strtolower((string) $server['HTTPS']) === 'on';
        $forwarded = isset($server['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $server['HTTP_X_FORWARDED_PROTO']) === 'https';
        $scheme = ($https || $forwarded) ? 'https' : 'http';
        $script = isset($server['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $server['SCRIPT_NAME']) : '/installer.php';
        $directory = rtrim(str_replace('/installer.php', '', $script), '/');

        return $scheme . '://' . $host . ($directory === '' ? '' : $directory);
    }
}
