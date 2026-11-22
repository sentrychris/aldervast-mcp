<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\FindSkillsTool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for find_skills: the filters on skills (type, tag, needs, levels), the order and fields,
 * and the support runes that fit a skill, on the saved copies of skills.html and supports.html.
 */
final class FindSkillsToolTest extends FixtureTestCase
{
    private function find(array $input): array
    {
        return $this->decode(FindSkillsTool::run($input));
    }

    public function test_skills_that_need_a_weapon_come_lowest_level_first_with_their_details(): void
    {
        $result = $this->find(['needs' => ' CROSSBOW ', 'max_level' => 10]);

        $this->assertSame(['needs' => 'CROSSBOW', 'levels' => '1 to 10'], $result['filters']);
        $this->assertSame(5, $result['total']);
        // Level 1 first, then the level 6 skills by name.
        $this->assertSame(['Piercing Shot', 'Arrow Squall', 'Fanfire', 'Kindled Bolt', 'Rime Shot'], array_column($result['skills'], 'name'));
        $this->assertSame([
            'name' => 'Piercing Shot',
            'type' => 'Projectile',
            'level' => 1,
            'tags' => ['Attack', 'Projectile', 'Physical'],
            'needs' => 'A crossbow in hand',
            'damage' => '100% of weapon damage',
            'mana' => '4',
            'starting_skill_of' => 'Stalker',
            'description' => 'Fire a bolt that pierces through several enemies.',
            'url' => 'https://aldervast.online/wiki/skills.html#skill-piercing_shot',
        ], $result['skills'][0]);
        $this->assertSame('12% chance to freeze', $result['skills'][4]['ailments']);
    }

    public function test_type_and_tag_are_matched_ignoring_case_and_all_filters_apply_together(): void
    {
        $result = $this->find(['type' => 'trap', 'tag' => 'LIGHTNING']);

        $this->assertSame(['type' => 'Trap', 'tag' => 'Lightning', 'levels' => '1 to 60'], $result['filters']);
        $this->assertSame(['Stormcage'], array_column($result['skills'], 'name'));
        // A skill with a cooldown says so, and a skill that needs nothing has no "needs" at all.
        $this->assertArrayHasKey('cooldown', $result['skills'][0]);
        $this->assertArrayNotHasKey('needs', $result['skills'][0]);
    }

    public function test_min_and_max_level_are_both_inclusive(): void
    {
        $result = $this->find(['min_level' => 14, 'max_level' => 22, 'limit' => 50]);

        $this->assertSame(9, $result['total']);
        $this->assertSame([14, 22], [$result['skills'][0]['level'], end($result['skills'])['level']]);
        $this->assertSame(
            $this->find(['min_level' => '14', 'max_level' => '22', 'limit' => 50]),
            $result
        );
    }

    public function test_with_no_filters_every_skill_counts_and_the_limit_is_kept_between_1_and_50(): void
    {
        $all = $this->find([]);
        $this->assertSame(['levels' => '1 to 60'], $all['filters']);
        $this->assertSame(49, $all['total']);
        $this->assertCount(25, $all['skills']);
        $this->assertSame(24, $all['skills_not_shown']);

        $this->assertCount(1, $this->find(['limit' => 0])['skills']);
        $this->assertCount(49, $this->find(['limit' => 50])['skills']);
        $this->assertArrayNotHasKey('skills_not_shown', $this->find(['limit' => 49]));
        $this->assertCount(49, $this->find(['limit' => 500])['skills']);
    }

