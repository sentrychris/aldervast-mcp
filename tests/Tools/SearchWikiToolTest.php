<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\SearchWikiTool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for search_wiki: how it ranks results, how the category filter works, and how summaries
 * are shown to the model.
 *
 * The tests use a saved copy of wiki/search.json, so the rankings stay the same until someone
 * downloads fresh copies.
 */
final class SearchWikiToolTest extends FixtureTestCase
{
    private function search(array $input): array
    {
        return $this->decode(SearchWikiTool::run($input));
    }

    /**
     * The scoring rules, as arithmetic on hand-made entries. Each query word earns points for every
     * field that it's found in: name 10, tags 6, category 4, summary 2. Then the whole query earns
     * a bonus if it's in the name: 100 if it is the whole name, 40 if the name starts with it, 25 if
     * the name only contains it. A query of two or more words earns 15 more if every word was found.
     *
     * If you change a weight in SearchWikiTool::score(), change the numbers here too. That's on
     * purpose: a change to the ranking should be a decision, not an accident.
     */
    public static function scoringRules(): array
    {
        $entry = fn (string $name, string $category = 'Other', string $summary = '', string $tags = '') =>
            ['n' => $name, 'c' => $category, 's' => $summary, 'k' => $tags];

        return [
            // crypt: name 10 + summary 2. "crypt" is inside the name (not at the start): + 25.
            'the name contains the query' => ['crypt', $entry('Hollow Crypt', 'Dungeon', 'An old crypt'), 37],
            // hollow: name 10, crypt: name 10. The whole name: + 100. Every word found: + 15.
            'the query is the whole name' => ['hollow crypt', $entry('Hollow Crypt'), 135],
            // 20 for the two words. The name starts with the query: + 40. Every word found: + 15.
            'the name starts with the query' => ['hollow crypt', $entry('Hollow Crypt Gate'), 75],
            // fire: tags 6 + summary 2, bolt: name 10 + summary 2. Every word found: + 15.
            'words found in several fields' => ['fire bolt', $entry('Ember Bolt', 'Skill glyph', 'Hurl a bolt of fire.', 'spell projectile fire'), 35],
            // skill: category 4. "crypt" isn't found, so there's no bonus for finding every word.
            'only some words found' => ['skill crypt', $entry('Ember Bolt', 'Skill glyph'), 4],
            'no word found' => ['crypt', $entry('Ember Bolt'), 0],
            // Upper-case accented letters must still match: name 10, and the name starts with it: + 40.
            'accented letters in another case' => ['ÉLAN', $entry('Élan Vital'), 50],
            // The same, in the other fields: tags 6 + category 4 + summary 2.
            'accented letters in the other fields' => ['café', $entry('X', 'CAFÉ', 'LE CAFÉ', 'CAFÉ'), 12],
        ];
    }

    #[DataProvider('scoringRules')]
    public function test_score_adds_up_the_points_for_each_rule(string $query, array $entry, int $expected): void
    {
        $this->assertSame($expected, SearchWikiTool::scoreFor($entry, $query));
    }

    public function test_entries_with_the_exact_name_come_first(): void
    {
        $result = $this->search(['query' => 'hollow crypt']);
        $top4 = array_column(array_slice($result['results'], 0, 4), 'url');

        // The place, the quest, the dungeon and the Hourglass run all share the name.
        $this->assertContains('https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt', $top4);
        $this->assertContains('https://aldervast.online/wiki/quests.html#quest-ms03_crypt', $top4);
    }

    public function test_words_match_at_the_start_of_a_word(): void
    {
        $names = array_column($this->search(['query' => 'stag', 'limit' => 5])['results'], 'name');

        $this->assertContains('Elderhorn, the Moss Stag', $names);
    }

    public function test_category_filter_accepts_an_exact_name(): void
    {
        $result = $this->search(['query' => 'level', 'category' => 'raid']);

        $this->assertSame(['Raid'], $result['categories']); // not "Raid boss"
        $this->assertSame(4, $result['total_matches']);
    }

    public function test_category_filter_accepts_the_start_of_a_name(): void
    {
        $this->assertSame(
            ['Hourglass omen', 'Hourglass run', 'Hourglass runs'],
            SearchWikiTool::matchCategories('hourglass')
        );
    }

    public function test_error_for_an_unknown_category_lists_the_real_ones(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown category "Dragons"\. Categories are: .*World boss/');

        SearchWikiTool::run(['query' => 'x', 'category' => 'Dragons']);
    }

