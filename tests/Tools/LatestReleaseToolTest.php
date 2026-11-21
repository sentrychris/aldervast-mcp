<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\AldervastSite;
use Aldervast\Tools\LatestReleaseTool;
use RuntimeException;

/**
 * Tests for latest_release: the newest version, its date and its notes, from changelog.html.
 *
 * Most tests use the saved copy of the real page. A few need a page that the website doesn't have
 * (notes over several lines, or no list of releases at all). Those tests write a small page into a
 * temporary folder and point ALDERVAST_FIXTURES at that folder instead.
 */
final class LatestReleaseToolTest extends FixtureTestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            array_map('unlink', glob($this->tempDir . '/*'));
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function release(): array
    {
        return $this->decode(LatestReleaseTool::run([]));
    }

    /** Makes LatestReleaseTool read this HTML as changelog.html. */
    private function useChangelog(string $body): void
    {
        $this->tempDir = sys_get_temp_dir() . '/aldervast-changelog-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir);
        file_put_contents($this->tempDir . '/' . AldervastSite::fileName(LatestReleaseTool::PATH), "<!doctype html><html><body>{$body}</body></html>");
        putenv('ALDERVAST_FIXTURES=' . $this->tempDir);
    }

    public function test_returns_the_newest_version_with_its_date_notes_and_url(): void
    {
        $release = $this->release();

        $this->assertSame(['version', 'date', 'notes', 'url'], array_keys($release));
        $this->assertSame('1.14.95', $release['version']);
        $this->assertSame('2026-11-20', $release['date']);
        $this->assertStringStartsWith('The server browser: Join a Server has a Browse servers button', $release['notes']);
        $this->assertSame('https://aldervast.online/changelog.html', $release['url']);
    }

    public function test_the_notes_are_whole_and_have_no_html_codes_in_them(): void
    {
        $notes = $this->release()['notes'];

        // The page writes these as "the game&#x27;s own alpha server" and "&quot;your name&#x27;s game&quot;".
        $this->assertStringContainsString("the game's own alpha server", $notes);
        $this->assertStringContainsString('"your name\'s game"', $notes);
        // The last words of the release's notes, so nothing was cut off.
        $this->assertStringEndsWith('and the update script now ships in the server zip and keeps itself up to date', $notes);
    }

    public function test_line_breaks_and_links_in_the_notes_become_plain_text(): void
    {
        $this->useChangelog(
            '<ol class="large timeline"><li><b> 2.0.0 </b><time datetime="2027-01-05">5 January</time>
                First line,
                second line with <a href="wiki/guides.html">a link</a>.
            </li><li><b>1.99.0</b><time datetime="2027-01-01">1 January</time>Older.</li></ol>'
        );

        $this->assertSame(
            ['version' => '2.0.0', 'date' => '2027-01-05', 'notes' => 'First line, second line with a link.', 'url' => 'https://aldervast.online/changelog.html'],
            $this->release()
        );
    }

    public function test_a_page_without_a_list_of_releases_is_an_error(): void
    {
        $this->useChangelog('<h1>Release history</h1><ol class="other"><li><b>1.0.0</b>Not the release list.</li></ol>');

        $this->expectLayoutError();
        LatestReleaseTool::run([]);
    }

    public function test_a_release_without_a_version_number_is_an_error(): void
    {
        $this->useChangelog('<ol class="timeline"><li><time datetime="2027-01-05">5 January</time>Notes but no version.</li></ol>');

        $this->expectLayoutError();
        LatestReleaseTool::run([]);
    }

    public function test_a_release_without_a_date_still_has_its_version_and_notes(): void
    {
        $this->useChangelog('<ol class="timeline"><li><b>2.0.0</b>Notes but no date.</li></ol>');

        $release = $this->release();

        $this->assertSame('2.0.0', $release['version']);
        $this->assertSame('', $release['date']);
        $this->assertSame('Notes but no date.', $release['notes']);
    }

    private function expectLayoutError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The changelog page has no releases in the layout this tool reads, '
            . 'so the website has probably changed. See https://aldervast.online/changelog.html');
    }
}
