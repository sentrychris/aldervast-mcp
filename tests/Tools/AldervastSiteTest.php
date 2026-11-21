<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\AldervastSite;
use RuntimeException;

/**
 * Tests for AldervastSite: building URLs and file names, and reading saved copies of pages.
 *
 * Downloading from the real website is checked by LiveSiteTest, not here.
 */
final class AldervastSiteTest extends FixtureTestCase
{
    public function test_the_cache_is_in_the_project_root(): void
    {
        // The path is built from this class's own folder, so moving the class would move the cache
        // without this test noticing anything else.
        $this->assertSame('cache', basename(AldervastSite::CACHE_DIR));
        $this->assertSame(realpath(dirname(__DIR__, 2)), realpath(dirname(AldervastSite::CACHE_DIR)));
    }

    public function test_url_joins_the_path_with_exactly_one_slash(): void
    {
        $this->assertSame('https://aldervast.online/wiki/a.html', AldervastSite::url('/wiki/a.html'));
        $this->assertSame('https://aldervast.online/wiki/a.html', AldervastSite::url('wiki/a.html'));
    }

    public function test_file_name_drops_the_part_after_the_hash_and_the_leading_slash(): void
    {
        $this->assertSame('wiki_dungeons.html', AldervastSite::fileName('/wiki/dungeons.html#dungeon-hollow_crypt'));
    }

    public function test_get_reads_the_saved_copy_for_any_form_of_the_path(): void
    {
        // The hand-made page isn't on the website, so this can only pass by reading the saved copy.
        $page = AldervastSite::get('/wiki/handmade.html#test-limits');

        $this->assertStringContainsString('id="test-limits"', $page);
    }

    public function test_the_folder_of_saved_copies_may_end_with_a_slash(): void
    {
        putenv('ALDERVAST_FIXTURES=' . self::FIXTURES . '/');

        $this->assertStringContainsString('id="test-limits"', AldervastSite::get('wiki/handmade.html'));
    }

    public function test_a_page_with_no_saved_copy_is_an_error_not_a_download(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('No test fixture for wiki/no-such-page.html');

        AldervastSite::get('wiki/no-such-page.html');
    }
}
