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

// Guidance for the whole server, sent once in the initialize reply. The client (e.g. Claude Desktop)
// adds it to the model's context in every chat that uses this server, so every user gets it,
// whatever their chat history. Each tool description says what one tool does. This text says when
// to use the server at all, and how the tools work together. Keep it general: no facts about
// specific game content.
const INSTRUCTIONS = 'These tools read the public wiki of Aldervast, an original fantasy action MMORPG. '
    . 'Your own knowledge of Aldervast is likely missing or out of date, so for any question about the '
    . 'game, look it up with these tools rather than answering from memory, even if you think you know. '
    . 'Typical flow: search_wiki to find entries (or list_category to browse when the player\'s wording '
    . 'doesn\'t match the wiki\'s), then get_wiki_entry for the full details. Base the answer on what '
    . 'the tools return, and cite the wiki URLs. Text ending in " …" was cut short: never fill in the '
    . 'missing words yourself; fetch the full entry with get_wiki_entry, or say the detail is incomplete.';

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
                'instructions' => INSTRUCTIONS,
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
            try {
                $text = $class::run($msg['params']['arguments'] ?? []);
                reply($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]);
            } catch (Throwable $e) {
                reply($id, ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true]);
            }
            break;

        case 'ping':
            reply($id, []);
            break;

        default:
            reply_error($id, -32601, "Method not found: {$method}");
    }
}
