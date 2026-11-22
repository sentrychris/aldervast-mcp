<?php

declare(strict_types=1);

namespace Aldervast\Tests\Mcp;

use Aldervast\Mcp\Server;
use Aldervast\Mcp\ToolCallLog;
use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\Tools;

/**
 * Tests for Server, the MCP part that the stdio script and the HTTP endpoint share. These run in
 * this process, so code coverage and mutation testing can see them. McpServerTest checks the same
 * behaviour through a real mcp-server.php process, the way that Claude Desktop uses it.
 */
final class ServerTest extends FixtureTestCase
{
    private ?string $logFile = null;

    protected function tearDown(): void
    {
        if ($this->logFile !== null && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        parent::tearDown();
    }

    private function send(string $method, array $params = [], int|string|null $id = 1): ?array
    {
        $this->logFile ??= tempnam(sys_get_temp_dir(), 'aldervast-log-');
        $server = new Server(new ToolCallLog($this->logFile, 'test'));
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];

        return $server->handle($id === null ? $message : $message + ['id' => $id]);
    }

    private function resultOf(string $method, array $params = []): array
    {
        $reply = $this->send($method, $params);
        $this->assertArrayNotHasKey('error', $reply, $method);

        return $reply['result'];
    }

    public function test_a_notification_gets_no_reply(): void
    {
        $this->assertNull($this->send('notifications/initialized', [], null));
    }

