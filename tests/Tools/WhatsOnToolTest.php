<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\WhatsOnTool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for whats_on: what the schedules on the saved wiki pages mean at a given moment. Every test
 * passes "at", so the answers don't depend on today's date.
 */
final class WhatsOnToolTest extends FixtureTestCase
{
    private function at(string $at): array
    {
        return $this->decode(WhatsOnTool::run(['at' => $at]));
    }

    public function test_during_the_festival_everything_that_runs_now_is_listed(): void
    {
        $result = $this->at('2026-11-22T14:25');

        $this->assertSame('2026-11-22 14:25 UTC, Sunday', $result['now']);
        $this->assertSame([
            'name' => 'Lanternfast',
            'when' => 'Every year, 20 November to 6 January',
            'running' => true,
            'ends' => '2027-01-06',
            'url' => 'https://aldervast.online/wiki/festivals.html#festival-lanternfast',
        ], $result['festivals'][0]);
        $this->assertTrue($result['seasons'][0]['running']);
        $this->assertSame('Season of Embers', $result['seasons'][0]['name']);
        $this->assertSame([
            'name' => 'The Old Chandlery',
            'open' => 'Only while Lanternfast runs (20 November to 6 January), and shown in the journal only then',
            'open_now' => true,
            'url' => 'https://aldervast.online/wiki/dungeons.html#dungeon-old_chandlery',
        ], $result['timed_dungeons'][0]);
        // Spaces around "at" and a "Z" for UTC at the end are fine.
        $this->assertSame('2026-11-22 14:25 UTC, Sunday', $this->at(' 2026-11-22T14:25Z ')['now']);
        $this->assertSame('The Saltreach Derby', $result['other'][0]['name']);
        $this->assertStringStartsWith('Every half hour', $result['other'][0]['when']);
    }

    public function test_world_bosses_come_soonest_first_with_their_next_wake_time(): void
    {
        $bosses = $this->at('2026-11-22T14:25')['world_bosses'];

        $this->assertSame(['14:40 UTC', '14:50 UTC', '15:00 UTC', '15:20 UTC'], array_column($bosses, 'next_wake'));
        $this->assertSame([15, 25, 35, 55], array_column($bosses, 'in_minutes'));
        $this->assertSame([
            'name' => 'Elderhorn, the Moss Stag',
            'level' => '40',
            'wakes' => 'Every hour at xx:40',
            'next_wake' => '14:40 UTC',
            'in_minutes' => 15,
            'url' => 'https://aldervast.online/wiki/worldbosses.html#waking-elderhorn',
        ], $bosses[0]);
    }

    public function test_a_wake_time_that_has_just_passed_is_next_hours_and_part_minutes_count_up(): void
    {
        $bosses = $this->at('2026-11-22T14:39:30')['world_bosses'];
        $this->assertSame(['14:40 UTC', 1], [$bosses[0]['next_wake'], $bosses[0]['in_minutes']]);

        // One second to go still counts as a minute.
        $this->assertSame(1, $this->at('2026-11-22T14:39:59')['world_bosses'][0]['in_minutes']);

        $later = $this->at('2026-11-22T14:40')['world_bosses'];
        $elderhorn = array_values(array_filter($later, fn (array $b) => str_starts_with($b['name'], 'Elderhorn')))[0];
        $this->assertSame(['15:40 UTC', 60], [$elderhorn['next_wake'], $elderhorn['in_minutes']]);
    }

    public function test_the_weekly_reset_is_the_next_one_after_the_moment(): void
    {
        $this->assertSame([
            'name' => 'Weekly spoils',
            'resets' => 'Wednesday at 07:00 UTC',
            'next' => '2026-11-25 07:00 UTC, Wednesday',
            'url' => 'https://aldervast.online/wiki/worldbosses.html#waking-spoils',
        ], $this->at('2026-11-22')['weekly_resets'][0]);
        $this->assertSame('2026-11-25 07:00 UTC, Wednesday', $this->at('2026-11-25T06:59')['weekly_resets'][0]['next']);
        $this->assertSame('2026-12-02 07:00 UTC, Wednesday', $this->at('2026-11-25T07:00')['weekly_resets'][0]['next']);
        $this->assertSame('Wednesday at 07:00 UTC', $this->at('2026-11-22')['weekly_resets'][0]['resets']);
    }

