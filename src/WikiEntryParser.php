<?php

declare(strict_types=1);

/**
 * Turns one entry on an Aldervast wiki page into a plain PHP array.
 *
 * A wiki page (e.g. wiki/dungeons.html) holds many entries. Each entry is an HTML element. Its id
 * is the part after "#" in the entry's URL. An entry comes in one of two forms:
 *
 *   1. An <article class="entry"> (dungeons, bosses, quests, guides, regions, most pages):
 *        <h3> name, <span class="sub"> subtitle, <p class="desc"> description,
 *        <dl class="facts"> label/value pairs, then optional <h4> sections such as
 *        "Objectives", "Phases" or "Encounter journal".
 *   2. A table row <tr> (base items, achievements): the column headings show what each cell holds.
 *
 * PHP's built-in DOMDocument parses the HTML and DOMXPath finds things in it, so we don't
 * need a scraping package.
 */
final class WikiEntryParser
{
    /** Tags that start a new block of text. When we turn HTML into plain text, we put a space around each of them. */
    private const BLOCK_TAGS = ['p', 'div', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'tr', 'td', 'th', 'table', 'h3', 'h4'];

    /**
     * CSS classes of <div>s that only exist for the page layout (the picture beside the text, or
     * the two columns of a region). We read the contents of these <div>s as if the <div> wasn't
     * there. We do the same for a <div> with no class at all. If the facts on a new kind of page
     * sit inside some other <div>, add that <div>'s class here.
     */
    private const LAYOUT_CLASSES = ['with-img', 'loc-grid'];

    /** Tags that hold nothing useful for the model. */
    private const SKIP_TAGS = ['img', 'svg', 'figure', 'script', 'style'];

    private DOMXPath $xpath;

    /**
     * @param string $html     the whole page
     * @param string $pagePath the page's path on the site, e.g. "wiki/dungeons.html", for resolving links
     */
    public function __construct(string $html, private string $pagePath)
    {
        $doc = new DOMDocument();
        // Real-world HTML is rarely perfect, so libxml produces warnings. Collect them instead of
        // printing them, because a warning on stdout would break the MCP protocol.
        $previous = libxml_use_internal_errors(true);
        // The <?xml> prefix tells libxml the page is UTF-8. Without it, "·" and "“" come out garbled.
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($doc);
    }

    /** The entry with this id as an array, or null if the page has no such element. */
    public function entry(string $id): ?array
    {
        // Only allow simple ids, so the id is safe to put inside an XPath expression.
        if (preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            throw new InvalidArgumentException("Not a valid entry id: {$id}");
        }
        $node = $this->xpath->query("//*[@id='{$id}']")->item(0);
        if (!$node instanceof DOMElement) {
            return null;
        }

        $entry = $node->nodeName === 'tr' ? $this->fromTableRow($node) : $this->fromArticle($node);
        $entry['links'] = $this->links($node, $id);

        return $entry;
    }

    private function fromArticle(DOMElement $article): array
    {
        $sub = $this->text($this->first(".//*[{$this->hasClass('entry-head')}]/*[{$this->hasClass('sub')}]", $article));
        // Boss entries have a label such as "Boss" or "Mini-boss" outside the heading. Add it to the subtitle.
        $tagline = $this->text($this->first(".//*[{$this->hasClass('tagline')}]", $article));

        $entry = [
            'name' => $this->text($this->first('.//h3', $article)),
            'sub' => implode(' · ', array_filter([$sub, $tagline])),
            'description' => '',
            'facts' => [],
            'text' => [],      // anything before the first <h4> that isn't the description or facts
            'sections' => [],  // "<h4> heading" => lines of text
        ];

        // Go through the article's content in order. Each <h4> starts a new section. Everything
        // before the first <h4> goes into the top-level fields.
        $section = null;
        foreach ($this->blocks($article) as $block) {
            if ($block->nodeName === 'h4') {
                $section = $this->text($block);
                $entry['sections'][$section] ??= [];
            } elseif ($section === null && $block->nodeName === 'dl') {
                $entry['facts'] = array_merge($entry['facts'], $this->facts($block));
            } elseif ($section === null && $entry['description'] === '' && $this->blockHasClass($block, 'desc')) {
                $entry['description'] = $this->text($block);
            } elseif ($section === null) {
                array_push($entry['text'], ...$this->lines($block));
            } else {
                array_push($entry['sections'][$section], ...$this->lines($block));
            }
        }

        return $entry;
    }

    /** A table row: the first cell is the name; the rest are facts, labelled by the column headings. */
    private function fromTableRow(DOMElement $row): array
    {
        $table = $this->first('ancestor::table[1]', $row);
        $headings = array_map(fn (DOMNode $th) => $this->text($th), iterator_to_array($this->xpath->query('.//thead//th', $table)));
        $cells = iterator_to_array($this->xpath->query('./td', $row));

        $facts = [];
        foreach (array_slice($cells, 1, null, true) as $i => $cell) {
            $value = $this->text($cell);
            if ($value !== '' && $value !== '—') {
                $facts[$headings[$i] ?? "Column " . ($i + 1)] = $value;
            }
        }

        return [
            'name' => $this->text($cells[0] ?? null),
            // The heading above the table is the name of the group, e.g. "Gloves (evasion and ward)".
            'sub' => $this->text($this->first('preceding::*[self::h2 or self::h3 or self::h4][1]', $table)),
            'description' => '',
            'facts' => $facts,
            'text' => [],
            'sections' => [],
        ];
    }

