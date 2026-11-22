<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\FindItemsTool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for find_items: which item types an input matches, the level filters, the order, and the
 * fields of base and unique items, on the saved copies of items.html and uniques.html.
 */
final class FindItemsToolTest extends FixtureTestCase
{
    private function find(array $input): array
    {
        return $this->decode(FindItemsTool::run($input));
    }

    public function test_a_word_matches_every_type_that_contains_it_and_items_come_highest_level_first(): void
    {
        $result = $this->find(['type' => 'crossbow', 'max_level' => 30, 'limit' => 4]);

        $this->assertSame(['Crossbows', 'Hand crossbows'], $result['types']);
        $this->assertSame('Items of these types with a level from 1 to 30, highest level first.', $result['rule']);
        // Two items at level 28 come in name order.
        $this->assertSame(['Siege Crossbow', 'Viper Repeater', 'Arbalest', "Assassin's Latch"], array_column($result['base_items'], 'name'));
        $this->assertSame(6, $result['base_items_not_shown']);
        $this->assertSame([
            'name' => 'Siege Crossbow',
            'type' => 'Crossbows',
            'level' => 28,
            'requires' => '53 DEX',
            'base_stats' => '31–67 physical · 1.4/s · 6% crit',
            'sockets' => 6,
            'url' => 'https://aldervast.online/wiki/items.html#item-siege_crossbow',
        ], $result['base_items'][0]);
        $this->assertSame('10% increased Projectile Speed', $result['base_items'][1]['implicit']);
    }

    public function test_unique_items_have_their_base_modifiers_and_url(): void
    {
        $result = $this->find(['type' => 'crossbow', 'max_level' => 30]);

        $this->assertSame(['Tidecaller'], array_column($result['uniques'], 'name'));
        $this->assertSame([
            'name' => 'Tidecaller',
            'type' => 'Crossbows',
            'level' => 16,
            'base' => "Hunter's Crossbow",
            'text' => [
                '80% increased Physical Damage',
                '20% increased Projectile Speed',
                '+20 Dexterity',
                'Projectiles return to you after reaching their maximum range',
                'Every bolt comes home, as every tide does.',
            ],
            'url' => 'https://aldervast.online/wiki/uniques.html#unique-u_tidecaller',
        ], $result['uniques'][0]);
        $this->assertArrayNotHasKey('uniques_not_shown', $result);
    }

    public function test_a_unique_says_where_it_drops_and_its_chance_or_which_reward_gives_it(): void
    {
        $crossbows = $this->find(['type' => 'Crossbows', 'min_level' => 40, 'max_level' => 40]);
        $elderhorn = $crossbows['uniques'][0];
        $this->assertSame('Elderhorn', $elderhorn['name']);
        $this->assertStringStartsWith('Elderhorn, the Moss Stag', $elderhorn['drops_from']);
        $this->assertStringStartsWith('30% for one of its two uniques', $elderhorn['chance']);

        $ring = $this->find(['type' => 'Rings', 'min_level' => 34, 'max_level' => 34])['uniques'][0];
        $this->assertSame('Lampblack', $ring['name']);
        $this->assertSame('Chosen at the end of The Vault of the Last Lantern; no monster drops it', $ring['reward_from']);
        $this->assertArrayNotHasKey('drops_from', $ring);
    }

    public function test_an_exact_type_matches_only_that_type(): void
    {
        $result = $this->find(['type' => 'CROSSBOWS']);

        $this->assertSame(['Crossbows'], $result['types']);
        $this->assertSame(['Crossbows'], array_values(array_unique(array_column($result['base_items'], 'type'))));
    }

    public function test_min_and_max_level_are_both_inclusive(): void
    {
        $result = $this->find(['type' => 'Crossbows', 'min_level' => 20, 'max_level' => 28]);

        $this->assertSame(['Siege Crossbow', 'Arbalest'], array_column($result['base_items'], 'name'));
        $this->assertSame('Items of these types with a level from 20 to 28, highest level first.', $result['rule']);
    }

    public function test_fields_that_an_item_lacks_are_left_out(): void
    {
        // Tabards have no attribute requirements, stats or sockets.
        $tabard = $this->find(['type' => 'Tabards'])['base_items'][0];

        $this->assertSame(['name', 'type', 'level', 'implicit', 'url'], array_keys($tabard));
    }

    public function test_the_limit_applies_to_each_list_and_is_kept_between_1_and_50(): void
    {
        $default = $this->find(['type' => 'body armour']);
        $this->assertCount(15, $default['base_items']);
        $this->assertSame(43 - 15, $default['base_items_not_shown']);

        $this->assertCount(1, $this->find(['type' => 'body armour', 'limit' => 0])['base_items']);
        $this->assertCount(43, $this->find(['type' => 'body armour', 'limit' => 50])['base_items']);
        $this->assertCount(43, $this->find(['type' => 'body armour', 'limit' => 500])['base_items']);
    }

    public function test_with_no_type_it_lists_every_type_with_its_numbers_of_items(): void
    {
        $result = $this->find([]);

        $this->assertStringStartsWith('Call find_items again', $result['message']);
        $this->assertSame(['base_items' => 8, 'uniques' => 2], $result['types']['Crossbows']);
        $this->assertSame(['base_items' => 6], $result['types']['Tabards']);
        $this->assertSame('One-handed swords', array_key_first($result['types']));
        $this->assertCount(45, $result['types']);
    }

    public function test_a_type_that_matches_nothing_is_an_error_that_lists_the_types(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^No item type matches "spaceship". Item types are: Amulets, Belts, /');

        FindItemsTool::run(['type' => 'spaceship']);
    }

    public function test_a_level_can_be_a_number_or_a_string_of_digits(): void
    {
        $this->assertSame(
            $this->find(['type' => 'Crossbows', 'max_level' => 30]),
            $this->find(['type' => 'Crossbows', 'max_level' => '30', 'min_level' => ''])
        );
    }

    public static function badLevels(): array
    {
        return [
            'zero' => [['max_level' => 0], 'max_level must be a whole number from 1 to 60'],
            'too high' => [['max_level' => 61], 'max_level must be a whole number from 1 to 60'],
            'words' => [['min_level' => 'ten'], 'min_level must be a whole number from 1 to 60'],
            'decimal' => [['min_level' => 2.5], 'min_level must be a whole number from 1 to 60'],
        ];
    }

    #[DataProvider('badLevels')]
    public function test_a_level_outside_1_to_60_or_not_a_whole_number_is_an_error(array $input, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        FindItemsTool::run($input + ['type' => 'Crossbows']);
    }
}
