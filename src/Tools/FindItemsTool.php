<?php

declare(strict_types=1);

namespace Aldervast\Tools;

use InvalidArgumentException;

/**
 * The find_items tool: the items of one type up to a level, highest level first.
 *
 * "What's the best crossbow I can use at level 30?" used to take a search, then one get_wiki_entry
 * call for each result, just to read its level and requirements. This tool reads the two item pages
 * once (items.html for base items, uniques.html for unique items) and filters them in PHP, so the
 * model gets the whole answer in one call.
 *
 * Both pages use the same type names, such as "Crossbows" or "Body armour (evasion)". On items.html
 * the type is the heading above each table (the "sub" that WikiEntryParser returns for a table row);
 * on uniques.html it's each entry's Type fact.
 */
final class FindItemsTool
{
    public const NAME = 'find_items';

    private const DEFAULT_LIMIT = 15;

    private const MAX_LIMIT = 50;

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Lists the Aldervast items of a type, highest level first: base items (name, type, '
                . 'level, attribute requirements, base stats, implicit, sockets) and unique items (name, type, '
                . 'base item, level, modifiers, and where they drop or which reward gives them), each with its '
                . 'wiki URL. Use it to choose gear for a character, e.g. the best item of a type that a '
                . 'character of some level can use: pass max_level. Call it with no type to get the list of '
                . 'item types and how many items each has. A type matches exactly if it can, and otherwise '
                . 'matches every type whose name contains it, so one word can cover several types.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'An item type, or a word from one, such as "Crossbows", "boots" or '
                            . '"evasion". Case-insensitive. Leave it out to list the types.',
                    ],
                    'max_level' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => LevellingRouteTool::MAX_LEVEL,
                        'description' => 'Only items whose level is at most this, e.g. the character\'s level.',
                    ],
                    'min_level' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => LevellingRouteTool::MAX_LEVEL,
                        'description' => 'Only items whose level is at least this.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                        'description' => 'The most base items, and the most unique items, to return. Default '
                            . self::DEFAULT_LIMIT . '.',
                    ],
                ],
            ],
        ];
    }

    public static function run(array $input): string
    {
        $base = self::entries('wiki/items.html', fn (array $entry) => $entry['sub']);
        $uniques = self::entries('wiki/uniques.html', fn (array $entry) => $entry['facts']['Type'] ?? '');

        $wanted = trim((string) ($input['type'] ?? ''));
        if ($wanted === '') {
            return self::types($base, $uniques);
        }
        $types = self::matchTypes($wanted, array_merge(array_column($base, 'type'), array_column($uniques, 'type')));
        $min = self::level($input, 'min_level') ?? 1;
        $max = self::level($input, 'max_level') ?? LevellingRouteTool::MAX_LEVEL;
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)));

        $result = [
            'types' => $types,
            'rule' => "Items of these types with a level from {$min} to {$max}, highest level first.",
        ];
        foreach (['base_items' => $base, 'uniques' => $uniques] as $key => $items) {
            $found = self::select($items, $types, $min, $max);
            $result[$key] = array_map(fn (array $item) => $item['out'], array_slice($found, 0, $limit));
            if (count($found) > $limit) {
                $result[$key . '_not_shown'] = count($found) - $limit;
            }
        }

        return Json::pretty($result);
    }

    /**
     * Every entry on a page, as ['type', 'level', 'out'], where 'out' is what the model gets.
     *
     * @param callable(array): string $typeOf reads an entry's item type
     */
    private static function entries(string $page, callable $typeOf): array
    {
        $items = [];
        foreach ((new WikiEntryParser(AldervastSite::get($page), $page))->entries() as $id => $entry) {
            $facts = $entry['facts'];
            $type = $typeOf($entry);
            $level = (int) ($facts['Level'] ?? 0);
            $out = ['name' => $entry['name'], 'type' => $type, 'level' => $level];
            if ($page === 'wiki/items.html') {
                $out += array_filter([
                    'requires' => $facts['Requires'] ?? '',
                    'base_stats' => $facts['Base stats'] ?? '',
                    'implicit' => $facts['Implicit'] ?? '',
                    'sockets' => (int) ($facts['Sockets'] ?? 0),
                ]);
            } else {
                // A unique's text is its modifiers, and usually a line of lore at the end.
                $out += array_filter([
                    'base' => $facts['Base'] ?? '',
                    'text' => $entry['text'],
                    'drops_from' => $facts['Drops from'] ?? '',
                    'reward_from' => $facts['Reward from'] ?? '',
                    'chance' => $facts['Chance'] ?? '',
                ]);
            }
            $out['url'] = AldervastSite::url("{$page}#{$id}");
            $items[] = ['type' => $type, 'level' => $level, 'out' => $out];
        }

        return $items;
    }

    /** The items of these types within the levels, highest level first, then by name (ignoring case). */
    private static function select(array $items, array $types, int $min, int $max): array
    {
        $found = array_values(array_filter(
            $items,
            fn (array $item) => in_array($item['type'], $types, true) && $item['level'] >= $min && $item['level'] <= $max
        ));
        usort($found, fn (array $a, array $b) => [$b['level'], mb_strtolower($a['out']['name'])] <=> [$a['level'], mb_strtolower($b['out']['name'])]);

        return $found;
    }

    /**
     * The types that the input means: an exact match (ignoring case) if there is one, or else every
     * type whose name contains it. Throws with the list of types when nothing matches, so the model
     * can correct its next call.
     */
    private static function matchTypes(string $wanted, array $all): array
    {
        $all = array_values(array_unique($all));
        sort($all);
        $lower = mb_strtolower($wanted);

        $exact = array_values(array_filter($all, fn (string $t) => mb_strtolower($t) === $lower));
        if ($exact !== []) {
            return $exact;
        }
        $containing = array_values(array_filter($all, fn (string $t) => str_contains(mb_strtolower($t), $lower)));
        if ($containing !== []) {
            return $containing;
        }

        throw new InvalidArgumentException("No item type matches \"{$wanted}\". Item types are: " . implode(', ', $all));
    }

    /** Every type with its number of base and unique items, in the order of the items page. */
    private static function types(array $base, array $uniques): string
    {
        $counts = [];
        foreach ($base as $item) {
            $counts[$item['type']]['base_items'] = ($counts[$item['type']]['base_items'] ?? 0) + 1;
        }
        foreach ($uniques as $item) {
            $counts[$item['type']]['uniques'] = ($counts[$item['type']]['uniques'] ?? 0) + 1;
        }

        return Json::pretty([
            'message' => 'Call find_items again with one of these types (or a word from one), and max_level.',
            'types' => $counts,
        ]);
    }

    /**
     * A level input as a number, or null when it's missing. Throws for anything that isn't a whole
     * number from 1 to the highest level. find_skills uses it too, so both tools check levels alike.
     */
    public static function level(array $input, string $name): ?int
    {
        if (!isset($input[$name]) || $input[$name] === '') {
            return null;
        }
        $value = $input[$name];
        $whole = is_int($value) || (is_string($value) && ctype_digit($value));
        if (!$whole || (int) $value < 1 || (int) $value > LevellingRouteTool::MAX_LEVEL) {
            throw new InvalidArgumentException("{$name} must be a whole number from 1 to " . LevellingRouteTool::MAX_LEVEL);
        }

        return (int) $value;
    }
}
