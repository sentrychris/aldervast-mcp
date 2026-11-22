<?php

declare(strict_types=1);

namespace Aldervast\Tests\Mcp;

use Aldervast\Mcp\WikiPages;
use Aldervast\Tests\FixtureTestCase;

/**
 * Tests for WikiPages, the MCP resources: one resource per wiki page, read as Markdown.
 * McpServerTest checks the same methods through the server.
 */
final class WikiPagesTest extends FixtureTestCase
{
    /** The resource for this page, from list(). */
    private function resource(string $page): array
    {
        foreach (WikiPages::list() as $resource) {
            if ($resource['name'] === $page) {
                return $resource;
            }
        }
        $this->fail("No resource for {$page}");
    }

    private function markdown(string $page): string
    {
        $contents = WikiPages::read('aldervast://' . $page);
        $this->assertNotNull($contents, "{$page} could not be read");

        return $contents[0]['text'];
    }

    public function test_there_is_one_resource_for_each_page_in_the_search_index_in_alphabetical_order(): void
    {
        $names = array_column(WikiPages::list(), 'name');

        // The saved search index points to 34 pages.
        $this->assertCount(34, $names);
        $this->assertSame('wiki/achievements.html', $names[0]);
        $this->assertSame('wiki/worldbosses.html', $names[33]);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function test_a_resource_has_an_aldervast_uri_a_markdown_type_and_the_page_address(): void
    {
        $this->assertSame([
            'uri' => 'aldervast://wiki/classes.html',
            'name' => 'wiki/classes.html',
            'title' => 'Aldervast wiki: Class',
            'description' => '6 entries (Class). The whole page as Markdown, from https://aldervast.online/wiki/classes.html.',
            'mimeType' => 'text/markdown',
        ], $this->resource('wiki/classes.html'));
    }

    public function test_a_page_with_several_categories_lists_them_with_the_largest_first(): void
    {
        $bosses = $this->resource('wiki/bosses.html');

        $this->assertSame('Aldervast wiki: Boss, Mini-boss, Raid boss, World boss', $bosses['title']);
        $this->assertStringStartsWith('77 entries: 33 Boss, 24 Mini-boss, 15 Raid boss, 5 World boss.', $bosses['description']);
    }

    public function test_reading_a_resource_returns_its_uri_type_and_text(): void
    {
        $contents = WikiPages::read('aldervast://wiki/classes.html');

        $this->assertCount(1, $contents);
        $this->assertSame('aldervast://wiki/classes.html', $contents[0]['uri']);
        $this->assertSame('text/markdown', $contents[0]['mimeType']);
        $this->assertStringStartsWith(
            "# Aldervast wiki: wiki/classes.html\n\nFrom https://aldervast.online/wiki/classes.html, 6 entries.\n\n## Ravager\n",
            $contents[0]['text']
        );
        $this->assertStringEndsWith("\n", $contents[0]['text']);
    }

    public function test_an_entry_has_its_subtitle_url_description_and_facts(): void
    {
        $this->assertStringContainsString(
            "## The Hollow Crypt\n\n*Dungeon · level 5*  \nhttps://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt\n\n"
            . "The Lantern Order buried its knights beneath Brightmeadow",
            $this->markdown('wiki/dungeons.html')
        );
        $this->assertStringContainsString(
            "the stair down to him winds past every grave the Order ever dug.\n\n- **Kind:** Dungeon\n- **Level:** 5\n",
            $this->markdown('wiki/dungeons.html')
        );
    }

    public function test_an_entry_has_its_other_text_and_its_sections_as_lists(): void
    {
        $classes = $this->markdown('wiki/classes.html');
        $bosses = $this->markdown('wiki/bosses.html');

        // Text that isn't the description or a fact follows the facts, as it is.
        $this->assertStringContainsString("- **Passive web start:** Strength side of the web\n\nSTR32\nDEX14\nINT14", $classes);
        $this->assertStringContainsString(
            "### Phases\n\n- From the start · Uses Rending Cleave, Crushing Slam",
            $bosses
        );
        $this->assertStringContainsString("\n\n### Encounter journal\n\n- Warden of the Lantern Gate", $bosses);
    }

    public function test_a_table_row_becomes_an_entry_with_the_column_headings_as_facts(): void
    {
        $this->assertStringContainsString(
            "## First Light\n\n*Adventure*  \nhttps://aldervast.online/wiki/achievements.html#achievement-ach_first_blood\n\n"
            . "- **How:** Slay your first monster.\n- **Points:** 5\n\n## ",
            $this->markdown('wiki/achievements.html')
        );
    }

    public function test_only_the_pages_in_the_list_can_be_read(): void
    {
        $this->assertNull(WikiPages::read('aldervast://wiki/nothing.html'));
        // A saved page that the search index doesn't point to isn't a resource either.
        $this->assertNull(WikiPages::read('aldervast://wiki/handmade.html'));
        $this->assertNull(WikiPages::read('aldervast://../.env'));
        $this->assertNull(WikiPages::read('aldervast://wiki/classes.html#class-ravager'));
        // The right page, but not this server's scheme.
        $this->assertNull(WikiPages::read('https://aldervast.online/wiki/classes.html'));
        $this->assertNull(WikiPages::read('xaldervast://wiki/classes.html'));
        $this->assertNull(WikiPages::read(''));
    }

    public function test_a_page_with_one_bare_entry_says_1_entry_and_has_no_empty_parts(): void
    {
        // A tiny site, made for this test: an index with one entry, and its page.
        $folder = sys_get_temp_dir() . '/aldervast-pages-' . bin2hex(random_bytes(4));
        mkdir($folder);
        file_put_contents("{$folder}/wiki_search.json", json_encode([
            ['n' => 'Lone', 'c' => 'Test', 'u' => 'wiki/tiny.html#test-lone', 's' => '', 'k' => ''],
        ]));
        file_put_contents("{$folder}/wiki_tiny.html", '<article class="entry" id="test-lone"><div class="entry-head"><h3>Lone</h3></div></article>');
        putenv("ALDERVAST_FIXTURES={$folder}");

        try {
            $resource = WikiPages::list()[0];
            $text = WikiPages::read('aldervast://wiki/tiny.html')[0]['text'];
        } finally {
            array_map('unlink', glob("{$folder}/*"));
            rmdir($folder);
        }

        $this->assertStringStartsWith('1 entry (Test).', $resource['description']);
        // No subtitle, description, facts or sections: only the name and the URL.
        $this->assertSame(
            "# Aldervast wiki: wiki/tiny.html\n\nFrom https://aldervast.online/wiki/tiny.html, 1 entry.\n\n"
            . "## Lone\n\nhttps://aldervast.online/wiki/tiny.html#test-lone\n",
            $text
        );
    }
}
