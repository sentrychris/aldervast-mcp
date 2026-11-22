<?php

declare(strict_types=1);

namespace Aldervast\Web;

use Closure;

/**
 * Writes one JSON line per chatbot question to a file, so that the site owner can see what the
 * chatbot costs. show-chat-usage.php reads the file and adds it up per day.
 *
 * A line holds the time, the outcome, and what the answer used: tokens, tool calls, turns and time.
 * It never holds the question, the answer or anything about the visitor. The tokens are enough to
 * work out the cost, and nothing else is needed.
 *
 * The outcomes:
 *   answered   the visitor got an answer
 *   no_answer  the model finished without any text (tokens were still spent)
 *   failed     the agent loop stopped with an error (tokens spent before the error still count)
 *   limited    refused by RateLimit, so nothing was spent
 *
 * Writing must never break the chatbot, so an error while writing is ignored, as in ToolCallLog.
 */
final class UsageLog
{
    private const NO_TOKENS = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0];

    /**
     * @param string|null  $file where to write, or null to write nothing
     * @param Closure|null $now  returns the current time as a Unix timestamp; the tests pass a fixed clock
     */
    public function __construct(private ?string $file, private ?Closure $now = null)
    {
    }

    /**
     * @param string $model the model that was asked
     * @param array  $usage token counts, as AgentLoop::run() returns them; missing counts are 0
     */
    public function record(string $outcome, string $model = '', array $usage = [], int $toolCalls = 0, int $turns = 0, float $seconds = 0.0): void
    {
        if ($this->file === null) {
            return;
        }
        $time = $this->now === null ? time() : ($this->now)();
        $line = json_encode([
            'time' => gmdate('Y-m-d\TH:i:s\Z', $time),
            'outcome' => $outcome,
            'model' => $model,
            'usage' => array_intersect_key($usage, self::NO_TOKENS) + self::NO_TOKENS,
            'tool_calls' => $toolCalls,
            'turns' => $turns,
            'seconds' => round($seconds, 2),
        ]);
        // LOCK_EX: several requests can finish at the same moment, and their lines must not mix.
        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
