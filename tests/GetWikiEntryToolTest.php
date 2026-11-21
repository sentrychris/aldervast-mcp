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
}
