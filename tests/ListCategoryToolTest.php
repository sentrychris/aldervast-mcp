<?php

declare(strict_types=1);

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
}
