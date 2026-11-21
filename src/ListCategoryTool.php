<?php

declare(strict_types=1);

/**
 * The list_category tool: every wiki entry in one category, such as all world bosses or all mounts.
 *
 * Why a list helps, even though we already have search: search matches WORDS, and players don't
 * use the wiki's words. When a player asks about "the big deer boss", search_wiki finds nothing
 * useful, because the wiki calls it "Elderhorn, the Moss Stag" and never uses the word "deer".
 * But if we list the ten world bosses, the model spots the stag at once. The model is good at
 * matching meaning; we just have to show it the candidates.
 *
 * If it's called with no category, it lists the categories and how many entries each one has,
 * so that the model can find the right one.
 */
final class ListCategoryTool
{
    public const NAME = 'list_category';

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 100;

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Lists every Aldervast wiki entry in one category, in wiki order, with each '
                . 'entry\'s name, short summary (often its level; one ending in " …" was cut off, so '
                . 'don\'t guess the rest) and URL. Use it to browse, e.g. "every '
                . 'group dungeon and its level", "all mounts", "the raids". Also use it when search_wiki '
                . 'misses because the question uses different words than the wiki: list the likely '
                . 'category and pick out the entry by meaning. Call it with no '
                . 'category to get the list of categories and how many entries each has. Big categories '
                . 'come in pages; pass next_offset back as offset for the next page.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'Optional. A category such as "World boss", "Boss", "Raid boss", '
                            . '"Group dungeon", "Raid", "Mount", "Pet", "Class", "Region", "Settlement". '
                            . 'Case-insensitive; a prefix also works. Leave it out to list the categories.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'description' => 'Skip this many entries. Use next_offset from the previous page.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                        'description' => 'How many entries to return. Default ' . self::DEFAULT_LIMIT . '.',
                    ],
                ],
            ],
        ];
    }

    public static function run(array $input): string
    {
        $categoryInput = trim((string) ($input['category'] ?? ''));
        if ($categoryInput === '') {
            return self::categories();
        }

        $categories = SearchWikiTool::matchCategories($categoryInput);
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)));

        $entries = array_values(array_filter(
            SearchWikiTool::index(),
            fn (array $e) => in_array($e['c'], $categories, true)
        ));
        $page = array_slice($entries, $offset, $limit);

        $result = [
            'categories' => $categories,
            'total' => count($entries),
            'offset' => $offset,
            'shown' => count($page),
            'entries' => array_map(fn (array $e) => self::present($e, count($categories) > 1), $page),
        ];
        if ($offset + count($page) < count($entries)) {
            $result['next_offset'] = $offset + count($page);
        }

        return pretty($result);
    }

    /** Every category with its number of entries, biggest first. */
    private static function categories(): string
    {
        $counts = array_count_values(array_column(SearchWikiTool::index(), 'c'));
        arsort($counts);

        return pretty([
            'message' => 'Call list_category again with one of these categories.',
            'categories' => $counts,
        ]);
    }

    /** Name, summary and URL: enough to choose, and the URL is what get_wiki_entry needs next. */
    private static function present(array $entry, bool $withCategory): array
    {
        $out = ['name' => $entry['n']];
        // Only include the category when a prefix matched several categories ("hourglass").
        // Otherwise every entry has the same category, and it would waste space.
        if ($withCategory) {
            $out['category'] = $entry['c'];
        }

        return $out + ['summary' => SearchWikiTool::summary($entry), 'url' => AldervastSite::url($entry['u'])];
    }
}
