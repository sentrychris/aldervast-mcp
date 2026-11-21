<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\LevellingRouteTool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for levelling_route: which regions, dungeons and quests suit a level. They use the saved
 * copies of regions.html, dungeons.html and quests.html, so the levels below are fixed until
 * someone downloads fresh copies.
 */
final class LevellingRouteToolTest extends FixtureTestCase
{
    private function route(int|string $level): array
    {
        return $this->decode(LevellingRouteTool::run(['level' => $level]));
    }

    public function test_a_starting_character_gets_the_first_region_dungeon_and_quests(): void
    {
        $route = $this->route(5);

        $this->assertSame(5, $route['level']);
        $this->assertSame([['name' => 'Brightmeadow Vale', 'levels' => '1 to 9', 'url' => 'https://aldervast.online/wiki/regions.html#region-brightmeadow']], $route['regions']);
        $this->assertSame(['The Hollow Crypt'], array_column($route['dungeons'], 'name'));
        $this->assertContains('The Hollow Crypt', array_column($route['quests'], 'name'));
        $this->assertArrayNotHasKey('note', $route);
    }

    public function test_each_quest_says_where_it_is_who_gives_it_and_what_comes_first(): void
    {
        $quests = array_column($this->route(5)['quests'], null, 'name');

        $this->assertSame([
            'name' => 'The Hollow Crypt',
            'level' => 4,
            'from_level' => 1,
            'region' => 'Brightmeadow Vale',
            'given_by' => 'Captain Odric Hale · in Harrowford',
            'needs' => 'The Restless Dead',
            'url' => 'https://aldervast.online/wiki/quests.html#quest-ms03_crypt',
        ], $quests['The Hollow Crypt']);
    }

    public function test_a_region_suits_every_level_in_its_range_including_both_ends(): void
    {
        // Brightmeadow Vale is 1 to 9 and Thornwood 8 to 16, so they overlap at 8 and 9.
        $regions = fn (int $level) => array_column($this->route($level)['regions'], 'name');

        $this->assertSame(['Brightmeadow Vale'], $regions(1));
        $this->assertSame(['Brightmeadow Vale'], $regions(7));
        $this->assertSame(['Brightmeadow Vale', 'Thornwood'], $regions(8));
        $this->assertSame(['Brightmeadow Vale', 'Thornwood'], $regions(9));
        $this->assertSame(['Thornwood'], $regions(10));
    }

    public function test_a_quest_shows_the_lowest_level_that_can_take_it(): void
    {
        $quests = array_column($this->route(23)['quests'], null, 'name');

        // "21 (you can take it from level 18)"
        $this->assertSame(21, $quests['The Cinder Pulpit']['level']);
        $this->assertSame(18, $quests['The Cinder Pulpit']['from_level']);
    }

    public function test_a_quest_without_a_first_quest_has_no_needs_field(): void
    {
        $quests = array_column($this->route(1)['quests'], null, 'name');

        $this->assertArrayNotHasKey('needs', $quests['A Light in Harrowford']);
        // It gives no lowest level, so any level can take it.
        $this->assertSame(1, $quests['A Light in Harrowford']['from_level']);
    }

    public function test_dungeons_within_two_levels_suit_and_three_away_do_not(): void
    {
        // The Cinderwell is level 50.
        $this->assertContains('The Cinderwell', array_column($this->route(48)['dungeons'], 'name'));
        $this->assertContains('The Cinderwell', array_column($this->route(52)['dungeons'], 'name'));
        $this->assertNotContains('The Cinderwell', array_column($this->route(47)['dungeons'], 'name'));
        $this->assertNotContains('The Cinderwell', array_column($this->route(53)['dungeons'], 'name'));
    }

    public function test_each_dungeon_says_what_kind_it_is(): void
    {
        $dungeons = array_column($this->route(50)['dungeons'], null, 'name');

        $this->assertSame(['name' => 'The Cinderwell', 'kind' => 'Raid', 'level' => 50, 'url' => 'https://aldervast.online/wiki/dungeons.html#dungeon-cinderwell'], $dungeons['The Cinderwell']);
        $this->assertSame('Group dungeon', $dungeons['The Far Side']['kind']);
    }

