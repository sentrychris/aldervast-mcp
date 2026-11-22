<?php

declare(strict_types=1);

namespace Aldervast\Agent;

use Aldervast\Tools\Json;
use Aldervast\Tools\Tools;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

/**
 * The agent loop: the whole "agent" in one class. agent.php uses it to print every step, and the
 * eval (evals/run.php) uses it to record every step, so both run exactly the same code.
 *
 * The loop, in plain words:
 *   1. Send the model a question AND a list of tools that it may ask for.
 *   2. If the reply's stop_reason is "tool_use", the model is asking us to run a tool.
 *      We run it in PHP and send the output back as a "tool_result".
 *   3. Repeat until stop_reason is "end_turn": the model has its final answer.
 *
 * That's all an "agent" is: a loop around an HTTP call, where the model chooses which of YOUR
 * functions to call next. On Anthropic's side, the only thing that happens is that the model
 * decides to reply with a tool_use block instead of plain text.
 */
final class AgentLoop
{
    public const MODEL = 'claude-sonnet-5-5';

    /** A limit, so that a confused model can't keep the loop going forever. */
    public const MAX_TURNS = 5;

    /** How often to retry a request that failed for a reason that may pass (rate limit, overload). */
    private const MAX_RETRIES = 3;

    /** HTTP status codes worth retrying: too many requests, server errors, and 529 "overloaded". */
    private const RETRY_STATUSES = [429, 500, 502, 503, 504, 529];

    /** No single request waits longer than this, even when the run's time limit allows more. */
    private const REQUEST_TIMEOUT_SECONDS = 120;

    /**
     * @param ClientInterface $http   a Guzzle client for the Messages API (see client())
     * @param string          $model  the model to ask
     * @param Closure|null    $onStep called as $onStep(string $title, mixed $data) at every step;
     *                                agent.php prints these, the eval doesn't need them
     * @param Closure|null    $sleep  waits a number of seconds between retries; the tests pass one
     *                                that doesn't wait
     * @param string          $extraInstructions added after Tools::INSTRUCTIONS in the system prompt,
     *                                for one use of the loop only; the website's chatbot uses it to stay
     *                                on topic (Chat::INSTRUCTIONS). agent.php and the eval add nothing.
     */
    public function __construct(
        private ClientInterface $http,
        private string $model = self::MODEL,
        private ?Closure $onStep = null,
        private ?Closure $sleep = null,
        private string $extraInstructions = '',
    ) {
    }

