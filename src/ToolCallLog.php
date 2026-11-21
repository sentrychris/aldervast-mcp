<?php

declare(strict_types=1);

/**
 * Writes one line to a log file for every tool call that the MCP server runs: the time, the tool,
 * its input, the size of the result, whether it failed, and how long it took.
 *
 * Why: Claude Desktop shows tool calls one chat at a time, folded away. The log shows every call in
 * one place, so you can see the route that Claude takes for a question (for example a search for
 * "deer", then "stag", then "antler"). It's also where real questions for an eval can come from.
 *
 * Each line is one JSON object, a format called JSON Lines. It's easy to read in an editor and easy
 * to process with a script; "composer log" prints it as a readable list (see show-log.php).
 *
 * The file is logs/tool-calls.jsonl, which git ignores. It holds what Claude searched for, so it
 * stays on this machine. The environment variable ALDERVAST_TOOL_LOG changes where it goes: a file
 * path writes there instead (the tests use this), and "off" turns the log off.
 *
 * Logging must never break a tool call or the MCP protocol, so an error while writing is ignored.
 */
final class ToolCallLog
{
    public const DEFAULT_FILE = __DIR__ . '/../logs/tool-calls.jsonl';

    /** A failed call's error message is kept, but cut to this many characters. */
    private const MAX_ERROR_CHARS = 200;

    /**
     * @param string|null $file    the log file, or null to log nothing
     * @param string      $session an id shared by every call from one server process
     */
    public function __construct(private ?string $file, private string $session)
    {
    }

    /** The log that the MCP server uses: the default file, unless ALDERVAST_TOOL_LOG says otherwise. */
    public static function fromEnvironment(): self
    {
        $setting = (string) getenv('ALDERVAST_TOOL_LOG');
        $file = match (true) {
            $setting === '' => self::DEFAULT_FILE,
            strtolower($setting) === 'off' => null,
            default => $setting,
        };

        // A client such as Claude Desktop starts one server process and sends it every call, so one
        // random id per process lets "composer log" group the calls that belong together.
        return new self($file, bin2hex(random_bytes(4)));
    }

    public function file(): ?string
    {
        return $this->file;
    }

    public function record(string $tool, array $input, string $result, bool $isError, float $seconds): void
    {
        if ($this->file === null) {
            return;
        }

        $entry = [
            'time' => date(DATE_ATOM),
            'session' => $this->session,
            'tool' => $tool,
            'input' => (object) $input,  // {} rather than [] when there is no input
            'result_bytes' => strlen($result),
            'is_error' => $isError,
            'ms' => (int) round($seconds * 1000),
        ];
        if ($isError) {
            $entry['error'] = mb_substr($result, 0, self::MAX_ERROR_CHARS);
        }

        // JSON_INVALID_UTF8_SUBSTITUTE: odd bytes in an input can't stop the line being written.
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        // The @ hides PHP warnings, e.g. when the folder can't be created. A failed write only loses
        // one log line; the tool call itself must still succeed.
        $dir = dirname($this->file);
        if (is_dir($dir) || @mkdir($dir, 0777, true)) {
            @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
