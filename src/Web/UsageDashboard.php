<?php

declare(strict_types=1);

namespace Aldervast\Web;

/**
 * The usage page at /usage: what the chatbot answered and what it cost, from UsageReport::days().
 * public/index.php shows it only to someone with the password in ALDERVAST_USAGE_PASSWORD.
 *
 * The page is plain HTML with an SVG bar chart, built here in PHP, so it needs no JavaScript
 * library. A few lines of JavaScript at the end only show the hover details on the chart.
 */
final class UsageDashboard
{
    private const WIDTH = 720;

    private const HEIGHT = 220;

    /** Space around the plot for the axis labels: left, right, top, bottom. */
    private const MARGIN = [52, 8, 12, 28];

    /**
     * @param array $days   UsageReport::days(), oldest first; the last one is today
     * @param int   $perDay the daily limit on questions (ALDERVAST_CHAT_PER_DAY)
     */
    public static function render(array $days, int $perDay): string
    {
        $today = end($days);
        $totals = UsageReport::totals($days);
        $asked = UsageReport::asked($totals);
        $perAnswer = $totals['answered'] > 0 ? self::money($totals['cost'] / $totals['answered'], 3) : '–';
        $count = count($days);

        $tiles = self::tile('Questions today', (string) UsageReport::asked($today), "of {$perDay} allowed")
            . self::tile('Cost today', $today['cost'] === null ? '?' : self::money($today['cost']), self::plural($today['answered'], 'answer'))
            . self::tile("Cost, last {$count} days", self::money($totals['cost']), self::plural($asked, 'question'))
            . self::tile('Cost per answer', $perAnswer, "average, last {$count} days")
            . self::tile("Refused, last {$count} days", (string) $totals['limited'], 'by the rate limits');

        $note = $totals['unknown'] ? '<p class="note">Some answers came from a model with no known price, so their cost is missing.</p>' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>Chatbot usage</title><style>' . self::css() . '</style></head><body><main>'
            . '<h1>Chatbot usage</h1>'
            . "<p class=\"intro\">The Aldervast chatbot over the last {$count} days (UTC). Costs are estimated "
            . 'from the token counts; the real bill is in the Claude Console.</p>'
            . "<section class=\"tiles\">{$tiles}</section>"
            . '<section class="panel"><h2>Cost per day</h2>' . self::chart($days) . '</section>'
            . '<section class="panel"><h2>By day</h2>' . self::table($days) . $note . '</section>'
            . '<p class="foot">Only what each question used is logged: never the question, the answer or the visitor.</p>'
            . '</main><div class="tip" role="tooltip" hidden></div><script>' . self::script() . '</script></body></html>';
    }

    private static function tile(string $label, string $value, string $detail): string
    {
        return '<div class="tile"><div class="label">' . self::e($label) . '</div><div class="value">'
            . self::e($value) . '</div><div class="detail">' . self::e($detail) . '</div></div>';
    }

    /** A bar for each day's cost, with a gridline and label at 0, half and the top of the scale. */
    private static function chart(array $days): string
    {
        [$left, $right, $top, $bottom] = self::MARGIN;
        $plotWidth = self::WIDTH - $left - $right;
        $plotHeight = self::HEIGHT - $top - $bottom;
        $max = self::niceMax(max(array_map(fn (array $d) => $d['cost'] ?? 0.0, $days)));
        $band = $plotWidth / count($days);

        $svg = '';
        foreach ([0, 0.5, 1] as $step) {
            $y = round($top + $plotHeight * (1 - $step), 1);
            $svg .= "<line class=\"grid\" x1=\"{$left}\" x2=\"" . (self::WIDTH - $right) . "\" y1=\"{$y}\" y2=\"{$y}\"/>"
                . '<text class="axis" x="' . ($left - 6) . '" y="' . ($y + 4) . '" text-anchor="end">'
                . self::money($max * $step) . '</text>';
        }
        foreach ($days as $i => $day) {
            $x = $left + $i * $band;
            $cost = $day['cost'] ?? 0.0;
            $height = $max > 0 ? $plotHeight * $cost / $max : 0;
            // A 2px gap between bars, and a rounded top of up to 4px.
            $barX = round($x + 1, 1);
            $barWidth = round(max(1, $band - 2), 1);
            if ($height > 0) {
                $svg .= '<path class="bar" d="' . self::bar($barX, $top + $plotHeight, $barWidth, $height) . '"/>';
            }
            // An invisible target over the whole column, so hovering near a short bar still works.
            $tip = self::label($day['day']) . ': ' . ($day['cost'] === null ? 'cost unknown' : self::money($cost)) . ', '
                . self::plural($day['answered'], 'answer');
            $svg .= '<rect class="hit" x="' . round($x, 1) . "\" y=\"{$top}\" width=\"" . round($band, 1)
                . "\" height=\"{$plotHeight}\" data-tip=\"" . self::e($tip) . '"><title>' . self::e($tip) . '</title></rect>';
            // A date under the first day, every 7th day and the last day, but not a 7th day so close
            // to the last that their dates would overlap.
            $last = count($days) - 1;
            if (($i % 7 === 0 && $i <= $last - 4) || $i === $last) {
                $svg .= '<text class="axis" x="' . round($x + $band / 2, 1) . '" y="' . (self::HEIGHT - 8)
                    . '" text-anchor="middle">' . self::e(self::label($day['day'])) . '</text>';
            }
        }

        return '<svg class="chart" viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" role="img" '
            . 'aria-label="Estimated cost per day, in US dollars. The table below has the same numbers.">' . $svg . '</svg>';
    }

