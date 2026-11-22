<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\AldervastSite;
use Aldervast\Tools\LatestReleaseTool;
use Aldervast\Tools\SearchReleasesTool;
use InvalidArgumentException;

/**
 * Tests for search_releases: searching the release notes by words, reading one version, and the
 * list of recent releases, on the saved copy of changelog.html.
 */
final class SearchReleasesToolTest extends FixtureTestCase
{
    private function search(array $input): array
    {
        return $this->decode(SearchReleasesTool::run($input));
    }

    public function test_releases_with_more_of_the_words_come_first_then_the_newest(): void
    {
        $result = $this->search(['query' => 'Pets  TITLES', 'limit' => 3]);

        $this->assertSame(['pets', 'titles'], $result['query']);
        $this->assertSame(['1.14.91', '1.14.93', '1.14.92'], array_column($result['releases'], 'version'));
        $this->assertSame(['pets', 'titles'], $result['releases'][0]['matched']);
        $this->assertSame(['titles'], $result['releases'][1]['matched']);
        $this->assertSame('2026-11-19', $result['releases'][0]['date']);
        $this->assertSame('https://aldervast.online/changelog.html', $result['url']);
    }

    public function test_excerpts_are_the_matching_clauses_most_words_first_and_long_ones_are_cut(): void
    {
        $excerpts = $this->search(['query' => 'pets titles', 'limit' => 1])['releases'][0]['excerpts'];

        $this->assertStringStartsWith('Pets, and titles to wear: twenty pets now follow', $excerpts[0]);
        $this->assertLessThanOrEqual(3, count($excerpts));
        $cut = array_values(array_filter($excerpts, fn (string $e) => str_ends_with($e, ' …')));
        $this->assertNotEmpty($cut);
        $this->assertLessThanOrEqual(302, mb_strlen($cut[0]));
    }

    public function test_excerpts_are_whole_clauses_without_spaces_and_cut_ones_are_their_first_300_characters(): void
    {
        $result = $this->search(['query' => 'pets pets PETS titles', 'limit' => 1]);
        $this->assertSame(['pets', 'titles'], $result['query']);
        $excerpts = $result['releases'][0]['excerpts'];

        // Every excerpt is a clause with one of the words in it, with no space at either end.
        foreach ($excerpts as $excerpt) {
            $this->assertMatchesRegularExpression('/\b(pets|titles)/i', $excerpt);
            $this->assertSame(trim($excerpt), $excerpt);
        }
        $notes = $this->search(['version' => '1.14.91'])['notes'];
        $clause = array_values(array_filter(array_map('trim', explode(';', $notes)), fn (string $c) => str_starts_with($c, 'they belong')))[0];
        $this->assertSame(rtrim(mb_substr($clause, 0, 300)) . ' …', $excerpts[1]);
    }

    public function test_a_release_gives_at_most_three_excerpts(): void
    {
        // The newest release with more than three clauses that contain "the".
        $many = array_values(array_filter(
            LatestReleaseTool::all(AldervastSite::get(LatestReleaseTool::PATH)),
            fn (array $r) => count(preg_grep('/\bthe\b/i', explode(';', $r['notes']))) > 3
        ))[0]['version'];

        $release = array_values(array_filter(
            $this->search(['query' => 'the', 'limit' => 30])['releases'],
            fn (array $r) => $r['version'] === $many
        ))[0];

        $this->assertCount(3, $release['excerpts']);
    }

    public function test_a_word_matches_only_at_the_start_of_a_word_in_the_notes(): void
    {
        // "pet" finds "pets"; "ets" is only ever the end of a word, so it finds nothing.
        $this->assertContains('1.14.91', array_column($this->search(['query' => 'pet'])['releases'], 'version'));
        $nothing = $this->search(['query' => 'ets']);
        $this->assertSame(0, $nothing['total_matches']);
        $this->assertSame([], $nothing['releases']);
    }

    public function test_a_version_returns_that_releases_whole_notes(): void
    {
        $release = $this->search(['version' => ' v1.10.0 ']);

        $this->assertSame(['version', 'date', 'notes', 'url'], array_keys($release));
        $this->assertSame('1.10.0', $release['version']);
        $this->assertSame('2026-11-07', $release['date']);
        $this->assertStringStartsWith('Heroes II: feet find the ground on slopes', $release['notes']);
    }

    public function test_an_unknown_version_is_an_error_that_names_the_newest_and_oldest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^No release has the version "9\.9\.9"\. The newest is 1\.14\.95 and the oldest \S+\.$/');

        SearchReleasesTool::run(['version' => '9.9.9']);
    }

    public function test_with_no_words_it_lists_the_newest_releases_and_the_limit_stays_between_1_and_30(): void
    {
        $result = $this->search(['query' => ' a ']);

        $this->assertSame(214, $result['total']);
        $this->assertCount(10, $result['releases']);
        $this->assertSame(204, $result['not_shown']);
        $this->assertSame(['version' => '1.14.95', 'date' => '2026-11-20'], $result['releases'][0]);

        $this->assertCount(1, $this->search(['limit' => 0])['releases']);
        $this->assertCount(30, $this->search(['limit' => 30])['releases']);
        $this->assertCount(30, $this->search(['limit' => 99])['releases']);
    }

    public function test_every_release_is_read_newest_first_and_the_newest_is_the_latest_release(): void
    {
        $all = LatestReleaseTool::all(AldervastSite::get(LatestReleaseTool::PATH));

        $this->assertCount(214, $all);
        $this->assertSame($this->decode(LatestReleaseTool::run())['version'], $all[0]['version']);
        $this->assertSame(['version', 'date', 'notes'], array_keys($all[0]));
    }
}
