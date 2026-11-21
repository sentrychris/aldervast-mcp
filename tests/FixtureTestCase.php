<?php

declare(strict_types=1);

namespace Aldervast\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base class for the offline tests.
 *
 * Before each test, it sets the environment variable ALDERVAST_FIXTURES. AldervastSite then reads
 * pages from tests/fixtures/ instead of downloading them. That folder holds saved copies of real
 * wiki pages, so every test gets the same data, and no test needs the network.
 *
 * One result of this: if the real website changes, these tests keep passing until someone
 * downloads fresh copies. LiveSiteTest is the test that checks the real website.
 */
abstract class FixtureTestCase extends TestCase
{
    protected const FIXTURES = __DIR__ . '/fixtures';

    protected function setUp(): void
    {
        putenv('ALDERVAST_FIXTURES=' . self::FIXTURES);
    }

    protected function tearDown(): void
    {
        putenv('ALDERVAST_FIXTURES');
    }

    /** Decode JSON returned from tool call results. */
    protected function decode(string $json): array
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
