<?php

declare(strict_types=1);

namespace Aldervast\Agent;

/**
 * Settings such as ANTHROPIC_API_KEY, from the environment or from the .env file, so that we don't
 * need another package for it. Only the scripts that call the API (agent.php and the eval) use it;
 * the MCP server needs no settings.
 */
final class Env
{
    /** Reads a .env file into $_ENV: KEY=value lines, skipping empty lines and lines that start with #. */
    public static function load(string $path): void
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
     * A setting from the environment, or from .env if the environment doesn't have it (call load()
     * first). A real environment variable wins, so that a command such as a test can override .env.
     *
     * Why both: load() fills $_ENV, but PHP often leaves real environment variables out of $_ENV (it
     * depends on variables_order in php.ini), so they have to be read with getenv().
     */
    public static function get(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }

        return $_ENV[$name] ?? $default;
    }
}
