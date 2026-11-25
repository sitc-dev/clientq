<?php

function removeTenantUploadDirectory(string $slug): bool {
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug)) {
        throw new InvalidArgumentException('Invalid tenant slug.');
    }

    $uploadsRoot = dirname(__DIR__) . '/uploads';
    $directory = $uploadsRoot . '/' . $slug;
    if (is_link($directory)) {
        return @unlink($directory);
    }
    if (!is_dir($directory)) {
        return true;
    }

    $resolvedRoot = realpath($uploadsRoot);
    $resolvedDirectory = realpath($directory);
    if ($resolvedRoot === false
        || $resolvedDirectory === false
        || dirname($resolvedDirectory) !== $resolvedRoot
    ) {
        throw new RuntimeException('Invalid tenant upload directory.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $resolvedDirectory,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $removed = $item->isDir() && !$item->isLink()
            ? @rmdir($item->getPathname())
            : @unlink($item->getPathname());
        if (!$removed) {
            return false;
        }
    }

    return @rmdir($resolvedDirectory);
}
