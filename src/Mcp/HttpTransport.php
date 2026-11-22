<?php

declare(strict_types=1);

namespace Aldervast\Mcp;

/**
 * MCP over HTTP ("Streamable HTTP" in the MCP spec, version 2025-11-25), for a server on the
 * internet. With it, someone adds the server to claude.ai or Claude Desktop as a custom connector
 * by pasting its URL, and installs nothing. Anthropic's servers then connect to this endpoint.
 *
 * Each JSON-RPC message arrives as the body of a POST request, and the reply goes back as the
 * response body. This class only does the HTTP part; Server::handle() does the MCP part, exactly as
 * it does for mcp-server.php over stdio.
 *
 * What the spec asks for, and how this class does it:
 *   - One endpoint (public/index.php sends /mcp here) that accepts POST.
 *   - GET may open a stream of messages from the server. This server never sends any of its own,
 *     so GET gets 405 Method Not Allowed, which the spec allows.
 *   - A notification, or a response from the client, gets 202 Accepted with no body.
 *   - A request gets its reply as application/json. (The other choice, text/event-stream, is for
 *     servers that send several messages while they work. Every tool here answers at once.)
 *   - Sessions (an Mcp-Session-Id header) are optional. This server keeps nothing between
 *     requests, so it uses none, and any server process can answer any request.
 *   - After initialize, the client sends the agreed version in an MCP-Protocol-Version header. A
 *     version that this server doesn't speak gets 400 Bad Request.
 *   - The Origin header must be checked, so that a web page in someone's browser can't use the
 *     server behind their back. A request with an Origin that isn't in the allowed list gets 403.
 *     Anthropic connects from its own servers, which don't send the Origin of some other site.
 */
final class HttpTransport
{
    /** A bigger request body is refused. The biggest real request (a tool call) is well under 1 KB. */
    public const MAX_BODY_BYTES = 100_000;

    /**
     * @param string[] $allowedOrigins full origins, such as "https://claude.ai"; a request with no
     *                                 Origin header at all is always allowed
     */
    public function __construct(private Server $server, private array $allowedOrigins)
    {
    }

    /**
     * The HTTP response to one request.
     *
     * @param array<string, string> $headers the request headers, with lower-case names
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, array $headers, string $body): array
    {
        $origin = $headers['origin'] ?? null;
        if ($origin !== null && !in_array($origin, $this->allowedOrigins, true)) {
            return self::error(403, -32600, "Origin not allowed: {$origin}");
        }
        if ($method !== 'POST') {
            return ['status' => 405, 'headers' => ['Allow' => 'POST'], 'body' => ''];
        }
        $version = $headers['mcp-protocol-version'] ?? null;
        if ($version !== null && !in_array($version, Server::PROTOCOL_VERSIONS, true)) {
            return self::error(400, -32600, 'Unsupported MCP-Protocol-Version: ' . $version
                . '. Supported: ' . implode(', ', Server::PROTOCOL_VERSIONS));
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return self::error(413, -32600, 'Request body too large');
        }

        $message = json_decode($body, true);
        if (!is_array($message)) {
            return self::error(400, -32700, 'Parse error: the body must be one JSON-RPC message');
        }
        // A JSON list would be a batch of messages. MCP stopped allowing batches in 2025-06-18.
        if (array_is_list($message)) {
            return self::error(400, -32600, 'Invalid Request: send one JSON-RPC message per request');
        }

        // A message without a method is the client's response to a request from the server, and
        // one without an id is a notification. Neither gets a reply.
        $reply = isset($message['method']) ? $this->server->handle($message) : null;
        if ($reply === null) {
            return ['status' => 202, 'headers' => [], 'body' => ''];
        }

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($reply, JSON_UNESCAPED_SLASHES),
        ];
    }

    /** An HTTP error with a JSON-RPC error body. The id is null because the request couldn't be read. */
    private static function error(int $status, int $code, string $message): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]],
                JSON_UNESCAPED_SLASHES
            ),
        ];
    }
}
