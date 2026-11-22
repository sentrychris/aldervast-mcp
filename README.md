# aldervast-mcp: watch an agent loop happen

A small PHP project that shows what happens inside tool calling. One command sends a question to
Claude together with a set of tools, and prints every step of the conversation, so that you can see
exactly what is sent and received. The same tools also run as an MCP server, so that apps such as
Claude Desktop can use them.

The code in `src/` is in five parts, one folder and one PHP namespace each:

```
src/Tools/     Aldervast\Tools   the tools themselves: what the MCP server offers
  Tools.php              the list of every tool, and the instructions that go with them
  ServerTool.php         the list_servers tool: its definition, and the PHP that runs it
  SearchWikiTool.php     the search_wiki tool: searches the game wiki's index
  GetWikiEntryTool.php   the get_wiki_entry tool: returns one full wiki entry as JSON
  GetWikiEntriesTool.php the get_wiki_entries tool: up to ten entries in one call
  ListCategoryTool.php   the list_category tool: lists every entry in one category, in pages
  FindItemsTool.php      the find_items tool: the items of a type up to a level, best first
  FindSkillsTool.php     the find_skills tool: skills by type, tag, weapon and level, or the runes for one skill
  LevellingRouteTool.php the levelling_route tool: the regions, dungeons and quests that suit a level
  WhatsOnTool.php        the whats_on tool: festivals, seasons, world boss times, resets and omens at a moment
  LatestReleaseTool.php  the latest_release tool: the newest version and its notes, from the changelog
  SearchReleasesTool.php the search_releases tool: every release, searched by words or read by version
  WikiEntryParser.php    turns the HTML of one wiki entry into an array (get_wiki_entry uses it)
  Html.php               parses a page of HTML (WikiEntryParser and latest_release use it)
  AldervastSite.php      downloads files from aldervast.online, and keeps copies in cache/ for an hour
  Json.php               prints JSON in a readable layout
src/Mcp/       Aldervast\Mcp     the MCP server's own code
  Server.php             answers one JSON-RPC message (initialize, tools/call, ...); both transports use it
  ProtocolError.php      a request that gets a JSON-RPC error, such as an unknown method
  HttpTransport.php      MCP over HTTP, for a server on the internet (custom connectors)
  ToolCallLog.php        writes every tool call that the server runs to logs/tool-calls.jsonl
  WikiPages.php          the MCP resources: each wiki page as Markdown, for a person to attach to a chat
  Prompts.php            the MCP prompts: ready-made requests such as "plan my build"
src/Agent/     Aldervast\Agent   the agent loop, which talks to the Messages API
  AgentLoop.php          the agent loop itself (read it from top to bottom)
  AgentLoopError.php     why a run failed before Claude answered
  Env.php                reads settings such as the API key from .env
src/Eval/      Aldervast\Eval    the eval
  EvalScore.php          the eval's grading
src/Web/       Aldervast\Web     the website's chatbot
  Chat.php               answers one visitor's question with the agent loop
  RateLimit.php          limits questions per visitor and per day, because each answer costs money
  UsageLog.php           writes what each question used (tokens, never the question) to cache/chat-usage.jsonl
  UsageReport.php        adds that log up per day (the usage page and show-chat-usage.php use it)
  UsageDashboard.php     the usage page at /usage: tiles, a chart of cost per day, and a table

mcp-server.php           the MCP server over stdio (step 2 below); uses src/Mcp
show-log.php             prints the tool call log as a readable list ("composer log")
show-chat-usage.php      prints what the website's chatbot used and cost, per day (run on the server)
agent.php                runs the agent loop on one question and prints every step; uses src/Agent
public/                  the website (step 3 below): index.php, the chatbot page, the chat widget
Dockerfile, docker/, docker-compose.yml, deploy/   how the website runs on a server (step 3)
evals/                   the eval's questions, and the script that runs them (see "The eval")
tests/                   PHPUnit tests, in the same five folders (see "Tests")
```

The parts depend on each other in one direction only. The MCP server and the agent loop each use
the tools, the eval and the chatbot use the agent loop, and the tools use none of the others:

```
Tools  <-  Mcp
Tools  <-  Agent  <-  Eval
           Agent  <-  Web
```

So `src/Tools/` is the whole MCP product, with no learning or measuring code mixed in.
`tests/ArchitectureTest.php` fails if a part starts to use one that it shouldn't.

## The tools

| Tool | Input | What it returns |
|---|---|---|
| `list_servers` | none | The public game servers: name, address, port, note. |
| `search_wiki` | `query`, optional `category` and `limit` (default 10, max 25) | The best-matching wiki entries: name, category, short summary, URL. |
| `get_wiki_entry` | `url_or_name`, optional `category` | One full entry: description, facts, sections, and links to the entries it mentions. |
| `get_wiki_entries` | `urls` (1 to 10 wiki URLs) | The same as `get_wiki_entry` for each URL, in one call, with any URL that failed listed under `errors`. |
| `list_category` | optional `category`, `offset`, `limit` (default 50, max 100) | Every entry in a category: name, summary, URL. With no category, the categories and their sizes. |
| `find_skills` | optional `type`, `tag`, `needs`, `max_level`, `min_level`, `supports_for`, `limit` (default 25, max 50) | Skill glyphs matching every filter, lowest level first, with tags, what they need in hand, damage and mana. With `supports_for` (a skill's name), the support runes that fit that skill. |
| `find_items` | optional `type`, `max_level`, `min_level`, `limit` (default 15, max 50) | The base items and unique items of a type within the levels, highest level first, with requirements, stats, modifiers and where uniques come from. With no type, the item types and their sizes. |
| `levelling_route` | `level` (1 to 60) | The regions, dungeons and quests that suit that level, with levels, quest givers, the quest that must come first, the quest that leads into each dungeon, when a dungeon is only open at certain times, the main-story chapter of each main-story quest, the boss of a world boss quest, and URLs. |
| `whats_on` | optional `at` (a UTC date or date-time; default now) | What's on at that moment, from the wiki's schedules: festivals and seasons running, each world boss's next wake time, the next weekly resets, this week's Hourglass omens, dungeons open only at certain times, and schedules the tool can't work out, as the wiki words them. |
| `latest_release` | none | The newest version of the game: its version number, release date and release notes, from the changelog page. |
| `search_releases` | optional `query`, `version`, `limit` (default 10, max 30) | Releases whose notes contain the words, most words first, with the matching parts of the notes; or one version's whole notes; or, with neither, the newest releases. |

### When search misses: words versus meaning

`search_wiki` matches **words**, and players don't use the wiki's words. If you ask "what's that
big deer boss called?", the search finds nothing useful: the wiki calls it "Elderhorn, the Moss
Stag", and no entry uses the word "deer" for it. Changing the scores can't fix that, because no
score can find a word that isn't there. (We tried giving rare words more weight. "boss" in a name
still scored higher than "deer" in a summary.)

