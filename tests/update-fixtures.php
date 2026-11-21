<?php

declare(strict_types=1);

/**
 * Downloads fresh copies of the wiki pages that the offline tests use, into tests/fixtures/.
 *
 *   php tests/update-fixtures.php
 *
 * The offline tests use these saved copies instead of the live website, so that they get the same
 * data every time. Run this script when the website has changed on purpose, for example after a
 * game release. Then run composer test. If a test fails, either the code needs to change or the
 * test's expected values do. Check the changes to the saved copies before you commit them.
 *
 * The script downloads through AldervastSite, which keeps copies in cache/ for an hour. So a page
 * can be up to an hour old.
 *
 * tests/fixtures/wiki_handmade.html is different: it was made by hand for the tests and isn't on the
 * website. This script never downloads or overwrites it.
 */

use Aldervast\Tools\AldervastSite;

require __DIR__ . '/../vendor/autoload.php';

// The pages that the tests use. Keep this list short: each page means one more download from the
// website, and one more file in git.
const PAGES = [
    'servers.json',
    'wiki/search.json',
    'wiki/dungeons.html',
    'wiki/bosses.html',
    'wiki/items.html',
    'wiki/achievements.html',
    'wiki/regions.html',
    'wiki/quests.html',
    'wiki/classes.html',
    'wiki/guides.html',
    'wiki/worldbosses.html',
    'changelog.html',
];

// Clear the setting, so that we download from the real website and not from the copies that we're
// about to replace.
putenv('ALDERVAST_FIXTURES');

$dir = __DIR__ . '/fixtures';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

foreach (PAGES as $page) {
    $body = AldervastSite::get($page);
    $file = $dir . '/' . AldervastSite::fileName($page);
    file_put_contents($file, $body);
    printf("%-26s %8s bytes -> tests/fixtures/%s\n", $page, number_format(strlen($body)), basename($file));
}
