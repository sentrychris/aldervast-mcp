<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for EvalScore, the eval's grading. Includes the two checks every eval needs before it is
 * trusted: a run that should pass (the right entry and the right answer) scores 100%, and a run that
 * should fail ("I don't know", no tools) scores 0%.
 */
final class EvalScoreTest extends TestCase
{
    private const CASE = [
        'id' => 'deer-boss',
        'found' => ['wiki/bosses.html#monster-elderhorn', 'wiki/worldbosses.html#waking-elderhorn'],
        'answer' => ['Elderhorn'],
    ];

    /** A run of the agent loop with this answer and these tool calls. */
    private static function agentRun(string $answer, array $calls = []): array
    {
        return ['answer' => $answer, 'calls' => $calls];
    }

    private static function call(string $tool, string $result, bool $isError = false): array
    {
        return ['tool' => $tool, 'input' => [], 'result' => $result, 'is_error' => $isError];
    }

    private const ELDERHORN_RESULT = '{"url": "https://aldervast.online/wiki/bosses.html#monster-elderhorn"}';

    public function test_the_right_entry_and_the_right_answer_pass(): void
    {
        $scored = EvalScore::grade(self::CASE, self::agentRun("It's Elderhorn, the Moss Stag.", [self::call('search_wiki', self::ELDERHORN_RESULT)]));

        $this->assertSame(['pass' => 1, 'found' => 1, 'answered' => 1, 'used_tools' => 1], $scored['grade']);
        $this->assertSame([
            'pass' => 'found and answered',
            'found' => 'tools returned wiki/bosses.html#monster-elderhorn',
            'answered' => 'answer has every expected fact',
            'used_tools' => 'tool calls: 1',
        ], $scored['explanation']);
    }

    public function test_i_dont_know_without_tools_fails_everything(): void
    {
        $scored = EvalScore::grade(self::CASE, self::agentRun("I don't know."));

        $this->assertSame(['pass' => 0, 'found' => 0, 'answered' => 0, 'used_tools' => 0], $scored['grade']);
        $this->assertSame('see found and answered', $scored['explanation']['pass']);
        $this->assertSame('answered without any tool', $scored['explanation']['used_tools']);
    }

    public function test_finding_only_one_part_of_the_answer_still_fails_with_the_right_explanation(): void
    {
        // Found, but the answer is wrong: "pass" points at the reasons.
        $scored = EvalScore::grade(self::CASE, self::agentRun('No idea.', [self::call('search_wiki', self::ELDERHORN_RESULT)]));
        $this->assertSame('see found and answered', $scored['explanation']['pass']);

        // Right answer, entry never found: the same.
        $scored = EvalScore::grade(self::CASE, self::agentRun('Elderhorn.', [self::call('search_wiki', '{}')]));
        $this->assertSame('see found and answered', $scored['explanation']['pass']);
    }

    public function test_the_explanation_names_whichever_expected_entry_was_found(): void
    {
        // Only the second of the two expected entries came back.
        $scored = EvalScore::grade(self::CASE, self::agentRun('Elderhorn.', [self::call('search_wiki', 'wiki/worldbosses.html#waking-elderhorn')]));

        $this->assertSame('tools returned wiki/worldbosses.html#waking-elderhorn', $scored['explanation']['found']);
    }

    public function test_the_right_answer_from_memory_does_not_pass(): void
    {
        $scored = EvalScore::grade(self::CASE, self::agentRun('Elderhorn.'));

        $this->assertSame(['pass' => 0, 'found' => 0, 'answered' => 1, 'used_tools' => 0], $scored['grade']);
    }

    public function test_the_right_entry_with_a_wrong_answer_does_not_pass(): void
    {
        $scored = EvalScore::grade(self::CASE, self::agentRun('It is Old Carapace.', [self::call('search_wiki', self::ELDERHORN_RESULT)]));

        $this->assertSame(['pass' => 0, 'found' => 1, 'answered' => 0, 'used_tools' => 1], $scored['grade']);
        $this->assertSame('answer lacks /Elderhorn/', $scored['explanation']['answered']);
    }

    public function test_a_failed_tool_call_does_not_count_as_finding_the_entry(): void
    {
        $scored = EvalScore::grade(self::CASE, self::agentRun('Elderhorn.', [self::call('get_wiki_entry', 'Error: ' . self::ELDERHORN_RESULT, true)]));

        $this->assertSame(0, $scored['grade']['found']);
        $this->assertSame(
            'no tool returned wiki/bosses.html#monster-elderhorn or wiki/worldbosses.html#waking-elderhorn',
            $scored['explanation']['found']
        );
    }

    public function test_every_answer_pattern_must_match_ignoring_case(): void
    {
        $case = ['found' => [], 'answer' => ['tidecaller', 'ELDERHORN']];
        $search = [self::call('search_wiki', '{}')];

        $this->assertSame(1, EvalScore::grade($case, self::agentRun('Tidecaller and Elderhorn.', $search))['grade']['answered']);
        $this->assertSame(0, EvalScore::grade($case, self::agentRun('Only Tidecaller.', $search))['grade']['answered']);
        $this->assertSame('answer lacks /ELDERHORN/', EvalScore::grade($case, self::agentRun('Only Tidecaller.', $search))['explanation']['answered']);
    }

