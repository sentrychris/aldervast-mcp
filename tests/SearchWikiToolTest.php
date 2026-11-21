<?php

declare(strict_types=1);

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