Two things help, and both rely on the model, which is good at matching meaning:

1. **The description of `search_wiki` tells Claude to try other words.** If the results don't fit
   the question, Claude searches again with synonyms or related words. "stag" and "antler" both
   find Elderhorn straight away.
2. **`list_category` lets Claude look through a whole category.** A list of the ten world bosses
   shows Claude "Elderhorn, the Moss Stag", and Claude recognises the deer itself. The tool is also
   useful on its own: "every group dungeon and its level", "what mounts are there?"

The bigger fix, if you ever need it, is **semantic search with embeddings**. You turn every entry
and every question into a list of numbers (a vector), and rank the entries by how close their
meaning is to the question. This is the core of what people call RAG. It needs an embeddings
provider (such as Voyage AI), an API key, and an index that you build in advance.

### How search_wiki works

`search_wiki` downloads https://aldervast.online/wiki/search.json (about 1,840 entries) and gives
every entry a score in PHP. A query word in the name counts most, then a word in the tags, the
category and the summary. An entry gets extra points when its name contains the whole query.

In the index, summaries are cut at 140 characters, often in the middle of a word. The tool ends a
cut summary with " …", and decodes HTML codes such as `&#x2019;`. Without that mark, the model can't
tell a cut summary from a complete one, and quietly finishes the sentence itself. With the mark, the
server instructions tell Claude to read the full entry instead of guessing.

The `category` input ignores case, and accepts the start of a category name (`"hourglass"` matches
"Hourglass run" and "Hourglass omen"). For an unknown category, the tool returns an error that lists
the real categories, so that the model can correct its next call.

### How levelling_route decides what suits a level

"I'm level 23, where should I go?" needs three wiki pages (and two more, below): `regions.html`, `dungeons.html` (story
dungeons, group dungeons, raids and the trial) and `quests.html` (188 quests). Every entry there has
a Level fact, and the tool keeps what suits the level:

| Kind | Suits level N when | Level fact on the wiki |
|---|---|---|
| Region | its range includes N | `Levels: 18 to 26` |
| Dungeon | its level is within 2 of N | `Level: 22` |
| Dungeon that scales | N is inside the range it scales over | `Level: Your party's own (its average), from 10 to 60` |
| Quest | its level is within 2 of N, and a level N character can already take it | `Level: 23 (you can take it from level 20)` |

Things to know:

- **The result states its own rule** (`rule`), so Claude can tell the player what "suited" means.
- **Quests are cut at 20**, with `quests_not_shown` saying how many more there were. Up to 24 quests
  suit a single level (level 50).
- **Region ranges stop at level 34**, but later content still takes place in those regions. Above
  34 no region's range fits, so the tool says so and names the regions where the listed quests are,
  from their Region facts.
- **"Can already take it" removes nothing on today's wiki**: every quest opens at least 3 levels
  below its own level, so any quest within 2 levels is open. The check stays as a safeguard for later
  game releases.
- **Each dungeon names the quest that leads into it** (`quests`), from the dungeon's Quests fact.
  28 of the 30 dungeons have one. Without it, Claude would have to guess the link from the names.
- **A dungeon that is only open at certain times says when** (`open`), from its Open fact. Today
  only the festival dungeon has one. Without it, Claude told a player that they could run the
  festival dungeon "any time".
- **Main-story quests carry their chapter** (`main_story`, e.g. "The Unlit Road"). A quest entry
  doesn't say whether it's main story, so the tool reads a fourth page, `guides.html`. It has one
  guide for each chapter of the main story, and each step of the guide starts with the quest's name
  and level ("A Light in Harrowford level 1 …"). A quest is marked when it starts a step of a chapter.
  The chapter guides also link to 48 side quests on the way, so a link alone isn't enough. This rule
  marks 85 quests, and they're the same 85 whose ids start with `quest-ms`. The tool doesn't use the
  ids, because nothing on the wiki says what `ms` means.
- **A quest whose target is a world boss names the boss** (`world_boss`). The quest entry mentions
  the boss only in the text of an objective ("Help slay Calathrax"), so the tool reads a fifth page,
  `worldbosses.html`, where a boss that is fought for a quest links to it. Today that's one quest,
  Wyrmfall. Before this, Claude called Wyrmfall a world boss quest because of its URL id
  (`quest-rq_world_boss`), which was right but was a guess.
- It reads each page once (`WikiEntryParser::entries()`). Once the pages are in the cache, a call
  takes about a tenth of a second.

### What get_wiki_entry is for

`search_wiki` only uses the search index, and every summary there is one short line. For the Hollow
Crypt dungeon, the whole summary is "Level 5 dungeon with 11 rooms". The things that players
actually ask about are only in the full wiki entry:

