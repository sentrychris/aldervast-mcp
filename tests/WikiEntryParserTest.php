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
}
