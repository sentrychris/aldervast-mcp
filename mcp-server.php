<?php

/**
 * mcp-server.php - the SAME list_servers tool, exposed as an MCP server.
 *
 * In agent.php we wrote the loop ourselves. With MCP (Model Context Protocol), an app that
 * already has a loop (Claude Code, Claude Desktop, ...) runs it for us. All we provide is the tool.
 *
 * MCP over "stdio" is simple: the app starts this script, writes JSON-RPC requests to our stdin,
 * one per line, and reads our JSON-RPC replies from stdout, one per line. Only three methods matter:
 *   initialize   -> say hello, say we offer tools
 *   tools/list   -> return the tool definitions (same shape as in agent.php)
 *   tools/call   -> run a tool and return its output
 *
 * IMPORTANT: stdout is the protocol channel. Never echo debug output to stdout; use STDERR.
 *
 * Try it by hand:
 *   echo {"jsonrpc":"2.0","id":1,"method":"tools/list"} | php mcp-server.php
 */

require __DIR__ . '/vendor/autoload.php';

// PHP's CLI prints warnings to stdout by default, which would corrupt the protocol. Send them to stderr.
ini_set('display_errors', 'stderr');

// Every tool this server offers, keyed by tool name. To add a tool, write its class in src/ and list it here.
$tools = [];
foreach ([ServerTool::class, SearchWikiTool::class, GetWikiEntryTool::class, ListCategoryTool::class] as $class) {
    $tools[$class::NAME] = $class;
}

// Server-wide guidance, sent once in the initialize reply. The client adds it to the model's context
// in every chat where this server is connected, so every user gets it, whatever their history.
// Tool descriptions say what each tool does; this says when to use the server at all and how the
// tools fit together. Keep it general: no facts about specific game content.
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
                // Echo the client's protocol version back; this tiny server doesn't depend on version differences.
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
