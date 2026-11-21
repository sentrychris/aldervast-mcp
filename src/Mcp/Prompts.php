<?php

declare(strict_types=1);

namespace Aldervast\Mcp;

use Aldervast\Tools\LevellingRouteTool;
use InvalidArgumentException;

/**
 * The MCP prompts of this server: ready-made requests that a person picks in the client.
 *
 * A prompt is a template for a message from the person. The person chooses it (in Claude Code it
 * appears as a slash command, /mcp__aldervast__plan_build), fills in its arguments, and the client
 * asks the server for the finished text with prompts/get. The client then sends that text to the
 * model as if the person had typed it. So a prompt saves the person from writing a good request
 * every time, and it can tell the model which tools to use for the job.
 *
 * The text below is for the model, like a tool description. Keep it general: it may name the tools
 * and say how to use them, but no facts about specific game content (see AGENTS.md). The model
 * looks the facts up.
 */
final class Prompts
{
    /** The description of a "level" argument. Its limit comes from levelling_route, so the two always agree. */
    private const LEVEL = 'The character\'s level, from 1 to ' . LevellingRouteTool::MAX_LEVEL . '.';

    /**
     * Each prompt's definition, as prompts/list returns it. Every argument value arrives as a
     * string, because that is how MCP sends prompt arguments.
     */
    private const PROMPTS = [
        'plan_build' => [
            'name' => 'plan_build',
            'title' => 'Plan my build',
            'description' => 'Plan a character build for a class, looked up on the Aldervast wiki.',
            'arguments' => [
                ['name' => 'class', 'description' => 'The character\'s class.', 'required' => true],
                ['name' => 'level', 'description' => self::LEVEL, 'required' => false],
                ['name' => 'playstyle', 'description' => 'How you like to play, e.g. "solo, safe" or "fast, risky".', 'required' => false],
            ],
        ],
        'plan_levelling' => [
            'name' => 'plan_levelling',
            'title' => 'What should I do at my level?',
            'description' => 'A plan of quests, dungeons and places for a character level, from the Aldervast wiki.',
            'arguments' => [
                ['name' => 'level', 'description' => self::LEVEL, 'required' => true],
            ],
        ],
    ];

    /** The reply to prompts/list. */
    public static function list(): array
    {
        return array_values(self::PROMPTS);
    }

    /**
     * The reply to prompts/get: the prompt's description and its message, with the arguments filled in.
     * Throws InvalidArgumentException for an unknown prompt, a missing required argument or a level
     * that isn't a whole number from 1 to LevellingRouteTool::MAX_LEVEL; the server turns that into a JSON-RPC "invalid params" error.
     */
    public static function get(string $name, array $arguments): array
    {
        $prompt = self::PROMPTS[$name] ?? throw new InvalidArgumentException("Unknown prompt: {$name}");
        foreach ($prompt['arguments'] as $argument) {
            if ($argument['required'] && trim((string) ($arguments[$argument['name']] ?? '')) === '') {
                throw new InvalidArgumentException("Missing argument: {$argument['name']}");
            }
        }

        $text = match ($name) {
            'plan_build' => self::planBuild($arguments),
            'plan_levelling' => self::planLevelling($arguments),
        };

        return [
            'description' => $prompt['description'],
            'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]],
        ];
    }

    private static function planBuild(array $arguments): string
    {
        $class = trim((string) $arguments['class']);
        $level = self::optionalLevel($arguments);
        $playstyle = trim((string) ($arguments['playstyle'] ?? ''));

        $about = "I play a {$class} in Aldervast" . ($level === null ? '' : " at level {$level}") . '.';
        if ($playstyle !== '') {
            $about .= " How I like to play: {$playstyle}.";
        }

        return $about . ' Plan a build for me.' . "\n\n"
            . 'Look everything up with the aldervast tools rather than answering from memory. Start with my '
            . 'class\'s wiki entry. Then find out from the wiki which parts a build is made of (list_category '
            . 'with no category shows every category) and which options in each part suit my class'
            . ($level === null ? '' : ' and level') . '. Read the full entries with get_wiki_entry before you '
            . 'recommend them.' . "\n\n"
            . 'For each choice, say in a sentence why it suits me, and cite its wiki URL. If the wiki doesn\'t '
            . 'say something that the build needs, say so rather than guessing.';
    }

    private static function planLevelling(array $arguments): string
    {
        $level = self::optionalLevel($arguments);

        return "My character is level {$level} in Aldervast. What should I do next?" . "\n\n"
            . "Call levelling_route with level {$level}, and use get_wiki_entry for anything whose details "
            . 'matter to the plan. Then give me a short plan in three parts: quests to do now, dungeons to '
            . 'run, and where to go. Put main story quests first. Cite the wiki URL for each item, and base '
            . 'the plan only on what the tools return.';
    }

    /** The "level" argument as a whole number, or null if it's empty or missing. */
    private static function optionalLevel(array $arguments): ?int
    {
        $value = trim((string) ($arguments['level'] ?? ''));
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > LevellingRouteTool::MAX_LEVEL) {
            throw new InvalidArgumentException('level must be a whole number from 1 to ' . LevellingRouteTool::MAX_LEVEL);
        }

        return (int) $value;
    }
}
