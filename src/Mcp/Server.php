<?php

declare(strict_types=1);

namespace Aldervast\Mcp;

use Aldervast\Tools\Tools;
use InvalidArgumentException;
use Throwable;

/**
 * The MCP server itself: takes one JSON-RPC message and returns the reply.
 *
 * It doesn't know how the message arrived. mcp-server.php reads messages from stdin (for Claude
 * Code, Claude Desktop and Codex on the same PC), and HttpTransport reads them from HTTP requests
 * (for a server on the internet, such as a custom connector in claude.ai). Both pass each message
 * to handle(), so the two ways of connecting always behave the same.
 *
 * The methods it answers:
 *   initialize      -> say hello, and say that we offer tools, resources and prompts
 *   tools/list      -> return the tool definitions (the same definitions that agent.php sends)
 *   tools/call      -> run a tool and return its output
 *   resources/list  -> return the wiki pages that the person can attach to a chat (WikiPages.php)
 *   resources/read  -> return one of those pages as Markdown
 *   prompts/list    -> return the ready-made requests, such as "plan my build" (Prompts.php)
 *   prompts/get     -> return one of those requests, with its arguments filled in
 *   ping            -> an empty reply, to show that the server is alive
 *
 * The model decides when to call a tool. The person decides when to use a resource or a prompt.
 */
final class Server
{
    /**
     * The MCP versions that this server can speak, newest first. A client asks for one in
     * initialize; if this server doesn't know it, the reply offers the newest one here instead,
     * and the client decides whether it can use that (this is how MCP agrees on a version).
     */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /** Every tool that this server offers, keyed by tool name. The list itself is in src/Tools/Tools.php. */
    private array $tools;

    public function __construct(private ToolCallLog $log)
    {
        $this->tools = Tools::byName();
    }

    /**
     * The JSON-RPC reply to one message, or null when there must be no reply. Messages without an
     * id are "notifications" (e.g. notifications/initialized), and MCP forbids replying to them.
     */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        if ($id === null) {
            return null;
        }
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->result($method, $params)];
        } catch (ProtocolError $e) {
            $error = ['code' => $e->getCode(), 'message' => $e->getMessage()] + ($e->data === null ? [] : ['data' => $e->data]);

            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
        }
    }

    /** The "result" of a request. Throws ProtocolError for anything that needs a JSON-RPC error instead. */
    private function result(string $method, array $params): array
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'tools/list' => ['tools' => $this->toolList()],
            'tools/call' => $this->callTool($params),
            'resources/list' => $this->resourceList(),
            'resources/read' => $this->readResource((string) ($params['uri'] ?? '')),
            'prompts/list' => ['prompts' => Prompts::list()],
            'prompts/get' => $this->getPrompt($params),
            'ping' => [],
            default => throw new ProtocolError(-32601, "Method not found: {$method}"),
        };
    }

    private function initialize(array $params): array
    {
        $asked = (string) ($params['protocolVersion'] ?? '');

        return [
            // The client's version if we know it, or else our newest one (see PROTOCOL_VERSIONS).
            'protocolVersion' => in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
            // What this server offers. A client only asks for resources or prompts if they are
            // declared here. The empty objects mean "no extras": the server never sends
            // notifications that a list changed, and resources can't be subscribed to.
            'capabilities' => ['tools' => (object) [], 'resources' => (object) [], 'prompts' => (object) []],
            'serverInfo' => ['name' => 'aldervast', 'version' => '1.0.0'],
            // Guidance for the tools as a whole; see Tools::INSTRUCTIONS in src/Tools/Tools.php.
            'instructions' => Tools::INSTRUCTIONS,
        ];
    }

    private function toolList(): array
    {
        // MCP calls the schema "inputSchema" (camelCase); the Messages API calls it "input_schema".
        $list = [];
        foreach ($this->tools as $class) {
            $definition = $class::definition();
            $list[] = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'inputSchema' => $definition['input_schema'],
            ];
        }

        return $list;
    }

    /**
     * Runs a tool. A tool that fails is still a normal result, with isError: true, so that the model
     * reads the error and can try again. Only an unknown tool is a protocol error.
     */
    private function callTool(array $params): array
    {
        $class = $this->tools[$params['name'] ?? ''] ?? throw new ProtocolError(-32602, 'Unknown tool');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $started = hrtime(true);
        try {
            $text = $class::run($arguments);
            $isError = false;
        } catch (Throwable $e) {
            $text = 'Error: ' . $e->getMessage();
            $isError = true;
        }
        $this->log->record($class::NAME, $arguments, $text, $isError, (hrtime(true) - $started) / 1e9);

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    private function resourceList(): array
    {
        // All 34 or so pages fit in one reply, so this server never sends a nextCursor (the MCP
        // way to split a long list into several replies).
        try {
            return ['resources' => WikiPages::list()];
        } catch (Throwable $e) {
            throw new ProtocolError(-32603, 'Could not list the wiki pages: ' . $e->getMessage());
        }
    }

    private function readResource(string $uri): array
    {
        try {
            $contents = WikiPages::read($uri);
        } catch (Throwable $e) {
            throw new ProtocolError(-32603, 'Could not read the page: ' . $e->getMessage(), ['uri' => $uri]);
        }
        if ($contents === null) {
            // -32002 is the MCP spec's error code for a resource that doesn't exist.
            throw new ProtocolError(-32002, 'Resource not found', ['uri' => $uri]);
        }

        return ['contents' => $contents];
    }

    private function getPrompt(array $params): array
    {
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        try {
            return Prompts::get((string) ($params['name'] ?? ''), $arguments);
        } catch (InvalidArgumentException $e) {
            // An unknown prompt or a missing argument is the client's mistake: "invalid params".
            throw new ProtocolError(-32602, $e->getMessage());
        }
    }
}
