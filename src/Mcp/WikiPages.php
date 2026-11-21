<?php

declare(strict_types=1);

namespace Aldervast\Mcp;

use Aldervast\Tools\AldervastSite;
use Aldervast\Tools\SearchWikiTool;
use Aldervast\Tools\WikiEntryParser;

/**
 * The MCP resources of this server: one resource for each wiki page, such as wiki/dungeons.html.
 *
 * Tools and resources differ in who decides to use them. The model calls a tool when it decides
 * that it needs one. A resource is chosen by the person, in the client: in Claude Code you type
 * "@" and pick it; in Claude Desktop you attach it. The client then reads the resource and puts its
 * contents into the chat. So a resource suits "here is the whole page, read it", and a tool suits
 * "find me the one entry that answers this".
 *
 * The list of pages comes from the wiki's search index (every page that an index entry points to),
 * so a new page on the site shows up here without a code change.
 *
 * Each resource has a URI. This server uses its own scheme, aldervast://, rather than the page's
 * https:// address. The MCP spec asks servers to use https:// only when the client could download
 * the resource itself, and this server sends something different from the page: its entries turned
 * into Markdown, without the HTML.
 */
final class WikiPages
{
    public const SCHEME = 'aldervast://';

    /** Markdown, because the client puts the text into the chat as it is, and a model reads Markdown well. */
    public const MIME_TYPE = 'text/markdown';

    /**
     * The reply to resources/list: one resource per wiki page, in alphabetical order.
     * Each has its URI, a name, a title and a description that says what is on the page.
     */
    public static function list(): array
    {
        $resources = [];
        foreach (self::pages() as $page => $categories) {
            $resources[] = [
                'uri' => self::SCHEME . $page,
                'name' => $page,
                'title' => 'Aldervast wiki: ' . implode(', ', array_keys($categories)),
                'description' => self::describe($categories) . ' The whole page as Markdown, from '
                    . AldervastSite::url($page) . '.',
                'mimeType' => self::MIME_TYPE,
            ];
        }

        return $resources;
    }

    /**
     * The reply to resources/read for this URI: the page as one Markdown text. Returns null if the
     * URI isn't one of the resources in list(). Only those pages can be read, so a URI such as
     * aldervast://../.env can't make the server fetch or read anything else.
     */
    public static function read(string $uri): ?array
    {
        $page = str_starts_with($uri, self::SCHEME) ? substr($uri, strlen(self::SCHEME)) : '';
        if (!isset(self::pages()[$page])) {
            return null;
        }

        return [['uri' => $uri, 'mimeType' => self::MIME_TYPE, 'text' => self::markdown($page)]];
    }

    /**
     * Every wiki page in the search index, in alphabetical order, as
     * page path => [category => number of entries in it], with the largest category first.
     */
    private static function pages(): array
    {
        $pages = [];
        foreach (SearchWikiTool::index() as $entry) {
            $page = explode('#', $entry['u'], 2)[0];
            $pages[$page][$entry['c']] = ($pages[$page][$entry['c']] ?? 0) + 1;
        }
        ksort($pages);
        foreach ($pages as &$categories) {
            arsort($categories);
        }

        return $pages;
    }

    /** "30 entries: 23 Group dungeon, 4 Raid, 2 Dungeon, 1 Trial." or "6 entries (Class)." */
    private static function describe(array $categories): string
    {
        $total = array_sum($categories);
        $entries = $total === 1 ? '1 entry' : "{$total} entries";
        if (count($categories) === 1) {
            return "{$entries} (" . array_key_first($categories) . ').';
        }
        $parts = [];
        foreach ($categories as $category => $count) {
            $parts[] = "{$count} {$category}";
        }

        return "{$entries}: " . implode(', ', $parts) . '.';
    }

    /**
     * The page as Markdown: a heading for the page, then one section per entry with its subtitle,
     * URL, description, facts, other text and sections, in page order.
     *
     * Each entry keeps its own URL, so that the model can cite it, or pass it to get_wiki_entry for
     * the entry's links. The links inside an entry are left out: a whole page with every link would
     * be much longer, and the tools can follow them when they're needed.
     */
    private static function markdown(string $page): string
    {
        $entries = (new WikiEntryParser(AldervastSite::get($page), $page))->entries();
        $count = count($entries);
        $lines = [
            "# Aldervast wiki: {$page}",
            '',
            'From ' . AldervastSite::url($page) . ', ' . ($count === 1 ? '1 entry' : "{$count} entries") . '.',
        ];

        foreach ($entries as $id => $entry) {
            array_push($lines, '', "## {$entry['name']}", '');
            if ($entry['sub'] !== '') {
                $lines[] = "*{$entry['sub']}*  ";
            }
            $lines[] = AldervastSite::url("{$page}#{$id}");
            if ($entry['description'] !== '') {
                array_push($lines, '', $entry['description']);
            }
            if ($entry['facts'] !== []) {
                $lines[] = '';
                foreach ($entry['facts'] as $label => $value) {
                    $lines[] = "- **{$label}:** {$value}";
                }
            }
            if ($entry['text'] !== []) {
                array_push($lines, '', ...$entry['text']);
            }
            foreach ($entry['sections'] as $heading => $sectionLines) {
                array_push($lines, '', "### {$heading}", '');
                foreach ($sectionLines as $line) {
                    $lines[] = "- {$line}";
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
