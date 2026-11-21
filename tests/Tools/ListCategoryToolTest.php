<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\ListCategoryTool;

/**
 * Tests for list_category: listing the entries in one category, splitting a big category 
 * into pages, and listing all categories with their sizes.
 */
final class ListCategoryToolTest extends FixtureTestCase
{
    private function list(array $input): array
    {
        return $this->decode(ListCategoryTool::run($input));
    }

    public function test_without_a_category_it_lists_the_categories_biggest_first(): void
    {
        $counts = $this->list([])['categories'];

        $this->assertSame('Base item', array_key_first($counts));
        $this->assertSame(323, $counts['Base item']);
        $this->assertSame(10, $counts['World boss']);
    }

    public function test_small_category_is_returned_in_full(): void
    {
        $result = $this->list(['category' => 'world boss']);

        $this->assertSame(10, $result['total']);
        $this->assertCount(10, $result['entries']);
        $this->assertArrayNotHasKey('next_offset', $result); // nothing more to fetch
        $this->assertContains('Elderhorn, the Moss Stag', array_column($result['entries'], 'name'));
    }

    public function test_big_category_is_split_into_pages(): void
    {
        $first = $this->list(['category' => 'Base item']);
        $this->assertCount(50, $first['entries']);
        $this->assertSame(50, $first['next_offset']);

        $last = $this->list(['category' => 'Base item', 'offset' => 300]);
        $this->assertCount(23, $last['entries']);
        $this->assertArrayNotHasKey('next_offset', $last);
    }

    public function test_category_is_labelled_only_when_a_prefix_matched_several(): void
    {
        $one = $this->list(['category' => 'Raid'])['entries'][0];
        $several = $this->list(['category' => 'hourglass', 'limit' => 1])['entries'][0];

        $this->assertArrayNotHasKey('category', $one);
        $this->assertArrayHasKey('category', $several);
    }

    public function test_cut_summaries_are_marked_here_too(): void
    {
        $classes = array_column($this->list(['category' => 'Class'])['entries'], 'summary', 'name');

        $this->assertStringEndsWith(' …', $classes['Ravager']);
        $this->assertStringEndsWith('anchor any group.', $classes['Warden']);
    }

    public function test_spaces_around_the_category_are_ignored(): void
    {
        $result = $this->list(['category' => '  world boss ']);

        $this->assertSame(['World boss'], $result['categories']);
        $this->assertSame(10, $result['total']);
    }

    public function test_a_negative_offset_starts_at_the_beginning(): void
    {
        $first = $this->list(['category' => 'Raid'])['entries'][0];

        $this->assertSame($first, $this->list(['category' => 'Raid', 'offset' => -5])['entries'][0]);
    }

    public function test_limit_is_used_and_kept_at_one_or_more(): void
    {
        $this->assertCount(5, $this->list(['category' => 'Base item', 'limit' => 5])['entries']);
        $this->assertCount(1, $this->list(['category' => 'Base item', 'limit' => 0])['entries']);
    }

    public function test_without_a_category_it_says_what_to_do_next(): void
    {
        // This is text for the model. The test pins it, so that a change to it is deliberate.
        $this->assertSame('Call list_category again with one of these categories.', $this->list([])['message']);
    }
}
