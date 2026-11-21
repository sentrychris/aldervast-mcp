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

    /**
     * The wiki's index cuts every summary at 140 characters, often mid-word ("…past Old Kaldris, ba").
     * A summary this long or longer has been cut. (Punctuation is no clue: many complete summaries
     * are fragments with no full stop.)
     */
    private const SUMMARY_CUT_LENGTH = 139;

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
                . 'to find the right entry. Summaries are short, and one ending in " …" was cut off by '
                . 'the index: don\'t guess the missing words; call get_wiki_entry if they matter. Short queries of distinctive words work best, such as '
                . '"hollow crypt" or "fire projectile". It matches words, not meaning, and the wiki has '
                . 'its own names for things, which are often not the words a player would use. If the '
                . 'results don\'t fit the question, search again with synonyms or related words, or '
                . 'browse the likely category with list_category.',
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

        $categories = $categoryInput === '' ? null : self::matchCategories($categoryInput);
        $matches = self::search($query, $categories);

        $shown = array_slice($matches, 0, $limit);
        $result = [
            'query' => $query,
            'categories' => $categories,
            'total_matches' => count($matches),
            'not_shown' => count($matches) - count($shown),
            'results' => array_map(fn (array $entry) => self::present($entry), $shown),
        ];
        if ($categories === null) {
            unset($result['categories']);
        }
        if ($matches === []) {
            $result['hint'] = 'No matches. Try fewer or different words, a synonym, a singular form or no '
                . 'category, or browse a category with list_category.';
        }

        return pretty($result);
    }

    /** The whole search index: about 1,840 entries of {n, c, u, s, k}. Cached by AldervastSite. */
    public static function index(): array
    {
        return AldervastSite::json(self::INDEX_PATH);
    }

    /**
     * Every index entry that matches the query, best first. get_wiki_entry uses this too,
     * to suggest close matches when a name isn't found.
     *
     * @param string[]|null $categories real category names to keep, or null for all
     */
    public static function search(string $query, ?array $categories = null): array
    {
        $phrase = mb_strtolower($query);
        $patterns = self::wordPatterns($phrase);

        $matches = [];
        foreach (self::index() as $entry) {
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

        return array_column($matches, 'entry');
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
    public static function matchCategories(string $wanted): array
    {
        $all = array_values(array_unique(array_column(self::index(), 'c')));
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

    /**
     * An entry's summary as the model should see it. The index stores some characters as HTML
     * entities ("&#x2019;" for ’), so decode them. And if the summary was cut, end it with " …".
     *
     * Why mark the cut: a model given "…armor and raw physical " will quietly finish the sentence
     * itself ("damage") and present the guess as fact. It can't tell a cut summary from a complete
     * one, but our code can, so the code should say so. Code is deterministic; the model's guess isn't.
     *
     * The mark alone doesn't tell the model what to do. That rule ("don't fill in the missing words;
     * call get_wiki_entry instead") is in INSTRUCTIONS in mcp-server.php and in this tool's
     * description above. The model reads those when it connects, then sees the mark in a result.
     */
    public static function summary(array $entry): string
    {
        $text = $entry['s'];
        $cut = mb_strlen($text) >= self::SUMMARY_CUT_LENGTH;
        if ($cut) {
            // The cut can land inside an entity, leaving a stub like "&#x2". Drop it before decoding.
            $text = preg_replace('/&#?[0-9A-Za-z]*$/', '', $text);
        }
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $cut ? "{$text} …" : $text;
    }

    /** The fields the model sees for each result, with readable names and a full URL. */
    public static function present(array $entry): array
    {
        $out = [
            'name' => $entry['n'],
            'category' => $entry['c'],
            'summary' => self::summary($entry),
            'url' => AldervastSite::url($entry['u']),
        ];
        if ($entry['k'] !== '') {
            $out['tags'] = $entry['k'];
        }

        return $out;
    }
}
