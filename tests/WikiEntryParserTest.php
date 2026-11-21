<?php

declare(strict_types=1);

/**
 * WikiEntryParser: Takes HTML and returns a structured array.
 */
final class WikiEntryParserTest extends FixtureTestCase
{
    private function entry(string $page, string $id): ?array
    {
        $html = (string) file_get_contents(self::FIXTURES . '/' . AldervastSite::fileName($page));

        return (new WikiEntryParser($html, $page))->entry($id);
    }

    public function test_article_gives_name_subtitle_description_and_facts(): void
    {
        $e = $this->entry('wiki/dungeons.html', 'dungeon-hollow_crypt');

        $this->assertSame('The Hollow Crypt', $e['name']);
        $this->assertSame('Dungeon · level 5', $e['sub']);
        $this->assertStringStartsWith('The Lantern Order buried its knights', $e['description']);
        $this->assertSame('5', $e['facts']['Level']);
        $this->assertSame('Warden Ossric, the Hollowed', $e['facts']['Boss']);
    }

    public function test_links_in_facts_become_plain_names_with_full_urls_alongside(): void
    {
        $e = $this->entry('wiki/dungeons.html', 'dungeon-hollow_crypt');

        // The fact is plain text, without HTML...
        $this->assertSame('Hollow Thrall, Hollow Bowman', $e['facts']['Monsters']);
        // ...and the link "../wiki/bosses.html#..." becomes a full URL that the model can pass to get_wiki_entry.
        $this->assertSame(
            'https://aldervast.online/wiki/bosses.html#monster-ossric',
            $e['links']['Warden Ossric, the Hollowed']
        );
        // The heading links to the entry itself. That link is left out, because it adds nothing.
        $this->assertNotContains('https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt', $e['links']);
    }

    public function test_h4_headings_become_sections_with_one_line_per_block(): void
    {
        $e = $this->entry('wiki/bosses.html', 'monster-ossric');

        $this->assertSame('Undead · Boss', $e['sub']); // the "Boss" label is added to the subtitle
        $this->assertSame(['Phases', 'Encounter journal'], array_keys($e['sections']));
        $this->assertCount(3, $e['sections']['Phases']);
        // A <br> inside a phase becomes " · ", so that the parts stay separate.
        $this->assertStringStartsWith('From the start · Uses Rending Cleave', $e['sections']['Phases'][0]);
    }

    public function test_column_headings_become_the_fact_labels_for_a_table_row(): void
    {
        $e = $this->entry('wiki/items.html', 'item-dusk_wraps');

        $this->assertSame('Dusk Wraps', $e['name']);
        $this->assertSame('Gloves (evasion and ward)', $e['sub']); // the heading above the table
        $this->assertSame('38', $e['facts']['Level']);
        $this->assertSame('32 DEX, 32 INT', $e['facts']['Requires']);
        $this->assertArrayNotHasKey('Implicit', $e['facts']); // "—" means empty, so it's left out
    }

    public function test_achievement_rows_are_read_like_item_rows(): void
    {
        $e = $this->entry('wiki/achievements.html', 'achievement-ach_first_blood');

        $this->assertSame('First Light', $e['name']);
        $this->assertSame('5', $e['facts']['Points']);
    }

    public function test_facts_inside_a_layout_div_are_still_found(): void
    {
        // Region entries have their facts inside <div class="loc-grid">. Before "loc-grid" was added
        // to LAYOUT_CLASSES, the parser returned all the facts as one long line of text.
        $e = $this->entry('wiki/regions.html', 'region-frostspire');

        $this->assertSame('24 to 34', $e['facts']['Levels']);
        $this->assertStringContainsString('The Fell (level 60)', $e['facts']['Dungeons']);
        $this->assertArrayHasKey('Weather', $e['sections']);
    }

    public function test_missing_entry_returns_null(): void
    {
        $this->assertNull($this->entry('wiki/dungeons.html', 'dungeon-no_such_place'));
    }

