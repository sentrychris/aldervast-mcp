<?php

declare(strict_types=1);

namespace Aldervast\Tests\Web;

use Aldervast\Web\RateLimit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for RateLimit: the per-visitor and daily limits on the chatbot, with a clock that the
 * tests move by hand.
 */
final class RateLimitTest extends TestCase
{
    private string $file;

    private int $now;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'aldervast-limits-');
        $this->now = gmmktime(12, 0, 0, 11, 22, 2026);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function limit(int $perHour = 2, int $perDay = 5): RateLimit
    {
        return new RateLimit($this->file, $perHour, $perDay, fn () => $this->now);
    }

    public function test_a_visitor_can_ask_up_to_the_hourly_limit_and_then_is_refused(): void
    {
        $limit = $this->limit(perHour: 2);

        $this->assertNull($limit->take('1.1.1.1'));
        $this->assertNull($limit->take('1.1.1.1'));
        $this->assertSame(
            "You've asked 2 questions in the last hour, which is the limit. Please try again later.",
            $limit->take('1.1.1.1')
        );
        // Another visitor has their own count.
        $this->assertNull($limit->take('2.2.2.2'));
    }

    public function test_a_question_counts_for_exactly_one_hour(): void
    {
        $limit = $this->limit(perHour: 1);
        $this->assertNull($limit->take('1.1.1.1'));

        $this->now += 3599;
        $this->assertNotNull($limit->take('1.1.1.1'));
        $this->now += 1;
        $this->assertNull($limit->take('1.1.1.1'));
    }

    public function test_a_refused_question_is_not_counted(): void
    {
        $limit = $this->limit(perHour: 1, perDay: 10);
        $this->assertNull($limit->take('1.1.1.1'));
        $this->assertNotNull($limit->take('1.1.1.1'));
        $this->assertNotNull($limit->take('1.1.1.1'));

        // An hour after the one counted question, the visitor can ask again, so the refusals didn't count.
        $this->now += 3600;
        $this->assertNull($limit->take('1.1.1.1'));
        $this->assertSame(2, json_decode((string) file_get_contents($this->file), true)['today']);
    }

    public function test_the_daily_limit_covers_everyone_and_starts_again_the_next_utc_day(): void
    {
        $limit = $this->limit(perHour: 10, perDay: 2);
        $this->assertNull($limit->take('1.1.1.1'));
        $this->assertNull($limit->take('2.2.2.2'));
        $this->assertSame(
            'The chatbot has answered as many questions as it can today. Please try again tomorrow.',
            $limit->take('3.3.3.3')
        );

        $this->now = gmmktime(0, 0, 0, 11, 23, 2026);
        $this->assertNull($limit->take('3.3.3.3'));
    }

    public function test_the_file_holds_no_ip_address_and_forgets_visitors_after_an_hour(): void
    {
        $limit = $this->limit();
        $limit->take('203.0.113.9');

        $saved = (string) file_get_contents($this->file);
        $this->assertStringNotContainsString('203.0.113.9', $saved);
        $this->assertCount(1, json_decode($saved, true)['visitors']);

        $this->now += 3600;
        $limit->take('198.51.100.7');
        $this->assertCount(1, json_decode((string) file_get_contents($this->file), true)['visitors']);
    }

    public function test_the_counts_last_between_requests_because_they_are_in_the_file(): void
    {
        $this->assertNull($this->limit(perHour: 1)->take('1.1.1.1'));

        $this->assertNotNull($this->limit(perHour: 1)->take('1.1.1.1'));
    }

    public function test_a_file_that_cannot_be_opened_is_an_error(): void
    {
        $limit = new RateLimit(sys_get_temp_dir() . '/no-such-folder/limits.json', 1, 1);

        $this->expectException(RuntimeException::class);
        $limit->take('1.1.1.1');
    }
}