- **"Who's the boss of the Hollow Crypt?"** Warden Ossric, the Hollowed (the dungeon's facts).
- **"Can I do it solo?"** Yes, with smaller packs and weaker bosses (its "Walked alone" fact).
- **"How do I beat Ossric?"** His phases and encounter journal (sections on the boss entry).
- **"What does the quest give me?"** 900 experience, 80 gold, a rare item... (the quest's Rewards fact).

It also lets Claude **follow links through the wiki**, the way that you would click through it.
Every entry comes back with `links`: the other entries that it mentions, each with its URL. So
Claude can answer "What does the Hollow Crypt boss drop?" in steps: `search_wiki` finds the dungeon,
`get_wiki_entry` on the dungeon gives the boss, and `get_wiki_entry` on the boss's link gives its
details. With only `search_wiki`, Claude would have to say that it doesn't know, or worse, guess.

So the two tools work like a search box and a page: `search_wiki` to find an entry, and
`get_wiki_entry` to read it.

### How get_wiki_entry works

You (or the model) pass either a URL from `search_wiki`, or a name. A URL always works. For a name,
the tool looks it up in the search index, and ignores case and a leading "The ". Many entries share
a name (the Hollow Crypt is a place, a quest, a dungeon and an Hourglass run). In that case the tool
returns the list of matches instead of guessing, and the model calls it again with a URL or a
`category`. If no entry has exactly that name, the tool returns the closest search results.

The tool downloads the page (and keeps a copy, like `search.json`). Then it finds the element whose
`id` is the part of the URL after "#", and turns that element into JSON. `src/Tools/WikiEntryParser.php`
handles the two forms that wiki entries take:

- **Articles** (most pages): `name`, `sub` (kind and level), `description` (the lore), `facts` (the
  label/value list), `sections` (each `<h4>` heading, such as "Objectives" or "Phases", with its
  lines), and `links`.
- **Table rows** (base items, achievements): the column headings become the `facts`, such as
  Level, Requires and Base stats, and the heading above the table becomes `sub`.

Links inside the text become plain names, so that the facts are easy to read, and the URLs go in
`links`. Pictures and layout HTML are removed. Each result goes into the model's context window, so
it has to stay small: long values are cut at 600 characters, sections at 30 lines and `links` at 40,
and the output marks every cut. A typical entry is 1 to 3 KB; the biggest (whole main-story
chapters) are about 9 KB.

Downloaded files are kept in `cache/` (which git ignores) for an hour, because the website only
changes when a new version of the game is released. Delete `cache/` to download everything again.

### Several entries in one call: get_wiki_entries

Some questions need many entries. To plan a build, Claude read every option that it might
recommend: skills, support runes, passives and items. That was about 30 `get_wiki_entry` calls, and
each call is a round trip: Claude asks for the tool, the client runs it, and the result goes back
to Claude before it can ask for the next one. `get_wiki_entries` takes up to ten URLs and returns
all the entries in one result, each exactly as `get_wiki_entry` returns it.

- **URLs only, no names.** A name can match several entries, and then `get_wiki_entry` returns a
  list to choose from. Ten of those lists in one result would be hard to read.
- **One bad URL doesn't fail the call.** It goes under `errors` with the reason, and the other
  entries still come back. Claude can usually answer with those, and retry the one that failed.
- **At most ten.** A typical entry is 1 to 3 KB, so ten fill 10 to 30 KB of the model's context
  window. URLs after the tenth go under `not_fetched`, with a message to call again with them.
- **Each page is parsed once per call.** Ten skills from one page means one parse, not ten,
  because parsing a big page (`quests.html` is about 600 KB) takes much longer than reading one
  entry from it. The parsed pages last for one call only, so the cache still decides when a page
  is too old.
- **There is no "facts only" option.** It would make the result smaller, but in the build example
  the details that mattered were often outside the facts: a passive's notables are in `sections`,
  and a support rune's effect is in `text`.

## Prerequisites

- **PHP 8.1 or newer** on your PATH (`php -v`) to run the tools. The tests need **PHP 8.4.1 or
  newer**, because PHPUnit 13 requires it. Herd, Laragon or the windows.php.net zip all work.
- **Composer** (`composer -V`).
- **Xdebug or PCOV**, only for `composer test:coverage` and `composer test:mutation`. If Xdebug's `xdebug.start_with_request` is
  `yes`, every PHP command waits about 0.2 seconds for a debugger and prints a "Time-out connecting"
  message to stderr. That's harmless, but `start_with_request = trigger` avoids it.
- PHP's `curl` and `openssl` extensions enabled. Herd and Laragon have them on already. With a plain
  PHP zip, copy `php.ini-development` to `php.ini` and uncomment `extension=curl` and `extension=openssl`.

## Get an API key

1. Sign in at https://console.anthropic.com
2. Add some credit under **Billing** (a run of this demo costs well under a cent).
3. **Settings → API Keys → Create Key**, and copy the `sk-ant-...` value.

## Run it

In PowerShell:

```powershell
cd C:\Users\chris\workspace\aldervast-mcp
composer install
copy .env.example .env      # then open .env and paste your key
php agent.php
php agent.php "Is there a server I can join, and what port is it on?"
```

`.env` is in `.gitignore`. Never commit it.

## What each printed step means

**TURN 1: REQUEST.** The JSON that we POST to `https://api.anthropic.com/v1/messages`. It has two
parts: `messages` (the conversation so far, which is just your question) and `tools` (the list of
functions that the model may ask for). Each tool is only a name, a description and a JSON Schema
for its input. The model reads the description to decide *when* to use the tool.

**TURN 1: RESPONSE, `stop_reason = "tool_use"`.** The model didn't answer. It replied with a
`tool_use` block ("please run `list_servers` with input `{}`") and an `id` such as `toolu_...`.
Your loop decides what to do next from one field, `stop_reason`:

| stop_reason | meaning | what the loop does |
|---|---|---|
| `tool_use` | the model wants us to run a tool | run it, send the result, go round the loop again |
| `end_turn` | the model has finished | print the answer, stop |
| `max_tokens` | the reply reached the length limit | stop (raise `max_tokens`) |
| `refusal` | a safety check declined the request | stop |

You may also see a `thinking` block with an empty `thinking` string. That's the model's reasoning,
which is hidden by default. We send it back unchanged; you can ignore it.

**TOOL CALL.** Our PHP finds the `tool_use` block and runs the matching PHP function,
`ServerTool::run()`, which downloads https://aldervast.online/servers.json. **The model never runs
code or uses the network itself.** Your code does that, and you decide what it may do.

**TOOL RESULT.** We wrap the output in a `tool_result` block whose `tool_use_id` matches the request,
and append it to `messages` as a *user* message.

**TURN 2: REQUEST.** Look at `messages`: it now holds the question, the model's tool_use reply and
our tool_result. The API doesn't remember anything between calls, so every request includes the
whole conversation.

**TURN 2: RESPONSE, `stop_reason = "end_turn"`.** Now that it has the data, the model writes a
normal text answer, printed as **FINAL ANSWER**.

That's the entire "agent": a `for` loop around one HTTP call, where the model chooses which of your
functions to call next. To add a tool, add its class to the list in `src/Tools/Tools.php` (see "How to
add a tool" in AGENTS.md). With several tools, the model can make several calls before it
answers.

The loop itself is in `src/Agent/AgentLoop.php`; `agent.php` only runs it and prints each step. Some
settings there that are worth knowing about:

- `claude-sonnet-5-5` is the model, and `effort: low` keeps a simple question quick and cheap.
- `system` is the same guidance that the MCP server sends to Claude Desktop (`Tools::INSTRUCTIONS`),
  so that this demo and the eval behave like Claude Desktop does.
- `cache_control` turns on prompt caching: the API keeps the start of each request (tools,
  guidance, conversation so far) for a few minutes, and the next request that starts the same way
  reads it at a tenth of the price. Every turn repeats everything before it, so this saves most of
  the input cost. In the printed usage, `cache_read_input_tokens` shows it working.
- `fallbacks: "default"` (with its `anthropic-beta` header) lets the API retry on another model if a
  safety check declines the request. You can safely delete both.
- If a request fails because of a rate limit or an overloaded server, the loop waits 1, 2, then 4
  seconds (plus a little at random) and tries again, up to three times.

## Step 2: the same tools as an MCP server

In `agent.php`, *you* run the loop. MCP (Model Context Protocol) is the standard way to let an app
that already has a loop, such as Claude Code or Claude Desktop, use your tools. `mcp-server.php`
uses the same tool classes, and only adds the MCP part: the app starts the script, and the two
exchange one JSON-RPC message per line over stdin and stdout. The script handles `initialize`,
`tools/list` (which returns the same definitions) and `tools/call` (which runs the same PHP).

### Server instructions

Besides the tools, the server sends **instructions** in its `initialize` reply. This is a short
paragraph (`Tools::INSTRUCTIONS` in `src/Tools/Tools.php`), which the client adds to Claude's
context in every chat that uses the server. Each tool description says what one tool does. The
instructions say when to use the server at all. They tell Claude that its own knowledge of
Aldervast is unreliable, so it should look things up rather than answer from memory, and they give
the usual order of the tools.

The text is part of the server's code, so everyone who installs the server gets the same guidance,
whatever their chat history or memory settings. It's guidance, not a guarantee, and the MCP spec
lets clients ignore it (Claude Desktop and Claude Code use it). Keep it general. Facts about
specific game content belong in the wiki, not in instructions or descriptions. Otherwise they give
Claude the answers to your test questions, and the tests prove nothing.

Try it by hand:

```powershell
'{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1"}}}' | php mcp-server.php
'{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php mcp-server.php
'{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list_servers","arguments":{}}}' | php mcp-server.php
'{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search_wiki","arguments":{"query":"hollow crypt","limit":5}}}' | php mcp-server.php
'{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"get_wiki_entry","arguments":{"url_or_name":"Hollow Crypt","category":"Dungeon"}}}' | php mcp-server.php
'{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"list_category","arguments":{"category":"World boss"}}}' | php mcp-server.php
```

### The tool call log

The server writes one line to `logs/tool-calls.jsonl` for every tool call: the time, a session id,
the tool, its input, the size of the result, whether it failed (with the start of the error), and how
long it took. Each line is one JSON object, a format called JSON Lines. To read it as a list, run:

```powershell
composer log          # the last 50 calls, grouped by session
composer log -- 200   # the last 200
```

```
Session f7f4f290, started 2026-11-21 19:54
  19:54:16  search_wiki     {"query":"deer"}                         730 bytes, 9 ms
  19:54:16  search_wiki     {"query":"stag"}                         1,482 bytes, 9 ms
  19:54:16  get_wiki_entry  {"url_or_name":"Elderhorn, the Moss Stag"}  4,351 bytes, 17 ms
```

The log shows the route that Claude took for each question, in one place, without screenshots. It's
also where the questions for an eval can come from: real questions, in the order that Claude asked.

Some things to know:

- **A session is one server process.** Claude Desktop starts the server once and sends it every call
  until you quit the app, so one session can hold several chats. The calls are in time order.
- **The log stays on your machine.** It holds what Claude searched for, so `logs/` is in `.gitignore`.
  Delete the file whenever you like; the server starts a new one.
- **It never breaks a tool call.** If the file can't be written, the call still works; only that log
  line is lost.
- **Times are in PHP's time zone,** which is UTC unless `date.timezone` is set in `php.ini`. Each time
  includes its offset (for example `+00:00`), so it's never ambiguous.
- **The environment variable `ALDERVAST_TOOL_LOG`** changes where the log goes: a file path writes
  there instead, and `off` turns it off. The tests use it, so they never write to your real log.

### Adding the server to Claude Code

Add it to Claude Code (no API key needed for this part; Claude Code is the client):

```powershell
claude mcp add aldervast -- php C:\Users\chris\workspace\aldervast-mcp\mcp-server.php
claude mcp list          # should show: aldervast ... Connected
```

Then start `claude` and ask "which Aldervast servers are there?", "what level is the Hollow
Crypt?" or "how do I beat the Hollow Crypt's boss?". It will ask permission to use each tool the
first time, call your PHP, and answer. Expand a tool call in the chat to see the exact input and
output. Remove it with `claude mcp remove aldervast`.

### Adding the server to Codex

This project's `.codex/config.toml` registers the same PHP server with Codex. Open this folder
as a trusted project, then start a new Codex session so that the tools are loaded. PHP must be
on the PATH. No Anthropic API key is needed.

The configuration uses Chris's absolute path, as `.mcp.json` does. If you clone the project
elsewhere, change the path in `.codex/config.toml` to your copy of `mcp-server.php`.

### Resources and prompts

Tools are one of three things that an MCP server can offer. The other two are **resources** and
**prompts**. The difference is who decides to use them:

| | Who decides | What this server offers |
|---|---|---|
| Tool | The model, during a chat | The eleven tools above |
| Resource | The person, who attaches it to a chat | Each wiki page, whole, as Markdown |
| Prompt | The person, who picks it like a command | `plan_build` and `plan_levelling` |

The server declares all three in its `initialize` reply (`"capabilities":{"tools":{},"resources":{},"prompts":{}}`).
A client only asks for resources or prompts if they are declared there.

**Resources** (`src/Mcp/WikiPages.php`). `resources/list` returns one resource for each wiki page
that the search index points to, such as `aldervast://wiki/dungeons.html`. The list comes from the
index, so a new page on the site appears without a code change. `resources/read` returns the page's
entries as Markdown: each entry's name, subtitle, URL, description, facts and sections, without the
HTML. A resource suits "read this whole page"; a tool suits "find the one entry that answers this".

Some things to know:

- **The URIs use their own scheme, `aldervast://`,** not the page's `https://` address. The MCP spec
  asks servers to use `https://` only when the client could download the resource itself. This
  server sends something different from the page (Markdown, not HTML), so it uses its own scheme.
- **Only the listed pages can be read.** Any other URI, such as `aldervast://../.env`, gets the
  spec's "resource not found" error, code `-32002`, and the server fetches nothing.
- **Some pages are big.** Most are 1,000 to 17,000 tokens, but `bosses.html` is about 40,000 and
  `quests.html` about 70,000. Attaching one of those fills much of the model's context window
  (the text that the model can hold at once). For a question about one quest, the tools are cheaper.
- **Resource reads aren't in the tool call log.** The log records tool calls only.

**Prompts** (`src/Mcp/Prompts.php`). A prompt is a template for a message from the person.
`prompts/list` returns each prompt with its arguments. `prompts/get` returns the finished message,
with the arguments filled in, and the client sends it to the model as if the person had typed it.
The text tells the model which tools to use and asks it to cite the wiki, so a person gets a
well-formed request without writing one.

| Prompt | Arguments | What it asks for |
|---|---|---|
| `plan_build` | `class`, optional `level` and `playstyle` | A build for the class, with every choice looked up on the wiki and cited |
| `plan_levelling` | `level` (1 to 60) | A plan for that level from `levelling_route`: quests, dungeons and where to go |

An unknown prompt, a missing required argument, or a level that isn't a whole number from 1 to 60
gets the JSON-RPC "invalid params" error, `-32602`.

The prompt text is for the model, like a tool description, so the same rule applies: it may name
the tools, but it holds no facts about specific game content. `PromptsTest` checks each text word
for word, so that a change to the wording is always deliberate.

Where to find them in a client:

- **Claude Code:** type `@` to attach a resource (`@aldervast:aldervast://wiki/dungeons.html`),
  and `/` for a prompt (`/mcp__aldervast__plan_build Warden 25`).
- **Claude Desktop:** both are in the "+" menu under the server's name.

Try them by hand:

```powershell
'{"jsonrpc":"2.0","id":1,"method":"resources/list"}' | php mcp-server.php
'{"jsonrpc":"2.0","id":2,"method":"resources/read","params":{"uri":"aldervast://wiki/classes.html"}}' | php mcp-server.php
'{"jsonrpc":"2.0","id":3,"method":"prompts/list"}' | php mcp-server.php
'{"jsonrpc":"2.0","id":4,"method":"prompts/get","params":{"name":"plan_build","arguments":{"class":"Warden","level":"25"}}}' | php mcp-server.php```

## Step 3: offering the server to other people

The stdio server only works on the PC that runs it. To let other people use the tools, the same
code also runs as a website, at **https://aldervast.versyxdigital.net**. It offers three things:

| Address | What it is | Who it's for |
|---|---|---|
| `/mcp` | The MCP server over HTTP | People with Claude Desktop or claude.ai: they add it as a custom connector |
| `/` and `/chat` | A chatbot page, and the endpoint that answers its questions | Anyone with a browser |
| `/widget.js` | A chat button for other websites, such as aldervast.online | Visitors to those sites |

### The MCP server over HTTP (custom connectors)

In claude.ai or Claude Desktop, add `https://aldervast.versyxdigital.net/mcp` as a custom connector,
with no sign-in. Anthropic's guide shows where:
https://support.claude.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp.
Every plan can add one (the Free plan only one). The person installs nothing, and
gets the same tools, resources and prompts as Claude Code gets locally. Anthropic's servers make the
connection, not the person's PC, so the address must be public and use HTTPS on port 443.

MCP calls this transport **Streamable HTTP**. Each JSON-RPC message is the body of a POST request,
and the reply is the response body. `src/Mcp/HttpTransport.php` does the HTTP part, and
`src/Mcp/Server.php` does the MCP part, the same `Server` that `mcp-server.php` uses over stdio.
Things to know:

- **No sessions.** The spec allows a server to skip them. Nothing is kept between requests, so any
  request can go to any PHP process.
- **GET gets 405.** GET is for a stream of messages from the server, and this server never sends any.
- **Origin is checked.** A request from a web page whose Origin isn't in `ALDERVAST_ALLOWED_ORIGINS`
  gets 403, so that a page in someone's browser can't use the server behind their back.
- **The tool call log is off** on the server, because it would hold strangers' questions.

Try it on the server (or through the public address):

```bash
curl -s https://aldervast.versyxdigital.net/mcp -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_servers","arguments":{}}}'
```

### The chatbot

The chatbot doesn't use MCP. MCP is for apps that run their own agent loop. On the website, the
website is the app: `src/Web/Chat.php` runs a visitor's question through `AgentLoop`, as `agent.php`
does, and returns the answer as JSON. **Every answer costs money on the API key in the server's
`.env`**, so:

- each visitor can ask 10 questions an hour, and everyone together 100 a day (settings below);
- a question can be at most 500 characters;
- it remembers only the last 3 questions and answers, so that a follow-up such as "please do" makes
  sense. The page keeps the conversation and sends it with each question; the server stores none
  of it. `Chat::history()` keeps only whole exchanges in turn order, at most 6 messages, each cut to
  1,500 characters, because the visitor's browser could send anything, and every message sent again
  is paid for again. A follow-up costs more than a first question for that reason;
- it only helps with Aldervast. `Chat::INSTRUCTIONS` is added to its system prompt (through
  `AgentLoop`'s `extraInstructions`). It tells Claude to look up anything that might be in the game,
  and to decline other requests, such as a real-world recipe, in a sentence or two. A declined
  question still costs one small request. The MCP server and the eval don't get this text;
- nothing about the visitor is stored. The counts are kept with hashed IP addresses and forgotten
  after an hour.

Without `ANTHROPIC_API_KEY`, the chatbot replies that it isn't set up, and `/mcp` still works.

### What the chatbot costs

Every question that reaches the API (or is refused by the limits) adds one line to
`cache/chat-usage.jsonl` on the server (`src/Web/UsageLog.php`). A line holds the time, the outcome
(answered, no answer, failed or limited), the model, the token counts, the number of tool calls and
turns, and how long it took. It never holds the question, the answer, or anything about the visitor.

To see it per day, open **https://aldervast.versyxdigital.net/usage**. The page shows today's
questions against the daily limit, the cost today and over 30 days, the cost per answer, a chart of
cost per day, and the table below it (`src/Web/UsageDashboard.php`). It needs the password in
`ALDERVAST_USAGE_PASSWORD` (the browser asks for it; any user name works). Without that setting, the
page doesn't exist. Or run this on the server:

```bash
docker exec aldervast-mcp-web-1 php show-chat-usage.php      # the last 14 days
docker exec aldervast-mcp-web-1 php show-chat-usage.php 60   # the last 60 days
```

```
Day (UTC)   Answered  No answer  Failed  Limited  Input tokens  Output tokens      Cost
2026-11-21         1          0       0        0        11,020            400     $0.02
```

The cost is worked out from the token counts with the eval's price list (`EvalScore::PRICES`), so
it's an estimate. The real bill is in the Claude Console. The file is in the Docker volume, so it
stays when the container is rebuilt.

### The chat widget

`public/widget.js` adds a round chat button to the bottom right of any page, which opens a small
chat panel. It talks to `/chat` on the server that it was loaded from. To add it to a page:

```html
<script src="https://aldervast.versyxdigital.net/widget.js" defer></script>
```

The widget is built inside a Shadow DOM (a part of the page that has its own CSS), so the site's
styles and the widget's styles can't affect each other. Because the page is on another domain, the
browser only lets it read the answers if the server allows that page's origin. The rules for this
are called **CORS**. The page's origin must be in `ALDERVAST_CHAT_ORIGINS`.

### Settings

All settings are environment variables, in `~/aldervast-mcp/.env` on the server. That file never
leaves the server: `deploy/deploy.sh` doesn't send this PC's `.env`, and doesn't overwrite the server's.

| Setting | Default | What it does |
|---|---|---|
| `ANTHROPIC_API_KEY` | none | The key that pays for the chatbot's answers. Without it, the chatbot is off |
| `ALDERVAST_CHAT_PER_HOUR` | `10` | Questions per visitor (IP address) in any 60 minutes |
| `ALDERVAST_CHAT_PER_DAY` | `100` | Questions per day (UTC) from everyone together |
| `ALDERVAST_CHAT_ORIGINS` | `https://aldervast.online` | Sites that may use the widget, separated by commas |
| `ALDERVAST_ALLOWED_ORIGINS` | `https://claude.ai,https://claude.com` | Browser origins that may call `/mcp` |
| `ALDERVAST_USAGE_PASSWORD` | none | The password for the usage page at `/usage`. Without it, that page doesn't exist |
| `ALDERVAST_TOOL_LOG` | off | A file path inside the container turns the tool call log on |

After changing `.env`, run `docker compose up -d` in `~/aldervast-mcp` on the server.

### How it runs on the server

```
visitor or Anthropic --HTTPS--> host Apache (aldervast.versyxdigital.net, Let's Encrypt)
                                   --HTTP--> Docker container on 127.0.0.1:8090
                                                Apache + PHP 8.4 -> public/index.php
```

- **The container** (`Dockerfile`, `docker-compose.yml`) brings its own PHP, so the server needs
  only Docker. Its port is open on `127.0.0.1` only, so the outside world reaches it through the
  host's Apache. Cached wiki pages and the chatbot's counts are kept in a Docker volume.
- **The host's Apache site** (`deploy/aldervast-site.conf`) passes every request to the container,
  and adds HTTPS. `deploy/setup-host.sh` installs it once; it needs `sudo`, so a person runs it.
- **Updating the code:** run `bash deploy/deploy.sh` in Git Bash on this PC. It copies only the
  files that the image needs to `~/aldervast-mcp` on the server, then rebuilds and restarts the
  container. No `sudo` is needed, only membership of the `docker` group.
- The same server runs the game server and other sites. Nothing here touches them: the container
  uses its own port, and the Apache site only answers for its own domain.

## Tests

```powershell
composer test                   # offline tests: fast, free, and no network needed
composer test:live              # tests against the real website
composer test:coverage          # offline tests with code coverage; fails if a tool is below 80%
composer test:coverage-html     # a coverage report to open in a browser: .phpunit.cache/html/index.html
composer test:mutation          # mutation testing (about 90 seconds); fails if the score is below 90%
php tests/update-fixtures.php   # download fresh copies of the pages that the offline tests use
```

You can ask two different questions about an MCP server, and these tests answer only the first:

- **Does each tool work?** The tools are ordinary PHP. The same input always gives the same
  output, so the tests in `tests/` can check them like any other code.
- **Does Claude choose the right tool for a question?** Claude makes that choice, and it can vary.
  To measure it, you need an eval: a set of questions written the way that players ask them, with
  each answer scored. These tests don't do that.

### What the offline tests check

| File | What it checks |
|---|---|
| `WikiEntryParserTest` | Turning a page's HTML into an array: facts, sections, links, table rows, facts inside layout `<div>`s, and refusing unsafe ids |
| `SearchWikiToolTest` | How results are ranked, the category filter, the result limit, the " …" on cut summaries, and HTML codes such as `&#x2019;` |
| `GetWikiEntryToolTest` | Different ways of writing a URL, names that several entries share, suggestions for near misses, refusing other websites, and shortening long output |
| `GetWikiEntriesToolTest` | Several entries in one call: each the same as from `get_wiki_entry`, in the order asked for, a repeated URL fetched once, failed URLs listed with their reasons next to the entries that worked, the limit of ten, and refusing input that isn't a list |
| `WhatsOnToolTest` | What's on at a given moment: a festival that crosses the new year (first day, last day, the days around it), its dungeon opening with it, a season's first and last day, world bosses soonest first and a wake time that has just passed, the next weekly reset, this week's Hourglass omens changing on Monday and repeating, and errors for a moment that isn't a real UTC date |
| `SearchReleasesToolTest` | The release history: releases with more of the words first, then the newest, the matching clauses (and long ones cut), words matched only at the start of a word, one version's whole notes, an unknown version, the list of newest releases, and the limit |
| `FindSkillsToolTest` | Skills by type, tag (ignoring case), what they need in hand and level, all filters together, lowest level first, the limit, the runes that fit a skill (and those left out by "Cannot support"), and errors that list the valid values |
| `FindItemsToolTest` | Item types matched exactly or by a word, highest level first and then by name, both level limits, the fields of base and unique items (and fields left out when an item lacks them), where uniques drop or which reward gives them, the limit, the list of types, and errors for an unknown type or a bad level |
| `ListCategoryToolTest` | The list of categories and their sizes, pages of results for big categories, category labels, and cut summaries |
| `LevellingRouteToolTest` | Which regions, dungeons and quests suit a level: both ends of a region's range, dungeons 2 levels away and not 3, a dungeon that scales, the quest that leads into a dungeon, when a dungeon is open, main-story chapters (and a side quest that a chapter guide links to, which stays unmarked), world boss quests, the 20-quest cut, the note when no region fits, and refused levels |
| `LatestReleaseToolTest` | The newest release: its version, date, whole notes without HTML codes, notes over several lines, a release without a date, and an error when the page has no list of releases or a release has no version |
| `HtmlTest` | Reading HTML: UTF-8 without a `<meta charset>` tag, and libxml's setting put back and its warnings cleared afterwards |
| `ServerToolTest` | The server list: each server's fields, the list without the object around it, and a definition with no inputs |
| `ToolDefinitionsTest` | Every tool's definition: the name matches the tool, the description isn't empty, the input schema is a JSON object, every input has a type and a description, and every required input exists |
| `AldervastSiteTest` | Building URLs and file names, and reading saved copies: with or without a leading slash or a part after "#", and an error (not a download) when a saved copy is missing |
| `WikiPagesTest` | The resources: one per page in the search index, in alphabetical order, their URIs and descriptions, each entry's parts in the Markdown, table rows, a page with one bare entry, and refusing any URI that isn't listed |
| `PromptsTest` | The prompts: their arguments, each prompt's exact text, optional arguments left out when blank, levels 1 and 60 allowed, and errors for an unknown prompt, a missing argument or a bad level |
| `ServerTest` | The MCP requests, answered in this process: version agreement in `initialize`, the tool list, tool calls and failed tools (and that both are logged), resources, prompts, and the spec's error codes |
| `HttpTransportTest` | MCP over HTTP: replies as JSON, 202 for notifications, 405 for GET, 403 for a bad Origin, 400 for an unknown protocol version, bad JSON or a batch, and 413 for a body over the limit |
| `ChatTest` | The chatbot, against recorded API responses: the answer and the tools used, no API key, missing or too long questions (refused before anything is paid for), the rate limit, API failures, its instructions word for word, and a usage line for every outcome without the question's text |
| `UsageReportTest` | The usage log added up per day (every day listed, each outcome counted, damaged lines skipped, unknown prices), and the usage page: its tiles, a bar per day with a cost, hover details, the table, and the page with no questions |
| `UsageLogTest` | The chatbot's usage log: one line per question with every field, missing token counts as 0, lines added to the end, and a file that can't be written breaks nothing |
| `RateLimitTest` | The chatbot's limits: per visitor per hour, per day for everyone, a new day, refused questions not counted, and no IP address in the file |
| `ToolCallLogTest` | The tool call log: one JSON line per call with every field, lines added to the end, errors kept but shortened, broken text still logged, and a file that can't be written doesn't stop the call |
| `AgentLoopTest` | The agent loop, against recorded API responses (no paid calls): tool calls and results, the history sent back, retries and their waits, time limits, a different model answering, refusals, the five-turn limit |
| `EvalScoreTest` | The eval's grading: a right run passes, "I don't know" fails everything, a right answer from memory doesn't pass, confidence intervals, cost |
| `JsonTest`, `EnvTest` | `Json::pretty()`, and `Env::load()` / `Env::get()` (a real environment variable wins over `.env`) |
| `ArchitectureTest` | That `src/Tools/`, `src/Mcp/`, `src/Agent/`, `src/Eval/` and `src/Web/` use only the parts they are allowed to (see the diagram at the top) |
| `EveryToolHasTestsTest` | That every tool in `src/Tools/Tools.php` has its own test class, with at least three tests that call the tool's `run()`. If someone adds a tool without tests, this test fails and says which file to create |
| `McpServerTest` | The MCP protocol. It starts `mcp-server.php` as a separate process, as Claude Desktop does. Then it checks that the server writes only JSON-RPC to its output, doesn't reply to notifications, sends correct schemas, reports tool failures with `isError`, declares resources and prompts, and sends the spec's error codes for an unknown resource or prompt |

### Saved copies of the pages (fixtures)

The offline tests don't use the live website. They use saved copies of ten wiki pages, the
changelog page and `servers.json` in `tests/fixtures/` (about 1.8 MB, committed to git). Saved test data like this is called a
fixture. When the environment variable `ALDERVAST_FIXTURES` is set, `AldervastSite` reads pages
from that folder and doesn't download anything. Each offline test sets it before it runs. As a
result, the tests give the same result every time, work offline, and don't fail because the
website changed.

One file in that folder isn't from the website: `tests/fixtures/wiki_handmade.html` was made by
hand. It holds edge cases that the real pages don't have, such as a value over 600 characters, a
section over 30 lines, exactly 40 links, and a table row with more cells than headings. The comment
at the top of the file lists them all. `tests/update-fixtures.php` never downloads or overwrites it.

### Live tests

Because the offline tests use saved copies, they keep passing when a game release changes the
website. The live tests (`composer test:live`) use the real website instead, through the one-hour
cache. They check three things:

- the search index still has the fields that the tools use,
- summaries are still cut at 140 characters (the code that adds " …" depends on this), and
- the parser can still read a known entry.

If a live test fails, the website has changed:

1. Run `php tests/update-fixtures.php` to download fresh copies of the pages.
2. Run `composer test`. The tests that fail show which parts of the code need to change.
3. Change the code until all tests pass. Then commit the code and the new copies together.

### Code coverage

Code coverage is a record of which lines of code ran while the tests ran. `composer test:coverage`
runs the offline tests with coverage switched on, and then `tests/check-coverage.php` checks the
result: **every tool must have at least 80% of its lines covered**, or the command fails and lists
the tools that are below it. Other classes are shown for information only. For example, most of
`AldervastSite` downloads from the website, so only the live tests run that code.

This needs Xdebug or PCOV. The command switches Xdebug's coverage mode on by itself, so your
`php.ini` can keep its normal Xdebug settings. To see exactly which lines aren't covered, run
`composer test:coverage-html` and open `.phpunit.cache/html/index.html` in a browser.

### Mutation testing

Code coverage shows that a line ran, not that a test would notice if the line were wrong. Mutation
testing checks that. `composer test:mutation` runs **Infection**, which makes hundreds of small
changes to the code in `src/`, one at a time: a `>` becomes `>=`, a `+ 1` becomes `- 1`, a line is
removed. Each changed copy is called a mutant. For each mutant, Infection runs the tests that cover
that line:

- if a test fails, the mutant is **killed**: the tests noticed the change, which is good;
- if every test still passes, the mutant **escaped**: the tests didn't notice.

The **mutation score** (MSI) is the percentage of mutants killed. It is about 94% today, and the
command fails if it drops below 90% (`minMsi` in `infection.json5`). To see which mutants escaped,
read `.phpunit.cache/infection.log`; each entry shows the line and the change.

About 30 mutants always escape, because they don't change what the code does, so 100% isn't
possible. The kinds that are harmless here:

- `explode('#', $path, 2)` changed to `1` or `3`, where only the first part is used, or the part
  after "#" is removed again later anyway;
- `mb_strtolower()` changed to `strtolower()` on text that is always plain ASCII, such as category names;
- `preg_quote()` removed, where the query has already been split on everything that isn't a letter
  or a digit, so no special regex character can reach it;
- `array_values()` removed, where the next step renumbers the array anyway;
- `maxAgeSeconds: 0` changed to `-1` or `1` in `ServerTool`, which works the same in practice.

Infection doesn't change the `definition()` methods (`global-ignore` in `infection.json5`). They are
mostly description text for the model, and no test can say which wording is right.
`ToolDefinitionsTest` checks their structure instead.

Three things to know when you run it:

- It needs Xdebug or PCOV, like code coverage. The Composer command switches Xdebug's coverage mode
  on by itself.
- Herd sets PHP's `auto_prepend_file` to its own `dump()` helper, and Infection refuses to start
  with it. The Composer command switches it off for the run (`-d auto_prepend_file=`).
- Classes must load by PSR-4, not under `autoload.files` in `composer.json`. Composer loads the
  files listed there as soon as PHPUnit starts, before Infection can swap in a mutant, so every
  mutant would escape. That happened here: the first run killed 0 of 579 mutants.

### How the checks fit together

1. `EveryToolHasTestsTest` (in `composer test`): every tool has a test class with at least three tests
   that call its `run()`.
2. `composer test:coverage`: those tests run at least 80% of the tool's code.
3. `composer test:mutation`: the tests notice when that code is changed, for at least 90% of changes.
4. A person reads new tests. No automatic check can tell whether a test checks the right *thing*.

`composer test` takes a few seconds, `composer test:coverage` a little longer, and
`composer test:mutation` about a minute and a half. So use `composer test` while you work, and the
other two before you commit.

## The eval

The tests above check that each tool works. The eval checks the other question: **does Claude pick
the right tools for real questions, and answer correctly?** Claude's route can vary from run to run,
so the eval runs each question more than once and reports a score with a confidence interval.

```powershell
composer eval                                   # every question, 2 runs each (CALLS THE PAID API)
php evals/run.php --only=deer-boss --reps=1     # just one question, once
php evals/run.php --variant=v1                  # a separate set of results, e.g. after a change
```

**The questions** are in `evals/cases.json`: 36 questions written the way that players type them
("whats the crypt boss in aldervast", "aldervast lvl 50 raid?"), 9 of them asked for real in Claude
Desktop or seen in the tool call log. Three ask about things that don't exist (a necromancer class,
a werewolf boss), to check that Claude says no instead of inventing an answer. Every expected
answer was checked against the wiki.

**How each run is scored** (`src/Eval/EvalScore.php`), with no extra API calls:

| Score | Means |
|---|---|
| **Pass** | both of the next two: the headline number |
| Right entry | a tool returned the wiki entry that the question is about |
| Right answer | the final answer contains every expected fact (each one a regular expression) |
| Used tools | Claude used a tool at all, rather than answering from memory |

A run that fails before giving an answer (the API kept failing, the run took longer than 3
minutes, or a different model answered) goes to `errors.jsonl` and isn't counted as a wrong
answer. Two kinds of finished run are counted and shown, but left out of the scores: an answer cut
off at `max_tokens` (status `truncated`), and a run that `AgentLoop`'s own limit of 5 turns stopped
before Claude answered (status `turn_limit`). Claude Desktop has no such limit, so a turn-limit run
says more about the test setup than about Claude. It is still worth a look: in the first baseline,
both runs of the werewolf question stopped there after 8 searches for a boss that doesn't exist.
The report shows such a question with no score; the terminal summary counts the runs.

**What a run prints:** one line per run (PASS or FAIL, the route of tool calls, and why it failed),
then each score as a percentage with its **95% interval**. The interval is how far the score could
move by chance alone: with 36 questions and 2 runs each, about ±12 points. A change that moves the
score by less than that isn't evidence of anything; run more questions or more runs to see smaller
changes.

**Where the results go:** `.claude/hillclimb/wiki-questions/<variant>/`, with `results.jsonl` (one
line per run: scores, route, tokens, time), `traces/` (each full conversation) and `errors.jsonl`.
Running the same command again only runs what's missing, so a crash loses nothing. To start a
variant again, delete its folder.

### Variants: comparing a change

A **variant** is one version of the setup, measured with the same questions and the same grading.
`baseline` is the setup as it is today. `v1`, `v2`, … are later versions, each with one change to
test: a reworded tool description, a parser fix, different server instructions, or another model
(`--model=claude-opus-5-5`).

1. Run the baseline: `composer eval`. Results go in `.claude/hillclimb/wiki-questions/baseline/`.
2. Make **one** change to the code.
3. Run `php evals/run.php --variant=v1`. Results go in a separate folder, `v1/`.
4. Write one line saying what you changed in `.claude/hillclimb/wiki-questions/v1/change.md`. The
   report shows that line next to the variant. The runner doesn't write this file for you.
5. Rebuild the report (below) and compare.

Three rules keep the comparison fair:

- **The variant name is only a label for where the results go.** The runner doesn't switch code
  for you: `v1` measures whatever the code is when you run it. So run the baseline *before* you
  change anything.
- **Change one thing per variant.** If you change two things and the score moves, you can't tell
  which one did it.
- **Keep the questions and the grading fixed.** The harness check (below) stops the eval when the
  runner, agent loop or grader changes. The tools can change, because they are what you're testing.

Only names like `baseline`, `v1`, `v2` work: the report ignores any other folder name.

### Reading the report

Build it with `composer eval:report` (it needs Node), then open
`.claude/hillclimb/wiki-questions/report.html` in a browser. The builder, `evals/build-report-lite.mjs`,
is a copy of the one that comes with the `claude-api` skill in Claude Code. It only reads the
results and writes the report; it doesn't use the network.

Git keeps each variant's `results.jsonl`, so variants can be compared later. The conversations
(`traces/`), `report.html` and `trajectory/` are left out of git (see `.gitignore`): the report can
be rebuilt, and the conversations are bulky. Keep a variant's conversations on your machine for as
long as you want to read them.

- **The header** says how many variants and questions there are, and that the main score is Pass.
- **Variants**, one row per version: `mean pass` is the share of questions that passed, from 0 to 1
  (1.000 is 100%). `truncated` counts answers cut off at `max_tokens`, and `failed attempts` counts
  runs that crashed, timed out, or were answered by another model. Neither is in the score.
- **Cases**, one row per question: its id and tags (topic, and `source: chris` for your own
  questions), then a score per variant, averaged over its runs (1.000 passed every run, 0.500 passed
  one of two, 0.000 failed every run). Click a column header to sort; sorting by score puts the
  failures first.
- **`rep0`, `rep1`** link to each run's full conversation, a JSON list of turns: `system` (the
  instructions), `user` (the question), `tool_call` and `tool_result` (each tool that Claude used,
  with its input and output), and `assistant` (Claude's text, ending with the answer).

The report shows only the Pass score. The other scores, why a run failed, and the cost are in the
terminal summary that the runner prints, and in each line of `results.jsonl` (the `explanation`
field says why each score is 0 or 1).

**The harness check:** the scores are only comparable while the runner, agent loop and grader stay
the same. So `evals/run.php` refuses to start when any of them changed since someone last ran it
with `--approve-harness`. Read the change first, then approve it.

**Cost:** each run is a few API calls. The summary prints the measured cost (failed attempts
included), priced from `EvalScore::PRICES`.

## Troubleshooting

- **`cURL error 60: SSL certificate problem`**: PHP can't find CA certificates. Download
  https://curl.se/ca/cacert.pem, then in `php.ini` set `curl.cainfo = "C:\path\to\cacert.pem"` and
  `openssl.cafile = "C:\path\to\cacert.pem"`.
- **`API error: ... authentication_error`**: the key in `.env` is wrong or has a stray space.
- **`credit balance is too low`**: add credit in the Console under Billing.
