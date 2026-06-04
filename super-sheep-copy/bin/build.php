<?php

declare(strict_types=1);

$plugin_dir = dirname(__DIR__);
$project_root = dirname($plugin_dir);
$dist_dir = $project_root . '/dist';
$archive_path = $dist_dir . '/super-sheep-copy.zip';
$plugin_slug = basename($plugin_dir);

try {
    build_release_archive($plugin_dir, $dist_dir, $archive_path, $plugin_slug);
    fwrite(STDOUT, 'Built ' . $archive_path . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

function build_release_archive(string $plugin_dir, string $dist_dir, string $archive_path, string $plugin_slug): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new \RuntimeException('ZipArchive is not available.');
    }

    if (!is_dir($dist_dir) && !mkdir($dist_dir, 0777, true) && !is_dir($dist_dir)) {
        throw new \RuntimeException('Unable to create dist directory.');
    }

    if (is_file($archive_path) && !unlink($archive_path)) {
        throw new \RuntimeException('Unable to replace existing archive.');
    }

    $zip = new ZipArchive();
    if ($zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException('Unable to create release archive.');
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $absolute_path = $file->getPathname();
        $relative_path = normalize_build_path(substr($absolute_path, strlen($plugin_dir) + 1));
        if (should_exclude_from_release($relative_path)) {
            continue;
        }

        $zip_path = $plugin_slug . '/' . $relative_path;
        if (!$zip->addFile($absolute_path, $zip_path)) {
            $zip->close();
            throw new \RuntimeException('Unable to add file to release archive: ' . $relative_path);
        }
    }

    if (!$zip->close()) {
        throw new \RuntimeException('Unable to finalize release archive.');
    }
}

function normalize_build_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function should_exclude_from_release(string $relative_path): bool
{
    if (basename($relative_path) === '.DS_Store') {
        return true;
    }

    $excluded_exact = array(
        '.phpunit.result.cache',
        'composer.json',
        'composer.lock',
        'phpunit.xml.dist',
    );

    if (in_array($relative_path, $excluded_exact, true)) {
        return true;
    }

    $excluded_prefixes = array(
        'bin/',
        'tests/',
        'vendor/',
    );

    foreach ($excluded_prefixes as $prefix) {
        if (strpos($relative_path, $prefix) === 0) {
            return true;
        }
    }

    return false;
}
