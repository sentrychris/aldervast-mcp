<?php

declare(strict_types=1);

namespace Aldervast\Tools;

use InvalidArgumentException;

/**
 * The levelling_route tool: the regions, dungeons and quests that suit a character's level.
 *
 * A player asks "I'm level 23, where should I go?". The answer is spread over three wiki pages:
 * regions.html (each region's level range), dungeons.html (story dungeons, group dungeons, raids and
 * the trial) and quests.html (188 quests). Each entry there has a Level fact, so this tool reads
 * those three pages and keeps what suits the level:
 *
 *   regions   whose level range includes the level ("Levels: 18 to 26")
 *   dungeons  whose level is within RANGE of it ("Level: 22"), or whose level scales and the
 *             level is inside the range it scales over ("Your party's own (its average), from 10 to 60")
 *   quests    whose level is within RANGE of it, and that the player can already take: "Level: 23
 *             (you can take it from level 20)" only suits a player of level 20 or more
 *
 * For each, the result has what the model needs to plan a route (levels, region, who gives a quest,
 * the quest that must come first, the quest that leads into a dungeon) and the URL, so that
 * get_wiki_entry can fetch the full entry. It also reads guides.html, to mark the quests of the
 * main story with their chapter (see mainStoryChapters()), and worldbosses.html, to mark the quests
 * whose target is a world boss (see worldBossQuests()).
 */
final class LevellingRouteTool
{
    public const NAME = 'levelling_route';

    /** Characters go from level 1 to level 60. */
    public const MAX_LEVEL = 60;

    /** A dungeon or quest suits a player when its level is at most this far from theirs. */
    public const RANGE = 2;

    /** Quests are the longest list (up to 25 at one level), so it is cut at this length. */
    private const MAX_QUESTS = 20;

