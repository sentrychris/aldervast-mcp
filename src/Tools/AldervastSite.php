<?php

declare(strict_types=1);

namespace Aldervast\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Reads files from the public Aldervast website (https://aldervast.online), and keeps copies on disk.
 *
 * Why keep copies: a model may call a tool many times in one conversation, and the wiki's
 * search.json alone is about 320 KB. The website only changes when a new version of the game is
 * released, so a copy that is up to an hour old is fine. It also means fewer requests to a small
 * website.
 *
 * The copies are kept in cache/, which git ignores. Delete that folder to download everything again.
 */
final class AldervastSite
{
    public const BASE_URL = 'https://aldervast.online';

    /** How long a copy in cache/ is used before it is downloaded again, unless the caller asks for less. */
    public const MAX_AGE_SECONDS = 3600;

    private const USER_AGENT = 'aldervast-mcp/1.0';

    /** cache/ in the project root, two folders up from this file (src/Tools/). AldervastSiteTest checks it. */
    public const CACHE_DIR = __DIR__ . '/../../cache';

    /** Turns a site path such as "wiki/dungeons.html#dungeon-hollow_crypt" into a full URL. */
    public static function url(string $path): string
    {
        return self::BASE_URL . '/' . ltrim($path, '/');
    }

    /**
     * Returns the contents of a file on the website. If cache/ has a copy that is younger than
     * $maxAgeSeconds (an hour, unless the caller says otherwise), it returns that copy. Otherwise it
     * downloads the file and saves a new copy. With $maxAgeSeconds = 0 it always downloads, but still
     * keeps the copy, which it uses if the website can't be reached.
     *
     * For tests: if the environment variable ALDERVAST_FIXTURES holds a folder name, it reads the
     * file from that folder instead, and downloads nothing. The tests then get the same data every
     * time, work offline, and don't fail because the website changed. The script
     * tests/update-fixtures.php puts fresh copies in that folder.
     */
    public static function get(string $path, int $maxAgeSeconds = self::MAX_AGE_SECONDS): string
    {
        // The part after "#" points to a place inside the page. Browsers never send it to the
        // server, so we remove it.
        $path = ltrim(explode('#', $path, 2)[0], '/');

        $fixtures = getenv('ALDERVAST_FIXTURES');
        if ($fixtures !== false && $fixtures !== '') {
            $fixture = rtrim($fixtures, '/\\') . '/' . self::fileName($path);
            if (!is_file($fixture)) {
                throw new RuntimeException("No test fixture for {$path} (looked for {$fixture})");
            }
            return (string) file_get_contents($fixture);
        }

        $file = self::CACHE_DIR . '/' . self::fileName($path);

        if (is_file($file) && time() - filemtime($file) < $maxAgeSeconds) {
            return (string) file_get_contents($file);
        }

        try {
            $http = new Client(['timeout' => 15, 'headers' => ['User-Agent' => self::USER_AGENT]]);
            $body = (string) $http->get(self::url($path))->getBody();
        } catch (GuzzleException $e) {
            // If the website is down or we're offline, an old copy is better than nothing.
            // If there is no copy at all, give up.
            if (is_file($file)) {
                fwrite(STDERR, "[aldervast-mcp] fetch failed, using stale cache for {$path}: {$e->getMessage()}\n");
                return (string) file_get_contents($file);
            }
            throw $e;
        }

        // Write to a temporary file first, then rename it into place. Several processes can share the
        // cache (the eval runs several at once), and a rename is all-or-nothing, so no process ever
        // reads a half-written copy. If the rename fails (another process has the file open), keep
        // the old copy; this process still has the fresh one in $body.
        $dir = dirname($file);
        if (is_dir($dir) || @mkdir($dir, 0777, true)) {
            $temporary = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temporary, $body) !== false && !@rename($temporary, $file)) {
                @unlink($temporary);
            }
        }

        return $body;
    }

    /** Same as get(), but decodes the JSON into PHP arrays. */
    public static function json(string $path, int $maxAgeSeconds = self::MAX_AGE_SECONDS): array
    {
        return json_decode(self::get($path, $maxAgeSeconds), true, flags: JSON_THROW_ON_ERROR);
    }

    /** The name of the file that holds a page, in cache/ or tests/fixtures/. For example, "wiki/search.json" becomes "wiki_search.json". */
    public static function fileName(string $path): string
    {
        return preg_replace('/[^A-Za-z0-9.-]+/', '_', ltrim(explode('#', $path, 2)[0], '/'));
    }
}