    public function test_a_reply_has_the_jsonrpc_version_and_the_request_id(): void
    {
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 'abc', 'result' => []], $this->send('ping', [], 'abc'));
    }

    public function test_initialize_agrees_to_a_known_version_and_offers_the_newest_for_any_other(): void
    {
        $this->assertSame('2025-06-18', $this->resultOf('initialize', ['protocolVersion' => '2025-06-18'])['protocolVersion']);
        $this->assertSame('2025-11-25', $this->resultOf('initialize', ['protocolVersion' => '2030-01-01'])['protocolVersion']);
        $this->assertSame('2025-11-25', $this->resultOf('initialize')['protocolVersion']);
    }

    public function test_initialize_declares_tools_resources_and_prompts_and_sends_the_instructions(): void
    {
        $result = $this->resultOf('initialize');

        $this->assertSame('{"tools":{},"resources":{},"prompts":{}}', json_encode($result['capabilities']));
        $this->assertSame(['name' => 'aldervast', 'version' => '1.0.0'], $result['serverInfo']);
        $this->assertSame(Tools::INSTRUCTIONS, $result['instructions']);
    }

    public function test_tools_list_has_every_tool_with_its_schema_under_the_mcp_name(): void
    {
        $tools = $this->resultOf('tools/list')['tools'];

        $this->assertSame(array_keys(Tools::byName()), array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $definition = Tools::byName()[$tool['name']]::definition();
            $this->assertSame(['name', 'description', 'inputSchema'], array_keys($tool));
            $this->assertSame($definition['description'], $tool['description']);
            // assertEquals, because an empty "properties" is a new (object) [] each time.
            $this->assertEquals($definition['input_schema'], $tool['inputSchema']);
        }
    }

    public function test_a_tool_call_returns_the_tool_output_as_text_and_is_logged(): void
    {
        $result = $this->resultOf('tools/call', ['name' => 'search_wiki', 'arguments' => ['query' => 'stag', 'limit' => 1]]);

        $this->assertFalse($result['isError']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame('stag', $this->decode($result['content'][0]['text'])['query']);

        $logged = $this->decode((string) file_get_contents($this->logFile));
        $this->assertSame('search_wiki', $logged['tool']);
        $this->assertSame(['query' => 'stag', 'limit' => 1], $logged['input']);
        $this->assertFalse($logged['is_error']);
    }

    public function test_a_tool_that_fails_is_a_normal_result_with_is_error(): void
    {
        $result = $this->resultOf('tools/call', ['name' => 'get_wiki_entry', 'arguments' => ['url_or_name' => '  ']]);

        $this->assertTrue($result['isError']);
        $this->assertStringStartsWith('Error: url_or_name is required', $result['content'][0]['text']);
        $this->assertTrue($this->decode((string) file_get_contents($this->logFile))['is_error']);
    }

    public function test_tool_arguments_that_are_not_an_object_count_as_none(): void
    {
        // list_servers takes no input, so it works the same with no arguments at all.
        $result = $this->resultOf('tools/call', ['name' => 'list_servers', 'arguments' => 'nonsense']);

        $this->assertFalse($result['isError']);
        $this->assertSame([], $this->decode((string) file_get_contents($this->logFile))['input']);
    }

    public function test_resources_can_be_listed_and_read(): void
    {
        $this->assertContains('aldervast://wiki/classes.html', array_column($this->resultOf('resources/list')['resources'], 'uri'));
        $this->assertStringContainsString("## Ravager\n", $this->resultOf('resources/read', ['uri' => 'aldervast://wiki/classes.html'])['contents'][0]['text']);
    }

    public function test_a_page_that_cannot_be_read_is_an_internal_error_with_its_uri(): void
    {
        // skills.html is in the search index but has no saved copy, so reading it fails.
        $reply = $this->send('resources/read', ['uri' => 'aldervast://wiki/skills.html']);

        $this->assertSame(-32603, $reply['error']['code']);
        $this->assertStringStartsWith('Could not read the page: No test fixture', $reply['error']['message']);
        $this->assertSame(['uri' => 'aldervast://wiki/skills.html'], $reply['error']['data']);
    }

    public function test_a_resource_list_that_cannot_be_built_is_an_internal_error(): void
    {
        putenv('ALDERVAST_FIXTURES=' . sys_get_temp_dir() . '/aldervast-no-fixtures-here');

        $reply = $this->send('resources/list');

        $this->assertSame(-32603, $reply['error']['code']);
        $this->assertStringStartsWith('Could not list the wiki pages: No test fixture', $reply['error']['message']);
        $this->assertArrayNotHasKey('data', $reply['error']);
    }

    public function test_prompts_can_be_listed_and_got(): void
    {
        $this->assertSame(['plan_build', 'plan_levelling'], array_column($this->resultOf('prompts/list')['prompts'], 'name'));
        $prompt = $this->resultOf('prompts/get', ['name' => 'plan_levelling', 'arguments' => ['level' => '12']]);
        $this->assertStringStartsWith('My character is level 12 ', $prompt['messages'][0]['content']['text']);
    }

    public static function protocolErrors(): array
    {
        return [
            'unknown method' => ['no/such/method', [], -32601, 'Method not found: no/such/method'],
            'unknown tool' => ['tools/call', ['name' => 'delete_everything'], -32602, 'Unknown tool'],
            'unknown resource' => ['resources/read', ['uri' => 'aldervast://../.env'], -32002, 'Resource not found'],
            'unknown prompt' => ['prompts/get', ['name' => 'nope'], -32602, 'Unknown prompt: nope'],
            'missing prompt argument' => ['prompts/get', ['name' => 'plan_build', 'arguments' => 'nonsense'], -32602, 'Missing argument: class'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protocolErrors')]
    public function test_a_bad_request_gets_a_json_rpc_error_with_the_spec_code(string $method, array $params, int $code, string $message): void
    {
        $reply = $this->send($method, $params);

        $this->assertArrayNotHasKey('result', $reply);
        $this->assertSame($code, $reply['error']['code']);
        $this->assertSame($message, $reply['error']['message']);
    }

    public function test_params_that_are_not_an_object_count_as_none(): void
    {
        $server = new Server(new ToolCallLog(null, ''));
        $reply = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => 'nonsense']);

        $this->assertSame('Unknown tool', $reply['error']['message']);
    }
}
