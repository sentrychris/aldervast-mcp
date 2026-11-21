<?php

/**
 * Tiny .env reader, so we don't need another package.
 * Reads KEY=value lines and ignores blanks and # comments.
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

/** Pretty-print anything as JSON so the wire format is easy to read. */
function pretty(mixed $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Print a coloured step heading (colours work in Windows Terminal and PowerShell 7). */
function step(string $title): void
{
    echo "\n\033[1;36m=== {$title} ===\033[0m\n";
}
