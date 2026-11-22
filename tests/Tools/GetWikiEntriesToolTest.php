<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\GetWikiEntriesTool;
use Aldervast\Tools\GetWikiEntryTool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for get_wiki_entries: several entries in one call, in the order asked for, with errors
 * listed next to the entries that worked.
 */
final class GetWikiEntriesToolTest extends FixtureTestCase
{
    private const CRYPT = 'https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt';

    private const STALKER = 'https://aldervast.online/wiki/classes.html#class-stalker';

    private const SHADE = 'wiki/classes.html#class-shade';

    private const CROSSBOW = 'https://aldervast.online/wiki/items.html#item-siege_crossbow';

    private function get(array $urls): array
    {
        return $this->decode(GetWikiEntriesTool::run(['urls' => $urls]));
    }

    public function test_each_entry_is_the_same_as_get_wiki_entry_returns_in_the_order_asked_for(): void
    {
        $result = $this->get([self::STALKER, self::CRYPT, self::CROSSBOW, self::SHADE]);

        $this->assertSame(['requested' => 4, 'found' => 4], array_slice($result, 0, 2));
        $this->assertSame(['Stalker', 'The Hollow Crypt', 'Siege Crossbow', 'Shade'], array_column($result['entries'], 'name'));
        foreach ([self::STALKER, self::CRYPT, self::CROSSBOW, self::SHADE] as $i => $url) {
            $this->assertSame($this->decode(GetWikiEntryTool::run(['url_or_name' => $url])), $result['entries'][$i], $url);
        }
        // Nothing failed and nothing was left out, so those keys aren't there at all.
        $this->assertSame(['requested', 'found', 'entries'], array_keys($result));
    }

    public function test_the_same_url_twice_is_fetched_once_and_spaces_around_a_url_are_ignored(): void
    {
        $result = $this->get([self::STALKER, '  ' . self::STALKER . ' ', self::CRYPT]);

        $this->assertSame(2, $result['requested']);
        $this->assertSame(['Stalker', 'The Hollow Crypt'], array_column($result['entries'], 'name'));
    }

    public function test_a_url_that_fails_is_listed_with_its_reason_and_the_others_still_come_back(): void
    {
        $result = $this->get([
            'https://example.com/wiki/x.html#a',
            self::STALKER,
            'https://aldervast.online/wiki/classes.html#class-no_such_class',
            'The Hollow Crypt',
            'https://aldervast.online/wiki/unsaved.html#entry-a',
            self::SHADE,
        ]);

        $this->assertSame(6, $result['requested']);
        $this->assertSame(2, $result['found']);
        $this->assertSame(['Stalker', 'Shade'], array_column($result['entries'], 'name'));

        $errors = array_column($result['errors'], 'error', 'url');
        $this->assertCount(4, $errors);
        $this->assertStringStartsWith('Not an Aldervast wiki entry URL', $errors['https://example.com/wiki/x.html#a']);
        $this->assertStringStartsWith(
            'wiki/classes.html has no entry #class-no_such_class',
            $errors['https://aldervast.online/wiki/classes.html#class-no_such_class']
        );
        // A name isn't a URL. get_wiki_entry is the tool for names.
        $this->assertStringStartsWith('Not an Aldervast wiki entry URL', $errors['The Hollow Crypt']);
        // In the tests, a page with no saved copy fails; on the real site, a page that doesn't exist fails the same way.
        $this->assertStringStartsWith('No test fixture for wiki/unsaved.html', $errors['https://aldervast.online/wiki/unsaved.html#entry-a']);
    }

    public function test_only_the_first_ten_urls_are_fetched_and_the_rest_are_listed(): void
    {
        $urls = [];
        foreach (['ravager', 'warden', 'arcanist', 'shade', 'stalker', 'skirmisher', 'a', 'b', 'c', 'd', 'e', 'f'] as $id) {
            $urls[] = "wiki/classes.html#class-{$id}";
        }

        $result = $this->get($urls);

        $this->assertSame(12, $result['requested']);
        $this->assertSame(6, $result['found']);
        $this->assertCount(4, $result['errors']);
        $this->assertSame(['wiki/classes.html#class-e', 'wiki/classes.html#class-f'], $result['not_fetched']);
        // The model reads this message, so check it word for word.
        $this->assertSame(
            'Only the first 10 URLs were fetched. Call get_wiki_entries again with the URLs in not_fetched.',
            $result['message']
        );
    }

    public function test_exactly_ten_urls_are_all_fetched(): void
    {
        $urls = [self::CRYPT, self::CROSSBOW];
        foreach (['ravager', 'warden', 'arcanist', 'shade', 'stalker', 'skirmisher', 'a', 'b'] as $id) {
            $urls[] = "wiki/classes.html#class-{$id}";
        }

        $result = $this->get($urls);

        $this->assertSame(10, $result['requested']);
        $this->assertSame(8, $result['found']);
        $this->assertArrayNotHasKey('not_fetched', $result);
        $this->assertArrayNotHasKey('message', $result);
    }

    public static function badInputs(): array
    {
        return [
            'no urls' => [[]],
            'an empty list' => [['urls' => []]],
            'one url as a string' => [['urls' => self::CRYPT]],
        ];
    }

    #[DataProvider('badInputs')]
    public function test_urls_must_be_a_list_that_is_not_empty(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('urls must be a list of wiki URLs');

        GetWikiEntriesTool::run($input);
    }
}
