<?php

declare(strict_types=1);

namespace Aldervast\Tests\Tools;

use Aldervast\Tests\FixtureTestCase;
use Aldervast\Tools\ServerTool;

/**
 * Tests for list_servers: the list of public game servers, read from servers.json.
 */
final class ServerToolTest extends FixtureTestCase
{
    private function servers(): array
    {
        return $this->decode(ServerTool::run([]));
    }

    public function test_returns_each_server_with_its_name_address_port_and_note(): void
    {
        $servers = $this->servers();

        $this->assertCount(1, $servers);
        $this->assertSame(['name', 'address', 'port', 'note'], array_keys($servers[0]));
        $this->assertSame('Aldervast Alpha', $servers[0]['name']);
        $this->assertSame(24650, $servers[0]['port']);
    }

    public function test_returns_the_list_itself_not_the_object_around_it(): void
    {
        // servers.json is {"servers": [...]}. The model only needs the list inside it.
        $this->assertTrue(array_is_list($this->servers()));
    }

    public function test_definition_takes_no_input_and_sends_an_empty_object(): void
    {
        // With no inputs, "properties" must still be an object ({}), not an array ([]).
        $this->assertSame(
            '{"type":"object","properties":{}}',
            json_encode(ServerTool::definition()['input_schema'])
        );
    }
}
