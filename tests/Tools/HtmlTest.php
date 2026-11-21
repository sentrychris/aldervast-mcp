<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tools\Html;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Html::xpath(), which WikiEntryParser and LatestReleaseTool use to read HTML.
 *
 * libxml (the library under DOMDocument) has one setting for the whole PHP process: whether it
 * prints its warnings or collects them. These tests check that Html::xpath() puts that setting back
 * and doesn't leave collected warnings behind.
 */
final class HtmlTest extends TestCase
{
    /** Broken HTML: a closing tag with no opening tag, and a tag that HTML doesn't have. libxml warns about both. */
    private const BROKEN_HTML = '<p>text</i><madeup>more</madeup></p>';

    private bool $previousSetting;

    protected function setUp(): void
    {
        $this->previousSetting = libxml_use_internal_errors();
    }

    protected function tearDown(): void
    {
        libxml_use_internal_errors($this->previousSetting);
    }

    public function test_html_without_a_charset_tag_is_still_read_as_utf8(): void
    {
        // No <meta charset> tag here, unlike the website's pages. Without the UTF-8 prefix in
        // Html::xpath(), libxml would read "é" as "Ã©".
        $xpath = Html::xpath('<h3>Café · “quoted”</h3>');

        $this->assertSame('Café · “quoted”', $xpath->evaluate('string(//h3)'));
    }

    public function test_libxml_prints_its_warnings_again_afterwards_if_it_did_before(): void
    {
        libxml_use_internal_errors(false);

        Html::xpath(self::BROKEN_HTML);

        $this->assertFalse(libxml_use_internal_errors());
    }

    public function test_the_warnings_about_broken_html_are_not_left_behind(): void
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        Html::xpath(self::BROKEN_HTML);

        $this->assertSame([], libxml_get_errors());
    }
}
