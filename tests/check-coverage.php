<?php

declare(strict_types=1);

/**
 * Checks that the tests run enough of each tool's code. Run it with "composer test:coverage", which
 * runs PHPUnit with code coverage first and then this script.
 *
 * Code coverage means: while the tests run, Xdebug (or PCOV) records which lines of src/ were
 * executed. PHPUnit writes the result to .phpunit.cache/clover.xml. This script reads that file,
 * and fails if any tool in src/Tools/Tools.php has less than MIN_PERCENT of its lines covered.
 *
 * Why per tool: EveryToolHasTestsTest checks that a tool has a test class that calls run(). This
 * script checks that those tests actually run most of the tool's code. A tool whose tests only touch
 * one branch fails here.
 *
 * Other classes in src/ are shown for information only. For example, most of AldervastSite downloads
 * from the website, so only the live tests can run that code.
 */

use Aldervast\Tools\Tools;

require __DIR__ . '/../vendor/autoload.php';

const MIN_PERCENT = 80;
const CLOVER_FILE = __DIR__ . '/../.phpunit.cache/clover.xml';

if (!is_file(CLOVER_FILE)) {
    fwrite(STDERR, "No coverage data in .phpunit.cache/clover.xml. Run \"composer test:coverage\", which needs Xdebug or PCOV.\n");
    exit(1);
}

// Covered and total lines for each file, from the clover report.
$lines = [];
foreach (simplexml_load_file(CLOVER_FILE)->xpath('//file') as $file) {
    $metrics = $file->metrics;
    $lines[realpath((string) $file['name'])] = [(int) $metrics['coveredstatements'], (int) $metrics['statements']];
}

$tools = array_map(fn (string $class) => realpath((new ReflectionClass($class))->getFileName()), Tools::ALL);
$failed = [];

echo "\nLine coverage per class (tools need at least " . MIN_PERCENT . "%):\n";
foreach ($lines as $path => [$covered, $total]) {
    $percent = $total === 0 ? 100.0 : 100 * $covered / $total;
    $isTool = in_array($path, $tools, true);
    $status = !$isTool ? 'information only' : ($percent >= MIN_PERCENT ? 'ok' : 'TOO LOW');
    printf("  %-24s %5.1f%%  (%3d of %3d lines)  %s\n", basename($path), $percent, $covered, $total, $status);
    if ($status === 'TOO LOW') {
        $failed[] = basename($path);
    }
}

// A tool with no coverage data at all was never loaded by the tests, which is a failure too.
foreach ($tools as $path) {
    if (!isset($lines[$path])) {
        echo '  ' . basename($path) . ": no coverage data, so no test ran it  TOO LOW\n";
        $failed[] = basename($path);
    }
}

if ($failed !== []) {
    fwrite(STDERR, "\nNot enough of these tools is tested: " . implode(', ', $failed) . ".\n"
        . "Add tests that run the uncovered code. To see which lines that is, run\n"
        . "  composer test:coverage-html\n"
        . "and open .phpunit.cache/html/index.html in a browser.\n");
    exit(1);
}

echo "\nEvery tool is above " . MIN_PERCENT . "%.\n";
