<?php

declare(strict_types=1);

namespace Aldervast\Tests\Web;

use Aldervast\Web\UsageDashboard;
use Aldervast\Web\UsageLog;
use Aldervast\Web\UsageReport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UsageReport (the usage log added up per day) and UsageDashboard (the page that shows it).
 * The log is written with UsageLog and a fixed clock, as the website writes it.
 */
final class UsageReportTest extends TestCase
{
    private const TOKENS = ['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 2000];

    private string $file;

    /** 2026-11-22 12:00 UTC. */
    private int $now;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'aldervast-usage-');
        $this->now = gmmktime(12, 0, 0, 11, 22, 2026);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** Writes one line, $daysAgo days before now. */
    private function log(int $daysAgo, string $outcome, string $model = 'claude-sonnet-5-5'): void
    {
        (new UsageLog($this->file, fn () => $this->now - $daysAgo * 86400))
            ->record($outcome, $outcome === 'limited' ? '' : $model, $outcome === 'limited' ? [] : self::TOKENS);
    }

    /** A cost of one cent per row, so that the tests can count rows through the cost. */
    private function days(int $days = 3): array
    {
        return UsageReport::days($this->file, fn (array $rows) => count($rows) / 100, $days, $this->now);
    }

    public function test_every_day_is_listed_oldest_first_including_days_with_nothing(): void
    {
        $this->log(0, 'answered');

        $days = $this->days();

        $this->assertSame(['2026-11-20', '2026-11-21', '2026-11-22'], array_column($days, 'day'));
        $this->assertSame([0, 0, 1], array_column($days, 'answered'));
        $this->assertSame([0.0, 0.0, 0.01], array_column($days, 'cost'));
    }

    public function test_a_day_counts_each_outcome_and_adds_up_its_tokens(): void
    {
        $this->log(1, 'answered');
        $this->log(1, 'answered');
        $this->log(1, 'no_answer');
        $this->log(1, 'failed');
        $this->log(1, 'limited');

        $day = $this->days()[1];

        $this->assertSame(
            ['day' => '2026-11-21', 'answered' => 2, 'no_answer' => 1, 'failed' => 1, 'limited' => 1,
                'input_tokens' => 4 * 3100, 'output_tokens' => 4 * 50, 'cost' => 0.04],
            $day
        );
        // The limited question was refused, so it didn't use one of the day's questions.
        $this->assertSame(4, UsageReport::asked($day));
    }

    public function test_lines_outside_the_period_and_damaged_lines_are_skipped(): void
    {
        $this->log(3, 'answered');
        file_put_contents($this->file, "not json\n{\"time\":\"2026-11-22T01:00:00Z\"}\n", FILE_APPEND);

        $this->assertSame(0, UsageReport::totals($this->days())['answered']);
        $this->assertSame(1, UsageReport::totals($this->days(4))['answered']);
    }

    public function test_a_missing_file_gives_empty_days(): void
    {
        $days = UsageReport::days(sys_get_temp_dir() . '/no-such-usage.jsonl', fn () => 1.0, 2, $this->now);

        $this->assertSame([0, 0], array_column($days, 'answered'));
        $this->assertSame([0.0, 0.0], array_column($days, 'cost'));
    }

    public function test_totals_add_the_days_and_say_when_a_cost_is_unknown(): void
    {
        $this->log(0, 'answered');
        $this->log(2, 'answered', 'some-future-model');
        $days = UsageReport::days($this->file, fn (array $rows) => $rows[0]['model'] === 'claude-sonnet-5-5' ? 0.02 : null, 3, $this->now);

        $totals = UsageReport::totals($days);

        $this->assertSame([null, 0.0, 0.02], array_column($days, 'cost'));
        $this->assertSame(2, $totals['answered']);
        $this->assertSame(0.02, $totals['cost']);
        $this->assertTrue($totals['unknown']);
        $this->assertFalse(UsageReport::totals(array_slice($days, 1))['unknown']);
    }

    public function test_the_page_shows_the_tiles_a_bar_for_each_day_with_a_cost_and_the_table(): void
    {
        $this->log(0, 'answered');
        $this->log(0, 'limited');
        $this->log(2, 'answered');
        $this->log(2, 'failed');

        $html = UsageDashboard::render($this->days(), 100);

        $this->assertStringContainsString('<title>Chatbot usage</title>', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringContainsString('<div class="label">Questions today</div><div class="value">1</div><div class="detail">of 100 allowed</div>', $html);
        $this->assertStringContainsString('<div class="label">Cost, last 3 days</div><div class="value">$0.03</div><div class="detail">3 questions</div>', $html);
        $this->assertStringContainsString('<div class="label">Cost per answer</div><div class="value">$0.015</div>', $html);
        $this->assertStringContainsString('<div class="label">Refused, last 3 days</div><div class="value">1</div>', $html);
        // Two days have a cost, so two bars; every day has a hover target.
        $this->assertSame(2, substr_count($html, '<path class="bar"'));
        $this->assertSame(3, substr_count($html, '<rect class="hit"'));
        $this->assertStringContainsString('data-tip="22 Nov: $0.01, 1 answer"', $html);
        // The table lists the days with activity, newest first, and leaves out the empty day.
        $this->assertMatchesRegularExpression('/<td>2026-11-22<\/td>.*<td>2026-11-20<\/td>/s', $html);
        $this->assertStringNotContainsString('<td>2026-11-21</td>', $html);
        $this->assertStringNotContainsString('class="note"', $html);
    }

    public function test_the_page_without_any_questions_says_so(): void
    {
        $html = UsageDashboard::render($this->days(), 100);

        $this->assertStringContainsString('No questions yet in this period.', $html);
        $this->assertStringContainsString('<div class="label">Cost per answer</div><div class="value">–</div>', $html);
        $this->assertSame(0, substr_count($html, '<path class="bar"'));
        // The scale still has labels, from $0.00 to its smallest top.
        $this->assertStringContainsString('>$0.05</text>', $html);
    }

    public function test_an_unknown_cost_shows_a_question_mark_and_a_note(): void
    {
        $this->log(0, 'answered', 'some-future-model');
        $days = UsageReport::days($this->file, fn () => null, 2, $this->now);

        $html = UsageDashboard::render($days, 100);

        $this->assertStringContainsString('<td>?</td>', $html);
        $this->assertStringContainsString('<div class="label">Cost today</div><div class="value">?</div>', $html);
        $this->assertStringContainsString('data-tip="22 Nov: cost unknown, 1 answer"', $html);
        $this->assertStringContainsString('class="note"', $html);
    }
}
