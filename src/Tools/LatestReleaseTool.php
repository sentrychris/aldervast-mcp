<?php

declare(strict_types=1);

namespace Aldervast\Tools;

use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * The latest_release tool: the newest version of the game, its date and its release notes.
 *
 * changelog.html is the game's release history. It is not a wiki page, so WikiEntryParser doesn't
 * read it. The page holds one list, newest release first, with one item per release:
 *
 *   <ol class="timeline">
 *     <li><b>1.14.95</b><time datetime="2026-11-20">2026-11-20</time>The server browser: …</li>
 *     <li><b>1.14.94</b>…</li>
 *   </ol>
 *
 * The tool returns the first item. The page is regenerated on each game release, so the cached copy
 * (up to an hour old, see AldervastSite) is fine: a release only becomes "latest" here once the
 * website has been updated anyway.
 */
final class LatestReleaseTool
{
    public const NAME = 'latest_release';

    /** The release history's path on the website, https://aldervast.online/changelog.html. */
    public const PATH = 'changelog.html';

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Returns the newest Aldervast release: version, date and notes, from the changelog. '
                . 'Use it for the current version, when the game was last updated, or what the update changed.',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
        ];
    }

    public static function run(array $input = []): string
    {
        $release = self::newest(AldervastSite::get(self::PATH));

        return Json::pretty($release + ['url' => AldervastSite::url(self::PATH)]);
    }

    /** The first release in the page's list, as version, date and notes. */
    private static function newest(string $html): array
    {
        $xpath = Html::xpath($html);
        $item = $xpath->query("//ol[contains(concat(' ', normalize-space(@class), ' '), ' timeline ')]/li")->item(0);
        // XPath's string() gives '' when the element is missing, so a missing version needs no extra check.
        $version = $item instanceof DOMElement ? trim($xpath->evaluate('string(./b)', $item)) : '';
        if ($version === '') {
            throw new RuntimeException('The changelog page has no releases in the layout this tool reads, '
                . 'so the website has probably changed. See ' . AldervastSite::url(self::PATH));
        }

        return self::read($xpath, $item, $version);
    }

    /**
     * Every release on the changelog page, newest first, as version, date and notes. search_releases
     * uses it. A list item without a version is skipped.
     */
    public static function all(string $html): array
    {
        $xpath = Html::xpath($html);
        $releases = [];
        foreach ($xpath->query("//ol[contains(concat(' ', normalize-space(@class), ' '), ' timeline ')]/li") as $item) {
            $version = $item instanceof DOMElement ? trim($xpath->evaluate('string(./b)', $item)) : '';
            if ($version !== '') {
                $releases[] = self::read($xpath, $item, $version);
            }
        }

        return $releases;
    }

    private static function read(DOMXPath $xpath, DOMElement $item, string $version): array
    {
        // The notes are the item's own text: everything except the version (<b>) and the date (<time>).
        $notes = '';
        foreach ($item->childNodes as $child) {
            if ($child->nodeName !== 'b' && $child->nodeName !== 'time') {
                $notes .= $child->textContent;
            }
        }

        return [
            'version' => $version,
            'date' => $xpath->evaluate('string(./time/@datetime)', $item),
            'notes' => trim(preg_replace('/\s+/u', ' ', $notes)),
        ];
    }
}
