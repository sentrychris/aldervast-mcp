<?php

declare(strict_types=1);

/**
 * Prints the MCP server's tool call log (logs/tool-calls.jsonl) as a readable list, grouped by
 * session, so that the route Claude took for each question reads from top to bottom.
 *
 *   composer log          the last 50 calls
 *   composer log -- 200   the last 200 calls
 *
 * src/ToolCallLog.php writes the log; this script only reads it.
 */

require __DIR__ . '/vendor/autoload.php';

$limit = max(1, (int) ($argv[1] ?? 50));
$file = ToolCallLog::fromEnvironment()->file() ?? ToolCallLog::DEFAULT_FILE;

if (!is_file($file)) {
    echo "No tool calls logged yet. The MCP server writes them to {$file} as Claude uses the tools.\n";
    exit(0);
}

$lines = array_slice(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$limit);
$session = null;

foreach ($lines as $line) {
    $call = json_decode($line, true);
    if (!is_array($call)) {
        continue; // a damaged line, e.g. from a write that was cut off
    }

    if ($call['session'] !== $session) {
        $session = $call['session'];
        echo "\nSession {$session}, started " . date('Y-m-d H:i', strtotime($call['time'])) . "\n";
    }

    $input = json_encode($call['input'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // A failed call shows the start of its error. The log file keeps the first 200 characters.
    $outcome = $call['is_error']
        ? 'ERROR: ' . mb_strimwidth(preg_replace('/^Error: /', '', $call['error']), 0, 70, '…')
        : number_format($call['result_bytes']) . ' bytes';
    printf("  %s  %-15s %-55s %s, %d ms\n", date('H:i:s', strtotime($call['time'])), $call['tool'], $input, $outcome, $call['ms']);
}
