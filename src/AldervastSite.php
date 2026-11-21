<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Reads files from the public Aldervast website (https://aldervast.online), with a small disk cache.
 *
 * Why a cache: a model may call a tool many times in one conversation, and the wiki's search.json
 * alone is about 320 KB. The site only changes when the game ships a release, so a copy up to an
 * hour old is fine, and it keeps us polite to a small static site.
 *
 * Cached copies live in cache/ (gitignored). Delete that folder to force fresh downloads.
 */
final class AldervastSite
{
    public const BASE_URL = 'https://aldervast.online';

    private const TTL_SECONDS = 3600;

    private const USER_AGENT = 'aldervast-mcp/1.0';

    /** Turns a site path such as "wiki/dungeons.html#dungeon-hollow_crypt" into a full URL. */
    public static function url(string $path): string
    {
        return self::BASE_URL . '/' . ltrim($path, '/');
    }

    /** The body of a site path: from the cache while it's fresh, otherwise from the network. */
    public static function get(string $path): string
    {
        // A "#fragment" picks a spot inside a page; it's never sent to the server, so drop it.
        $path = ltrim(explode('#', $path, 2)[0], '/');
        $file = self::cacheFile($path);

        if (is_file($file) && time() - filemtime($file) < self::TTL_SECONDS) {
            return (string) file_get_contents($file);
        }

        try {
            $http = new Client(['timeout' => 15, 'headers' => ['User-Agent' => self::USER_AGENT]]);
            $body = (string) $http->get(self::url($path))->getBody();
        } catch (GuzzleException $e) {
            // Site down, or we're offline? An old copy beats no answer. With no copy at all, give up.
            if (is_file($file)) {
                fwrite(STDERR, "[aldervast-mcp] fetch failed, using stale cache for {$path}: {$e->getMessage()}\n");
                return (string) file_get_contents($file);
            }
            throw $e;
        }

        $dir = dirname($file);
        if (is_dir($dir) || mkdir($dir, 0777, true)) {
            file_put_contents($file, $body);
        }

        return $body;
    }

    /** Same as get(), decoded as JSON into PHP arrays. */
    public static function json(string $path): array
    {
        return json_decode(self::get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** "wiki/search.json" is cached as cache/wiki_search.json. */
    private static function cacheFile(string $path): string
    {
        $name = preg_replace('/[^A-Za-z0-9.-]+/', '_', $path);

        return dirname(__DIR__) . '/cache/' . $name;
    }
}