    public function test_search_with_no_matches_returns_a_hint_not_an_error(): void
    {
        $result = $this->search(['query' => 'zzqqxx']);

        $this->assertSame([], $result['results']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function test_limit_is_capped(): void
    {
        $result = $this->search(['query' => 'boss', 'limit' => 1000]);

        $this->assertCount(25, $result['results']);
        $this->assertSame($result['total_matches'] - 25, $result['not_shown']);
    }

    public function test_empty_query_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SearchWikiTool::run(['query' => '   ']);
    }

    public function test_query_of_only_common_words_is_still_searched(): void
    {
        // "the" is in STOP_WORDS. If every word is a stop word, the search uses them anyway,
        // rather than searching for nothing.
        $this->assertGreaterThan(0, $this->search(['query' => 'the'])['total_matches']);
    }

    public function test_cut_summary_is_marked_so_the_model_knows_text_is_missing(): void
    {
        $ravager = $this->indexEntry('Ravager', 'Class');

        // In the index it just stops: "...raw physical " (the full text ends "damage.").
        $this->assertSame(140, mb_strlen($ravager['s']));
        $this->assertStringEndsWith('life, armor and raw physical …', SearchWikiTool::summary($ravager));
    }

    public function test_complete_summary_is_left_alone(): void
    {
        $warden = $this->indexEntry('Warden', 'Class');

        $this->assertSame($warden['s'], SearchWikiTool::summary($warden));
    }

    public function test_html_entities_are_decoded_and_a_cut_entity_is_dropped(): void
    {
        $this->assertSame('It’s here', SearchWikiTool::summary(['s' => 'It&#x2019;s here']));

        // If the cut falls inside "&#x2019;", only "&#x2" is left. The model must never get that.
        $cut = str_repeat('a', 136) . '&#x2';
        $this->assertSame(str_repeat('a', 136) . ' …', SearchWikiTool::summary(['s' => $cut]));
    }

    public function test_html5_entity_names_are_decoded_too(): void
    {
        $this->assertSame("It's here", SearchWikiTool::summary(['s' => 'It&apos;s here']));
    }

    public function test_a_summary_of_139_characters_counts_as_cut(): void
    {
        $this->assertStringEndsWith(' …', SearchWikiTool::summary(['s' => str_repeat('a', 139)]));
    }

    public function test_a_short_summary_with_accents_is_not_cut(): void
    {
        // 100 accented letters are 200 bytes. The cut length counts characters, not bytes.
        $this->assertSame(str_repeat('é', 100), SearchWikiTool::summary(['s' => str_repeat('é', 100)]));
    }

    public function test_the_best_match_comes_first_and_the_query_is_returned(): void
    {
        $result = $this->search(['query' => 'hollow crypt']);

        $this->assertSame('hollow crypt', $result['query']);
        $this->assertSame('Hollow Crypt', $result['results'][0]['name']);
    }

    public function test_limit_below_one_returns_one_result(): void
    {
        $this->assertCount(1, $this->search(['query' => 'crypt', 'limit' => 0])['results']);
    }

    public function test_spaces_around_the_category_are_ignored(): void
    {
        $this->assertSame(['Raid'], $this->search(['query' => 'level', 'category' => ' raid '])['categories']);
    }

    public function test_common_words_in_a_query_are_ignored(): void
    {
        $with = $this->search(['query' => 'the hollow crypt'])['total_matches'];
        $without = $this->search(['query' => 'hollow crypt'])['total_matches'];

        $this->assertSame($without, $with);
    }

    public function test_only_results_with_tags_show_them(): void
    {
        $results = array_column($this->search(['query' => 'ember bolt', 'limit' => 25])['results'], null, 'name');

        $this->assertSame('spell projectile fire', $results['Ember Bolt']['tags']);
        $this->assertArrayNotHasKey('tags', $this->search(['query' => 'hollow crypt'])['results'][0]);
    }

    public function test_no_matches_hint_names_both_ways_to_try_again(): void
    {
        // This is text for the model. The test pins it, so that a change to it is deliberate.
        $this->assertSame(
            'No matches. Try fewer or different words, a synonym, a singular form or no category, '
            . 'or browse a category with list_category.',
            $this->search(['query' => 'zzqqxx'])['hint']
        );
    }

    private function indexEntry(string $name, string $category): array
    {
        foreach (SearchWikiTool::index() as $entry) {
            if ($entry['n'] === $name && $entry['c'] === $category) {
                return $entry;
            }
        }
        $this->fail("{$category} \"{$name}\" is not in the fixture index");
    }
}
