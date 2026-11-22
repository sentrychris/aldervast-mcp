<?php

declare(strict_types=1);

namespace Aldervast\Web;

use Aldervast\Agent\AgentLoop;
use Aldervast\Agent\AgentLoopError;

/**
 * The chatbot on the website: a visitor's question goes through the same agent loop as agent.php,
 * and the answer comes back as JSON for the page (public/chat.html) to show.
 *
 * The chatbot doesn't use MCP. MCP is for apps that run their own agent loop (Claude Desktop,
 * claude.ai). Here the website IS the app: it calls the Messages API with the tools itself, through
 * AgentLoop, just as agent.php does. Every answer costs money on the site owner's API key, which is
 * why RateLimit exists and why questions are kept short.
 *
 * Each question is answered on its own, with no memory of earlier ones. That keeps every request
 * small and cheap, and nothing about a visitor is kept on the server.
 */
final class Chat
{
    public const MAX_QUESTION_CHARS = 500;

    /**
     * @param AgentLoop|null $loop      null when the server has no API key: the chatbot is off
     * @param float          $timeLimit seconds that one answer may take
     */
    public function __construct(
        private ?AgentLoop $loop,
        private RateLimit $limit,
        private float $timeLimit = 90,
    ) {
    }

    /**
     * The response to one POST /chat request, whose body is {"question": "..."}.
     *
     * @return array{status: int, body: array} body is {"answer", "tools"} or {"error"}
     */
    public function ask(string $body, string $ip): array
    {
        if ($this->loop === null) {
            return self::error(503, 'The chatbot is not set up on this server yet.');
        }
        $request = json_decode($body, true);
        $question = is_array($request) ? trim((string) ($request['question'] ?? '')) : '';
        if ($question === '') {
            return self::error(400, 'Please type a question.');
        }
        if (mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            return self::error(400, 'Please keep your question under ' . self::MAX_QUESTION_CHARS . ' characters.');
        }
        $refused = $this->limit->take($ip);
        if ($refused !== null) {
            return self::error(429, $refused);
        }

        try {
            $result = $this->loop->run($question, $this->timeLimit);
        } catch (AgentLoopError $e) {
            // The details go to the server's log, not to the visitor.
            error_log("[aldervast-chat] {$e->kind}: {$e->getMessage()}");

            return self::error(502, 'Sorry, something went wrong while answering. Please try again in a minute.');
        }
        if (trim($result['answer']) === '') {
            error_log("[aldervast-chat] no answer, stop_reason {$result['stop_reason']}");

            return self::error(502, "Sorry, I couldn't find an answer to that. Please try asking another way.");
        }

        return ['status' => 200, 'body' => [
            'answer' => $result['answer'],
            // Which tools were used, in order, so the page can show where the answer came from.
            'tools' => array_column($result['calls'], 'tool'),
        ]];
    }

    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $message]];
    }
}