    public function test_patterns_are_regular_expressions(): void
    {
        $this->assertTrue(EvalScore::matches('\b(no|not)\b', 'No, there is no necromancer.'));
        $this->assertFalse(EvalScore::matches('\b(no|not)\b', 'Nobody knows; it is notable.'));
        $this->assertTrue(EvalScore::matches('20(th)? (of )?Nov', 'From 20th of November'));
        // A ~ in a pattern is escaped, so it can't end the pattern early.
        $this->assertTrue(EvalScore::matches('a~b', 'a~b'));
    }

    public function test_with_no_expected_entry_any_wiki_search_counts_as_found(): void
    {
        $case = ['found' => [], 'answer' => ['\bno\b']];

        $this->assertSame(1, EvalScore::grade($case, self::agentRun('No.', [self::call('search_wiki', '{"results": []}')]))['grade']['found']);
        $this->assertSame('searched the wiki', EvalScore::grade($case, self::agentRun('No.', [self::call('search_wiki', '{}')]))['explanation']['found']);
        // The server list isn't the wiki.
        $this->assertSame(0, EvalScore::grade($case, self::agentRun('No.', [self::call('list_servers', '[]')]))['grade']['found']);
        $this->assertSame('never searched the wiki', EvalScore::grade($case, self::agentRun('No.'))['explanation']['found']);
    }

    public function test_the_summary_averages_runs_per_case_before_averaging_cases(): void
    {
        $rows = [
            ['prompt_id' => 'a', 'grade' => ['pass' => 1, 'found' => 1, 'answered' => 1, 'used_tools' => 1]],
            ['prompt_id' => 'a', 'grade' => ['pass' => 0, 'found' => 1, 'answered' => 0, 'used_tools' => 1]],
            ['prompt_id' => 'b', 'grade' => ['pass' => 1, 'found' => 1, 'answered' => 1, 'used_tools' => 1]],
        ];
        $summary = EvalScore::summarise($rows);

        $this->assertSame(2, $summary['cases']);
        $this->assertSame(3, $summary['rows']);
        // Case a: 0.5, case b: 1.0, so 0.75 (not 2/3, which would weigh case a twice).
        $this->assertEqualsWithDelta(0.75, $summary['scores']['pass']['mean'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $summary['scores']['found']['mean'], 1e-9);
    }

    public function test_the_interval_is_the_mean_plus_or_minus_1_96_standard_errors(): void
    {
        // Mean 0.5; standard deviation of (0, 1, 0, 1) is 0.577; standard error 0.289; 1.96 x that is 0.566.
        $interval = EvalScore::meanWithInterval([0.0, 1.0, 0.0, 1.0]);

        $this->assertEqualsWithDelta(0.5, $interval['mean'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $interval['low'], 1e-9);   // 0.5 - 0.566, kept at 0
        $this->assertEqualsWithDelta(1.0, $interval['high'], 1e-9);  // 0.5 + 0.566, kept at 1

        $narrow = EvalScore::meanWithInterval(array_merge(array_fill(0, 50, 1.0), array_fill(0, 50, 0.0)));
        $this->assertEqualsWithDelta(0.5 - 1.96 * sqrt(0.25 * 100 / 99 / 100), $narrow['low'], 1e-9);
        $this->assertEqualsWithDelta(0.5 + 1.96 * sqrt(0.25 * 100 / 99 / 100), $narrow['high'], 1e-9);
    }

    public function test_the_interval_uses_the_spread_around_the_mean(): void
    {
        // Mean 0.75; variance ((3 x 0.25^2) + 0.75^2) / 3 = 0.25; 1.96 x sqrt(0.25 / 4) = 0.49.
        $interval = EvalScore::meanWithInterval([1.0, 1.0, 1.0, 0.0]);

        $this->assertEqualsWithDelta(0.75, $interval['mean'], 1e-9);
        $this->assertEqualsWithDelta(0.26, $interval['low'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $interval['high'], 1e-9);
    }

    public function test_one_value_or_none_has_no_interval(): void
    {
        $this->assertSame(['mean' => 0.8, 'low' => 0.8, 'high' => 0.8], EvalScore::meanWithInterval([0.8]));
        $this->assertSame(['mean' => 0.0, 'low' => 0.0, 'high' => 0.0], EvalScore::meanWithInterval([]));
    }

    public function test_cost_prices_every_kind_of_token_by_the_model_that_answered(): void
    {
        $usage = fn (int $in, int $out, int $write, int $read) => [
            'input_tokens' => $in, 'output_tokens' => $out, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read,
        ];
        $rows = [
            ['model' => 'claude-sonnet-5-5', 'usage' => $usage(1_000_000, 0, 0, 0)],          // $2.00
            ['model' => 'claude-sonnet-5-5', 'usage' => $usage(0, 1_000_000, 0, 0)],          // $10.00
            ['model' => 'claude-sonnet-5-5', 'usage' => $usage(0, 0, 1_000_000, 1_000_000)],  // $2.50 + $0.10
            ['model' => 'claude-haiku-5-5', 'usage' => $usage(1_000_000, 1_000_000, 0, 0)],    // $0.10 + $0.50
        ];

        $this->assertEqualsWithDelta(15.20, EvalScore::cost($rows), 1e-9);
    }

    public function test_cost_is_not_guessed_for_a_model_without_a_price(): void
    {
        $this->assertNull(EvalScore::cost([['model' => 'claude-unknown', 'usage' => []]]));
    }

    public function test_the_headline_metric_comes_first(): void
    {
        // The report treats the first binary metric as the headline.
        $this->assertSame(['pass', 'found', 'answered', 'used_tools'], array_column(EvalScore::METRICS, 'id'));
    }
}