    /** A Guzzle client for the Messages API. Every turn is a POST to /v1/messages. */
    public static function client(string $apiKey, string $baseUrl = 'https://api.anthropic.com'): Client
    {
        return new Client([
            'base_uri' => $baseUrl,
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2026-10-21',  // API version, required on every request.
                'content-type' => 'application/json',
                // Opt-in beta: if a safety check declines the request, the API retries it on a
                // fallback model inside the same call (see "fallbacks" below). Safe to remove.
                'anthropic-beta' => 'server-side-fallback-2026-07-01',
            ],
        ]);
    }

    /**
     * Runs one question to the end and returns everything that happened:
     *   answer       the final text
     *   stop_reason  why the model stopped: end_turn, max_tokens (cut off), refusal, or max_turns
     *                (our own limit above)
     *   model        the model that answered, read from the response
     *   turns        how many requests were sent
     *   calls        every tool call: [tool, input, result, is_error]
     *   usage        tokens over all requests: input, output, cache reads and cache writes
     *   latency_s    time spent in successful requests, without retry waits
     *   retries      how many requests had to be sent again
     *   transcript   the conversation as a list of turns, in the format the eval report reads
     *
     * @param float|null $timeLimit seconds that the whole run may take; null for no limit
     * @throws AgentLoopError when the run fails before the model gives an answer
     */
    public function run(string $question, ?float $timeLimit = null): array
    {
        $started = hrtime(true) / 1e9; // seconds, from a clock that only moves forward
        $tools = Tools::byName();
        // The same guidance that the MCP server sends to Claude Desktop (see src/Tools/Tools.php),
        // plus any instructions for this one use of the loop.
        $system = Tools::INSTRUCTIONS . ($this->extraInstructions === '' ? '' : "\n\n" . $this->extraInstructions);
        // The model only ever sees the definitions (name, description, input schema), never the PHP.
        $definitions = array_map(fn (string $class) => $class::definition(), array_values($tools));

        // The conversation. The API is stateless: we send the WHOLE history every time.
        $messages = [['role' => 'user', 'content' => $question]];

        $result = [
            'answer' => '', 'stop_reason' => 'max_turns', 'model' => '', 'turns' => 0, 'calls' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            'latency_s' => 0.0, 'retries' => 0,
            'transcript' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $question]],
        ];

        for ($turn = 1; $turn <= self::MAX_TURNS; $turn++) {
            $request = [
                'model' => $this->model,
                'max_tokens' => 4096,
                'output_config' => ['effort' => 'low'], // Simple questions: less thinking, faster and cheaper.
                'fallbacks' => 'default',
                'system' => $system,
                'tools' => $definitions,
                'messages' => $messages,
                // Prompt caching: the API keeps the start of this request (tools, system, history) for a
                // few minutes, and the next request that starts the same way reads it at a tenth of the
                // price. Each turn repeats everything before it, so this saves most of the input cost.
                'cache_control' => ['type' => 'ephemeral'],
            ];
            $this->step("TURN {$turn}: REQUEST we send to POST /v1/messages", $request);

            $reply = $this->send($request, $result, $started, $timeLimit);
            $result['turns'] = $turn;
            $result['model'] = $reply['model'] ?? '';
            foreach (array_keys($result['usage']) as $field) {
                $result['usage'][$field] += $reply['usage'][$field] ?? 0;
            }

            // A safety-check fallback (or any other switch) means a different model answered, so the
            // answer says nothing about the model we meant to test.
            if (!str_starts_with($result['model'], $this->model)) {
                throw new AgentLoopError('model_mismatch', "Asked for {$this->model}, got {$result['model']}", $result['usage']);
            }

            // PHP gotcha: json_decode(..., true) turns an empty JSON object {} into an empty array [].
            // A tool_use "input" must go back to the API as an object, so cast it back.
            foreach ($reply['content'] as &$block) {
                if ($block['type'] === 'tool_use') {
                    $block['input'] = (object) $block['input'];
                }
            }
            unset($block);

            $this->step("TURN {$turn}: RESPONSE  stop_reason = \"{$reply['stop_reason']}\"", [
                'content' => $reply['content'],
                'usage' => $reply['usage'] ?? [],
            ]);

            // Put the model's reply into the history EXACTLY as it came back (all blocks, including
            // any empty "thinking" blocks). The next request must include it.
            $messages[] = ['role' => 'assistant', 'content' => $reply['content']];

            $text = '';
            foreach ($reply['content'] as $block) {
                if ($block['type'] === 'text') {
                    $text .= $block['text'];
                }
            }
            if ($text !== '') {
                $result['transcript'][] = ['role' => 'assistant', 'content' => $text];
            }

            // --- The decision point of every agent loop ---------------------------------------
            if ($reply['stop_reason'] !== 'tool_use') {
                // end_turn = finished. (Other values: max_tokens = cut off, refusal = declined.)
                $result['answer'] = $text;
                $result['stop_reason'] = $reply['stop_reason'];
                $this->step('FINAL ANSWER', $text);

                return $result;
            }

            // stop_reason is "tool_use": run every tool the model asked for.
            $results = [];
            foreach ($reply['content'] as $block) {
                if ($block['type'] !== 'tool_use') {
                    continue;
                }
                $this->step("TOOL CALL  the model asked us to run \"{$block['name']}\"", $block);

                $input = (array) $block['input'];
                try {
                    $class = $tools[$block['name']] ?? throw new RuntimeException("Unknown tool {$block['name']}");
                    $output = $class::run($input);
                    $isError = false;
                } catch (Throwable $e) {
                    // Tell the model the tool failed rather than crashing; it can explain or retry.
                    $output = 'Error: ' . $e->getMessage();
                    $isError = true;
                }

                $result['calls'][] = ['tool' => $block['name'], 'input' => $input, 'result' => $output, 'is_error' => $isError];
                $result['transcript'][] = ['role' => 'tool_call', 'name' => $block['name'], 'content' => Json::pretty((object) $input)];
                $result['transcript'][] = ['role' => 'tool_result', 'name' => $block['name'], 'content' => $output];

                // tool_use_id links this result to the exact request that the model made.
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => $output, 'is_error' => $isError];
            }

            $this->step('TOOL RESULT  going back to the model (as a "user" message)', $results);

            // Tool results go back in a user message. Then the loop sends everything again.
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        $this->step('STOPPED', 'Stopped after ' . self::MAX_TURNS . ' turns without a final answer.');

        return $result;
    }

    /**
     * Sends one request, and retries it with a growing, slightly random wait when the failure may pass
     * (rate limit, overload, server error, lost connection). Other failures, and running out of time
     * or retries, end the run with an AgentLoopError.
     */
    private function send(array $request, array &$result, float $started, ?float $timeLimit): array
    {
        for ($attempt = 0; ; $attempt++) {
            $timeout = self::REQUEST_TIMEOUT_SECONDS;
            if ($timeLimit !== null) {
                $left = $timeLimit - (hrtime(true) / 1e9 - $started);
                if ($left <= 0) {
                    throw new AgentLoopError('timeout', "Ran out of time after {$timeLimit} s", $result['usage']);
                }
                $timeout = min($timeout, $left);
            }

            try {
                $sent = hrtime(true);
                $response = $this->http->request('POST', '/v1/messages', ['json' => $request, 'timeout' => $timeout]);
                $result['latency_s'] += (hrtime(true) - $sent) / 1e9;
                $body = (string) $response->getBody();
                $reply = json_decode($body, true);
                if (!is_array($reply) || !isset($reply['content'], $reply['stop_reason'])) {
                    // Something answered, but not with a Messages API response (e.g. a proxy's error page).
                    throw new AgentLoopError('api_error', 'Not a Messages API response: ' . mb_strimwidth($body, 0, 200, '…'), $result['usage']);
                }

                return $reply;
            } catch (GuzzleException $e) {
                $status = $e instanceof RequestException && $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
                $worthRetrying = $e instanceof ConnectException || in_array($status, self::RETRY_STATUSES, true);
                if (!$worthRetrying || $attempt >= self::MAX_RETRIES) {
                    $body = $e instanceof RequestException && $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
                    throw new AgentLoopError('api_error', trim(($status ?? 'no response') . ' ' . $body), $result['usage']);
                }

                // Wait 1, 2, then 4 seconds, plus up to a second at random, so that parallel runs
                // that failed together don't all retry at the same moment.
                $wait = 2 ** $attempt + mt_rand(0, 1000) / 1000;
                $result['retries']++;
                $this->step('RETRY', "Request failed ({$status}); trying again in " . number_format($wait, 1) . ' s');
                ($this->sleep ?? fn (float $s) => usleep((int) ($s * 1e6)))($wait);
            }
        }
    }

    private function step(string $title, mixed $data): void
    {
        if ($this->onStep !== null) {
            ($this->onStep)($title, $data);
        }
    }
}
