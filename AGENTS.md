# AGENTS.md: aldervast-mcp

Instructions for any coding agent working in this folder (Claude Code, Codex, Cursor and others).
Claude Code reads this through `CLAUDE.md`. Keep this file the single source of truth.

## What this project is

A small PHP project that does two jobs:

1. **A learning demo of the agent loop.** `agent.php` sends a question and tool definitions to the
   Anthropic Messages API, runs whatever tool the model asks for, sends back a `tool_result`, and prints
   every step.
2. **An MCP server for the game Aldervast.** `mcp-server.php` exposes the same tools over MCP (stdio,
   JSON-RPC 2.0), so Claude Code, the Claude desktop app or any MCP client can answer questions about the
   game: its servers, world, quests, items, dungeons and so on.

**The goal now is to grow the MCP server's tools** using the public game knowledge on
https://aldervast.online (the site and its wiki). The owner, Chris, is a senior Laravel/PHP and AWS
developer who is learning how agents and MCP work. Write code he can read top to bottom.

Aldervast is an original fantasy action MMORPG built in Godot 4.7 (GDScript) with a dedicated Linux
server. **This folder is not the game.** Never edit the game or the website from here. Game development
is paused; this project only *reads* public game data.

## Layout

```
agent.php           the agent loop demo (Messages API over Guzzle)
mcp-server.php      stdio MCP server: initialize, tools/list, tools/call, ping
src/helpers.php     load_env(), pretty(), step()
src/ServerTool.php  the list_servers tool: definition() + run()
src/SearchWikiTool.php  the search_wiki tool: scores wiki/search.json entries against a query
src/GetWikiEntryTool.php  the get_wiki_entry tool: resolves a URL or name, returns one entry, trimmed
src/WikiEntryParser.php   one entry's HTML -> array (article or table row)
src/ListCategoryTool.php  the list_category tool: a category's entries from search.json, paged; no category = category counts
src/AldervastSite.php   cached, polite fetching from aldervast.online (cache/ is gitignored, 1 hour TTL)
composer.json       PHP >= 8.1, guzzlehttp/guzzle ^7.8, autoload "files"
.env.example        ANTHROPIC_API_KEY placeholder (the real .env is gitignored)
.mcp.json           registers mcp-server.php with Claude Code for this folder
docs/notes.md       Chris's own learning notes. Don't rewrite them unless asked.
README.md           the learner's guide: setup, running, what each printed step means
```

## Commands

The machine is Windows with PowerShell. PHP and Composer are on the PATH.

```powershell
composer install
composer dump-autoload            # after adding a file to "autoload"
php -l mcp-server.php             # syntax check
php agent.php "your question"     # needs ANTHROPIC_API_KEY in .env, and costs a little money

# Test the MCP server by hand, with no API key and no cost:
'{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php mcp-server.php
'{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list_servers","arguments":{}}}' | php mcp-server.php

# Run from THIS folder: .mcp.json is project-scoped, so `claude mcp list` elsewhere won't show it.
cd C:\Users\chris\workspace\aldervast-mcp
claude mcp list                   # "aldervast ... Connected" (first time: start `claude` here and approve it)
```

After changing the server, restart the MCP client (or start a new Claude Code session) so it lists the
tools again. Ask before running `agent.php`, because every run calls the paid API.

## How to add a tool

Every tool is one class in `src/`, shaped like `ServerTool`:

- `const NAME` in snake_case, e.g. `search_wiki`.
- `definition()` returns `name`, `description` and `input_schema` (JSON Schema). Write the description
  for the model: what the tool returns and *when* to use it. Use `(object) []` for empty `properties`
  so it encodes as `{}`, not `[]`.
- `run(array $input): string` does the work and returns text (often JSON) for the model to read.
  Throw on failure; both callers turn exceptions into an error result.

Then wire it up in three places:

