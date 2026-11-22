<?php

declare(strict_types=1);

namespace Aldervast\Web;

use Closure;

/**
 * Adds up the chatbot's usage log (UsageLog) per day, for the usage page (UsageDashboard) and for
 * show-chat-usage.php.
 *
 * The cost of a day comes from a function that the caller passes in, because the price list lives in
 * the eval's code (EvalScore::PRICES), which this part of src/ may not use (see ArchitectureTest).
 */
final class UsageReport
{
    /** The outcomes that used one of the day's questions (RateLimit counted them). "limited" didn't. */
    public const ASKED = ['answered', 'no_answer', 'failed'];

    private const DAY_SECONDS = 86400;

    /**
     * Every day of the last $days days (UTC), oldest first, including days with no questions, so that
     * a chart of them has no gaps. Each day: day, answered, no_answer, failed, limited, input and
     * output tokens, and cost (null if a model in it has no known price).
     *
     * @param Closure $cost called with a day's rows that spent tokens; returns US dollars, or null
     * @param int     $now  the current time as a Unix timestamp
     */
    public static function days(string $file, Closure $cost, int $days, int $now): array
    {
        $rows = [];
        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $row = json_decode($line, true);
            // Skip a damaged line, e.g. from a write that was cut off.
            if (is_array($row) && isset($row['time'], $row['outcome'], $row['usage'])) {
                $rows[substr($row['time'], 0, 10)][] = $row;
            }
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', $now - $i * self::DAY_SECONDS);
            $dayRows = $rows[$day] ?? [];
            $counts = array_count_values(array_column($dayRows, 'outcome'));
            // Rows that spent tokens. A "limited" row has no model, because nothing was asked.
            $paid = array_values(array_filter($dayRows, fn (array $r) => ($r['model'] ?? '') !== ''));
            $result[] = [
                'day' => $day,
                'answered' => $counts['answered'] ?? 0,
                'no_answer' => $counts['no_answer'] ?? 0,
                'failed' => $counts['failed'] ?? 0,
                'limited' => $counts['limited'] ?? 0,
                'input_tokens' => array_sum(array_map(fn (array $r) => $r['usage']['input_tokens']
                    + $r['usage']['cache_read_input_tokens'] + $r['usage']['cache_creation_input_tokens'], $paid)),
                'output_tokens' => array_sum(array_map(fn (array $r) => $r['usage']['output_tokens'], $paid)),
                'cost' => $paid === [] ? 0.0 : $cost($paid),
            ];
        }

        return $result;
    }

    /** The days added together. A cost of null (unknown price) counts as 0, and unknown says so. */
    public static function totals(array $days): array
    {
        $totals = ['answered' => 0, 'no_answer' => 0, 'failed' => 0, 'limited' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0, 'unknown' => false];
        foreach ($days as $day) {
            foreach (['answered', 'no_answer', 'failed', 'limited', 'input_tokens', 'output_tokens'] as $key) {
                $totals[$key] += $day[$key];
            }
            $totals['cost'] += $day['cost'] ?? 0.0;
            $totals['unknown'] = $totals['unknown'] || $day['cost'] === null;
        }

        return $totals;
    }

    /** How many of the day's questions were used: every outcome in ASKED. */
    public static function asked(array $day): int
    {
        return array_sum(array_map(fn (string $outcome) => $day[$outcome], self::ASKED));
    }
}
