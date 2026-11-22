<?php

declare(strict_types=1);

namespace Aldervast\Tools;

use InvalidArgumentException;

/**
 * The find_skills tool: skill glyphs filtered by type, tag, what they need in hand and level, or
 * the support runes that fit one skill.
 *
 * Planning a build took one get_wiki_entry call per skill, just to read its tags, level and what
 * weapon it needs. This tool reads skills.html once and filters it in PHP. With supports_for, it
 * reads supports.html too, and returns the runes whose own facts say they can support that skill.
 *
 * How a rune is matched to a skill is this tool's reading of two facts on each rune:
 *   "Supports skills tagged"  e.g. "Any", "Physical" or "Spell|projectile|area" (| means "or")
 *   "Cannot support"          e.g. "Totem, Trap, Movement" (checked against the skill's type and tags)
 * The wiki lists these facts but doesn't explain them, so the result states the rule, and the model
 * can say where it comes from.
 */
final class FindSkillsTool
{
    public const NAME = 'find_skills';

    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 50;

    private const SUPPORT_RULE = 'Runes whose "Supports skills tagged" is Any or names one of the skill\'s tags '
        . '(| means or), and whose "Cannot support" names none of the skill\'s type or tags. This is the '
        . 'tool\'s reading of those two facts on each rune; the wiki lists them without explaining them.';

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Lists the skill glyphs that match every given filter, lowest level first: type, '
                . 'level, tags, what they need in hand (only when the wiki names something), damage, cooldown, '
                . 'mana, description and URL. Use it to find skills for a build, e.g. by tag, weapon (needs) or '
                . 'level. With supports_for, it lists the support runes that fit one skill instead. An unknown '
                . 'value returns the valid ones.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'A skill type. Case-insensitive.',
                    ],
                    'tag' => [
                        'type' => 'string',
                        'description' => 'A skill tag. Case-insensitive.',
                    ],
                    'needs' => [
                        'type' => 'string',
                        'description' => 'A word from what a skill needs in hand; only skills that need it are listed.',
                    ],
                    'max_level' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => LevellingRouteTool::MAX_LEVEL,
                        'description' => 'Only skills (or runes) up to this level, e.g. the character\'s level.',
                    ],
                    'min_level' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => LevellingRouteTool::MAX_LEVEL,
                        'description' => 'Only skills (or runes) from this level up.',
                    ],
                    'supports_for' => [
                        'type' => 'string',
                        'description' => 'A skill\'s exact name: list the runes that fit it instead. Other '
                            . 'filters except the levels are ignored.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                        'description' => 'How many to return. Default ' . self::DEFAULT_LIMIT . '.',
                    ],
                ],
            ],
        ];
    }

    public static function run(array $input): string
    {
        $skills = self::skills();
        $min = FindItemsTool::level($input, 'min_level') ?? 1;
        $max = FindItemsTool::level($input, 'max_level') ?? LevellingRouteTool::MAX_LEVEL;
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)));

        $for = trim((string) ($input['supports_for'] ?? ''));
        if ($for !== '') {
            return self::supportsFor($for, $skills, $min, $max, $limit);
        }

        $type = self::pick($input, 'type', array_column($skills, 'type'));
        $tag = self::pick($input, 'tag', array_merge(...array_column($skills, 'tags')));
        $needs = trim((string) ($input['needs'] ?? ''));
        if ($needs !== '') {
            $known = array_values(array_unique(array_filter(array_column($skills, 'needs'))));
            if (!array_filter($known, fn (string $n) => str_contains(mb_strtolower($n), mb_strtolower($needs)))) {
                throw new InvalidArgumentException("No skill needs \"{$needs}\". The wiki names these: " . implode(', ', $known)
                    . '. Every other skill lists no requirement.');
            }
        }

        $found = array_values(array_filter($skills, fn (array $s) => $s['level'] >= $min && $s['level'] <= $max
            && ($type === null || $s['type'] === $type)
            && ($tag === null || in_array($tag, $s['tags'], true))
            && ($needs === '' || str_contains(mb_strtolower($s['needs']), mb_strtolower($needs)))));

        return Json::pretty(self::page(['filters' => array_filter([
            'type' => $type, 'tag' => $tag, 'needs' => $needs, 'levels' => "{$min} to {$max}",
        ])], 'skills', $found, $limit));
    }

    /** The runes that fit one skill, within the levels. */
    private static function supportsFor(string $name, array $skills, int $min, int $max, int $limit): string
    {
        $matches = array_values(array_filter($skills, fn (array $s) => mb_strtolower($s['out']['name']) === mb_strtolower($name)));
        if ($matches === []) {
            $names = array_column(array_column($skills, 'out'), 'name');
            sort($names);
            throw new InvalidArgumentException("No skill is called \"{$name}\". Skills are: " . implode(', ', $names));
        }
        $skill = $matches[0];
        // Everything that "Cannot support" might name: the skill's tags and its type, in lower case.
        $kinds = array_map('mb_strtolower', array_merge($skill['tags'], [$skill['type']]));

        $runes = [];
        $page = 'wiki/supports.html';
        foreach ((new WikiEntryParser(AldervastSite::get($page), $page))->entries() as $id => $entry) {
            $facts = $entry['facts'];
            $level = (int) ($facts['Level'] ?? 0);
            $tagged = array_map('mb_strtolower', array_map('trim', explode('|', $facts['Supports skills tagged'] ?? '')));
            $cannot = array_map('mb_strtolower', array_map('trim', explode(',', $facts['Cannot support'] ?? '')));
            $fits = (in_array('any', $tagged, true) || array_intersect($tagged, $kinds) !== [])
                && array_intersect($cannot, $kinds) === [];
            if (!$fits || $level < $min || $level > $max) {
                continue;
            }
            $runes[] = ['level' => $level, 'out' => array_filter([
                'name' => $entry['name'],
                'level' => $level,
                'effects' => $entry['text'],
                'supports_skills_tagged' => $facts['Supports skills tagged'] ?? '',
                'cannot_support' => $facts['Cannot support'] ?? '',
                'mana_cost' => $facts['Mana cost'] ?? '',
                'fixed' => $facts['Fixed'] ?? '',
                'url' => AldervastSite::url("{$page}#{$id}"),
            ])];
        }

        return Json::pretty(self::page([
            'skill' => array_intersect_key($skill['out'], array_flip(['name', 'type', 'tags', 'url'])),
            'rule' => self::SUPPORT_RULE . " Levels {$min} to {$max}.",
        ], 'supports', $runes, $limit));
    }

    /** Every skill as ['type', 'tags', 'needs', 'level', 'out'], where 'out' is what the model gets. */
    private static function skills(): array
    {
        $page = 'wiki/skills.html';
        $skills = [];
        foreach ((new WikiEntryParser(AldervastSite::get($page), $page))->entries() as $id => $entry) {
            $facts = $entry['facts'];
            $tags = array_values(array_filter(array_map('trim', explode(',', $facts['Tags'] ?? ''))));
            $level = (int) ($facts['Level'] ?? 0);
            $skills[] = [
                'type' => $facts['Type'] ?? '',
                'tags' => $tags,
                'needs' => $facts['Needs'] ?? '',
                'level' => $level,
                'out' => array_filter([
                    'name' => $entry['name'],
                    'type' => $facts['Type'] ?? '',
                    'level' => $level,
                    'tags' => $tags,
                    'needs' => $facts['Needs'] ?? '',
                    'damage' => $facts['Damage'] ?? '',
                    'ailments' => $facts['Ailments'] ?? '',
                    'shape' => $facts['Shape'] ?? '',
                    'cooldown' => $facts['Cooldown'] ?? '',
                    'mana' => $facts['Mana'] ?? '',
                    'starting_skill_of' => $facts['Starting skill of'] ?? '',
                    'description' => $entry['description'],
                    'url' => AldervastSite::url("{$page}#{$id}"),
                ]),
            ];
        }

        return $skills;
    }

    /**
     * The value of a type or tag input, spelt as the wiki spells it, or null when it's missing.
     * Throws with the valid values when the input matches none, so the model can correct its call.
     */
    private static function pick(array $input, string $name, array $valid): ?string
    {
        $wanted = trim((string) ($input[$name] ?? ''));
        if ($wanted === '') {
            return null;
        }
        $valid = array_values(array_unique($valid));
        sort($valid);
        foreach ($valid as $value) {
            if (mb_strtolower($value) === mb_strtolower($wanted)) {
                return $value;
            }
        }

        throw new InvalidArgumentException("Unknown {$name} \"{$wanted}\". Valid values: " . implode(', ', $valid));
    }

    /** Sorts by level, then name (ignoring case), and keeps the first $limit, saying how many were left out. */
    private static function page(array $result, string $key, array $rows, int $limit): array
    {
        usort($rows, fn (array $a, array $b) => [$a['level'], mb_strtolower($a['out']['name'])] <=> [$b['level'], mb_strtolower($b['out']['name'])]);
        $result['total'] = count($rows);
        $result[$key] = array_map(fn (array $row) => $row['out'], array_slice($rows, 0, $limit));
        if (count($rows) > $limit) {
            $result[$key . '_not_shown'] = count($rows) - $limit;
        }

        return $result;
    }
}
