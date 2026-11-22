<?php

declare(strict_types=1);

namespace Aldervast\Mcp;

use RuntimeException;

/**
 * A request that gets a JSON-RPC "error" instead of a "result": an unknown method, an unknown
 * tool, a resource that doesn't exist, and so on. Server::handle() turns it into the error reply.
 *
 * The code is one of JSON-RPC's numbers, such as -32601 "method not found" or -32602 "invalid
 * params", or one from the MCP spec, such as -32002 "resource not found". A tool that fails is
 * NOT a protocol error: it's a normal result with isError: true (see Server::callTool()).
 */
final class ProtocolError extends RuntimeException
{
    /** @param array|null $data extra detail for the client, e.g. the URI of a missing resource */
    public function __construct(int $code, string $message, public readonly ?array $data = null)
    {
        parent::__construct($message, $code);
    }
}
