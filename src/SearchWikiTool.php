<?php

declare(strict_types=1);

/**
 * The search_wiki tool: finds entries in the Aldervast wiki.
 *
 * The wiki publishes its whole search index as one file, wiki/search.json: about 1,840 entries,
 * each shaped like
 *   {"n": name, "c": category, "u": "wiki/page.html#anchor", "s": short summary, "k": "space separated tags"}
 *
 * There's no search API, so we download that file (cached, see AldervastSite) and score every
 * entry in PHP. 1,840 entries is tiny; a loop over all of them takes a few milliseconds.
 *
 * This is usually the model's FIRST call for a game question: it finds the right entry and its
 * URL, and a later tool (get_wiki_entry) can fetch the full details.
 */
final class SearchWikiTool
{
    public const NAME = 'search_wiki';

    private const INDEX_PATH = 'wiki/search.json';

    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 25;

    /** Words so common they'd match half the summaries, so they only add noise to the ranking. */
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'at', 'by', 'do', 'for', 'how', 'i', 'in', 'is', 'it',
        'of', 'on', 'or', 'the', 'to', 'what', 'where', 'which', 'who', 'with',
    ];

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Searches the Aldervast game wiki: about 1,840 entries covering quests, items, '
                . 'monsters, bosses, dungeons, raids, places, people, factions, classes, skills, passives, '
                . 'mounts, pets, professions, achievements, guides and more. Matches the query against each '
                . 'entry\'s name, tags, category and short summary, and returns the best matches with their '
                . 'name, category, summary and wiki URL. Use it first for any question about the game world, '
                . 'to find the right entry. Summaries are cut at about 140 characters, so they hold a few '
                . 'facts, not the full entry. Short queries of distinctive words work best, such as '
                . '"hollow crypt" or "fire projectile".',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Words to look for, e.g. "Harrowford", "hollow crypt", "frost raid".',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Optional. Only return entries in this category, e.g. "Quest", '
                            . '"Group dungeon", "Raid", "Boss", "World boss", "Mount", "Pet", "Skill glyph", '
                            . '"Unique item", "Place", "Person", "Guide". Case-insensitive. A prefix also works: '
                            . '"hourglass" matches "Hourglass run" and "Hourglass omen".',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                        'description' => 'How many results to return. Default ' . self::DEFAULT_LIMIT . '.',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    public static function run(array $input): string
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            throw new InvalidArgumentException('query is required, e.g. {"query": "hollow crypt"}');
        }
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)));
        $categoryInput = trim((string) ($input['category'] ?? ''));

        $entries = AldervastSite::json(self::INDEX_PATH);
        $categories = $categoryInput === '' ? null : self::matchCategories($categoryInput, $entries);

        $phrase = mb_strtolower($query);
        $patterns = self::wordPatterns($phrase);

        $matches = [];
        foreach ($entries as $entry) {
            if ($categories !== null && !in_array($entry['c'], $categories, true)) {
                continue;
            }
            $score = self::score($entry, $phrase, $patterns);
            if ($score > 0) {
                $matches[] = ['score' => $score, 'entry' => $entry];
            }
        }

        // Best score first; ties in alphabetical order, so the same query always gives the same list.
        usort($matches, fn (array $a, array $b) => [$b['score'], $a['entry']['n']] <=> [$a['score'], $b['entry']['n']]);

        $shown = array_slice($matches, 0, $limit);
        $result = [
            'query' => $query,
            'categories' => $categories,
            'total_matches' => count($matches),
            'not_shown' => count($matches) - count($shown),
            'results' => array_map(fn (array $m) => self::present($m['entry']), $shown),
        ];
        if ($categories === null) {
            unset($result['categories']);
        }
        if ($matches === []) {
            $result['hint'] = 'No matches. Try fewer or different words, a singular form, or no category.';
        }

        return pretty($result);
    }

    /**
     * Scores one entry against the query. 0 means "not a match".
     *
     * The weights are a judgement call, picked so that a name match beats everything else:
     * if you search "hollow crypt" you want the dungeon called Hollow Crypt, not every
     * monster whose summary mentions it.
     */
    private static function score(array $entry, string $phrase, array $patterns): int
    {
        $name = mb_strtolower($entry['n']);
        $fields = [
            // field text                     points per query word found in it
            [$name, 10],
            [mb_strtolower($entry['k']), 6],   // tags, e.g. "spell projectile fire"
            [mb_strtolower($entry['c']), 4],   // category, so "fire skill" can find skill glyphs
            [mb_strtolower($entry['s']), 2],   // summary
        ];

        $score = 0;
        $wordsFound = 0;
        foreach ($patterns as $pattern) {
            $points = 0;
            foreach ($fields as [$text, $weight]) {
                if (preg_match($pattern, $text) === 1) {
                    $points += $weight;
                }
            }
            if ($points > 0) {
                $wordsFound++;
                $score += $points;
            }
        }

        if ($wordsFound === 0) {
            return 0;
        }

        // The whole query in the name is the strongest signal of all.
        if ($name === $phrase) {
            $score += 100;
        } elseif (str_starts_with($name, $phrase)) {
            $score += 40;
        } elseif (str_contains($name, $phrase)) {
            $score += 25;
        }

        // Every word found somewhere: far more likely to be what was meant than a one-word match.
        if ($wordsFound === count($patterns) && count($patterns) > 1) {
            $score += 15;
        }

        return $score;
    }

    /**
     * One regex per query word, matching at the START of a word: "crypt" finds "Crypts",
     * but "ice" doesn't find "price". The /u flag makes it Unicode-aware.
     */
    private static function wordPatterns(string $phrase): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY);
        $useful = array_diff($words, self::STOP_WORDS);
        if ($useful === []) {
            $useful = $words;   // A query made only of stop words, like "the": search for it anyway.
        }

        return array_map(fn (string $w) => '/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '/u', array_values(array_unique($useful)));
    }

    /**
     * Finds the real category names for what the caller typed. An exact (case-insensitive) match
     * wins; otherwise every category that starts with it. The wiki has near-duplicates such as
     * "Hourglass run" and "Hourglass runs", so a prefix is often what's wanted.
     */
    private static function matchCategories(string $wanted, array $entries): array
    {
        $all = array_values(array_unique(array_column($entries, 'c')));
        sort($all);
        $lower = mb_strtolower($wanted);

        $exact = array_values(array_filter($all, fn (string $c) => mb_strtolower($c) === $lower));
        if ($exact !== []) {
            return $exact;
        }
        $prefix = array_values(array_filter($all, fn (string $c) => str_starts_with(mb_strtolower($c), $lower)));
        if ($prefix !== []) {
            return $prefix;
        }

        // Tell the model the valid choices, so it can fix the call on its next try.
        throw new InvalidArgumentException("Unknown category \"{$wanted}\". Categories are: " . implode(', ', $all));
    }

    /** The fields the model sees for each result, with readable names and a full URL. */
    private static function present(array $entry): array
    {
        $out = [
            'name' => $entry['n'],
            'category' => $entry['c'],
            'summary' => $entry['s'],
            'url' => AldervastSite::url($entry['u']),
        ];
        if ($entry['k'] !== '') {
            $out['tags'] = $entry['k'];
        }

        return $out;
    }
}