    /** The search index categories that are dungeons of some kind, all on dungeons.html. */
    private const DUNGEON_CATEGORIES = ['Dungeon', 'Group dungeon', 'Raid', 'Trial'];

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Lists the regions, dungeons (story dungeons, group dungeons, raids and trials) and '
                . 'quests in Aldervast that suit a character level, from the Level facts on the wiki. Use it '
                . 'when a player asks where to go, what to do, or what to run at their level. A dungeon or '
                . 'quest suits a level when its own level is within ' . self::RANGE . ' of it; a quest is '
                . 'only listed if the player can already take it; a region is listed if its level range '
                . 'includes the level. Each result has its level and wiki URL. Dungeons also have the quest '
                . 'that leads into them, where the wiki names one, and open, when the dungeon is only open '
                . 'at certain times. Quests also have their region, who gives '
                . 'them and the quest that must come first; a quest of the main story has main_story, the '
                . 'name of its chapter, and a quest without it is not part of the main story; a quest whose target '
                . 'is a world boss has world_boss, the name of the boss. Call get_wiki_entry on a URL '
                . 'for details.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'level' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_LEVEL,
                        'description' => 'The character\'s level, from 1 to ' . self::MAX_LEVEL . '.',
                    ],
                ],
                'required' => ['level'],
            ],
        ];
    }

    public static function run(array $input): string
    {
        $level = self::level($input);
        $index = SearchWikiTool::index();

        $regions = [];
        foreach (self::entries('wiki/regions.html', ['Region'], $index) as [$item, $entry]) {
            [$from, $to] = self::numbers($entry['facts']['Levels'] ?? '') + [0, 0];
            if ($from <= $level && $level <= $to) {
                $regions[] = ['name' => $item['n'], 'levels' => "{$from} to {$to}", 'url' => AldervastSite::url($item['u'])];
            }
        }

        $dungeons = [];
        foreach (self::entries('wiki/dungeons.html', self::DUNGEON_CATEGORIES, $index) as [$item, $entry]) {
            $fact = $entry['facts']['Level'] ?? '';
            $numbers = self::numbers($fact);
            // One number is a fixed level. Two ("from 10 to 60") mean the dungeon scales to the party.
            $suits = count($numbers) === 1
                ? abs($numbers[0] - $level) <= self::RANGE
                : count($numbers) === 2 && $numbers[0] <= $level && $level <= $numbers[1];
            if ($suits) {
                $dungeons[] = array_filter([
                    'name' => $item['n'],
                    'kind' => $item['c'],
                    'level' => count($numbers) === 1 ? $numbers[0] : $fact,
                    // The quest that sends the player into this dungeon, so the model doesn't have to
                    // guess the link between them from their names.
                    'quests' => $entry['facts']['Quests'] ?? null,
                    // When a dungeon is only open part of the year. Without this, a dungeon that
                    // suits the level looks as if it is always open.
                    'open' => $entry['facts']['Open'] ?? null,
                    'url' => AldervastSite::url($item['u']),
                ], fn ($value) => $value !== null);
            }
        }
        usort($dungeons, fn (array $a, array $b) => [is_int($a['level']) ? $a['level'] : $level, $a['name']] <=> [is_int($b['level']) ? $b['level'] : $level, $b['name']]);

        $chapters = self::mainStoryChapters();
        $worldBosses = self::worldBossQuests();
        $quests = [];
        foreach (self::entries('wiki/quests.html', ['Quest'], $index) as [$item, $entry]) {
            // "23 (you can take it from level 20)": the quest's level, then the lowest level that can
            // take it. A few early quests give no lowest level, so anyone can take them.
            $numbers = self::numbers($entry['facts']['Level'] ?? '');
            if ($numbers === []) {
                continue;
            }
            $questLevel = $numbers[0];
            $takeFrom = $numbers[1] ?? 1;
            if (abs($questLevel - $level) <= self::RANGE && $takeFrom <= $level) {
                $url = AldervastSite::url($item['u']);
                $quests[] = array_filter([
                    'name' => $item['n'],
                    'level' => $questLevel,
                    'from_level' => $takeFrom,
                    'region' => $entry['facts']['Region'] ?? null,
                    'given_by' => $entry['facts']['Given by'] ?? null,
                    'needs' => $entry['facts']['Needs'] ?? null,
                    'main_story' => $chapters[$url] ?? null,
                    'world_boss' => $worldBosses[$url] ?? null,
                    'url' => $url,
                ], fn ($value) => $value !== null);
            }
        }
        usort($quests, fn (array $a, array $b) => [$a['level'], $a['name']] <=> [$b['level'], $b['name']]);

        $result = [
            'level' => $level,
            'rule' => "Regions whose level range includes {$level}; dungeons and quests whose level is within "
                . self::RANGE . " of {$level}; only quests that a level {$level} character can already take.",
            'regions' => $regions,
            'dungeons' => $dungeons,
            'quests' => array_slice($quests, 0, self::MAX_QUESTS),
        ];
        if (count($quests) > self::MAX_QUESTS) {
            $result['quests_not_shown'] = count($quests) - self::MAX_QUESTS;
        }
        if ($regions === []) {
            // The regions' level ranges stop at 34, but later content still takes place in them, so
            // say where the suited quests are rather than leave "no region" standing on its own.
            $questRegions = array_values(array_unique(array_filter(array_column($quests, 'region'))));
            sort($questRegions);
            $result['note'] = "No region's level range on the wiki includes level {$level}."
                . ($questRegions === [] ? '' : ' The quests listed are in: ' . implode(', ', $questRegions) . '.');
        }

        return Json::pretty($result);
    }

    /** The level from the input: a whole number from 1 to MAX_LEVEL. A number written as text ("23") is accepted too. */
    private static function level(array $input): int
    {
        $value = $input['level'] ?? null;
        $valid = is_int($value) || (is_string($value) && ctype_digit($value));
        if (!$valid || (int) $value < 1 || (int) $value > self::MAX_LEVEL) {
            throw new InvalidArgumentException('level must be a whole number from 1 to ' . self::MAX_LEVEL . ', e.g. {"level": 23}');
        }

        return (int) $value;
    }

    /**
     * The quests of the main story: quest URL => the name of its chapter, e.g. "The Unlit Road".
     *
     * Quest entries don't say whether a quest is part of the main story, but guides.html has one
     * guide for each chapter, with the subtitle "Chapter one of the main story" and so on. Each step
     * of a chapter is one line of the guide's text, and it starts with the quest's name and level:
     * "A Light in Harrowford level 1 Take it from …". A chapter guide also links to side quests that
     * are on the way, so a link alone doesn't make a quest part of the main story; the quest must
     * start one of the steps.
     */
    private static function mainStoryChapters(): array
    {
        $chapters = [];
        $guides = (new WikiEntryParser(AldervastSite::get('wiki/guides.html'), 'wiki/guides.html'))->entries();
        foreach ($guides as $guide) {
            if (stripos($guide['sub'], 'main story') === false) {
                continue;
            }
            foreach ($guide['links'] as $name => $url) {
                foreach ($guide['text'] as $line) {
                    if (str_contains($url, '/quests.html#') && str_starts_with($line, "{$name} level ")) {
                        $chapters[$url] = $guide['name'];
                    }
                }
            }
        }

        return $chapters;
    }

    /**
     * The quests whose target is a world boss: quest URL => the boss's name, e.g. "Calathrax, Elder Wyrm".
     *
     * A quest entry names its target only in the text of an objective ("Help slay Calathrax"), with
     * no link to the boss. worldbosses.html links the other way: a world boss that is fought for a
     * quest links to that quest. The world bosses that wake on a timer have no quest, so they link
     * to none.
     */
    private static function worldBossQuests(): array
    {
        $quests = [];
        $bosses = (new WikiEntryParser(AldervastSite::get('wiki/worldbosses.html'), 'wiki/worldbosses.html'))->entries();
        foreach ($bosses as $boss) {
            foreach ($boss['links'] as $url) {
                if (str_contains($url, '/quests.html#')) {
                    $quests[$url] = $boss['name'];
                }
            }
        }

        return $quests;
    }

    /**
     * The search index entries in these categories on this page, each with its parsed wiki entry:
     * a list of [index entry, wiki entry] pairs. The page is read once, however many entries it has.
     */
    private static function entries(string $page, array $categories, array $index): array
    {
        $parsed = (new WikiEntryParser(AldervastSite::get($page), $page))->entries();
        $pairs = [];
        foreach ($index as $item) {
            [$itemPage, $id] = explode('#', $item['u'], 2) + [1 => ''];
            if ($itemPage === $page && in_array($item['c'], $categories, true) && isset($parsed[$id])) {
                $pairs[] = [$item, $parsed[$id]];
            }
        }

        return $pairs;
    }

    /** Every whole number in a text, in order: "23 (you can take it from level 20)" gives [23, 20]. */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d+/', $text, $matches);

        return array_map('intval', $matches[0]);
    }
}
