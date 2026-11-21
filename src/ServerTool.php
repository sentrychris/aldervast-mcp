<?php

use GuzzleHttp\Client;

/**
 * The one tool in this project: list_servers.
 *
 * A "tool" is just two things:
 *   1. a DEFINITION (name, description, JSON Schema for its input) that we send to the model, and
 *   2. some CODE that we run ourselves when the model asks for it.
 *
 * The model never runs anything. It only says "please call list_servers with this input",
 * and our PHP does the real work. Both agent.php and mcp-server.php use this class,
 * which is the whole point: same tool, two ways to plug it in.
 */
final class ServerTool
{
    public const NAME = 'list_servers';

    public const URL = 'https://aldervast.online/servers.json';

    /** The definition the model sees. The description is what tells it WHEN to use the tool. */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Lists the public Aldervast game servers. Returns each server\'s name, '
                . 'address, port and a short note. Use it whenever the user asks which servers exist '
                . 'or how to connect to one.',
            // JSON Schema for the input. This tool takes no arguments, so it's an empty object.
            // (Must be an object {} in JSON, not an array [], hence the (object) cast.)
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
        ];
    }

    /**
     * The code that actually runs. Fetches servers.json, which looks like:
     *   {"servers": [{"name": "...", "address": "...", "port": 24650, "note": "..."}]}
     * Returns a plain string, because a tool result is just text (or JSON as text) for the model to read.
     */
    public static function run(array $input = []): string
    {
        $http = new Client(['timeout' => 15]);
        $body = (string) $http->get(self::URL)->getBody();
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return pretty($data['servers'] ?? []);
    }
}
