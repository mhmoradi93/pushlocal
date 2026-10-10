<?php

declare(strict_types=1);

namespace PushLocal\Service;

use ParseError;
use WP_Error;

final class PhpSyntaxValidator
{
    public function validateFile(string $path, string $relativePath)
    {
        if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) !== 'php') {
            return true;
        }

        if (!is_readable($path)) {
            return new WP_Error(
                'pushlocal_php_unreadable',
                sprintf(
                    __('Could not read PHP file for syntax validation: %s', 'pushlocal'),
                    $relativePath
                ),
                ['status' => 409]
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return new WP_Error(
                'pushlocal_php_unreadable',
                sprintf(
                    __('Could not read PHP file for syntax validation: %s', 'pushlocal'),
                    $relativePath
                ),
                ['status' => 409]
            );
        }

        try {
            token_get_all($contents, TOKEN_PARSE);
        } catch (ParseError $error) {
            return new WP_Error(
                'pushlocal_php_syntax_error',
                sprintf(
                    __('PHP syntax error in %1$s: %2$s', 'pushlocal'),
                    $relativePath,
                    $error->getMessage()
                ),
                [
                    'status' => 422,
                    'file' => $relativePath,
                    'line' => $error->getLine(),
                ]
            );
        }

        return true;
    }
}
