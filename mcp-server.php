<?php

/**
 * mcp-server.php - the SAME tools as agent.php, offered to any app on this PC as an MCP server.
 *
 * In agent.php we wrote the loop ourselves. With MCP (Model Context Protocol), an app that
 * already has a loop (Claude Code, Claude Desktop, ...) runs it for us. All we provide is the tools.
 *
 * MCP over "stdio" is simple: the app starts this script, writes JSON-RPC requests to our stdin,
 * one per line, and reads our JSON-RPC replies from stdout, one per line. What each request does
 * is in src/Mcp/Server.php, which public/index.php also uses to offer the same server over HTTP.
 *
 * IMPORTANT: stdout carries the protocol messages. Never write debug output to stdout; use STDERR.
 *
 * Try it by hand:
 *   echo {"jsonrpc":"2.0","id":1,"method":"tools/list"} | php mcp-server.php
 */

use Aldervast\Mcp\Server;
use Aldervast\Mcp\ToolCallLog;

require __DIR__ . '/vendor/autoload.php';

// By default, PHP on the command line prints warnings to stdout, which would break the protocol.
// Send them to stderr instead.
ini_set('display_errors', 'stderr');

// Every tool call is written to logs/tool-calls.jsonl, so you can see which tools Claude used and
// in what order. "composer log" shows it. See src/Mcp/ToolCallLog.php.
$server = new Server(ToolCallLog::fromEnvironment());

fwrite(STDERR, "[aldervast-mcp] started, waiting for JSON-RPC on stdin\n");
while (($line = fgets(STDIN)) !== false) {
    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    $message = json_decode(trim($line), true);
    if (!is_array($message)) {
        continue;
    }
    fwrite(STDERR, '[aldervast-mcp] <- ' . ($message['method'] ?? '') . "\n");

    $reply = $server->handle($message);
    // No reply to a notification (a message without an id).
    if ($reply !== null) {
        fwrite(STDOUT, json_encode($reply, JSON_UNESCAPED_SLASHES) . "\n");
    }
}
