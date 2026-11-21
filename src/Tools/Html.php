<?php

declare(strict_types=1);

namespace Aldervast\Tools;

use DOMDocument;
use DOMXPath;

/**
 * Reads a page of HTML. WikiEntryParser and LatestReleaseTool both use this.
 *
 * PHP's built-in DOMDocument parses the HTML, and DOMXPath finds things in it, so we don't need a
 * scraping package.
 */
final class Html
{
    /** Parses the HTML and returns a DOMXPath for searching it. */
    public static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        // Real-world HTML is rarely perfect, so libxml produces warnings. Collect them instead of
        // printing them, because a warning on stdout would break the MCP protocol. Then clear them,
        // and put libxml's setting back the way it was, so that nothing else is affected.
        $previous = libxml_use_internal_errors(true);
        // The <?xml> prefix tells libxml that the page is UTF-8. The website's pages already say so
        // in a <meta charset> tag, but HTML without that tag would come out garbled ("é" as "Ã©"),
        // so the prefix is a safety net. HtmlTest checks it with HTML that has no <meta> tag.
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }
}