    public function test_a_dungeon_that_scales_suits_every_level_in_its_range(): void
    {
        // The Old Chandlery: "Your party's own (its average), from 10 to 60".
        $this->assertContains('The Old Chandlery', array_column($this->route(10)['dungeons'], 'name'));
        $this->assertContains('The Old Chandlery', array_column($this->route(60)['dungeons'], 'name'));
        $this->assertNotContains('The Old Chandlery', array_column($this->route(9)['dungeons'], 'name'));

        $chandlery = array_column($this->route(23)['dungeons'], null, 'name')['The Old Chandlery'];
        $this->assertSame("Your party's own (its average), from 10 to 60", $chandlery['level']);
    }

    public function test_dungeons_are_in_level_order_with_a_scaling_one_at_the_players_level(): void
    {
        $this->assertSame(
            ['The Cinder Forge', 'The Old Chandlery', 'The Varrow Baths'],   // 22, scales, 24
            array_column($this->route(23)['dungeons'], 'name')
        );
    }

    public function test_quests_more_than_two_levels_away_are_left_out(): void
    {
        foreach ($this->route(5)['quests'] as $quest) {
            $this->assertLessThanOrEqual(2, abs($quest['level'] - 5), $quest['name']);
            $this->assertLessThanOrEqual(5, $quest['from_level'], $quest['name']);
        }
        // "Pannage" is level 8: 3 levels from level 5, 2 levels from level 6.
        $this->assertNotContains('Pannage', array_column($this->route(5)['quests'], 'name'));
        $this->assertContains('Pannage', array_column($this->route(6)['quests'], 'name'));

        // The other rule, "only quests the player can already take", removes nothing on today's
        // wiki: every quest can be taken from at least 3 levels below its own level, so any quest
        // within 2 levels is already open. The rule stays as a safeguard for later releases.
    }

    public function test_quests_are_in_level_order_then_by_name(): void
    {
        $quests = $this->route(5)['quests'];
        $order = array_map(fn (array $q) => [$q['level'], $q['name']], $quests);
        $sorted = $order;
        sort($sorted);

        $this->assertSame($sorted, $order);
    }

    public function test_a_long_quest_list_is_cut_at_20_and_says_how_many_were_left_out(): void
    {
        $route = $this->route(50);   // 24 quests suit level 50

        $this->assertCount(20, $route['quests']);
        $this->assertSame(4, $route['quests_not_shown']);
        $this->assertArrayNotHasKey('quests_not_shown', $this->route(5));

        // Exactly 20 suit level 42: all shown, nothing left out.
        $this->assertCount(20, $this->route(42)['quests']);
        $this->assertArrayNotHasKey('quests_not_shown', $this->route(42));
    }

    public function test_without_a_region_for_the_level_it_says_where_the_quests_are(): void
    {
        $route = $this->route(50);

        $this->assertSame([], $route['regions']);
        $this->assertSame(
            "No region's level range on the wiki includes level 50. The quests listed are in: Cinderreach Wastes, Frostspire Peaks.",
            $route['note']
        );
    }

    public function test_the_rule_is_stated_in_the_result(): void
    {
        $this->assertSame(
            'Regions whose level range includes 23; dungeons and quests whose level is within 2 of 23; '
            . 'only quests that a level 23 character can already take.',
            $this->route(23)['rule']
        );
    }

    public function test_a_level_written_as_text_is_accepted(): void
    {
        $this->assertSame(23, $this->route('23')['level']);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidLevels(): array
    {
        return [
            'zero' => [0], 'above the cap' => [61], 'not a number' => ['high'], 'a number then text' => ['23abc'],
            'a fraction' => [2.5], 'missing' => [null],
        ];
    }

    #[DataProvider('invalidLevels')]
    public function test_a_level_outside_1_to_60_is_refused(mixed $level): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('level must be a whole number from 1 to 60, e.g. {"level": 23}');

        LevellingRouteTool::run($level === null ? [] : ['level' => $level]);
    }
}
