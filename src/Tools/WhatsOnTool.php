<?php

declare(strict_types=1);

namespace Aldervast\Tools;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use InvalidArgumentException;
use Throwable;

/**
 * The whats_on tool: what's happening in Aldervast at a given time (now, by default).
 *
 * The wiki states schedules as facts, and this tool reads them from six pages and works out what
 * they mean at that moment. Every time on the wiki is in UTC (server time), and so is every time here.
 *
 *   Festivals       festivals.html, "When": "Every year, 20 November to 6 January"
 *   Seasons         endgame.html, "Runs": "2026-11-01 to 2027-02-01"
 *   World bosses    worldbosses.html, "Wakes": "Every hour at xx:20"
 *   Weekly resets   worldbosses.html and hourglass.html, "Resets": "Wednesday at 07:00 UTC"
 *   Hourglass omens hourglass.html, the rotation table (<table data-hg-rot="8">, one row per week)
 *   Timed dungeons  dungeons.html, "Open": "Only while ... runs (20 November to 6 January)"
 *
 * A schedule in a form that the tool can't read (such as "at a quarter past and a quarter to the
 * hour") goes under "other", word for word, so the model can read it itself rather than the tool
 * guessing. That includes the When facts on professions.html.
 */
final class WhatsOnTool
{
    public const NAME = 'whats_on';

