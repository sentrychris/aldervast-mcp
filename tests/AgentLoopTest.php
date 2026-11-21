<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for AgentLoop, with no paid API calls. Guzzle's MockHandler plays back the API responses
 * that each test lists, in order, and records every request that the loop sends, so a test can
 * check both sides. The tools themselves are real and read the saved copies of the wiki pages.
 */
final class AgentLoopTest extends FixtureTestCase
{
    /** Every request that the loop sent, as recorded by Guzzle's history middleware. */
    private array $sent = [];

    /** The waits that the loop asked for between retries, in seconds. */
    private array $waits = [];

    /** The step titles that the loop reported, in order. */
    private array $steps = [];

    /** The data that went with each step, keyed by title (the last one wins). */
    private array $stepData = [];

    /** A loop that gets these responses, in order, instead of calling the API. */
    private function loop(array $responses, string $model = 'claude-sonnet-5-5'): AgentLoop
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        return new AgentLoop(
            new Client(['handler' => $stack, 'base_uri' => 'https://api.example.test']),
            $model,
            onStep: function (string $title, mixed $data): void {
                $this->steps[] = $title;
                $this->stepData[$title] = $data;
            },
            sleep: function (float $seconds): void {
                $this->waits[] = $seconds;
            },
        );
    }

    /** A Messages API response with these content blocks. */
    private static function reply(string $stopReason, array $content, string $model = 'claude-sonnet-5-5'): Response
    {
        return new Response(200, [], json_encode([
            'model' => $model,
            'stop_reason' => $stopReason,
            'content' => $content,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cache_read_input_tokens' => 100, 'cache_creation_input_tokens' => 20],
        ]));
    }

    private static function toolUse(string $name, array $input, string $id = 'toolu_1'): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) $input];
    }

    private static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** The JSON body of the n-th request that the loop sent. */
    private function body(int $n): array
    {
        return json_decode((string) $this->sent[$n]['request']->getBody(), true);
    }

    public function test_a_tool_call_is_run_and_its_result_sent_back(): void
    {
        $run = $this->loop([
            self::reply('tool_use', [self::text('Let me search.'), self::toolUse('search_wiki', ['query' => 'stag'])]),
            self::reply('end_turn', [self::text("It's Elderhorn.")]),
        ])->run('big deer boss?');

        $this->assertSame("It's Elderhorn.", $run['answer']);
        $this->assertSame('end_turn', $run['stop_reason']);
        $this->assertSame(2, $run['turns']);
        $this->assertSame('claude-sonnet-5-5', $run['model']);

        // The tool really ran, on the saved copy of the search index.
        $this->assertCount(1, $run['calls']);
        $this->assertSame(['query' => 'stag'], $run['calls'][0]['input']);
        $this->assertStringContainsString('Elderhorn, the Moss Stag', $run['calls'][0]['result']);
        $this->assertFalse($run['calls'][0]['is_error']);

        // Its result went back to the model, linked to the request by its id.
        $toolResult = $this->body(1)['messages'][2]['content'][0];
        $this->assertSame(['tool_result', 'toolu_1', false], [$toolResult['type'], $toolResult['tool_use_id'], $toolResult['is_error']]);
        $this->assertSame($run['calls'][0]['result'], $toolResult['content']);
    }

    public function test_usage_is_added_up_over_every_request(): void
    {
        $run = $this->loop([
            self::reply('tool_use', [self::toolUse('search_wiki', ['query' => 'stag'])]),
            self::reply('end_turn', [self::text('Done.')]),
        ])->run('q');

        $this->assertSame(
            ['input_tokens' => 20, 'output_tokens' => 10, 'cache_read_input_tokens' => 200, 'cache_creation_input_tokens' => 40],
            $run['usage']
        );
    }

    public function test_every_request_carries_the_instructions_the_tools_and_prompt_caching(): void
    {
        $this->loop([self::reply('end_turn', [self::text('Hi.')])])->run('hello');
        $body = $this->body(0);

        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(Tools::INSTRUCTIONS, $body['system']);
        $this->assertSame(array_keys(Tools::byName()), array_column($body['tools'], 'name'));
        $this->assertSame(['type' => 'ephemeral'], $body['cache_control']);
        $this->assertSame([['role' => 'user', 'content' => 'hello']], $body['messages']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertSame(4096, $body['max_tokens']);
    }

    public function test_the_transcript_lists_every_turn_in_the_report_format(): void
    {
        $run = $this->loop([
            self::reply('tool_use', [self::text('Let me search.'), self::toolUse('search_wiki', ['query' => 'stag'])]),
            self::reply('end_turn', [self::text("It's Elderhorn.")]),
        ])->run('big deer boss?');

        $this->assertSame(['system', 'user', 'assistant', 'tool_call', 'tool_result', 'assistant'], array_column($run['transcript'], 'role'));
        $this->assertSame(Tools::INSTRUCTIONS, $run['transcript'][0]['content']);
        $this->assertSame('search_wiki', $run['transcript'][3]['name']);
        $this->assertSame("{\n    \"query\": \"stag\"\n}", $run['transcript'][3]['content']);
    }

    public function test_steps_are_reported_in_order(): void
    {
        $this->loop([
            self::reply('tool_use', [self::toolUse('search_wiki', ['query' => 'stag'])]),
            self::reply('end_turn', [self::text('Done.')]),
        ])->run('q');

        $this->assertSame([
            'TURN 1: REQUEST we send to POST /v1/messages',
            'TURN 1: RESPONSE  stop_reason = "tool_use"',
            'TOOL CALL  the model asked us to run "search_wiki"',
            'TOOL RESULT  going back to the model (as a "user" message)',
            'TURN 2: REQUEST we send to POST /v1/messages',
            'TURN 2: RESPONSE  stop_reason = "end_turn"',
            'FINAL ANSWER',
        ], $this->steps);
    }

    public function test_a_failing_tool_is_reported_to_the_model_not_thrown(): void
    {
        $run = $this->loop([
            self::reply('tool_use', [self::toolUse('get_wiki_entry', ['url_or_name' => 'https://example.com/x.html#a'])]),
            self::reply('end_turn', [self::text('Sorry.')]),
        ])->run('q');

        $this->assertTrue($run['calls'][0]['is_error']);
        $this->assertStringStartsWith('Error: Not an Aldervast wiki entry URL', $run['calls'][0]['result']);
        $this->assertTrue($this->body(1)['messages'][2]['content'][0]['is_error']);
    }

    public function test_an_unknown_tool_is_an_error_result(): void
    {
        $run = $this->loop([
            self::reply('tool_use', [self::toolUse('delete_everything', [])]),
            self::reply('end_turn', [self::text('Sorry.')]),
        ])->run('q');

        $this->assertSame('Error: Unknown tool delete_everything', $run['calls'][0]['result']);
        $this->assertTrue($run['calls'][0]['is_error']);
    }

    public function test_empty_tool_input_goes_back_to_the_api_as_an_object(): void
    {
        $this->loop([
            self::reply('tool_use', [self::toolUse('list_servers', [])]),
            self::reply('end_turn', [self::text('One server.')]),
        ])->run('q');

        // json_decode turns {} into []; the API needs {} back.
        $this->assertStringContainsString('"input":{}', (string) $this->sent[1]['request']->getBody());
    }

    public function test_the_loop_stops_after_five_turns(): void
    {
        $replies = array_map(fn (int $i) => self::reply('tool_use', [self::toolUse('search_wiki', ['query' => "q{$i}"], "toolu_{$i}")]), range(1, 5));
        $run = $this->loop($replies)->run('q');

        $this->assertSame('max_turns', $run['stop_reason']);
        $this->assertSame(5, $run['turns']);
        $this->assertCount(5, $this->sent);
        $this->assertSame('', $run['answer']);
        $this->assertSame('STOPPED', end($this->steps));
        $this->assertSame('Stopped after 5 turns without a final answer.', $this->stepData['STOPPED']);
    }

    public function test_a_refusal_ends_the_run_with_its_stop_reason(): void
    {
        $run = $this->loop([self::reply('refusal', [])])->run('q');

        $this->assertSame('refusal', $run['stop_reason']);
        $this->assertSame('', $run['answer']);
    }

    public function test_rate_limits_and_overloads_are_retried_with_growing_waits(): void
    {
        $run = $this->loop([
            new Response(429),
            new Response(529),
            self::reply('end_turn', [self::text('Done.')]),
        ])->run('q');

        $this->assertSame('Done.', $run['answer']);
        $this->assertSame(2, $run['retries']);
        // 1 s then 2 s, each plus up to 1 s at random.
        $this->assertGreaterThanOrEqual(1.0, $this->waits[0]);
        $this->assertLessThanOrEqual(2.0, $this->waits[0]);
        $this->assertGreaterThanOrEqual(2.0, $this->waits[1]);
        $this->assertLessThanOrEqual(3.0, $this->waits[1]);
    }

    public function test_a_lost_connection_is_retried(): void
    {
        $run = $this->loop([
            new ConnectException('connection reset', new Request('POST', '/v1/messages')),
            self::reply('end_turn', [self::text('Done.')]),
        ])->run('q');

        $this->assertSame(1, $run['retries']);
        $this->assertSame('Done.', $run['answer']);
    }

    public function test_a_request_that_keeps_failing_ends_the_run_after_three_retries(): void
    {
        try {
            $this->loop(array_fill(0, 5, new Response(503, [], 'overloaded')))->run('q');
            $this->fail('expected an AgentLoopError');
        } catch (AgentLoopError $e) {
            $this->assertSame('api_error', $e->kind);
            $this->assertSame('503 overloaded', $e->getMessage());
            $this->assertCount(4, $this->sent); // the first try and three retries
        }
    }

    public function test_a_response_that_is_not_from_the_messages_api_is_an_api_error(): void
    {
        try {
            $this->loop([new Response(200, [], '<html>Bad gateway</html>')])->run('q');
            $this->fail('expected an AgentLoopError');
        } catch (AgentLoopError $e) {
            $this->assertSame('api_error', $e->kind);
            $this->assertSame('Not a Messages API response: <html>Bad gateway</html>', $e->getMessage());
        }
    }

    public function test_a_bad_request_is_not_retried(): void
    {
        try {
            $this->loop([new Response(400, [], '{"error":"bad"}'), self::reply('end_turn', [])])->run('q');
            $this->fail('expected an AgentLoopError');
        } catch (AgentLoopError $e) {
            $this->assertSame('api_error', $e->kind);
            $this->assertCount(1, $this->sent);
            $this->assertSame([], $this->waits);
        }
    }

    public function test_a_different_model_answering_ends_the_run_and_keeps_the_billed_tokens(): void
    {
        try {
            $this->loop([self::reply('end_turn', [self::text('Hi.')], 'claude-sonnet-5')])->run('q');
            $this->fail('expected an AgentLoopError');
        } catch (AgentLoopError $e) {
            $this->assertSame('model_mismatch', $e->kind);
            $this->assertSame('Asked for claude-sonnet-5-5, got claude-sonnet-5', $e->getMessage());
            $this->assertSame(10, $e->usage['input_tokens']);
        }
    }

    public function test_a_run_past_its_time_limit_ends_as_a_timeout(): void
    {
        try {
            $this->loop([self::reply('end_turn', [])])->run('q', timeLimit: 0.0);
            $this->fail('expected an AgentLoopError');
        } catch (AgentLoopError $e) {
            $this->assertSame('timeout', $e->kind);
            $this->assertCount(0, $this->sent);
        }
    }

    public function test_each_request_waits_no_longer_than_the_time_left(): void
    {
        $this->loop([self::reply('end_turn', [self::text('Hi.')])])->run('q', timeLimit: 30.0);

        $this->assertLessThanOrEqual(30.0, $this->sent[0]['options']['timeout']);
        $this->assertGreaterThan(29.0, $this->sent[0]['options']['timeout']);
    }

    public function test_the_history_sent_back_keeps_each_role(): void
    {
        $this->loop([
            self::reply('tool_use', [self::toolUse('search_wiki', ['query' => 'stag'])]),
            self::reply('end_turn', [self::text('Done.')]),
        ])->run('q');

        $this->assertSame(['user', 'assistant', 'user'], array_column($this->body(1)['messages'], 'role'));
        // The tools go as a JSON list, not an object keyed by tool name.
        $this->assertStringContainsString('"tools":[{', (string) $this->sent[0]['request']->getBody());
    }

    public function test_every_text_block_of_the_final_reply_is_kept(): void
    {
        $run = $this->loop([self::reply('end_turn', [self::text('First part. '), self::text('Second part.')])])->run('q');

        $this->assertSame('First part. Second part.', $run['answer']);
        $this->assertSame('First part. Second part.', $this->stepData['FINAL ANSWER']);
    }

    public function test_each_call_records_which_tool_ran(): void
    {
        $run = $this->loop([
            self::reply('tool_use', [self::toolUse('search_wiki', ['query' => 'stag'])]),
            self::reply('end_turn', [self::text('Done.')]),
        ])->run('q');

        $this->assertSame('search_wiki', $run['calls'][0]['tool']);
    }

    public function test_each_response_step_shows_the_content_and_the_tokens(): void
    {
        $this->loop([self::reply('end_turn', [self::text('Hi.')])])->run('q');

        $this->assertSame([self::text('Hi.')], $this->stepData['TURN 1: RESPONSE  stop_reason = "end_turn"']['content']);
        $this->assertSame(10, $this->stepData['TURN 1: RESPONSE  stop_reason = "end_turn"']['usage']['input_tokens']);
    }

    public function test_missing_usage_fields_count_as_zero(): void
    {
        $run = $this->loop([new Response(200, [], json_encode([
            'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'content' => [self::text('Hi.')],
            'usage' => ['input_tokens' => 7, 'output_tokens' => 3],
        ]))])->run('q');

        $this->assertSame(['input_tokens' => 7, 'output_tokens' => 3, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0], $run['usage']);
    }

    public function test_time_in_the_api_is_measured_and_small_for_a_fake_api(): void
    {
        $run = $this->loop([
            self::reply('tool_use', [self::toolUse('search_wiki', ['query' => 'stag'])]),
            self::reply('end_turn', [self::text('Done.')]),
        ])->run('q');

        $this->assertGreaterThan(0.0, $run['latency_s']);
        $this->assertLessThan(1.0, $run['latency_s']);
    }

    public function test_a_retry_says_why_and_how_long_it_waits(): void
    {
        $this->loop([new Response(429), self::reply('end_turn', [self::text('Done.')])])->run('q');

        $this->assertMatchesRegularExpression('/^Request failed \(429\); trying again in \d\.\d s$/', $this->stepData['RETRY']);
    }

    public function test_a_lost_connection_that_never_recovers_says_there_was_no_response(): void
    {
        try {
            $this->loop(array_fill(0, 4, new ConnectException('connection reset', new Request('POST', '/v1/messages'))))->run('q');
            $this->fail('expected an AgentLoopError');
        } catch (AgentLoopError $e) {
            $this->assertSame('api_error', $e->kind);
            $this->assertSame('no response connection reset', $e->getMessage());
        }
    }

    public function test_an_error_status_with_no_body_gives_just_the_status(): void
    {
        try {
            $this->loop(array_fill(0, 4, new Response(503)))->run('q');
            $this->fail('expected an AgentLoopError');
        } catch (AgentLoopError $e) {
            $this->assertSame('503', $e->getMessage());
        }
    }

    public function test_json_without_the_fields_of_a_reply_is_an_api_error(): void
    {
        try {
            $this->loop([new Response(200, [], '{"error":"something else"}')])->run('q');
            $this->fail('expected an AgentLoopError');
        } catch (AgentLoopError $e) {
            $this->assertSame('api_error', $e->kind);
        }
    }

    public function test_a_long_bad_response_is_shortened_in_the_error(): void
    {
        try {
            $this->loop([new Response(200, [], str_repeat('x', 300))])->run('q');
            $this->fail('expected an AgentLoopError');
        } catch (AgentLoopError $e) {
            $this->assertSame('Not a Messages API response: ' . str_repeat('x', 199) . '…', $e->getMessage());
        }
    }

    public function test_without_a_time_limit_each_request_waits_up_to_two_minutes(): void
    {
        $this->loop([self::reply('end_turn', [self::text('Hi.')])])->run('q');

        $this->assertSame(120, $this->sent[0]['options']['timeout']);
    }
}
