<?php

declare(strict_types=1);

namespace Aldervast\Tests\Mcp;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\Tools;

/**
 * Tests the MCP server in the same way that Claude Desktop uses it. Each test starts mcp-server.php
 * as a separate process, writes JSON-RPC requests to its standard input, and reads the replies from
 * its standard output.
 *
 * This checks the protocol rules in AGENTS.md: the server writes only JSON-RPC replies to standard
 * output, it doesn't reply to notifications, and so on.
 */
final class McpServerTest extends FixtureTestCase
{
    private string $stdout = '';

    private string $stderr = '';

    /** Where the server writes its tool call log. "off" by default, so tests don't fill your real log. */
    private string $toolLog = 'off';

    /**
     * Sends the messages to a new server process, one JSON object per line. Then it closes the
     * server's input, so that the server's read loop ends, and returns every reply as an array.
     * The server reads the saved copies of the pages, so the test downloads nothing.
     */
    private function talk(array ...$messages): array
    {
        $env = ['ALDERVAST_FIXTURES' => self::FIXTURES, 'ALDERVAST_TOOL_LOG' => $this->toolLog] + getenv();
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/mcp-server.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        $this->assertIsResource($process, 'could not start mcp-server.php');

        foreach ($messages as $message) {
            fwrite($pipes[0], json_encode($message) . "\n");
        }
        fclose($pipes[0]);
        $this->stdout = (string) stream_get_contents($pipes[1]);
        $this->stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $lines = array_filter(explode("\n", $this->stdout), fn (string $l) => trim($l) !== '');

        return array_map(fn (string $l) => $this->decode($l), array_values($lines));
    }