    private const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august',
        'september', 'october', 'november', 'december'];

    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    private const WEEK_SECONDS = 604800;

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Says what is happening in Aldervast at a moment (now, by default), from the '
                . 'schedules on the wiki: festivals and seasons running, each world boss\'s next wake time, '
                . 'the next weekly resets, this week\'s Hourglass omens, dungeons open only at certain times, '
                . 'and other schedules as the wiki words them. All times are UTC. Use it for questions about '
                . 'what is on, when something happens next, or whether an event is running.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'at' => [
                        'type' => 'string',
                        'description' => 'Optional: another moment to ask about, as a UTC date or date-time, '
                            . 'such as "2026-12-24" or "2026-12-24T18:30". Leave it out for now.',
                    ],
                ],
            ],
        ];
    }

    public static function run(array $input): string
    {
        $now = self::moment($input);
        $t = $now->getTimestamp();

        $result = ['now' => $now->format('Y-m-d H:i') . ' UTC, ' . $now->format('l')];
        $other = [];

        foreach (self::entries('wiki/festivals.html') as [$entry, $url]) {
            if (isset($entry['facts']['When'])) {
                $result['festivals'][] = ['name' => $entry['name'], 'when' => $entry['facts']['When']]
                    + self::yearly($entry['facts']['When'], $now) + ['url' => $url];
            }
        }
        foreach (self::entries('wiki/endgame.html') as [$entry, $url]) {
            if (isset($entry['facts']['Runs']) && ($dates = self::isoRange($entry['facts']['Runs'])) !== null) {
                [$from, $to] = $dates;
                $result['seasons'][] = ['name' => $entry['name'], 'runs' => $entry['facts']['Runs'],
                    'running' => $now >= $from && $now < $to->modify('+1 day'), 'url' => $url];
            }
        }
        foreach (self::entries('wiki/worldbosses.html') as [$entry, $url]) {
            $wakes = $entry['facts']['Wakes'] ?? '';
            if (preg_match('/\bxx:(\d{2})\b/', $wakes, $m) === 1) {
                $next = (int) (floor($t / 3600) * 3600) + (int) $m[1] * 60;
                $next += $next <= $t ? 3600 : 0;
                $result['world_bosses'][] = ['name' => $entry['name'], 'level' => $entry['facts']['Level'] ?? '',
                    'wakes' => $wakes, 'next_wake' => gmdate('H:i', $next) . ' UTC',
                    'in_minutes' => intdiv($next - $t + 59, 60), 'url' => $url];
            }
        }
        foreach (['wiki/worldbosses.html', 'wiki/hourglass.html'] as $page) {
            foreach (self::entries($page) as [$entry, $url]) {
                $resets = $entry['facts']['Resets'] ?? '';
                if (preg_match('/\b(' . implode('|', self::DAYS) . ') at (\d{2}):(\d{2}) UTC\b/i', $resets, $m) === 1) {
                    $result['weekly_resets'][] = ['name' => $entry['name'], 'resets' => $resets,
                        'next' => self::nextWeekly($now, $m[1], (int) $m[2], (int) $m[3]), 'url' => $url];
                }
            }
        }
        $omens = self::omens($t);
        if ($omens !== null) {
            $result['hourglass_omens'] = $omens;
        }
        foreach (self::entries('wiki/dungeons.html') as [$entry, $url]) {
            if (isset($entry['facts']['Open'])) {
                $open = self::yearly($entry['facts']['Open'], $now);
                $result['timed_dungeons'][] = ['name' => $entry['name'], 'open' => $entry['facts']['Open']]
                    + ($open === [] ? [] : ['open_now' => $open['running']]) + ['url' => $url];
            }
        }
        foreach (self::entries('wiki/professions.html') as [$entry, $url]) {
            if (isset($entry['facts']['When'])) {
                $other[] = ['name' => $entry['name'], 'when' => $entry['facts']['When'], 'url' => $url];
            }
        }
        if ($other !== []) {
            $result['other'] = $other;
        }
        usort($result['world_bosses'], fn (array $a, array $b) => $a['in_minutes'] <=> $b['in_minutes']);

        return Json::pretty($result);
    }

    /** The moment to ask about: "at" if given, or else now. Always UTC. */
    private static function moment(array $input): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $at = trim((string) ($input['at'] ?? ''));
        if ($at === '') {
            return new DateTimeImmutable('now', $utc);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?Z?$/', $at) !== 1) {
            throw new InvalidArgumentException('at must be a UTC date or date-time, such as "2026-12-24" or "2026-12-24T18:30"');
        }
        try {
            $moment = new DateTimeImmutable(rtrim($at, 'Z'), $utc);
        } catch (Throwable) {
            throw new InvalidArgumentException("at is not a real date: {$at}");
        }
        // PHP moves an impossible date on (30 February becomes 2 March), so check that it stayed put.
        if ($moment->format('Y-m-d') !== substr($at, 0, 10)) {
            throw new InvalidArgumentException("at is not a real date: {$at}");
        }

        return $moment;
    }

    /** Every entry on a page, as [entry, url]. */
    private static function entries(string $page): array
    {
        $out = [];
        foreach ((new WikiEntryParser(AldervastSite::get($page), $page))->entries() as $id => $entry) {
            $out[] = [$entry, AldervastSite::url("{$page}#{$id}")];
        }

        return $out;
    }

    /**
     * For a yearly range in the text, such as "20 November to 6 January": whether it's running at
     * $now (both days included), and the date it next starts or ends. [] when the text has no such range.
     */
    private static function yearly(string $text, DateTimeImmutable $now): array
    {
        $months = implode('|', self::MONTHS);
        if (preg_match("/(\\d{1,2}) ({$months}) to (\\d{1,2}) ({$months})/i", $text, $m) !== 1) {
            return [];
        }
        $start = [array_search(strtolower($m[2]), self::MONTHS, true) + 1, (int) $m[1]];
        $end = [array_search(strtolower($m[4]), self::MONTHS, true) + 1, (int) $m[3]];
        $today = [(int) $now->format('n'), (int) $now->format('j')];
        $year = (int) $now->format('Y');

        // A range that crosses the new year (November to January) runs from its start to the year's
        // end, and from the year's start to its end.
        $wraps = $start > $end;
        $running = $wraps ? ($today >= $start || $today <= $end) : ($today >= $start && $today <= $end);
        if ($running) {
            $endYear = $wraps && $today >= $start ? $year + 1 : $year;

            return ['running' => true, 'ends' => sprintf('%04d-%02d-%02d', $endYear, ...$end)];
        }

        return ['running' => false, 'starts' => sprintf('%04d-%02d-%02d', $today < $start ? $year : $year + 1, ...$start)];
    }

    /** "2026-11-01 to 2027-02-01" as two dates, or null. */
    private static function isoRange(string $text): ?array
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2}) to (\d{4}-\d{2}-\d{2})/', $text, $m) !== 1) {
            return null;
        }
        $utc = new DateTimeZone('UTC');

        return [new DateTimeImmutable($m[1], $utc), new DateTimeImmutable($m[2], $utc)];
    }

    /** The next time a weekly reset happens after $now, such as "2026-11-25 07:00 UTC, Wednesday". */
    private static function nextWeekly(DateTimeImmutable $now, string $day, int $hour, int $minute): string
    {
        $next = $now->modify('this ' . strtolower($day))->setTime($hour, $minute);
        if ($next <= $now) {
            $next = $next->modify('+1 week');
        }

        return $next->format('Y-m-d H:i') . ' UTC, ' . $next->format('l');
    }

    /**
     * This week's row of the Hourglass omen rotation, or null if the page has no rotation table.
     *
     * The page says that a week runs from Monday 00:00 UTC and that the rotation repeats. Which row
     * is "this week" comes from the site's own script (js/site.js), which marks it on the page with
     * the same sum: weeks since the Unix epoch, counted from a Monday (the epoch was a Thursday, so 3
     * days are added first), then the remainder after dividing by the rotation's length.
     */
    private static function omens(int $t): ?array
    {
        $page = 'wiki/hourglass.html';
        $xpath = Html::xpath(AldervastSite::get($page));
        $table = $xpath->query('//table[@data-hg-rot]')->item(0);
        $length = $table instanceof DOMElement ? (int) $table->getAttribute('data-hg-rot') : 0;
        if ($length < 1) {
            return null;
        }
        $week = intdiv($t + 3 * 86400, self::WEEK_SECONDS) % $length;
        $row = $xpath->query(".//tr[@data-wk='{$week}']", $table)->item(0);
        if (!$row instanceof DOMElement) {
            return null;
        }
        $headings = array_map(fn ($th) => trim($th->textContent), iterator_to_array($xpath->query('.//thead//th', $table)));
        $omens = [];
        foreach (iterator_to_array($xpath->query('./td', $row)) as $i => $cell) {
            if ($i > 0) {
                $omens[$headings[$i] ?? "Column {$i}"] = trim($cell->textContent);
            }
        }
        $weekStart = intdiv($t + 3 * 86400, self::WEEK_SECONDS) * self::WEEK_SECONDS - 3 * 86400;
        // The URL points at the heading above the table, so it lands on the rotation.
        $heading = $xpath->query('preceding::h2[@id][1]', $table)->item(0);
        $anchor = $heading instanceof DOMElement ? '#' . $heading->getAttribute('id') : '';

        return [
            'week' => $week + 1,
            'of' => $length,
            'omens' => $omens,
            'until' => gmdate('Y-m-d H:i', $weekStart + self::WEEK_SECONDS) . ' UTC, Monday',
            'url' => AldervastSite::url($page . $anchor),
        ];
    }
}
