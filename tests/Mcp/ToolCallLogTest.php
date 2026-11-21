<?php

declare(strict_types=1);

namespace Aldervast\Tests\Mcp;

use Aldervast\Mcp\ToolCallLog;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ToolCallLog, which writes one line per tool call to logs/tool-calls.jsonl.
 * The tests write to a temporary file instead, and McpServerTest checks the log from the server.
 */
final class ToolCallLogTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/aldervast-tool-log-' . bin2hex(random_bytes(4)) . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        putenv('ALDERVAST_TOOL_LOG');
    }

    /** Every line in the log file, decoded. */
    private function lines(): array
    {
        return array_map(
            fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->file, FILE_IGNORE_NEW_LINES)
        );
    }

    public function test_a_call_is_written_as_one_json_line_with_every_field(): void
    {
        (new ToolCallLog($this->file, 'abc123'))->record('search_wiki', ['query' => 'stag'], '{"results":[]}', false, 0.0126);

        [$line] = $this->lines();
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $line['time']);
        unset($line['time']);
        $this->assertSame([
            'session' => 'abc123',
            'tool' => 'search_wiki',
            'input' => ['query' => 'stag'],
            'result_bytes' => 14,
            'is_error' => false,
            'ms' => 13, // 0.0126 seconds, rounded to the nearest millisecond
        ], $line);
    }

    public function test_calls_are_added_to_the_end_of_the_file(): void
    {
        $log = new ToolCallLog($this->file, 'abc123');
        $log->record('search_wiki', ['query' => 'deer'], '', false, 0.0);
        $log->record('search_wiki', ['query' => 'stag'], '', false, 0.0);

        $this->assertSame([['query' => 'deer'], ['query' => 'stag']], array_column($this->lines(), 'input'));
    }

    public function test_no_input_is_written_as_an_empty_object(): void
    {
        (new ToolCallLog($this->file, 'abc123'))->record('list_servers', [], '[]', false, 0.0);

        $this->assertStringContainsString('"input":{}', (string) file_get_contents($this->file));
    }

    public function test_a_failed_call_keeps_the_start_of_its_error_message(): void
    {
        $error = 'Error: ' . str_repeat('x', 300);
        (new ToolCallLog($this->file, 'abc123'))->record('get_wiki_entry', [], $error, true, 0.0);

        [$line] = $this->lines();
        $this->assertTrue($line['is_error']);
        $this->assertSame(mb_substr($error, 0, 200), $line['error']);
    }

    public function test_a_successful_call_has_no_error_field(): void
    {
        (new ToolCallLog($this->file, 'abc123'))->record('search_wiki', [], 'ok', false, 0.0);

        $this->assertArrayNotHasKey('error', $this->lines()[0]);
    }

    public function test_input_with_broken_text_is_still_logged(): void
    {
        // "\xC3" on its own isn't valid UTF-8, which json_encode would normally refuse.
        (new ToolCallLog($this->file, 'abc123'))->record('search_wiki', ['query' => "caf\xC3"], '', false, 0.0);

        $this->assertCount(1, $this->lines());
    }

    public function test_a_log_with_no_file_writes_nothing(): void
    {
        (new ToolCallLog(null, 'abc123'))->record('search_wiki', [], '', false, 0.0);

        $this->assertFileDoesNotExist($this->file);
    }

    public function test_a_file_that_cannot_be_written_does_not_stop_the_call(): void
    {
        // A folder can't be created inside a file, so this write fails. That must not throw or warn.
        (new ToolCallLog(__FILE__ . '/no/such/folder/log.jsonl', 'abc123'))->record('search_wiki', [], '', false, 0.0);

        $this->assertFileDoesNotExist(__FILE__ . '/no/such/folder/log.jsonl');
    }

    public function test_the_default_log_is_in_the_project_root(): void
    {
        // The path is built from this class's own folder, so moving the class would move the log
        // without anything else noticing.
        $this->assertSame('tool-calls.jsonl', basename(ToolCallLog::DEFAULT_FILE));
        $this->assertSame('logs', basename(dirname(ToolCallLog::DEFAULT_FILE)));
        $this->assertSame(realpath(dirname(__DIR__, 2)), realpath(dirname(ToolCallLog::DEFAULT_FILE, 2)));
    }

    public function test_the_environment_variable_chooses_the_file_or_turns_the_log_off(): void
    {
        putenv('ALDERVAST_TOOL_LOG');
        $this->assertSame(ToolCallLog::DEFAULT_FILE, ToolCallLog::fromEnvironment()->file());

        putenv('ALDERVAST_TOOL_LOG=' . $this->file);
        $this->assertSame($this->file, ToolCallLog::fromEnvironment()->file());

        putenv('ALDERVAST_TOOL_LOG=OFF');
        $this->assertNull(ToolCallLog::fromEnvironment()->file());
    }

    public function test_each_server_process_gets_its_own_session_id(): void
    {
        putenv('ALDERVAST_TOOL_LOG=' . $this->file);
        ToolCallLog::fromEnvironment()->record('a', [], '', false, 0.0);
        ToolCallLog::fromEnvironment()->record('b', [], '', false, 0.0);

        [$first, $second] = $this->lines();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $first['session']);
        $this->assertNotSame($first['session'], $second['session']);
    }
}
