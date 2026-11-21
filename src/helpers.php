<?php

/**
 * Reads a .env file, so that we don't need another package for it.
 * It reads KEY=value lines, and skips empty lines and lines that start with #.
 */
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim(trim($value), "\"'");
    }
}

/**
 * A setting from the environment, or from .env if the environment doesn't have it (call load_env()
 * first). A real environment variable wins, so a command such as a test can override .env.
 *
 * Why both: load_env() fills $_ENV, but PHP often leaves real environment variables out of $_ENV
 * (it depends on variables_order in php.ini), so they have to be read with getenv().
 */
function env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }

    return $_ENV[$name] ?? $default;
}

/** Formats any value as indented JSON, so that the requests and replies are easy to read. */
function pretty(mixed $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Prints a coloured heading for one step of the agent loop (colours work in Windows Terminal and PowerShell 7). */
function step(string $title): void
{
    echo "\n\033[1;36m=== {$title} ===\033[0m\n";
}
