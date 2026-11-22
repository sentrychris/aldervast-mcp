<?php

declare(strict_types=1);

namespace Aldervast\Tools;

use InvalidArgumentException;

/**
 * The search_releases tool: the game's whole release history (changelog.html), searched by words or
 * read one version at a time.
 *
 * latest_release only shows the newest release. Questions such as "when were pets added?" or "what
 * changed in 1.10.0?" need the other 200 or so. The notes of one release can run to several
 * thousand characters, written as clauses separated by semicolons, so a search returns only the
 * clauses that contain the words, and a version returns that release's whole notes.
 */
final class SearchReleasesTool
{
    public const NAME = 'search_releases';

    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 30;

    /** At most this many matching clauses for each release, each at most MAX_CLAUSE_CHARS long. */
    private const MAX_CLAUSES = 3;

    private const MAX_CLAUSE_CHARS = 300;

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Searches the release history of Aldervast (every version, newest first) for words '
                . 'in the release notes, and returns the matching releases with their version, date and the '
                . 'parts of the notes that contain the words, best matches first. Use it for questions such as '
                . 'when something was added or changed. With version, it returns that release\'s whole notes '
                . 'instead. With neither, it lists the most recent releases.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Words to look for in the notes. Releases with more of the words come first.',
                    ],
                    'version' => [
                        'type' => 'string',
                        'description' => 'One exact version number: return its whole notes.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                        'description' => 'How many releases to return. Default ' . self::DEFAULT_LIMIT . '.',
                    ],
                ],
            ],
        ];
    }

    public static function run(array $input): string
    {
        $releases = LatestReleaseTool::all(AldervastSite::get(LatestReleaseTool::PATH));
        $url = AldervastSite::url(LatestReleaseTool::PATH);
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)));

        $version = ltrim(trim((string) ($input['version'] ?? '')), 'vV');
        if ($version !== '') {
            foreach ($releases as $release) {
                if ($release['version'] === $version) {
                    return Json::pretty($release + ['url' => $url]);
                }
            }
            throw new InvalidArgumentException("No release has the version \"{$version}\". The newest is "
                . ($releases[0]['version'] ?? 'unknown') . ' and the oldest ' . (end($releases)['version'] ?? 'unknown') . '.');
        }

        $words = self::words((string) ($input['query'] ?? ''));
        if ($words === []) {
            return Json::pretty(self::page(
                ['total' => count($releases)],
                array_map(fn (array $r) => ['version' => $r['version'], 'date' => $r['date']], $releases),
                $limit,
                $url
            ));
        }

        $found = [];
        foreach ($releases as $i => $release) {
            $matched = array_values(array_filter($words, fn (string $w) => self::has($release['notes'], $w)));
            if ($matched !== []) {
                $found[] = ['score' => count($matched), 'order' => $i, 'out' => [
                    'version' => $release['version'],
                    'date' => $release['date'],
                    'matched' => $matched,
                    'excerpts' => self::excerpts($release['notes'], $matched),
                ]];
            }
        }
        // More of the words first; between equals, the newer release first (the page lists newest first).
        usort($found, fn (array $a, array $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return Json::pretty(self::page(['query' => $words, 'total_matches' => count($found)], array_column($found, 'out'), $limit, $url));
    }

    /**
     * Whether the text has a word that starts with $word, ignoring case: "pet" finds "pets", but
     * "art" doesn't find "start".
     */
    private static function has(string $text, string $word): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '/iu', $text) === 1;
    }

    /** The query's words, in lower case, without repeats or one-letter words. */
    private static function words(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}.\'-]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) > 1)));
    }

    /**
     * The clauses of the notes (split at semicolons) that contain any of the words, most words first,
     * at most MAX_CLAUSES, each cut to MAX_CLAUSE_CHARS with " …" at the end.
     */
    private static function excerpts(string $notes, array $words): array
    {
        $clauses = [];
        foreach (array_map('trim', explode(';', $notes)) as $clause) {
            $hits = count(array_filter($words, fn (string $w) => self::has($clause, $w)));
            if ($hits > 0) {
                $clauses[] = ['hits' => $hits, 'text' => mb_strlen($clause) > self::MAX_CLAUSE_CHARS
                    ? rtrim(mb_substr($clause, 0, self::MAX_CLAUSE_CHARS)) . ' …' : $clause];
            }
        }
        // usort keeps equal clauses in their order in the notes.
        usort($clauses, fn (array $a, array $b) => $b['hits'] <=> $a['hits']);

        return array_column(array_slice($clauses, 0, self::MAX_CLAUSES), 'text');
    }

    private static function page(array $result, array $releases, int $limit, string $url): array
    {
        $result['releases'] = array_slice($releases, 0, $limit);
        if (count($releases) > $limit) {
            $result['not_shown'] = count($releases) - $limit;
        }

        return $result + ['url' => $url];
    }
}
