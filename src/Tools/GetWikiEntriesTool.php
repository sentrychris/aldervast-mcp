<?php

declare(strict_types=1);

namespace Aldervast\Tools;

use InvalidArgumentException;
use Throwable;

/**
 * The get_wiki_entries tool: several full wiki entries in one tool call.
 *
 * Some questions need many entries. To plan a build, for example, the model reads every option it
 * might recommend: skills, support runes, passives and items. With get_wiki_entry that is one tool
 * call, and one round trip to the model, for each entry. Planning one build took about 30 of them.
 * This tool takes a list of URLs and returns all the entries at once, in the same form as
 * get_wiki_entry, so that the model can ask for everything that it found in a single step.
 *
 * It accepts URLs only. A name can match several entries, and then get_wiki_entry asks the model to
 * choose; in a list of ten, that would make the answer hard to read.
 *
 * One bad URL doesn't fail the call. Its error is listed under "errors", and the other entries
 * still come back, because the model can usually answer with those and retry the one that failed.
 */
final class GetWikiEntriesTool
{
    public const NAME = 'get_wiki_entries';

    /**
     * The most entries in one result. A typical entry is 1 to 3 KB and the biggest about 9 KB,
     * and the whole result goes into the model's context window.
     */
    public const MAX_URLS = 10;

    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Fetches up to ' . self::MAX_URLS . ' full wiki entries in one call, each as '
                . 'get_wiki_entry returns it. Use it instead of several get_wiki_entry calls when you already '
                . 'have the URLs, e.g. to compare options or read the entries that an entry links to. URLs only, '
                . 'not names. URLs past the limit come back under not_fetched; a URL that fails is listed under '
                . 'errors, and the others still come back.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'urls' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'minItems' => 1,
                        'maxItems' => self::MAX_URLS,
                        'description' => 'Wiki entry URLs. The entries come back in this order.',
                    ],
                ],
                'required' => ['urls'],
            ],
        ];
    }

    public static function run(array $input): string
    {
        $urls = $input['urls'] ?? null;
        if (!is_array($urls) || $urls === []) {
            throw new InvalidArgumentException(
                'urls must be a list of wiki URLs, e.g. {"urls": ["https://aldervast.online/wiki/dungeons.html#dungeon-hollow_crypt"]}'
            );
        }

        // The same URL twice would only make the result longer.
        $urls = array_values(array_unique(array_map(fn ($url) => trim((string) $url), $urls)));
        $notFetched = array_slice($urls, self::MAX_URLS);
        $urls = array_slice($urls, 0, self::MAX_URLS);

        $entries = [];
        $errors = [];
        // One parser for each page, because parsing a page takes much longer than reading one
        // entry from it. They last for this call only, so the next call gets the page from the
        // cache again, and the cache decides when it is too old.
        $parsers = [];
        foreach ($urls as $url) {
            try {
                $location = GetWikiEntryTool::parseUrl($url);
                $page = explode('#', $location, 2)[0];
                $parsers[$page] ??= new WikiEntryParser(AldervastSite::get($page), $page);
                $entries[] = GetWikiEntryTool::fromPage($parsers[$page], $location);
            } catch (Throwable $e) {
                $errors[] = ['url' => $url, 'error' => $e->getMessage()];
            }
        }

        $result = ['requested' => count($urls) + count($notFetched), 'found' => count($entries), 'entries' => $entries];
        // Leave out empty lists: the model could read an empty "errors" as something to look at.
        if ($errors !== []) {
            $result['errors'] = $errors;
        }
        if ($notFetched !== []) {
            $result['not_fetched'] = $notFetched;
            $result['message'] = 'Only the first ' . self::MAX_URLS . ' URLs were fetched. '
                . 'Call get_wiki_entries again with the URLs in not_fetched.';
        }

        return Json::pretty($result);
    }
}