1. `composer.json` `autoload.files` (or move to PSR-4 once there are several classes; that's fine), then
   run `composer dump-autoload`.
2. `mcp-server.php`: add the class to the tool registry list near the top. `tools/list` and `tools/call`
   both read that registry.
3. `agent.php`: add the class to its registry list too, so the demo can use it.

Fetch from the site through `AldervastSite::get()` / `json()`, not a bare Guzzle call, so the request is
cached and sends the project's User-Agent.

Test it by piping `tools/call` JSON-RPC into the server before you try it through a client.

## MCP rules that break things when you forget them

- **stdout is the protocol channel.** Only JSON-RPC replies go to stdout, one JSON object per line.
  Logs go to `STDERR`. A stray `echo`, `var_dump` or PHP warning on stdout breaks the client.
  Keep `display_errors` off for the server, or send errors to stderr.
- Messages without an `id` are notifications (e.g. `notifications/initialized`). Never reply to them.
- MCP calls the schema `inputSchema` (camelCase). The Messages API calls it `input_schema`. The server
  maps one to the other.
- `tools/call` returns `{"content":[{"type":"text","text":"..."}],"isError":false}`. Report a tool
  failure with `isError: true` in a normal result. Use a JSON-RPC `error` only for protocol problems
  such as an unknown method or an unknown tool.
- `initialize` returns server-wide `instructions` (the `INSTRUCTIONS` constant in `mcp-server.php`).
  Keep it and every tool description general: no facts about specific game content (e.g. "deer means
  stag"). Such examples leak answers into tests and don't scale; the model supplies synonyms itself.
- `json_decode($x, true)` turns `{}` into `[]`. When a decoded object goes back over the wire (e.g. a
  `tool_use` `input` in `agent.php`), cast it with `(object)`.

## Data sources (public, read-only)

Base URL: `https://aldervast.online`. It's a static site on GitHub Pages, regenerated each game release
from the game's own data files. There's no API; these are the useful endpoints:

| URL | What it is |
|---|---|
| `/servers.json` | `{"servers":[{"name","address","port","note"}]}`, the server browser list. Today it holds one server: "Aldervast Alpha", `versyxdigital.net`, port 24650. |
| `/wiki/search.json` | About 320 KB, about 1,840 entries: the whole wiki as a search index. Each entry is `{"n": name, "c": category, "u": url path, "s": summary (cut hard at 140 characters, sometimes inside an HTML entity; `SearchWikiTool::summary()` decodes it and marks a cut with " …"), "k": keywords or tags, often empty}`. |
| `/wiki/<page>.html` | Full entries. Each is `<article class="entry" id="<kind>-<id>">` with an `<h3>` name, `<span class="sub">` (kind · level), `<p class="desc">` (lore), and `<dl class="facts">` holding `<dt>`/`<dd>` pairs such as Level, Entrance, Boss, Monsters, Quests, Rewards, with links to other entries, then optional `<h4>` sections (Objectives, Phases, Encounter journal). Some pages (`items.html`, `achievements.html`) use table rows instead: `<tr id="item-...">`, with meaning in the `<thead>` column headings. `WikiEntryParser` handles both. |
| `/changelog.html` | Release history, version by version. |
| `/download.html`, `/servers.html`, `/play.html`, `/world.html` | Download links, the server list, how to play, and the world overview. |

The `u` values in `search.json` look like `wiki/dungeons.html#dungeon-hollow_crypt`. Fetch the page,
then find the `<article>` whose `id` matches the fragment. `DOMDocument` with `libxml_use_internal_errors(true)`
and `DOMXPath` handles this well, so you don't need a scraping package.

Search categories, with counts as of game version 1.14.95: Base item 323, Quest 188, Monster 160,
Place 93, Achievement 85, Person 72, Unique item 63, Affix 56, Skill glyph 49, Passive cluster 49,
Status 44, Support rune 36, Title 36, Boss 33, Guide 28, Item set 28, Waystone modifier 28,
Mini-boss 24, Group dungeon 23, Pet 20, Mount 17, Raid boss 15, Companion 13, Specialisation 12,
World boss 10, Class 6, Settlement 6, Region 5, Faction 5, Ancestry 5, Raid 4, Battleground 3, Festival 5,
plus smaller ones for crafting, fishing, gathering, the Hourglass, Warp Tears, Tide Bells and Loose Threads.

Be polite to the site. Cache responses on disk (e.g. `cache/` holding a gitignored copy, with a TTL of
about an hour, since the site only changes on a game release). Send a plain User-Agent like
`aldervast-mcp/1.0`. Never fetch the whole site in a loop.

## Game knowledge, for orientation

All of this is on the wiki. Treat the wiki as the source of truth over this summary.

- **Levels 1 to 60.** Six classes: Ravager, Warden, Arcanist, Shade, Stalker, Skirmisher. Five ancestries:
  Hearthborn, Vaelin, Durrak, Ashkin, Wildborn. Twelve specialisations.
- **Builds** use skill glyphs, support runes and a passive web (clusters, keystones), with affixes on items.
- **World.** Five regions: Brightmeadow Vale, Thornwood, Saltreach Coast, Cinderreach Wastes, Frostspire
  Peaks. Six settlements, Harrowford first. Five factions.
- **Story and quests.** A main story told in chapters (quest chains), plus side quests; 188 quests in all.
- **Instances.** Story dungeons; 23 group dungeons from level 10 to 59; four raids (Spire of the Sundered
  Crown 30, Drowned Basilica 40, Cinderwell 50, the Fell 60); the Trial of the Lattice; five world bosses
  that wake on timers; three battlegrounds (PvP: Shattered Hall, Sunken Reliquary, Kiln Road).
- **Endgame:** waystone maps with modifiers, the Hourglass (timed runs), Warp Tears, Tide Bells, Loose Threads.
- **Other systems:** professions (gathering, fishing, smithing, cooking, alchemy, enchanting), pets,
  mounts and the Stable, companions, titles, achievements, and the Lanternfast winter festival
  (20 Nov to 6 Jan, with its own dungeon, the Old Chandlery).
- **Servers.** Players join through the in-game server browser, which reads `/servers.json`. The game
  talks over ENet on **UDP 24650**, unencrypted. A game server also answers a no-login "status" query
  (name, MOTD, players, version) over that ENet connection.

## Ideas for the next tools, roughly easiest first

1. ~~`search_wiki(query, category?)`~~: done (`src/SearchWikiTool.php`).
2. ~~`get_wiki_entry(url_or_name, category?)`~~: done (`src/GetWikiEntryTool.php`, `src/WikiEntryParser.php`).
3. ~~`list_category(category?)`~~: done (`src/ListCategoryTool.php`). It also covers search misses caused by
   wording ("big deer boss" vs "Moss Stag"): the model browses the category and matches by meaning. The
   heavier fix, semantic search with embeddings, needs a new provider and API key; ask Chris first.
4. `levelling_route(level)`: dungeons, quests and regions suited to a level, from the Level facts.
5. `latest_release()`: the newest version and its notes, parsed from `/changelog.html`.
6. MCP **resources** (`resources/list`, `resources/read`) for whole pages, and **prompts**
   (e.g. "plan my build"), once the tools are solid. Declare each capability in `initialize`.

**Hard; don't start without asking:** a live `server_status` tool. The status query runs over ENet (a
UDP protocol) plus Godot's RPC encoding, which PHP can't speak without reimplementing both. A simpler
first step is a reachability check, or reading status from a small HTTP endpoint if the game ever
publishes one. That would be game work, which is paused.

## Rules from Chris

- **Never publish or hard-code the game server's raw IP address.** Use the hostname `versyxdigital.net`.
  This covers code, docs, tests, fixtures and tool output.
- **Use standard industry terms** (tool, tool call, MCP server, client, cache, endpoint) and plain
  English. Don't invent jargon or cute names for ordinary things.
- **Tools are read-only by default.** Anything that writes, changes a server or uses an admin command must
  be clearly marked, sit behind an explicit confirmation step, and never be on by default. Today nothing
  in this project writes anywhere.
- **Never commit or print secrets.** `.env` is gitignored, and `.env.example` holds only a placeholder.
  Don't read or echo `.env` contents.
- **This is a learning project.** Prefer small, plain PHP over frameworks or extra packages. Comment *why*,
  for a reader new to agents. Update `README.md` when you add a tool or change how to run things.
- Say plainly what you tested and what you didn't. Calls to the paid API aren't free, so ask first.

## Code style

PHP 8.1+, `declare(strict_types=1)` welcome in new files, PSR-12 formatting, `final` classes, early
returns, and no globals beyond what the two entry scripts need. Use Guzzle for HTTP. Keep each tool's
output small and structured (JSON text), since it lands in the model's context window. Trim long lists and
say how many items you left out.