    /**
     * The blocks of the article's content, in order. For a <div> that only exists for the page
     * layout (such as the one that puts the picture beside the text), we return the blocks inside it.
     */
    private function blocks(DOMElement $parent): array
    {
        $blocks = [];
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement || in_array($child->nodeName, self::SKIP_TAGS, true)) {
                continue;
            }
            if ($this->blockHasClass($child, 'entry-head') || $this->blockHasClass($child, 'tagline')) {
                continue; // name and subtitle, read separately
            }
            $classes = preg_split('/\s+/', $child->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY);
            $isLayoutWrapper = $child->nodeName === 'div'
                && ($classes === [] || array_intersect($classes, self::LAYOUT_CLASSES) !== []);
            if ($isLayoutWrapper) {
                array_push($blocks, ...$this->blocks($child));
            } else {
                $blocks[] = $child;
            }
        }

        return $blocks;
    }

    /** One block as lines of text: one line per list item, fact or table row; otherwise one line. */
    private function lines(DOMElement $block): array
    {
        if ($block->nodeName === 'ul' || $block->nodeName === 'ol') {
            $items = iterator_to_array($this->xpath->query('./li', $block));
            $lines = array_map(fn (DOMNode $li) => $this->text($li), $items);
        } elseif ($block->nodeName === 'dl') {
            $lines = [];
            foreach ($this->facts($block) as $label => $value) {
                $lines[] = "{$label}: {$value}";
            }
        } elseif ($block->nodeName === 'table' || $this->first('.//table', $block) !== null) {
            $lines = [];
            foreach ($this->xpath->query('.//tr', $block) as $tr) {
                $cells = array_map(fn (DOMNode $c) => $this->text($c), iterator_to_array($this->xpath->query('./th|./td', $tr)));
                $lines[] = implode(' | ', $cells);
            }
        } else {
            $lines = [$this->text($block)];
        }

        return array_values(array_filter($lines, fn (string $line) => $line !== ''));
    }

    /** <dt>/<dd> pairs as label => value. Two <dd>s under one <dt> are joined with "; ". */
    private function facts(DOMElement $dl): array
    {
        $facts = [];
        $label = null;
        foreach ($dl->childNodes as $child) {
            if ($child->nodeName === 'dt') {
                $label = $this->text($child);
            } elseif ($child->nodeName === 'dd' && $label !== null) {
                $value = $this->text($child);
                $facts[$label] = isset($facts[$label]) ? "{$facts[$label]}; {$value}" : $value;
            }
        }

        return $facts;
    }

    /**
     * Every link inside the entry, as link text => full URL. These let the model follow the wiki:
     * from a dungeon to its boss, from the boss to the item it drops, and so on.
     */
    private function links(DOMElement $entry, string $ownId): array
    {
        $links = [];
        foreach ($this->xpath->query('.//a[@href]', $entry) as $a) {
            // query() is declared to return DOMNodes, and only elements have getAttribute(). This query
            // can only match <a> elements, but the check makes that clear, and stops editors warning about it.
            if (!$a instanceof DOMElement) {
                continue;
            }
            $href = $a->getAttribute('href');
            $name = $this->text($a);
            if ($href === "#{$ownId}" || $name === '') {
                continue; // the heading links to the entry itself
            }
            $url = $this->absoluteUrl($href);
            if (!isset($links[$name]) && !in_array($url, $links, true)) {
                $links[$name] = $url;
            }
        }

        return $links;
    }

    /** "../wiki/bosses.html#monster-ossric", seen from wiki/dungeons.html, becomes a full aldervast.online URL. */
    private function absoluteUrl(string $href): string
    {
        if (preg_match('#^[a-z]+:#i', $href) === 1) {
            return $href; // already absolute (https:, mailto:)
        }
        if (str_starts_with($href, '#')) {
            return AldervastSite::url($this->pagePath . $href);
        }

        $parts = explode('/', dirname($this->pagePath));
        foreach (explode('/', $href) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.' && $segment !== '') {
                $parts[] = $segment;
            }
        }

        return AldervastSite::url(implode('/', $parts));
    }

    /** An element's readable text, with whitespace tidied and no stray " · " at either end. */
    private function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $text = preg_replace('/\s+/u', ' ', $this->rawText($node));

        return preg_replace('/^[\s·]+|[\s·]+$/u', '', $text);
    }

    /**
     * Like DOM's textContent, but puts a space between block elements, so "<p>a</p><p>b</p>"
     * becomes "a b", not "ab". A line break <br> becomes " · ", because the wiki uses <br> to split
     * a boss phase into parts. A <cite> (the person who said a quote) gets a dash in front.
     */
    private function rawText(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= $child->data;
            } elseif ($child instanceof DOMElement && !in_array($child->nodeName, self::SKIP_TAGS, true)) {
                $inner = $this->rawText($child);
                $out .= match (true) {
                    $child->nodeName === 'br' => ' · ',
                    $child->nodeName === 'cite' => " — {$inner}",
                    in_array($child->nodeName, self::BLOCK_TAGS, true) => " {$inner} ",
                    default => $inner,
                };
            }
        }

        return $out;
    }

    private function first(string $query, ?DOMNode $context): ?DOMNode
    {
        return $context === null ? null : $this->xpath->query($query, $context)->item(0);
    }

    /** An XPath condition that is true when an element has this CSS class, even if it has several classes. */
    private function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    private function blockHasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', $element->getAttribute('class')), true);
    }
}
