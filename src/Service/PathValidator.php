<?php

declare(strict_types=1);

namespace PushLocal\Service;

use WP_Error;

final class PathValidator
{
    private const MAX_PATH_LENGTH = 1000;
    private const MAX_SEGMENT_LENGTH = 255;
    private const MAX_DEPTH = 40;

    public function validateRelativeFile(string $relativePath)
    {
        if (
            $relativePath === '' ||
            strlen($relativePath) > self::MAX_PATH_LENGTH ||
            preg_match('/[\x00-\x1F\x7F]/', $relativePath)
        ) {
            return new WP_Error(
                'pushlocal_invalid_path',
                __('Invalid file path.', 'pushlocal'),
                ['status' => 400]
            );
        }

        $normalized = wp_normalize_path($relativePath);

        if (
            $normalized !== $relativePath ||
            str_starts_with($normalized, '/') ||
            str_contains($normalized, "\0") ||
            validate_file($normalized) !== 0
        ) {
            return new WP_Error(
                'pushlocal_invalid_path',
                __('Invalid or unsafe file path.', 'pushlocal'),
                ['status' => 400]
            );
        }

        $segments = explode('/', $normalized);

        if (count($segments) > self::MAX_DEPTH) {
            return new WP_Error(
                'pushlocal_path_too_deep',
                __('File path is too deeply nested.', 'pushlocal'),
                ['status' => 400]
            );
        }

        foreach ($segments as $segment) {
            if (
                $segment === '' ||
                $segment === '.' ||
                $segment === '..' ||
                strlen($segment) > self::MAX_SEGMENT_LENGTH
            ) {
                return new WP_Error(
                    'pushlocal_invalid_path',
                    __('Invalid file path segment.', 'pushlocal'),
                    ['status' => 400]
                );
            }
        }

        return true;
    }

    public function resolveDestination(string $pluginRoot, string $relativePath)
    {
        $valid = $this->validateRelativeFile($relativePath);

        if (is_wp_error($valid)) {
            return $valid;
        }

        $root = rtrim(wp_normalize_path($pluginRoot), '/');
        $candidate = $root . '/' . $relativePath;
        $current = $root;

        foreach (explode('/', $relativePath) as $segment) {
            $current .= '/' . $segment;

            if (file_exists($current) && is_link($current)) {
                return new WP_Error(
                    'pushlocal_symlink_rejected',
                    __('PushLocal does not deploy through symbolic links.', 'pushlocal'),
                    ['status' => 400]
                );
            }
        }

        $normalized = wp_normalize_path($candidate);

        if (!str_starts_with($normalized, $root . '/')) {
            return new WP_Error(
                'pushlocal_path_escape',
                __('The destination path escapes the plugin directory.', 'pushlocal'),
                ['status' => 400]
            );
        }

        return $candidate;
    }
}
