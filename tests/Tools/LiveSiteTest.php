<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tools\GetWikiEntryTool;
use Aldervast\Tools\SearchWikiTool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests against the real website, to find out whether it has changed in a way that breaks our code.
 * They check that the search index still has the fields that the tools use, that summaries are
 * still cut at 140 characters, and that the parser can still read a known entry.
 *
 * The other tests use saved copies of the pages, so they keep passing when the website changes.
 * These tests fail instead.
 *
 * PHPUnit skips these tests unless you ask for them (see phpunit.xml). Run them after a game
 * release, or when a tool starts to give wrong results:
 *   composer test:live
 *
 * They download through AldervastSite, so they use cache/ and make at most a few requests.
 *
 * If one of them fails, the website has changed:
 *   1. Run php tests/update-fixtures.php to download fresh copies of the pages.
 *   2. Run composer test. The tests that fail show which parts of the code need to change.
 *   3. Change the code until all tests pass. Then commit the code and the new copies together.
 */
#[Group('live')]
final class LiveSiteTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ALDERVAST_FIXTURES'); // Clear the setting, if it is set, so that these tests use the real website.
    }

    public function test_search_index_has_the_fields_that_the_tools_use(): void
    {
        $index = SearchWikiTool::index();

        $this->assertGreaterThan(1000, count($index));
        foreach (['n', 'c', 'u', 's', 'k'] as $field) {
            $this->assertArrayHasKey($field, $index[0], "search.json entries lost the \"{$field}\" field");
        }
    }

    public function test_summaries_are_still_cut_at_140_characters(): void
    {
        // SearchWikiTool::SUMMARY_CUT_LENGTH depends on this length. If the website starts to cut
        // summaries at a different length, or adds its own "…", that constant and
        // SearchWikiTool::summary() need to change.
        $longest = max(array_map(fn (array $e) => mb_strlen($e['s']), SearchWikiTool::index()));

        $this->assertSame(140, $longest);
    }

    public function test_parser_can_still_read_a_known_article(): void
    {
        $result = json_decode(
            GetWikiEntryTool::run(['url_or_name' => 'https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt']),
            true
        );

        $this->assertSame('The Hollow Crypt', $result['name']);
        $this->assertArrayHasKey('Boss', $result['facts']);
        $this->assertNotEmpty($result['links']);
    }
}
