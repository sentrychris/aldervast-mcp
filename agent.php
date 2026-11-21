<?php

/**
 * agent.php - the whole "agent loop" in one readable file.
 *
 * Run:  php agent.php
 *  or:  php agent.php "Is there a server I can join, and what port?"
 *
 * The loop, in plain words:
 *   1. Send the model a question AND a list of tools it may ask for.
 *   2. If the reply's stop_reason is "tool_use", the model is asking us to run a tool.
 *      We run it in PHP and send the output back as a "tool_result".
 *   3. Repeat until stop_reason is "end_turn": the model has its final answer.
 *
 * That's all an "agent" is: a while loop around an HTTP call, where the model chooses
 * which of YOUR functions to call next. No magic happens on Anthropic's side beyond the model
 * deciding to emit a tool_use block instead of plain text.
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

load_env(__DIR__ . '/.env');

$apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';
if ($apiKey === '' || str_contains($apiKey, 'your-key-here')) {
    exit("No API key. Copy .env.example to .env and paste your key from https://console.anthropic.com\n");
}

$question = $argv[1] ?? 'What Aldervast servers can I play on, and how do I connect?';

// One Guzzle client for the Anthropic Messages API. Every turn is a POST to /v1/messages.
$anthropic = new Client([
    'base_uri' => $_ENV['ANTHROPIC_BASE_URL'] ?? 'https://api.anthropic.com',
    'timeout' => 120,
    'headers' => [
        'x-api-key' => $apiKey,
        'anthropic-version' => '2026-10-21',  // API version, required on every request.
        'content-type' => 'application/json',
        // Opt-in beta: if a safety check declines the request, the API retries it on a
        // fallback model inside the same call (see "fallbacks" below). Safe to remove.
        'anthropic-beta' => 'server-side-fallback-2026-07-01',
    ],
]);

// The conversation. The API is stateless: we send the WHOLE history every time.
$messages = [
    ['role' => 'user', 'content' => $question],
];

// The tools the model may ask for, keyed by tool name, so we can find the class to run later.
$toolClasses = [];
foreach ([ServerTool::class, SearchWikiTool::class, GetWikiEntryTool::class, ListCategoryTool::class] as $class) {
    $toolClasses[$class::NAME] = $class;
}
// The model only ever sees the definitions (name, description, input schema), never the PHP.
$tools = array_map(fn (string $class) => $class::definition(), array_values($toolClasses));

for ($turn = 1; $turn <= 5; $turn++) {   // A safety cap so a confused model can't loop forever.
    $request = [
        'model' => 'claude-sonnet-5-5',
        'max_tokens' => 4096,
        'output_config' => ['effort' => 'low'], // Simple question: less thinking, faster and cheaper.
        'fallbacks' => 'default',
        'tools' => $tools,
        'messages' => $messages,
    ];

    step("TURN {$turn}: REQUEST we send to POST /v1/messages");
    echo pretty($request), "\n";

    try {
        $response = $anthropic->post('/v1/messages', ['json' => $request]);
    } catch (RequestException $e) {
        $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
        exit("\nAPI error: {$body}\n");
    }
    $reply = json_decode((string) $response->getBody(), true);

    // PHP gotcha: json_decode(..., true) turns an empty JSON object {} into an empty array [].
    // A tool_use "input" must go back to the API as an object, so cast it back.
    foreach ($reply['content'] as &$block) {
        if ($block['type'] === 'tool_use') {
            $block['input'] = (object) $block['input'];
        }
    }
    unset($block);

    step("TURN {$turn}: RESPONSE  stop_reason = \"{$reply['stop_reason']}\"");
    echo pretty($reply['content']), "\n";
    echo "(tokens in: {$reply['usage']['input_tokens']}, out: {$reply['usage']['output_tokens']})\n";

    // Put the model's reply into the history EXACTLY as it came back (all blocks, including
    // any empty "thinking" blocks). The next request must include it.
    $messages[] = ['role' => 'assistant', 'content' => $reply['content']];

    // --- The decision point of every agent loop ---------------------------------------
    if ($reply['stop_reason'] !== 'tool_use') {
        // end_turn = finished. (Other values: max_tokens = cut off, refusal = declined.)
        $answer = '';
        foreach ($reply['content'] as $block) {
            if ($block['type'] === 'text') {
                $answer .= $block['text'];
            }
        }
        step('FINAL ANSWER');
        echo $answer, "\n";
        exit(0);
    }

    // stop_reason is "tool_use": run every tool the model asked for.
    $results = [];
    foreach ($reply['content'] as $block) {
        if ($block['type'] !== 'tool_use') {
            continue;
        }

        step("TOOL CALL  the model asked us to run \"{$block['name']}\"");
        echo pretty($block), "\n";

        try {
            $class = $toolClasses[$block['name']] ?? throw new RuntimeException("Unknown tool {$block['name']}");
            $output = $class::run((array) $block['input']);
            $isError = false;
        } catch (Throwable $e) {
            // Tell the model the tool failed rather than crashing; it can explain or retry.
            $output = 'Error: ' . $e->getMessage();
            $isError = true;
        }

        // tool_use_id links this result to the exact request the model made.
        $results[] = [
            'type' => 'tool_result',
            'tool_use_id' => $block['id'],
            'content' => $output,
            'is_error' => $isError,
        ];
    }

    step('TOOL RESULT  going back to the model (as a "user" message)');
    echo pretty($results), "\n";

    // Tool results go back in a user message. Then the loop sends everything again.
    $messages[] = ['role' => 'user', 'content' => $results];
}

echo "\nStopped after 5 turns without a final answer.\n";
