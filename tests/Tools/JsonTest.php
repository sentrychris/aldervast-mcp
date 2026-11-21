<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tools\Json;
use PHPUnit\Framework\TestCase;

/** Tests for Json, the formatting that every tool's output goes through. */
final class JsonTest extends TestCase
{
    public function test_pretty_prints_indented_json_with_slashes_and_accents_as_they_are(): void
    {
        $this->assertSame("{\n    \"url\": \"a/b\",\n    \"name\": \"Café\"\n}", Json::pretty(['url' => 'a/b', 'name' => 'Café']));
    }

    public function test_an_empty_object_stays_an_object(): void
    {
        $this->assertSame('{}', Json::pretty((object) []));
    }
}
