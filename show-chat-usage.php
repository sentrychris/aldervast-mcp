<?php

declare(strict_types=1);

/**
 * Prints what the website's chatbot used, per day (UTC): how many questions it answered, failed or
 * refused, the tokens, and what they cost. It reads cache/chat-usage.jsonl, which src/Web/UsageLog.php
 * writes. The same numbers are on the usage page, https://aldervast.versyxdigital.net/usage.
 * On the server, run it inside the container:
 *
 *   docker exec aldervast-mcp-web-1 php show-chat-usage.php        the last 14 days
 *   docker exec aldervast-mcp-web-1 php show-chat-usage.php 60     the last 60 days
 *
 * The cost uses the eval's price list (EvalScore::PRICES), so it's an estimate from the token counts.
 * The real bill is in the Claude Console.
 */

use Aldervast\Eval\EvalScore;
use Aldervast\Web\UsageReport;

require __DIR__ . '/vendor/autoload.php';

const USAGE_FILE = __DIR__ . '/cache/chat-usage.jsonl';

if (!is_file(USAGE_FILE)) {
    echo "No chatbot questions logged yet. The website writes them to " . USAGE_FILE . ".\n";
    exit(0);
}

$days = UsageReport::days(USAGE_FILE, fn (array $rows) => EvalScore::cost($rows), max(1, (int) ($argv[1] ?? 14)), time());
$money = fn (?float $cost) => $cost === null ? '?' : '$' . number_format($cost, 2);
$format = "%-10s  %8s  %9s  %6s  %7s  %12s  %13s  %8s\n";

printf($format, 'Day (UTC)', 'Answered', 'No answer', 'Failed', 'Limited', 'Input tokens', 'Output tokens', 'Cost');
foreach ($days as $day) {
    // Only days with something in them; the usage page shows the empty days on its chart.
    if (UsageReport::asked($day) + $day['limited'] > 0) {
        printf($format, $day['day'], $day['answered'], $day['no_answer'], $day['failed'], $day['limited'],
            number_format($day['input_tokens']), number_format($day['output_tokens']), $money($day['cost']));
    }
}
$t = UsageReport::totals($days);
printf($format, 'Total', $t['answered'], $t['no_answer'], $t['failed'], $t['limited'],
    number_format($t['input_tokens']), number_format($t['output_tokens']), $money($t['cost']));

echo "\nCost is estimated from the token counts. A \"?\" means a model with no known price. Questions and\n"
    . "visitors are never logged, only what each question used.\n";
