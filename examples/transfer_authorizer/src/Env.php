<?php

declare(strict_types=1);

namespace Manza\Examples\TransferAuthorizer;

/**
 * Loads KEY=value lines from a .env file into the environment, overriding
 * what the shell exported (a stale export would otherwise mask the file).
 * Blank lines and `#` comments are skipped; surrounding quotes are dropped.
 * Enough for this example; use vlucas/phpdotenv in an application.
 */
final class Env
{
    private function __construct()
    {
    }

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (preg_match('/\A([\'"])(.*)\1\z/', $value, $quoted) === 1) {
                $value = $quoted[2];
            }
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}
