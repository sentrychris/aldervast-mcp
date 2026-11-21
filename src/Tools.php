<?php

declare(strict_types=1);

/**
 * The list of every tool in this project.
 *
 * mcp-server.php, agent.php and the tests all read this list, so that a new tool only has to be
 * added in one place. To add a tool, write its class in src/ and add the class here (the full steps
 * are in AGENTS.md, under "How to add a tool").
 */
final class Tools
{
    /** Each tool's class. tools/list returns the tools in this order. */
    public const ALL = [
        ServerTool::class,
        SearchWikiTool::class,
        GetWikiEntryTool::class,
        ListCategoryTool::class,
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
