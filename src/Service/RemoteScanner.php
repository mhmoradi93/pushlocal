<?php

declare(strict_types=1);

namespace PushLocal\Service;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use WP_Error;

final class RemoteScanner
{
    private const MAX_FILES = 5000;

    private IgnoreMatcher $ignoreMatcher;

    public function __construct(?IgnoreMatcher $ignoreMatcher = null)
    {
        $this->ignoreMatcher = $ignoreMatcher ?? new IgnoreMatcher();
    }

    public function scan(string $root, array $ignorePatterns = [])
    {
        if (!is_dir($root) || !is_readable($root)) {
            return new WP_Error(
                'pushlocal_remote_unreadable',
                __('The selected plugin directory is not readable.', 'pushlocal'),
                ['status' => 500]
            );
        }

        $rootNormalized = rtrim(wp_normalize_path($root), '/');
        $files = [];

        try {
            $directory = new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            );

            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                function (SplFileInfo $current) use ($rootNormalized, $ignorePatterns): bool {
                    if ($current->isLink()) {
                        return false;
                    }

                    $absolute = wp_normalize_path($current->getPathname());

                    if (!str_starts_with($absolute, $rootNormalized . '/')) {
                        return false;
                    }

                    $relative = ltrim(substr($absolute, strlen($rootNormalized)), '/');

                    return !$this->ignoreMatcher->isIgnored(
                        $relative,
                        $current->isDir(),
                        $ignorePatterns
                    );
                }
            );

            $iterator = new RecursiveIteratorIterator(
                $filter,
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $fileInfo) {
                if (
                    !$fileInfo instanceof SplFileInfo ||
                    !$fileInfo->isFile() ||
                    $fileInfo->isLink()
                ) {
                    continue;
                }

                if (count($files) >= self::MAX_FILES) {
                    return new WP_Error(
                        'pushlocal_too_many_files',
                        sprintf(
                            __('Plugin contains more than %d scannable files.', 'pushlocal'),
                            self::MAX_FILES
                        ),
                        ['status' => 413]
                    );
                }

                $absolutePath = wp_normalize_path($fileInfo->getPathname());

                if (!str_starts_with($absolutePath, $rootNormalized . '/')) {
                    continue;
                }

                $relativePath = ltrim(substr($absolutePath, strlen($rootNormalized)), '/');

                if ($relativePath === '' || validate_file($relativePath) !== 0) {
                    continue;
                }

                $hash = hash_file('sha256', $fileInfo->getPathname());

                if ($hash === false) {
                    return new WP_Error(
                        'pushlocal_hash_failed',
                        sprintf(__('Could not hash remote file: %s', 'pushlocal'), $relativePath),
                        ['status' => 500]
                    );
                }

                $files[] = [
                    'path' => $relativePath,
                    'size' => (int) $fileInfo->getSize(),
                    'sha256' => $hash,
                ];
            }
        } catch (\UnexpectedValueException) {
            return new WP_Error(
                'pushlocal_scan_failed',
                __('Could not scan the remote plugin directory.', 'pushlocal'),
                ['status' => 500]
            );
        }

        usort(
            $files,
            static fn(array $a, array $b): int => strcmp($a['path'], $b['path'])
        );

        return $files;
    }
}
