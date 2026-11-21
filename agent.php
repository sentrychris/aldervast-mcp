<?php

/**
 * agent.php - watch the agent loop happen, one printed step at a time.
 *
 * Run:  php agent.php
 *  or:  php agent.php "Is there a server I can join, and what port?"
 *
 * The loop itself is in src/Agent/AgentLoop.php: read that file from top to bottom to see how an
 * agent works. This script only runs it on your question and prints each step as it happens: the
 * request we send, the model's response, each tool call and its result, and the final answer. The
 * eval (evals/run.php) runs the same loop, so what you see here is what the eval measures.
 */

use Aldervast\Agent\AgentLoop;
use Aldervast\Agent\AgentLoopError;
use Aldervast\Agent\Env;
use Aldervast\Tools\Json;

require __DIR__ . '/vendor/autoload.php';

/** Prints a coloured heading for one step of the agent loop (colours work in Windows Terminal and PowerShell 7). */
function step(string $title): void
{
    echo "\n\033[1;36m=== {$title} ===\033[0m\n";
}

Env::load(__DIR__ . '/.env');

$apiKey = Env::get('ANTHROPIC_API_KEY');
if ($apiKey === '' || str_contains($apiKey, 'your-key-here')) {
    exit("No API key. Copy .env.example to .env and paste your key from https://console.anthropic.com\n");
}

$question = $argv[1] ?? 'What Aldervast servers can I play on, and how do I connect?';

$loop = new AgentLoop(
    AgentLoop::client($apiKey, Env::get('ANTHROPIC_BASE_URL', 'https://api.anthropic.com')),
    // Print every step: a coloured heading, then the JSON (or text) that goes with it.
    onStep: function (string $title, mixed $data): void {
        step($title);
        echo is_string($data) ? $data : Json::pretty($data), "\n";
    },
);

try {
    $loop->run($question);
} catch (AgentLoopError $e) {
    exit("\nThe run failed ({$e->kind}): {$e->getMessage()}\n");
}
