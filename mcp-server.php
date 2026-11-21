<?php

/**
 * mcp-server.php - the SAME tools as agent.php, offered to any app as an MCP server.
 *
 * In agent.php we wrote the loop ourselves. With MCP (Model Context Protocol), an app that
 * already has a loop (Claude Code, Claude Desktop, ...) runs it for us. All we provide is the tools.
 *
 * MCP over "stdio" is simple: the app starts this script, writes JSON-RPC requests to our stdin,
 * one per line, and reads our JSON-RPC replies from stdout, one per line. The main methods are:
 *   initialize   -> say hello, and say that we offer tools
 *   tools/list   -> return the tool definitions (the same definitions that agent.php sends)
 *   tools/call   -> run a tool and return its output
 *
 * IMPORTANT: stdout carries the protocol messages. Never write debug output to stdout; use STDERR.
 *
 * Try it by hand:
 *   echo {"jsonrpc":"2.0","id":1,"method":"tools/list"} | php mcp-server.php
 */

require __DIR__ . '/vendor/autoload.php';

// By default, PHP on the command line prints warnings to stdout, which would break the protocol.
// Send them to stderr instead.
ini_set('display_errors', 'stderr');

// Every tool that this server offers, keyed by tool name. The list itself is in src/Tools.php.
$tools = Tools::byName();

// Every tool call is written to logs/tool-calls.jsonl, so you can see which tools Claude used and
// in what order. "composer log" shows it. See src/ToolCallLog.php.
$log = ToolCallLog::fromEnvironment();

function reply(int|string $id, array $result): void
{
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_UNESCAPED_SLASHES) . "\n");
}

function reply_error(int|string|null $id, int $code, string $message): void
{
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]) . "\n");
}

fwrite(STDERR, "[aldervast-mcp] started, waiting for JSON-RPC on stdin\n");
while (($line = fgets(STDIN)) !== false) {
    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    $msg = json_decode(trim($line), true);
    if (!is_array($msg)) {
        continue;
    }
    $method = $msg['method'] ?? '';
    $id = $msg['id'] ?? null;
    fwrite(STDERR, "[aldervast-mcp] <- {$method}\n");

    // Messages with no id are "notifications" (e.g. notifications/initialized): no reply allowed.
    if ($id === null) {
        continue;
    }

    switch ($method) {
        case 'initialize':
            reply($id, [
                // Send the client's protocol version back. This small server doesn't use any feature
                // that differs between versions.
                'protocolVersion' => $msg['params']['protocolVersion'] ?? '2025-06-18',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'aldervast', 'version' => '1.0.0'],
                // Guidance for the tools as a whole; see Tools::INSTRUCTIONS in src/Tools.php.
                'instructions' => Tools::INSTRUCTIONS,
            ]);
            break;

        case 'tools/list':
            // MCP calls the schema "inputSchema" (camelCase); the Messages API calls it "input_schema".
            $list = [];
            foreach ($tools as $class) {
                $def = $class::definition();
                $list[] = [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'inputSchema' => $def['input_schema'],
                ];
            }
            reply($id, ['tools' => $list]);
            break;

        case 'tools/call':
            $class = $tools[$msg['params']['name'] ?? ''] ?? null;
            if ($class === null) {
                reply_error($id, -32602, 'Unknown tool');
                break;
            }
            $arguments = $msg['params']['arguments'] ?? [];
            $started = hrtime(true);
            try {
                $text = $class::run($arguments);
                $isError = false;
            } catch (Throwable $e) {
                $text = 'Error: ' . $e->getMessage();
                $isError = true;
            }
            $log->record($class::NAME, $arguments, $text, $isError, (hrtime(true) - $started) / 1e9);
            reply($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError]);
            break;

        case 'ping':
            reply($id, []);
            break;

        default:
            reply_error($id, -32601, "Method not found: {$method}");
    }
}
