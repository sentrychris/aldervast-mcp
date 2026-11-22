<?php

declare(strict_types=1);

namespace Aldervast\Tests\Mcp;

use Aldervast\Mcp\HttpTransport;
use Aldervast\Mcp\Server;
use Aldervast\Mcp\ToolCallLog;
use Aldervast\Tests\FixtureTestCase;

/**
 * Tests for HttpTransport: the HTTP rules of MCP's Streamable HTTP transport. The MCP part is
 * Server's job (ServerTest); these tests check status codes, headers and bodies.
 */
final class HttpTransportTest extends FixtureTestCase
{
    private function post(array|string $message, array $headers = []): array
    {
        $transport = new HttpTransport(new Server(new ToolCallLog(null, '')), ['https://claude.ai']);
        $body = is_string($message) ? $message : json_encode($message);

        return $transport->handle('POST', $headers, $body);
    }

    private function errorIn(array $response): array
    {
        return $this->decode($response['body'])['error'];
    }

    public function test_a_request_gets_its_reply_as_json(): void
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
            'params' => ['name' => 'search_wiki', 'arguments' => ['query' => 'hollow crypt', 'limit' => 1]]]);

        $this->assertSame(200, $response['status']);
        $this->assertSame(['Content-Type' => 'application/json'], $response['headers']);
        $reply = $this->decode($response['body']);
        $this->assertSame(7, $reply['id']);
        $this->assertFalse($reply['result']['isError']);
        // Slashes in URLs stay as they are, as in the stdio server's replies.
        $this->assertStringContainsString('https://aldervast.online/wiki/', $response['body']);
    }

    public function test_a_notification_or_a_response_from_the_client_gets_202_with_no_body(): void
    {
        $notification = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $response = $this->post(['jsonrpc' => '2.0', 'id' => 3, 'result' => []]);

        $this->assertSame(['status' => 202, 'headers' => [], 'body' => ''], $notification);
        $this->assertSame(['status' => 202, 'headers' => [], 'body' => ''], $response);
    }

    public function test_get_and_other_methods_get_405_and_say_that_only_post_is_allowed(): void
    {
        $transport = new HttpTransport(new Server(new ToolCallLog(null, '')), []);

        foreach (['GET', 'DELETE', 'PUT'] as $method) {
            $this->assertSame(['status' => 405, 'headers' => ['Allow' => 'POST'], 'body' => ''], $transport->handle($method, [], ''), $method);
        }
    }

    public function test_an_origin_that_is_not_allowed_gets_403_and_an_allowed_or_missing_one_works(): void
    {
        $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

        $refused = $this->post($ping, ['origin' => 'https://evil.example']);
        $this->assertSame(403, $refused['status']);
        $this->assertSame(['code' => -32600, 'message' => 'Origin not allowed: https://evil.example'], $this->errorIn($refused));

        $this->assertSame(200, $this->post($ping, ['origin' => 'https://claude.ai'])['status']);
        $this->assertSame(200, $this->post($ping)['status']);
    }

    public function test_an_unsupported_protocol_version_gets_400_and_a_supported_one_works(): void
    {
        $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

        $refused = $this->post($ping, ['mcp-protocol-version' => '1999-01-01']);
        $this->assertSame(400, $refused['status']);
        $this->assertSame(
            ['code' => -32600, 'message' => 'Unsupported MCP-Protocol-Version: 1999-01-01. Supported: 2025-11-25, 2025-06-18, 2025-03-26, 2024-11-05'],
            $this->errorIn($refused)
        );

        $this->assertSame(200, $this->post($ping, ['mcp-protocol-version' => '2025-06-18'])['status']);
    }

    public function test_a_body_that_is_not_json_gets_400_with_the_parse_error_code(): void
    {
        $response = $this->post('{not json');

        $this->assertSame(400, $response['status']);
        $this->assertSame(['Content-Type' => 'application/json'], $response['headers']);
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: the body must be one JSON-RPC message']],
            $this->decode($response['body'])
        );
    }

    public function test_a_batch_of_messages_gets_400_because_mcp_no_longer_allows_batches(): void
    {
        $response = $this->post([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']]);

        $this->assertSame(400, $response['status']);
        $this->assertSame(-32600, $this->errorIn($response)['code']);
    }

    public function test_a_body_over_the_limit_gets_413_and_one_at_the_limit_is_read(): void
    {
        $atLimit = str_pad('{"jsonrpc":"2.0","id":1,"method":"ping"}', HttpTransport::MAX_BODY_BYTES);

        $tooBig = $this->post($atLimit . ' ');
        $this->assertSame(413, $tooBig['status']);
        $this->assertSame(['code' => -32600, 'message' => 'Request body too large'], $this->errorIn($tooBig));
        $this->assertSame(200, $this->post($atLimit)['status']);
    }
}
