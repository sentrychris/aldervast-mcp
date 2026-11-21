<?php

declare(strict_types=1);

/**
 * Tests for get_wiki_entry: how it works out which entry the caller means, and which fields it returns.
 */
final class GetWikiEntryToolTest extends FixtureTestCase
{
    private function get(array $input): array
    {
        return $this->decode(GetWikiEntryTool::run($input));
    }

    public function test_accepts_the_url_in_any_of_its_usual_forms(): void
    {
        foreach ([
            'https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt',
            '/wiki/dungeons.html#dungeon-hollow_crypt',
            '../wiki/dungeons.html#dungeon-hollow_crypt',
            'wiki/dungeons.html#dungeon-hollow_crypt',
        ] as $url) {
            $this->assertSame('The Hollow Crypt', $this->get(['url_or_name' => $url])['name'], $url);
        }
    }

    public function test_name_that_several_entries_share_returns_the_choices(): void
    {
        $result = $this->get(['url_or_name' => 'Hollow Crypt']);

        $this->assertFalse($result['found']);
        $this->assertSame(
            ['Place', 'Quest', 'Dungeon', 'Hourglass run'],
            array_column($result['matches'], 'category')
        );
    }

    public function test_name_with_a_category_finds_one_entry_ignoring_case_and_a_leading_the(): void
    {
        $result = $this->get(['url_or_name' => 'hollow crypt', 'category' => 'dungeon']);

        $this->assertSame('The Hollow Crypt', $result['name']);
        $this->assertSame('https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt', $result['url']);
    }

    public function test_partial_name_returns_the_closest_entries(): void
    {
        $result = $this->get(['url_or_name' => 'warden ossric']);

        $this->assertFalse($result['found']);
        $this->assertSame('Warden Ossric, the Hollowed', $result['matches'][0]['name']);
    }

    public function test_message_says_so_when_nothing_is_close(): void
    {
        $result = $this->get(['url_or_name' => 'Ossrik']);

        $this->assertSame([], $result['matches']);
        $this->assertStringContainsString('nothing close was found', $result['message']);
    }

    public function test_refuses_urls_from_other_websites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GetWikiEntryTool::run(['url_or_name' => 'https://example.com/wiki/x.html#a']);
    }

    public function test_empty_input_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GetWikiEntryTool::run(['url_or_name' => '   ']);
    }

    public function test_missing_entry_on_a_real_page_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('has no entry #no-such-entry');

        GetWikiEntryTool::run(['url_or_name' => 'wiki/dungeons.html#no-such-entry']);
    }

    public function test_empty_fields_are_left_out(): void
    {
        $result = $this->get(['url_or_name' => 'wiki/items.html#item-dusk_wraps']);

        $this->assertSame(['name', 'sub', 'url', 'facts'], array_keys($result));
    }

    public function test_long_link_lists_are_cut_with_the_number_left_out(): void
    {
        $result = $this->get(['url_or_name' => 'wiki/regions.html#region-frostspire']);

        $this->assertCount(40, $result['links']);
        $this->assertGreaterThan(0, $result['links_not_shown']);
    }

    public function test_spaces_around_the_name_and_category_are_ignored(): void
    {
        $result = $this->get(['url_or_name' => '  hollow crypt  ', 'category' => ' dungeon ']);

        $this->assertSame('The Hollow Crypt', $result['name']);
    }

    public function test_url_with_extra_text_after_it_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^Not an Aldervast wiki entry URL: .*\. Expected something like https:\/\/aldervast\.online\/wiki\/dungeons\.html#dungeon-hollow_crypt$/'
        );

        GetWikiEntryTool::run(['url_or_name' => 'wiki/dungeons.html#dungeon-hollow_crypt"><b>']);
    }

    public function test_close_matches_are_limited_to_eight_and_say_what_to_do(): void
    {
        $result = $this->get(['url_or_name' => 'hollow']);

        $this->assertCount(8, $result['matches']);
        $this->assertSame(
            'No entry has exactly this name. These are the closest matches; call get_wiki_entry '
            . 'again with one of their URLs.',
            $result['message']
        );
    }

    // The tests below use tests/fixtures/wiki_handmade.html, a page made by hand with edge cases
    // that the real wiki pages don't have. The comment at the top of that file lists them.

    public function test_a_long_value_is_cut_at_a_whole_word_and_says_so(): void
    {
        $facts = $this->get(['url_or_name' => 'wiki/handmade.html#test-limits'])['facts'];

        // "café " is 5 characters, so 600 characters hold exactly 120 of them.
        $this->assertSame(
            trim(str_repeat('café ', 120)) . ' … (cut short; the rest is on the wiki page)',
            $facts['Long']
        );
    }

    public function test_values_up_to_600_characters_are_not_cut(): void
    {
        $facts = $this->get(['url_or_name' => 'wiki/handmade.html#test-limits'])['facts'];

        $this->assertSame(str_repeat('a', 600), $facts['Exactly 600']);
        // 400 accented letters are 800 bytes. The limit counts characters, not bytes.
        $this->assertSame(str_repeat('é', 400), $facts['Accented']);
    }

    public function test_a_long_line_in_a_section_is_cut_too(): void
    {
        $lines = $this->get(['url_or_name' => 'wiki/handmade.html#test-limits'])['sections']['Long line'];

        // "word " is 5 characters, so 600 characters hold exactly 120 of them.
        $this->assertSame([trim(str_repeat('word ', 120)) . ' … (cut short; the rest is on the wiki page)'], $lines);
    }

    public function test_a_long_section_keeps_30_lines_and_says_how_many_were_left_out(): void
    {
        $lines = $this->get(['url_or_name' => 'wiki/handmade.html#test-limits'])['sections']['Many lines'];

        $this->assertCount(31, $lines);
        $this->assertSame('Line 1', $lines[0]);
        $this->assertSame('Line 30', $lines[29]);
        $this->assertSame('… 5 more lines on the wiki page', $lines[30]);
    }

    public function test_a_long_link_list_keeps_the_first_40(): void
    {
        $result = $this->get(['url_or_name' => 'wiki/handmade.html#test-limits']);

        $this->assertSame('Ossric', array_key_first($result['links']));
        $this->assertSame(6, $result['links_not_shown']);
    }

    public function test_exactly_40_links_are_not_cut(): void
    {
        $result = $this->get(['url_or_name' => 'wiki/handmade.html#test-forty-links']);

        $this->assertCount(40, $result['links']);
        $this->assertArrayNotHasKey('links_not_shown', $result);
    }
}