    private function call(int $id, string $tool, array $arguments): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments]];
    }

    public function test_each_tool_call_is_written_to_the_tool_call_log(): void
    {
        $this->toolLog = tempnam(sys_get_temp_dir(), 'aldervast-log-');

        [$search] = $this->talk(
            $this->call(1, 'search_wiki', ['query' => 'stag']),
            $this->call(2, 'get_wiki_entry', ['url_or_name' => 'https://example.com/x.html#a']),
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], // not a tool call, so not logged
        );
        $lines = file($this->toolLog, FILE_IGNORE_NEW_LINES);
        unlink($this->toolLog);

        $this->assertCount(2, $lines);
        [$first, $second] = array_map(fn (string $line) => $this->decode($line), $lines);

        $this->assertSame('search_wiki', $first['tool']);
        $this->assertSame(['query' => 'stag'], $first['input']);
        $this->assertSame(strlen($search['result']['content'][0]['text']), $first['result_bytes']);
        $this->assertFalse($first['is_error']);

        $this->assertSame('get_wiki_entry', $second['tool']);
        $this->assertTrue($second['is_error']);
        $this->assertStringStartsWith('Error: Not an Aldervast wiki entry URL', $second['error']);
        // Both calls came from one server process, so they share a session id.
        $this->assertSame($first['session'], $second['session']);
    }

    public function test_stdout_holds_only_json_rpc_replies_and_logs_go_to_stderr(): void
    {
        $replies = $this->talk(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        // decode() in talk() would already have thrown on any non-JSON line.
        $this->assertSame([['jsonrpc' => '2.0', 'id' => 1, 'result' => []]], $replies);
        $this->assertStringContainsString('[aldervast-mcp] started', $this->stderr);
    }

    public function test_initialize_returns_instructions_and_sends_back_the_protocol_version(): void
    {
        [$reply] = $this->talk(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) []]]);

        $this->assertSame('2025-06-18', $reply['result']['protocolVersion']);
        $this->assertSame('aldervast', $reply['result']['serverInfo']['name']);
        $this->assertStringContainsString('rather than answering from memory', $reply['result']['instructions']);
        // Capabilities must be JSON objects ({}), not arrays ([]). A client only asks for resources
        // and prompts if they are declared here.
        $this->assertStringContainsString('"capabilities":{"tools":{},"resources":{},"prompts":{}}', $this->stdout);
    }

    public function test_resources_can_be_listed_and_read(): void
    {
        [$list, $read] = $this->talk(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'aldervast://wiki/classes.html']],
        );

        $this->assertContains('aldervast://wiki/classes.html', array_column($list['result']['resources'], 'uri'));
        $this->assertArrayNotHasKey('nextCursor', $list['result']);
        $this->assertSame('aldervast://wiki/classes.html', $read['result']['contents'][0]['uri']);
        $this->assertStringContainsString("## Ravager\n", $read['result']['contents'][0]['text']);
    }

    public function test_an_unknown_resource_is_the_resource_not_found_error_with_its_uri(): void
    {
        [$reply] = $this->talk(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => 'aldervast://../.env']]);

        $this->assertSame(['code' => -32002, 'message' => 'Resource not found', 'data' => ['uri' => 'aldervast://../.env']], $reply['error']);
    }

    public function test_prompts_can_be_listed_and_got_with_their_arguments_filled_in(): void
    {
        [$list, $get] = $this->talk(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/get', 'params' => ['name' => 'plan_levelling', 'arguments' => ['level' => '25']]],
        );

        $this->assertSame(['plan_build', 'plan_levelling'], array_column($list['result']['prompts'], 'name'));
        $this->assertSame('user', $get['result']['messages'][0]['role']);
        $this->assertStringContainsString('levelling_route with level 25', $get['result']['messages'][0]['content']['text']);
    }

    public function test_an_unknown_prompt_or_a_missing_argument_is_an_invalid_params_error(): void
    {
        [$unknown, $missing] = $this->talk(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/get', 'params' => ['name' => 'nope']],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/get', 'params' => ['name' => 'plan_build', 'arguments' => (object) []]],
        );

        $this->assertSame(-32602, $unknown['error']['code']);
        $this->assertSame('Missing argument: class', $missing['error']['message']);
    }

    public function test_notifications_get_no_reply(): void
    {
        $replies = $this->talk(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping'],
        );

        $this->assertSame([7], array_column($replies, 'id'));
    }

    public function test_tools_list_offers_every_tool_with_an_mcp_input_schema(): void
    {
        [$reply] = $this->talk(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $tools = $reply['result']['tools'];

        // The server must offer exactly the tools in src/Tools/Tools.php, in the same order.
        $this->assertSame(array_keys(Tools::byName()), array_column($tools, 'name'));
        foreach ($tools as $tool) {
            // In MCP this field is called inputSchema. The Messages API name, input_schema, must not appear here.
            $this->assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            $this->assertArrayNotHasKey('input_schema', $tool);
        }
        // For a tool without inputs, the server must send "properties":{} (an object).
        // Strict clients reject "properties":[] (an array).
        $this->assertStringContainsString('"properties":{}', $this->stdout);
    }

    public function test_tool_call_returns_text_content(): void
    {
        [$reply] = $this->talk($this->call(1, 'search_wiki', ['query' => 'hollow crypt', 'limit' => 1]));

        $this->assertFalse($reply['result']['isError']);
        $this->assertSame('text', $reply['result']['content'][0]['type']);
        // The text is exactly the JSON that the model gets.
        $result = $this->decode($reply['result']['content'][0]['text']);
        $this->assertSame('hollow crypt', $result['query']);
        $this->assertCount(1, $result['results']);
    }

    public function test_tool_failure_is_a_normal_result_with_is_error_not_a_protocol_error(): void
    {
        [$reply] = $this->talk($this->call(1, 'get_wiki_entry', ['url_or_name' => 'https://example.com/x.html#a']));

        $this->assertArrayNotHasKey('error', $reply);
        $this->assertTrue($reply['result']['isError']);
        $this->assertStringStartsWith('Error: Not an Aldervast wiki entry URL', $reply['result']['content'][0]['text']);
    }

    public function test_unknown_tool_and_unknown_method_are_protocol_errors(): void
    {
        [$tool, $method] = $this->talk(
            $this->call(1, 'delete_everything', []),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'no/such/method'],
        );

        $this->assertSame(-32602, $tool['error']['code']);
        $this->assertSame(-32601, $method['error']['code']);
    }
}
