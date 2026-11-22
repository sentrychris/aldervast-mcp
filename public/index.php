<?php

/**
 * public/index.php - the website: the only PHP file that the web server runs.
 *
 * Apache sends every request for a path that isn't a real file here (FallbackResource in
 * docker/apache.conf). This file only picks the handler and sends its response:
 *
 *   GET  /           the chatbot page (public/chat.html)
 *   POST /chat       one question for the chatbot (src/Web/Chat.php); needs ANTHROPIC_API_KEY
 *   *    /mcp        the MCP server over HTTP, for custom connectors (src/Mcp/HttpTransport.php)
 *   GET  /widget.js  the chat button for other sites; Apache serves it directly, as a real file
 *   GET  /usage      the chatbot's usage and cost per day (src/Web/UsageDashboard.php); needs a password
 *
 * Settings come from environment variables (README.md, "Offering the server to other people").
 */

use Aldervast\Agent\AgentLoop;
use Aldervast\Agent\Env;
use Aldervast\Mcp\HttpTransport;
use Aldervast\Mcp\Server;
use Aldervast\Mcp\ToolCallLog;
use Aldervast\Web\Chat;
use Aldervast\Eval\EvalScore;
use Aldervast\Web\RateLimit;
use Aldervast\Web\UsageDashboard;
use Aldervast\Web\UsageLog;
use Aldervast\Web\UsageReport;

require dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

/** Sends a status, headers and body, and stops. */
function respond(int $status, array $headers, string $body): never
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo $body;
    exit;
}

/**
 * The visitor's IP address. Requests reach this app through the host's Apache, which adds the
 * real address at the end of X-Forwarded-For, so the last entry is the one to trust. (Entries
 * before it come from the visitor's side and could be made up.)
 */
function client_ip(): string
{
    $forwarded = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

    return end($forwarded) ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/mcp') {
    // Strangers' questions shouldn't be kept by default, so the log is off unless a setting turns it on.
    $log = Env::get('ALDERVAST_TOOL_LOG') === '' ? new ToolCallLog(null, '') : ToolCallLog::fromEnvironment();
    $origins = array_filter(array_map('trim', explode(',', Env::get('ALDERVAST_ALLOWED_ORIGINS', 'https://claude.ai,https://claude.com'))));
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $response = (new HttpTransport(new Server($log), $origins))->handle($method, $headers, (string) file_get_contents('php://input'));
    respond($response['status'], $response['headers'], $response['body']);
}

if ($path === '/chat') {
    // The chat widget (public/widget.js) runs on other sites, such as aldervast.online. A browser only
    // lets such a page read our reply if we name its origin in Access-Control-Allow-Origin (this is
    // CORS). Before the real request, the browser asks with OPTIONS whether it may send JSON at all.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_map('trim', explode(',', Env::get('ALDERVAST_CHAT_ORIGINS', 'https://aldervast.online')));
    $cors = in_array($origin, $allowed, true)
        ? ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin']
        : ['Vary' => 'Origin'];
    if ($method === 'OPTIONS') {
        respond(204, $cors + ['Access-Control-Allow-Methods' => 'POST', 'Access-Control-Allow-Headers' => 'Content-Type', 'Access-Control-Max-Age' => '86400'], '');
    }

    if ($method === 'POST') {
        // An answer can take a while: several turns, each a call to the Messages API.
        set_time_limit(150);
        $apiKey = Env::get('ANTHROPIC_API_KEY');
        // Chat::INSTRUCTIONS keeps the chatbot to questions about Aldervast.
        $loop = $apiKey === '' ? null : new AgentLoop(AgentLoop::client($apiKey), extraInstructions: Chat::INSTRUCTIONS);
        $limit = new RateLimit(
            dirname(__DIR__) . '/cache/chat-limits.json',
            (int) Env::get('ALDERVAST_CHAT_PER_HOUR', '10'),
            (int) Env::get('ALDERVAST_CHAT_PER_DAY', '100'),
        );
        // What each question used (tokens, never the question), for show-chat-usage.php.
        $usage = new UsageLog(dirname(__DIR__) . '/cache/chat-usage.jsonl');
        $response = (new Chat($loop, $limit, usage: $usage))->ask((string) file_get_contents('php://input'), client_ip());
        respond($response['status'], ['Content-Type' => 'application/json'] + $cors, json_encode($response['body'], JSON_UNESCAPED_SLASHES));
    }
}

if ($path === '/usage' && $method === 'GET') {
    // The chatbot's usage page. It's private, so it needs the password in ALDERVAST_USAGE_PASSWORD
    // (the browser asks for it; any user name works). Without that setting, the page doesn't exist.
    $password = Env::get('ALDERVAST_USAGE_PASSWORD');
    if ($password === '') {
        respond(404, ['Content-Type' => 'text/plain'], "Not found\n");
    }
    if (!hash_equals($password, (string) ($_SERVER['PHP_AUTH_PW'] ?? ''))) {
        respond(401, ['WWW-Authenticate' => 'Basic realm="Aldervast usage"', 'Content-Type' => 'text/plain'], "Password needed\n");
    }
    // The prices are the eval's (EvalScore::PRICES). This file may use any part of src/; UsageReport
    // may not use Eval, so it gets the cost as a function.
    $days = UsageReport::days(
        dirname(__DIR__) . '/cache/chat-usage.jsonl',
        fn (array $rows) => EvalScore::cost($rows),
        30,
        time(),
    );
    respond(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
        UsageDashboard::render($days, (int) Env::get('ALDERVAST_CHAT_PER_DAY', '100')));
}

if ($path === '/' && $method === 'GET') {
    respond(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents(__DIR__ . '/chat.html'));
}

respond(404, ['Content-Type' => 'text/plain'], "Not found\n");
