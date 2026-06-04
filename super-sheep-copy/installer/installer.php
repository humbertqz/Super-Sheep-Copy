<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    if (!is_readable(__DIR__ . '/restore-engine/config.php')) {
        exit;
    }
}

require_once __DIR__ . '/restore-engine/Bootstrap.php';

\SuperSheepCopyInstaller\Bootstrap::run();