    /** The path of one bar: straight sides, a rounded top, sitting on the baseline. */
    private static function bar(float $x, float $base, float $width, float $height): string
    {
        $r = min(4, $width / 2, $height);
        $topY = round($base - $height, 1);
        $right = round($x + $width, 1);

        return "M{$x},{$base}V" . round($topY + $r, 1) . "Q{$x},{$topY} " . round($x + $r, 1) . ",{$topY}"
            . 'H' . round($right - $r, 1) . "Q{$right},{$topY} {$right}," . round($topY + $r, 1) . "V{$base}Z";
    }

    /** The days with any activity, newest first. */
    private static function table(array $days): string
    {
        $rows = '';
        foreach (array_reverse($days) as $day) {
            if (UsageReport::asked($day) + $day['limited'] === 0) {
                continue;
            }
            $rows .= '<tr><td>' . self::e($day['day']) . '</td><td>' . $day['answered'] . '</td><td>' . $day['no_answer']
                . '</td><td>' . $day['failed'] . '</td><td>' . $day['limited'] . '</td><td>' . number_format($day['input_tokens'])
                . '</td><td>' . number_format($day['output_tokens']) . '</td><td>'
                . ($day['cost'] === null ? '?' : self::money($day['cost'])) . '</td></tr>';
        }
        if ($rows === '') {
            return '<p class="empty">No questions yet in this period.</p>';
        }

        return '<div class="scroll"><table><thead><tr><th>Day</th><th>Answered</th><th>No answer</th><th>Failed</th>'
            . '<th>Refused</th><th>Input tokens</th><th>Output tokens</th><th>Cost</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    /** A round number at or above $value for the top of the scale, so the labels read well. */
    private static function niceMax(float $value): float
    {
        foreach ([0.05, 0.1, 0.2, 0.5, 1, 2, 5, 10, 20, 50, 100] as $step) {
            if ($value <= $step) {
                return (float) $step;
            }
        }

        return ceil($value / 100) * 100;
    }

    private static function money(float $dollars, int $decimals = 2): string
    {
        return '$' . number_format($dollars, $decimals);
    }

    private static function plural(int $n, string $word): string
    {
        return $n . ' ' . $word . ($n === 1 ? '' : 's');
    }

    /** "2026-11-22" -> "22 Nov". */
    private static function label(string $day): string
    {
        return gmdate('j M', (int) strtotime($day . ' UTC'));
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<'CSS'
:root { color-scheme: light; --bg: #f6f5f2; --surface: #fcfcfb; --line: #e4e2dc; --text: #0b0b0b;
  --text-2: #52514e; --muted: #77756f; --series: #2a78d6; --grid: #ebe9e4; }
@media (prefers-color-scheme: dark) {
  :root { color-scheme: dark; --bg: #121211; --surface: #1a1a19; --line: #2e2d2a; --text: #f0efec;
    --text-2: #c3c2b7; --muted: #94938a; --series: #3987e5; --grid: #2a2927; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 900px; margin: 0 auto; padding: 28px 16px 40px; }
h1 { font-size: 1.5rem; margin: 0 0 4px; }
h2 { font-size: 1rem; margin: 0 0 12px; }
.intro, .foot, .note, .empty { color: var(--text-2); }
.foot { font-size: .85rem; margin-top: 24px; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 20px 0; }
.tile, .panel { background: var(--surface); border: 1px solid var(--line); border-radius: 10px; }
.tile { padding: 14px 16px; }
.label { color: var(--text-2); font-size: .85rem; }
.value { font-size: 1.7rem; font-weight: 600; font-variant-numeric: tabular-nums; margin: 2px 0; }
.detail { color: var(--muted); font-size: .8rem; }
.panel { padding: 16px; margin-bottom: 16px; }
.chart { width: 100%; height: auto; display: block; }
.grid { stroke: var(--grid); stroke-width: 1; }
.axis { fill: var(--muted); font-size: 11px; font-variant-numeric: tabular-nums; }
.bar { fill: var(--series); }
.hit { fill: transparent; cursor: default; }
.hit:hover { fill: var(--text); fill-opacity: .06; }
.scroll { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; font-variant-numeric: tabular-nums; font-size: .9rem; }
th, td { padding: 6px 10px; text-align: right; border-bottom: 1px solid var(--line); white-space: nowrap; }
th:first-child, td:first-child { text-align: left; }
th { color: var(--text-2); font-weight: 600; }
.tip { position: fixed; pointer-events: none; background: var(--text); color: var(--bg); padding: 4px 8px;
  border-radius: 6px; font-size: .8rem; white-space: nowrap; }
CSS;
    }

    /** Shows a column's details next to the pointer. The <title> in each column does the same without JavaScript. */
    private static function script(): string
    {
        return <<<'JS'
const tip = document.querySelector('.tip');
document.querySelectorAll('.hit').forEach((hit) => {
  hit.querySelector('title').remove();
  hit.addEventListener('pointermove', (e) => {
    tip.textContent = hit.dataset.tip;
    tip.hidden = false;
    tip.style.left = Math.min(e.clientX + 12, innerWidth - tip.offsetWidth - 8) + 'px';
    tip.style.top = (e.clientY - 34) + 'px';
  });
  hit.addEventListener('pointerleave', () => { tip.hidden = true; });
});
JS;
    }
}