    public function test_id_that_could_break_the_xpath_query_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->entry('wiki/dungeons.html', "x'] | //*[@id='y");
    }

    public function test_the_heading_and_label_are_not_repeated_in_the_text(): void
    {
        $e = $this->entry('wiki/bosses.html', 'monster-ossric');

        // Only the quote is loose text. The heading and the "Boss" label are read separately.
        $this->assertSame(['“None pass the Lantern Gate while I stand.”'], $e['text']);
    }

    // The tests below use tests/fixtures/wiki_handmade.html, a page made by hand with edge cases
    // that the real wiki pages don't have. The comment at the top of that file lists them.

    public function test_accented_characters_are_read_correctly(): void
    {
        $this->assertSame('Café Limits', $this->entry('wiki/handmade.html', 'test-limits')['name']);
    }

    public function test_html_without_a_charset_tag_is_still_read_as_utf8(): void
    {
        // No <meta charset> tag here, unlike the wiki's pages. Without the parser's UTF-8 prefix,
        // libxml would read "é" as "Ã©".
        $html = '<article class="entry" id="a"><h3>Café · “quoted”</h3></article>';

        $this->assertSame('Café · “quoted”', (new WikiEntryParser($html, 'wiki/a.html'))->entry('a')['name']);
    }

    public function test_a_second_description_goes_into_the_text(): void
    {
        // Region entries have two descriptions: a short one, then a longer one.
        $e = $this->entry('wiki/regions.html', 'region-frostspire');

        $this->assertStringStartsWith('Knife-edged mountains crowned by Highwatch Keep.', $e['description']);
        $this->assertStringStartsWith('Knife-edged granite mountains', $e['text'][0]);
    }

    public function test_facts_from_two_lists_are_merged_and_a_value_without_a_label_is_ignored(): void
    {
        $facts = $this->entry('wiki/handmade.html', 'test-limits')['facts'];

        $this->assertSame(['Long', 'Accented', 'Exactly 600', 'Twice', 'Merged'], array_keys($facts));
        $this->assertSame('first; second', $facts['Twice']);
        $this->assertSame('from a second list', $facts['Merged']);
    }

    public function test_loose_text_skips_empty_blocks_figures_and_svg(): void
    {
        $e = $this->entry('wiki/handmade.html', 'test-limits');

        $this->assertSame(['Text before any heading.', 'Beforeafter'], $e['text']);
    }

    public function test_a_repeated_heading_adds_to_the_same_section(): void
    {
        $this->assertSame(['One', 'Two'], $this->entry('wiki/handmade.html', 'test-limits')['sections']['Twice']);
    }

    public function test_lists_tables_quotes_and_nested_blocks_become_lines(): void
    {
        $sections = $this->entry('wiki/handmade.html', 'test-limits')['sections'];

        $this->assertSame(['First step', 'Second step'], $sections['Steps']);
        $this->assertSame(['A | B', '1 | 2'], $sections['Table']);
        $this->assertSame(['“Quote.” — Someone'], $sections['Quote']);
        $this->assertSame(['a b'], $sections['Blocks']);
    }

    public function test_a_description_inside_a_section_stays_in_the_section(): void
    {
        $e = $this->entry('wiki/handmade.html', 'test-limits');

        $this->assertSame('A page made by hand for the tests.', $e['description']);
        $this->assertSame(['A description inside a section.'], $e['sections']['Journal']);
    }

    public function test_links_are_resolved_and_each_url_is_listed_once(): void
    {
        $links = $this->entry('wiki/handmade.html', 'test-limits')['links'];

        $this->assertSame([
            'Ossric' => 'https://aldervast.online/wiki/bosses.html#monster-ossric',
            'Crypt' => 'https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt',
            'External' => 'HTTPS://Example.com/page',
            'Colon later' => 'https://aldervast.online/wiki/notes.html?see=http:',
        ], array_slice($links, 0, 4, true));
        // "Ossric again" links to the same URL as "Ossric", so it isn't listed twice.
        $this->assertArrayNotHasKey('Ossric again', $links);
    }

    public function test_a_table_row_with_more_cells_than_headings_labels_the_extra_cell(): void
    {
        $facts = $this->entry('wiki/handmade.html', 'test-row')['facts'];

        // The first cell is the name, so it isn't a fact.
        $this->assertSame(['Level' => '5', 'Column 3' => 'extra'], $facts);
    }
}
