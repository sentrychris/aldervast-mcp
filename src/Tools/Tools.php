<?php

declare(strict_types=1);

namespace Aldervast\Tools;

/**
 * The list of every tool in this project, and the instructions that go with them.
 *
 * mcp-server.php, agent.php, the eval and the tests all read this list, so that a new tool only has
 * to be added in one place. To add a tool, write its class in src/ and add the class here (the full
 * steps are in AGENTS.md, under "How to add a tool").
 */
final class Tools
{
    /**
     * Guidance for the tools as a whole. The MCP server sends it in its initialize reply, and the
     * client (e.g. Claude Desktop) adds it to the model's context in every chat that uses the server.
     * AgentLoop sends the same text as its system prompt, so that agent.php and the eval give the
     * model what Claude Desktop gives it.
     *
     * Each tool description says what one tool does. This text says when to use the tools at all, and
     * how they work together. It is text for the model: keep it general, with no facts about specific
     * game content, and test any change (see "Writing comments and documentation" in AGENTS.md).
     */
    public const INSTRUCTIONS = 'These tools read the public wiki of Aldervast, an original fantasy action MMORPG. '
        . 'Your own knowledge of Aldervast is likely missing or out of date, so for any question about the '
        . 'game, look it up with these tools rather than answering from memory, even if you think you know. '
        . 'Typical flow: search_wiki to find entries (or list_category to browse when the player\'s wording '
        . 'doesn\'t match the wiki\'s), then get_wiki_entry for the full details. Base the answer on what '
        . 'the tools return, and cite the wiki URLs. Text ending in " …" was cut short: never fill in the '
        . 'missing words yourself; fetch the full entry with get_wiki_entry, or say the detail is incomplete.';

    /** Each tool's class. tools/list returns the tools in this order. */
    public const ALL = [
        ServerTool::class,
        SearchWikiTool::class,
        GetWikiEntryTool::class,
        ListCategoryTool::class,
        LevellingRouteTool::class,
    ];

    /**
     * The same classes, keyed by tool name, e.g. 'search_wiki' => SearchWikiTool::class.
     * When the model asks for a tool by name, this is how we find the class that runs it.
     */
    public static function byName(): array
    {
        $tools = [];
        foreach (self::ALL as $class) {
            $tools[$class::NAME] = $class;
        }

        return $tools;
    }
}
