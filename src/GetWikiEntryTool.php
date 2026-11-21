<?php

declare(strict_types=1);

/**
 * The get_wiki_entry tool: one full wiki entry as clean JSON.
 *
 * search_wiki only sees the search index, whose summaries are a single line (the Hollow Crypt's is
 * "Level 5 dungeon with 11 rooms"). The details a player actually asks about, such as the boss,
 * the entrance, the rewards, a quest's objectives or a boss's phases, are only on the full page.
 * This tool fetches that page, cuts out the one entry, and drops the layout HTML.
 *
 * Its "links" list is what lets the model follow the wiki the way a player clicks through it:
 * dungeon -> its boss -> the item the boss drops, one get_wiki_entry call each.
 */
final class GetWikiEntryTool
{
    public const NAME = 'get_wiki_entry';

    /** Limits that keep one result small, since it all lands in the model's context window. */
    private const MAX_CHARS_PER_VALUE = 600;

    private const MAX_LINES_PER_SECTION = 30;

    private const MAX_LINKS = 40;

    private const MAX_SUGGESTIONS = 8;

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Fetches one full entry from the Aldervast game wiki. Returns JSON with its name, '
                . 'sub (kind, level or group), description (lore), facts (label => value, such as Level, '
                . 'Entrance, Boss, Monsters, Given by, Needs, Rewards, or an item\'s stats), sections '
                . '(such as Objectives, Phases, Encounter journal, or a guide\'s steps) and links: the other '
                . 'entries it mentions, name => URL. Use it after search_wiki whenever the summary isn\'t '
                . 'enough, and call it again on a link to follow the wiki, e.g. from a dungeon to its boss, '
                . 'then to what the boss drops. Pass a URL from search_wiki (most reliable) or an exact '
                . 'entry name. Many names are shared (the Hollow Crypt is a place, a quest and a dungeon); '
                . 'then it lists the matches, and you call again with a URL or add a category.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url_or_name' => [
                        'type' => 'string',
                        'description' => 'A wiki URL from search_wiki, such as '
                            . '"https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt", '
                            . 'or an exact entry name, such as "Warden Ossric, the Hollowed".',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Optional, used with a name: picks between entries that share it, '
                            . 'e.g. "Dungeon" or "Quest". Same categories as search_wiki.',
                    ],
                ],
                'required' => ['url_or_name'],
            ],
        ];
    }

    public static function run(array $input): string
    {
        $target = trim((string) ($input['url_or_name'] ?? ''));
        if ($target === '') {
            throw new InvalidArgumentException('url_or_name is required, e.g. {"url_or_name": "The Hollow Crypt"}');
        }
        $categoryInput = trim((string) ($input['category'] ?? ''));
        $categories = $categoryInput === '' ? null : SearchWikiTool::matchCategories($categoryInput);

        if (str_contains($target, '.html#')) {
            $location = self::parseUrl($target);
        } else {
            $matches = self::findByName($target, $categories);
            if (count($matches) !== 1) {
                // Not found, or ambiguous. That's a normal answer, not an error: give the model choices.
                return self::choices($target, $categories, $matches);
            }
            $location = $matches[0]['u'];
        }

        [$page, $id] = explode('#', $location, 2);
        $entry = (new WikiEntryParser(AldervastSite::get($page), $page))->entry($id);
        if ($entry === null) {
            throw new RuntimeException("{$page} has no entry #{$id}. The wiki may have changed; use search_wiki to find it again.");
        }

        // Name, subtitle and URL first, so the JSON reads top-down.
        $entry = ['name' => $entry['name'], 'sub' => $entry['sub'], 'url' => AldervastSite::url($location)] + $entry;

        return pretty(self::shrink($entry));
    }

    /**
     * Accepts "https://aldervast.online/wiki/x.html#id", "/wiki/x.html#id" or "wiki/x.html#id",
     * and returns "wiki/x.html#id". Anything else is refused, so the tool only ever reads the wiki.
     */
    private static function parseUrl(string $url): string
    {
        $path = preg_replace('#^https?://(www\.)?aldervast\.online/#i', '', $url);
        $path = preg_replace('#^(\.\./|/)+#', '', $path);

        if (preg_match('#^wiki/[A-Za-z0-9_-]+\.html\#[A-Za-z0-9_-]+$#', $path) !== 1) {
            throw new InvalidArgumentException(
                "Not an Aldervast wiki entry URL: {$url}. Expected something like "
                . 'https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt'
            );
        }

        return $path;
    }

    /**
     * Index entries whose name matches, ignoring case and a leading "The ",
     * so "hollow crypt" finds both "Hollow Crypt" and "The Hollow Crypt".
     */
    private static function findByName(string $name, ?array $categories): array
    {
        $wanted = self::normaliseName($name);

        return array_values(array_filter(
            SearchWikiTool::index(),
            fn (array $e) => self::normaliseName($e['n']) === $wanted
                && ($categories === null || in_array($e['c'], $categories, true))
        ));
    }

    private static function normaliseName(string $name): string
    {
        return preg_replace('/^the\s+/', '', mb_strtolower(trim($name)));
    }

    /** The reply when a name matches several entries, or none (then we suggest the closest). */
    private static function choices(string $name, ?array $categories, array $matches): string
    {
        if ($matches !== []) {
            $message = 'Several entries have this name. Call get_wiki_entry again with one of these URLs.';
        } else {
            $matches = SearchWikiTool::search($name, $categories);
            $message = $matches === []
                ? 'No entry has this name, and nothing close was found. Check the spelling, or try '
                    . 'search_wiki with one or two distinctive words.'
                : 'No entry has exactly this name. These are the closest matches; call get_wiki_entry '
                    . 'again with one of their URLs.';
        }

        return pretty([
            'found' => false,
            'message' => $message,
            'matches' => array_map(
                fn (array $e) => SearchWikiTool::present($e),
                array_slice($matches, 0, self::MAX_SUGGESTIONS)
            ),
        ]);
    }

    /** Cuts long values, sections and link lists down to size, and drops empty fields. */
    private static function shrink(array $entry): array
    {
        $entry['description'] = self::cut($entry['description']);
        $entry['facts'] = array_map(fn (string $v) => self::cut($v), $entry['facts']);
        $entry['text'] = self::cutLines($entry['text']);
        $entry['sections'] = array_map(fn (array $lines) => self::cutLines($lines), $entry['sections']);

        $linkCount = count($entry['links']);
        if ($linkCount > self::MAX_LINKS) {
            $entry['links'] = array_slice($entry['links'], 0, self::MAX_LINKS, true);
            $entry['links_not_shown'] = $linkCount - self::MAX_LINKS;
        }

        // An empty [] would read as "this entry has no facts"; it's clearer to leave the key out.
        return array_filter($entry, fn ($value) => $value !== '' && $value !== []);
    }

    /** @param string[] $lines */
    private static function cutLines(array $lines): array
    {
        $kept = array_map(fn (string $line) => self::cut($line), array_slice($lines, 0, self::MAX_LINES_PER_SECTION));
        $left = count($lines) - count($kept);
        if ($left > 0) {
            $kept[] = "… {$left} more lines on the wiki page";
        }

        return $kept;
    }

    private static function cut(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_CHARS_PER_VALUE) {
            return $text;
        }
        $short = mb_substr($text, 0, self::MAX_CHARS_PER_VALUE);

        // End on a whole word, and say it was cut so the model doesn't take it as the full text.
        return preg_replace('/\s+\S*$/u', '', $short) . ' … (cut short; the rest is on the wiki page)';
    }
}