    public function test_this_weeks_hourglass_omens_change_on_monday_and_repeat_after_the_rotation(): void
    {
        $sunday = $this->at('2026-11-22T23:59')['hourglass_omens'];
        $this->assertSame([
            'week' => 1,
            'of' => 8,
            'omens' => ['From tier 2' => 'Hardened', 'From tier 4' => 'Volatile', 'From tier 7' => 'Quaking'],
            'until' => '2026-11-23 00:00 UTC, Monday',
            'url' => 'https://aldervast.online/wiki/hourglass.html#g-the-rotation',
        ], $sunday);

        $monday = $this->at('2026-11-23')['hourglass_omens'];
        $this->assertSame(2, $monday['week']);
        $this->assertSame('Lordly', $monday['omens']['From tier 2']);
        $this->assertSame('2026-11-30 00:00 UTC, Monday', $monday['until']);

        // Eight weeks on, the rotation is back at week 1.
        $this->assertSame($sunday['omens'], $this->at('2027-01-17T23:59')['hourglass_omens']['omens']);
    }

    public static function festivalDays(): array
    {
        return [
            'first day' => ['2026-11-20', true, 'ends', '2027-01-06'],
            'new year\'s eve' => ['2026-12-31', true, 'ends', '2027-01-06'],
            'last day, after the new year' => ['2027-01-06T23:59', true, 'ends', '2027-01-06'],
            'the day after' => ['2027-01-07', false, 'starts', '2027-11-20'],
            'the day before' => ['2026-11-19T23:59', false, 'starts', '2026-11-20'],
            'midsummer' => ['2026-07-01', false, 'starts', '2026-11-20'],
        ];
    }

    #[DataProvider('festivalDays')]
    public function test_a_yearly_festival_that_crosses_the_new_year_runs_on_both_of_its_days(string $at, bool $running, string $key, string $date): void
    {
        $result = $this->at($at);
        $festival = $result['festivals'][0];

        $this->assertSame($running, $festival['running']);
        $this->assertSame($date, $festival[$key]);
        // The festival's dungeon opens and shuts with it.
        $this->assertSame($running, $result['timed_dungeons'][0]['open_now']);
    }

    public function test_a_season_runs_from_its_first_day_to_the_end_of_its_last(): void
    {
        $this->assertFalse($this->at('2026-10-31T23:59')['seasons'][0]['running']);
        $this->assertTrue($this->at('2026-11-01')['seasons'][0]['running']);
        $this->assertTrue($this->at('2027-02-01T23:59')['seasons'][0]['running']);
        $this->assertFalse($this->at('2027-02-02')['seasons'][0]['running']);
    }

    public function test_without_at_it_answers_for_now(): void
    {
        $result = $this->decode(WhatsOnTool::run([]));

        $this->assertStringStartsWith(gmdate('Y-m-d'), $result['now']);
        $this->assertStringEndsWith(gmdate('l'), $result['now']);
    }

    public static function badMoments(): array
    {
        return [
            'words' => ['next tuesday', 'at must be a UTC date or date-time'],
            'text before the date' => ['on 2026-11-22', 'at must be a UTC date or date-time'],
            'text after the date' => ['2026-11-22 please', 'at must be a UTC date or date-time'],
            'no such day' => ['2026-02-30', 'at is not a real date: 2026-02-30'],
            'no such hour' => ['2026-02-03T25:00', 'at is not a real date: 2026-02-03T25:00'],
        ];
    }

    #[DataProvider('badMoments')]
    public function test_an_at_that_is_not_a_real_utc_moment_is_an_error(string $at, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        WhatsOnTool::run(['at' => $at]);
    }
}
