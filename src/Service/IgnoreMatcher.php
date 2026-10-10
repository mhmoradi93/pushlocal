<?php

declare(strict_types=1);

namespace PushLocal\Service;

final class IgnoreMatcher
{
    private const MAX_PATTERNS = 100;
    private const MAX_PATTERN_LENGTH = 200;

    private const DEFAULT_PATTERNS = [
        '.git/',
        '.github/',
        '.idea/',
        '.vscode/',
        'node_modules/',
        '.DS_Store',
        'Thumbs.db',
        '.pushlocalignore',
        '.env',
        '.env.*',
        '*.log',
    ];

    public function normalizePatterns(array $customPatterns): array
    {
        $patterns = self::DEFAULT_PATTERNS;

        foreach (array_slice($customPatterns, 0, self::MAX_PATTERNS) as $raw) {
            if (!is_string($raw)) {
                continue;
            }

            $pattern = trim(str_replace('\\', '/', $raw));

            if (
                $pattern === '' ||
                str_starts_with($pattern, '#') ||
                str_starts_with($pattern, '!') ||
                strlen($pattern) > self::MAX_PATTERN_LENGTH
            ) {
                continue;
            }

            $patterns[] = $pattern;
        }

        return array_values(array_unique($patterns));
    }

    public function isIgnored(string $relativePath, bool $isDirectory, array $customPatterns = []): bool
    {
        $path = ltrim(wp_normalize_path($relativePath), '/');

        if ($path === '') {
            return false;
        }

        foreach ($this->normalizePatterns($customPatterns) as $pattern) {
            if ($this->matches($path, $isDirectory, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $path, bool $isDirectory, string $pattern): bool
    {
        $anchored = str_starts_with($pattern, '/');
        $directoryOnly = str_ends_with($pattern, '/');
        $pattern = trim($pattern, '/');

        if ($pattern === '') {
            return false;
        }

        if ($directoryOnly) {
            if ($this->matchPattern($path, $pattern, $anchored)) {
                return true;
            }

            $segments = explode('/', $path);

            for ($i = count($segments) - 1; $i > 0; $i--) {
                $ancestor = implode('/', array_slice($segments, 0, $i));

                if ($this->matchPattern($ancestor, $pattern, $anchored)) {
                    return true;
                }
            }

            return false;
        }

        return $this->matchPattern($path, $pattern, $anchored);
    }

    private function matchPattern(string $path, string $pattern, bool $anchored): bool
    {
        $hasSlash = str_contains($pattern, '/');
        $regexBody = $this->globToRegex($pattern);

        if ($anchored) {
            return (bool) preg_match('#^' . $regexBody . '$#u', $path);
        }

        if (!$hasSlash) {
            return (bool) preg_match('#^' . $regexBody . '$#u', basename($path));
        }

        return (bool) preg_match('#(?:^|/)' . $regexBody . '$#u', $path);
    }

    private function globToRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '*') {
                $isDouble = ($i + 1 < $length && $pattern[$i + 1] === '*');

                if ($isDouble) {
                    $regex .= '.*';
                    $i++;
                } else {
                    $regex .= '[^/]*';
                }

                continue;
            }

            if ($char === '?') {
                $regex .= '[^/]';
                continue;
            }

            $regex .= preg_quote($char, '#');
        }

        return $regex;
    }
}
