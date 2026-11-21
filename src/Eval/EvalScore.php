<?php

declare(strict_types=1);

namespace Aldervast\Eval;

/**
 * Grading for the eval (evals/run.php): turns one run of the agent loop into scores, and a set of
 * results into a summary. Everything here is plain arithmetic on recorded data, with no API calls,
 * so the tests can check it exactly.
 *
 * Each case in evals/cases.json says what counts as right:
 *   found   texts (usually wiki URLs), any one of which must appear in a tool result. That means
 *           Claude was shown the right entry. An empty list means any wiki tool call counts.
 *   answer  regular expressions that must all match the final answer, ignoring case.
 */
final class EvalScore
{
    /**
     * The scores, in the order that the report shows them. "pass" comes first because it's the
     * headline: the right entry was found AND the answer says the right thing.
     */
    public const METRICS = [
        ['id' => 'pass', 'label' => 'Pass', 'kind' => 'binary'],
        ['id' => 'found', 'label' => 'Right entry', 'kind' => 'binary'],
        ['id' => 'answered', 'label' => 'Right answer', 'kind' => 'binary'],
        ['id' => 'used_tools', 'label' => 'Used tools', 'kind' => 'binary'],
    ];

    /** The tools that read the wiki, as opposed to list_servers. */
    private const WIKI_TOOLS = ['search_wiki', 'get_wiki_entry', 'list_category'];

    /**
     * Prices in US dollars per million tokens, from Anthropic's price list (checked 2026-11-21).
     * Cache writes cost 1.25 times the input price; cache reads cost less than input.
     */
    public const PRICES = [
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.10],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_write' => 0.125, 'cache_read' => 0.01],
    ];

    /**
     * Scores one run of the agent loop against its case. Returns the scores (each 0 or 1) and a short
     * explanation of each, which the report shows next to the score.
     *
     * @param array $case one entry from evals/cases.json
     * @param array $run  what AgentLoop::run() returned
     * @return array{grade: array<string, int>, explanation: array<string, string>}
     */
    public static function grade(array $case, array $run): array
    {
        $calls = $run['calls'];
        $wikiCalls = array_filter($calls, fn (array $call) => in_array($call['tool'], self::WIKI_TOOLS, true));

        if ($case['found'] === []) {
            $found = $wikiCalls !== [];
            $foundWhy = $found ? 'searched the wiki' : 'never searched the wiki';
        } else {
            $seen = array_values(array_filter(
                $case['found'],
                fn (string $text) => self::anyResultContains($calls, $text)
            ));
            $found = $seen !== [];
            $foundWhy = $found ? 'tools returned ' . $seen[0] : 'no tool returned ' . implode(' or ', $case['found']);
        }

        $missing = array_values(array_filter($case['answer'], fn (string $pattern) => !self::matches($pattern, $run['answer'])));
        $answered = $missing === [];
        $answerWhy = $answered ? 'answer has every expected fact' : 'answer lacks /' . implode('/, /', $missing) . '/';

        $usedTools = $calls !== [];

        return [
            'grade' => [
                'pass' => (int) ($found && $answered),
                'found' => (int) $found,
                'answered' => (int) $answered,
                'used_tools' => (int) $usedTools,
            ],
            'explanation' => [
                'pass' => $found && $answered ? 'found and answered' : 'see found and answered',
                'found' => $foundWhy,
                'answered' => $answerWhy,
                'used_tools' => $usedTools ? 'tool calls: ' . count($calls) : 'answered without any tool',
            ],
        ];
    }

    /** True if the pattern matches the text, ignoring case. The pattern is a regular expression. */
    public static function matches(string $pattern, string $text): bool
    {
        return preg_match('~' . str_replace('~', '\~', $pattern) . '~iu', $text) === 1;
    }

    /**
     * The headline numbers for a set of result rows (one per case and run): for each score, the mean
     * and a 95% confidence interval. Each case counts once: its runs are averaged first, then the
     * cases. That keeps a case with an extra run from counting more than the others.
     *
     * @return array{cases: int, rows: int, scores: array<string, array{mean: float, low: float, high: float}>}
     */
    public static function summarise(array $rows): array
    {
        $byCase = [];
        foreach ($rows as $row) {
            $byCase[$row['prompt_id']][] = $row['grade'];
        }

        $scores = [];
        foreach (self::METRICS as ['id' => $id]) {
            $perCase = array_map(
                fn (array $grades) => array_sum(array_column($grades, $id)) / count($grades),
                array_values($byCase)
            );
            $scores[$id] = self::meanWithInterval($perCase);
        }

        return ['cases' => count($byCase), 'rows' => count($rows), 'scores' => $scores];
    }

    /**
     * The mean of the values, with a 95% confidence interval: mean ± 1.96 × standard error. The
     * interval says how much the mean could move by chance alone, so a change smaller than it
     * isn't evidence of anything.
     *
     * @param float[] $values
     */
    public static function meanWithInterval(array $values): array
    {
        $n = count($values);
        if ($n === 0) {
            return ['mean' => 0.0, 'low' => 0.0, 'high' => 0.0];
        }
        $mean = array_sum($values) / $n;
        $variance = $n > 1 ? array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values)) / ($n - 1) : 0.0;
        $half = 1.96 * sqrt($variance / $n);

        return ['mean' => $mean, 'low' => max(0.0, $mean - $half), 'high' => min(1.0, $mean + $half)];
    }

    /**
     * What the tokens in these rows cost, in US dollars, priced by the model that each row says
     * answered. Returns null when a row's model has no price here, rather than a wrong number.
     */
    public static function cost(array $rows): ?float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $price = self::PRICES[$row['model']] ?? null;
            if ($price === null) {
                return null;
            }
            $usage = $row['usage'];
            $total += ($usage['input_tokens'] * $price['input']
                + $usage['output_tokens'] * $price['output']
                + $usage['cache_creation_input_tokens'] * $price['cache_write']
                + $usage['cache_read_input_tokens'] * $price['cache_read']) / 1e6;
        }

        return $total;
    }

    private static function anyResultContains(array $calls, string $text): bool
    {
        foreach ($calls as $call) {
            if (!$call['is_error'] && str_contains($call['result'], $text)) {
                return true;
            }
        }

        return false;
    }
}
