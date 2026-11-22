<?php

declare(strict_types=1);

namespace Aldervast\Tests\Web;

use Aldervast\Web\UsageLog;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UsageLog: one JSON line per chatbot question, with the token counts and nothing about
 * the question or the visitor.
 */
final class UsageLogTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'aldervast-usage-');
        unlink($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function lines(): array
    {
        return array_map(fn (string $l) => json_decode($l, true), file($this->file, FILE_IGNORE_NEW_LINES));
    }

    public function test_a_line_has_the_time_outcome_model_tokens_calls_turns_and_seconds(): void
    {
        $log = new UsageLog($this->file, fn () => gmmktime(13, 5, 9, 11, 22, 2026));

        $log->record('answered', 'claude-sonnet-5-5', [
            'input_tokens' => 10, 'output_tokens' => 400, 'cache_read_input_tokens' => 5000, 'cache_creation_input_tokens' => 6000,
        ], 2, 3, 4.567);

        $this->assertSame([[
            'time' => '2026-11-22T13:05:09Z',
            'outcome' => 'answered',
            'model' => 'claude-sonnet-5-5',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 400, 'cache_read_input_tokens' => 5000, 'cache_creation_input_tokens' => 6000],
            'tool_calls' => 2,
            'turns' => 3,
            'seconds' => 4.57,
        ]], $this->lines());
    }

    public function test_missing_token_counts_are_0_and_other_keys_are_dropped(): void
    {
        (new UsageLog($this->file))->record('failed', 'claude-sonnet-5-5', ['input_tokens' => 7, 'secret' => 'x']);

        $this->assertSame(
            ['input_tokens' => 7, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            $this->lines()[0]['usage']
        );
    }

    public function test_lines_are_added_to_the_end(): void
    {
        $log = new UsageLog($this->file);
        $log->record('limited');
        $log->record('answered', 'claude-sonnet-5-5');

        $this->assertSame(['limited', 'answered'], array_column($this->lines(), 'outcome'));
        $this->assertSame('', $this->lines()[0]['model']);
    }

    public function test_no_file_means_nothing_is_written_and_a_file_that_cannot_be_written_breaks_nothing(): void
    {
        (new UsageLog(null))->record('answered');
        $this->assertFileDoesNotExist($this->file);

        (new UsageLog(sys_get_temp_dir() . '/no-such-folder/usage.jsonl'))->record('answered');
        $this->addToAssertionCount(1); // reaching this line is the test: no exception, no warning
    }
}