    public function test_supports_for_lists_the_runes_whose_facts_fit_the_skill(): void
    {
        $result = $this->find(['supports_for' => ' piercing shot ', 'max_level' => 4, 'tag' => 'ignored']);

        $this->assertSame([
            'name' => 'Piercing Shot',
            'type' => 'Projectile',
            'tags' => ['Attack', 'Projectile', 'Physical'],
            'url' => 'https://aldervast.online/wiki/skills.html#skill-piercing_shot',
        ], $result['skill']);
        $this->assertStringStartsWith('Runes whose "Supports skills tagged" is Any or names one of the skill\'s tags', $result['rule']);
        $this->assertStringEndsWith(' Levels 1 to 4.', $result['rule']);
        // Physical, Attack|spell, Any, Projectile and Any all fit; runes for other tags don't.
        // Names sort ignoring case, so "the Volley" comes before "Thrift".
        $this->assertSame(
            ['Rune of Brutality', 'Rune of Cinders', 'Rune of Precision', 'Rune of the Volley', 'Rune of Thrift'],
            array_column($result['supports'], 'name')
        );
        $this->assertSame([
            'name' => 'Rune of Brutality',
            'level' => 4,
            'effects' => ['30% more Physical Damage'],
            'supports_skills_tagged' => 'Physical',
            'mana_cost' => '×1.3',
            'url' => 'https://aldervast.online/wiki/supports.html#support-rune_brutality',
        ], $result['supports'][0]);
    }

    public function test_a_rune_that_cannot_support_the_skill_type_or_a_tag_is_left_out(): void
    {
        // Iron Jaw is a Trap, and the Snare and Pylon runes name Trap under "Cannot support",
        // although Iron Jaw's Spell tag is one that they support.
        $trap = array_column($this->find(['supports_for' => 'Iron Jaw', 'limit' => 50])['supports'], 'name');
        $this->assertNotContains('Rune of the Snare', $trap);
        $this->assertNotContains('Rune of the Pylon', $trap);
        $this->assertContains('Rune of Brutality', $trap);

        // Mending Light's type is Heal, which isn't one of its tags (Spell, Area). The Snare rune
        // supports spells but names Heal under "Cannot support", so the type must be checked too.
        $heal = array_column($this->find(['supports_for' => 'Mending Light', 'limit' => 50])['supports'], 'name');
        $this->assertNotContains('Rune of the Snare', $heal);
        $this->assertContains('Rune of Precision', $heal);

        $shot = $this->find(['supports_for' => 'Piercing Shot', 'limit' => 50])['supports'];
        $snare = array_values(array_filter($shot, fn (array $r) => $r['name'] === 'Rune of the Snare'))[0];
        $this->assertSame('Totem, Trap, Movement, Aura, Summon, Curse, Buff, Heal, Warcry', $snare['cannot_support']);
        $fixed = array_values(array_filter($shot, fn (array $r) => $r['name'] === 'Rune of the Executioner'))[0];
        $this->assertStringStartsWith('Since 1.14.92', $fixed['fixed']);
    }

    public function test_rune_levels_filter_the_runes_and_lower_case_the_sort_by_name(): void
    {
        $result = $this->find(['supports_for' => 'Piercing Shot', 'min_level' => 10, 'max_level' => 10, 'limit' => 50]);

        $names = array_column($result['supports'], 'name');
        $this->assertSame(16, $result['total']);
        $this->assertSame([10], array_values(array_unique(array_column($result['supports'], 'level'))));
        // "Rune of the ..." sorts among the other names, not after every capital letter.
        $this->assertLessThan(array_search('Rune of Venom', $names, true), array_search('Rune of the Executioner', $names, true));
        $this->assertArrayNotHasKey('supports_not_shown', $result);
        $this->assertSame(15, $this->find(['supports_for' => 'Piercing Shot', 'min_level' => 10, 'limit' => 1])['supports_not_shown']);
    }

    public static function badInputs(): array
    {
        return [
            'unknown type' => [['type' => 'Laser'], '/^Unknown type "Laser". Valid values: Aura, Buff, Chain, /'],
            'unknown tag' => [['tag' => 'Holy'], '/^Unknown tag "Holy". Valid values: Area, Attack, /'],
            'needs nothing named' => [['needs' => 'wand'], '/^No skill needs "wand". The wiki names these: A shield in hand, A crossbow in hand\. Every other skill lists no requirement\.$/'],
            'unknown skill' => [['supports_for' => 'Fireball'], '/^No skill is called "Fireball". Skills are: Arrow Squall, Banner of Might, /'],
            'bad level' => [['max_level' => 0], '/^max_level must be a whole number from 1 to 60$/'],
        ];
    }

    #[DataProvider('badInputs')]
    public function test_an_unknown_value_is_an_error_that_lists_the_valid_ones(array $input, string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($pattern);

        FindSkillsTool::run($input);
    }
}
