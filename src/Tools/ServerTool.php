<?php

declare(strict_types=1);

namespace Aldervast\Tools;

/**
 * The list_servers tool, and the first tool in this project.
 *
 * A "tool" is just two things:
 *   1. a DEFINITION (name, description, JSON Schema for its input) that we send to the model, and
 *   2. some CODE that we run ourselves when the model asks for it.
 *
 * The model never runs anything. It only asks us to "call list_servers with this input", and our
 * PHP does the real work. Both agent.php and mcp-server.php use this class. That's the point of
 * keeping each tool in its own class: the same tool works in both places.
 */
final class ServerTool
{
    public const NAME = 'list_servers';

    /** The server list's path on the website, https://aldervast.online/servers.json. */
    public const PATH = 'servers.json';

    /** The definition that we send to the model. The model reads the description to decide WHEN to use the tool. */
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
     * The code that runs when the model asks for this tool. It downloads servers.json, which looks like:
     *   {"servers": [{"name": "...", "address": "...", "port": 24650, "note": "..."}]}
     * Returns a plain string, because a tool result is just text (or JSON as text) for the model to read.
     *
     * A server can be added or taken down at any time, so this asks AldervastSite for a fresh
     * download every time (a maximum age of 0), rather than a copy that is up to an hour old.
     * Going through AldervastSite still matters: it sends the project's User-Agent, keeps a copy for
     * when the website can't be reached, and lets the tests use a saved copy instead.
     */
    public static function run(array $input = []): string
    {
        $data = AldervastSite::json(self::PATH, maxAgeSeconds: 0);

        return Json::pretty($data['servers'] ?? []);
    }
}
