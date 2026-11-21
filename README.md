# aldervast-mcp: watch an agent loop happen

A small PHP project that shows what happens inside tool calling. One command sends a question to
Claude together with a set of tools, and prints every step of the conversation, so that you can see
exactly what is sent and received. The same tools also run as an MCP server, so that apps such as
Claude Desktop can use them.

```
agent.php                the agent loop (about 150 lines; read it from top to bottom)
mcp-server.php           the same tools, offered as an MCP server (step 2 below)
src/Tools.php            the list of every tool; agent.php, mcp-server.php and the tests all read it
src/ServerTool.php       the list_servers tool: its definition, and the PHP that runs it
src/SearchWikiTool.php   the search_wiki tool: searches the game wiki's index
src/GetWikiEntryTool.php the get_wiki_entry tool: returns one full wiki entry as JSON
src/WikiEntryParser.php  turns the HTML of one wiki entry into an array (get_wiki_entry uses it)
src/ListCategoryTool.php the list_category tool: lists every entry in one category, in pages
src/AldervastSite.php    downloads files from aldervast.online, and keeps copies in cache/ for an hour
src/helpers.php          reads .env, and prints JSON in a readable layout
tests/                   PHPUnit tests, with saved copies of wiki pages in tests/fixtures/ (see "Tests")
```

## The tools

| Tool | Input | What it returns |
|---|---|---|
| `list_servers` | none | The public game servers: name, address, port, note. |
| `search_wiki` | `query`, optional `category` and `limit` (default 10, max 25) | The best-matching wiki entries: name, category, short summary, URL. |
| `get_wiki_entry` | `url_or_name`, optional `category` | One full entry: description, facts, sections, and links to the entries it mentions. |
| `list_category` | optional `category`, `offset`, `limit` (default 50, max 100) | Every entry in a category: name, summary, URL. With no category, the categories and their sizes. |

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
`id` is the part of the URL after "#", and turns that element into JSON. `src/WikiEntryParser.php`
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

## Prerequisites

- **PHP 8.1 or newer** on your PATH (`php -v`) to run the tools. The tests need **PHP 8.4.1 or
  newer**, because PHPUnit 13 requires it. Herd, Laragon or the windows.php.net zip all work.
- **Composer** (`composer -V`).
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
functions to call next. To add a tool, add its class to the list in `src/Tools.php` (see "How to
add a tool" in AGENTS.md). With several tools, the model can make several calls before it
answers.

Some settings in `agent.php` that are worth knowing about: `claude-sonnet-5-5` is the model;
`effort: low` keeps a simple question quick and cheap; `fallbacks: "default"` (with its
`anthropic-beta` header) lets the API retry on another model if a safety check declines the
request. You can safely delete both of the last two lines.

## Step 2: the same tools as an MCP server

In `agent.php`, *you* run the loop. MCP (Model Context Protocol) is the standard way to let an app
that already has a loop, such as Claude Code or Claude Desktop, use your tools. `mcp-server.php`
uses the same tool classes, and only adds the MCP part: the app starts the script, and the two
exchange one JSON-RPC message per line over stdin and stdout. The script handles `initialize`,
`tools/list` (which returns the same definitions) and `tools/call` (which runs the same PHP).

### Server instructions

Besides the tools, the server sends **instructions** in its `initialize` reply. This is a short
paragraph (the `INSTRUCTIONS` constant in `mcp-server.php`), which the client adds to Claude's
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

Add it to Claude Code (no API key needed for this part; Claude Code is the client):

```powershell
claude mcp add aldervast -- php C:\Users\chris\workspace\aldervast-mcp\mcp-server.php
claude mcp list          # should show: aldervast ... Connected
```

Then start `claude` and ask "which Aldervast servers are there?", "what level is the Hollow
Crypt?" or "how do I beat the Hollow Crypt's boss?". It will ask permission to use each tool the
first time, call your PHP, and answer. Expand a tool call in the chat to see the exact input and
output. Remove it with `claude mcp remove aldervast`.

## Tests

```powershell
composer test                   # offline tests: fast, free, and no network needed
composer test:live              # tests against the real website
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
| `ListCategoryToolTest` | The list of categories and their sizes, pages of results for big categories, category labels, and cut summaries |
| `ServerToolTest` | The server list: each server's fields, the list without the object around it, and a definition with no inputs |
| `EveryToolHasTestsTest` | That every tool in `src/Tools.php` has its own test class, with at least three tests that call the tool's `run()`. If someone adds a tool without tests, this test fails and says which file to create |
| `McpServerTest` | The MCP protocol. It starts `mcp-server.php` as a separate process, as Claude Desktop does. Then it checks that the server writes only JSON-RPC to its output, doesn't reply to notifications, sends correct schemas, and reports tool failures with `isError` |

### Saved copies of the pages (fixtures)

The offline tests don't use the live website. They use saved copies of seven wiki pages and
`servers.json` in `tests/fixtures/` (about 900 KB, committed to git). Saved test data like this is called a
fixture. When the environment variable `ALDERVAST_FIXTURES` is set, `AldervastSite` reads pages
from that folder and doesn't download anything. Each offline test sets it before it runs. As a
result, the tests give the same result every time, work offline, and don't fail because the
website changed.

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

## Troubleshooting

- **`cURL error 60: SSL certificate problem`**: PHP can't find CA certificates. Download
  https://curl.se/ca/cacert.pem, then in `php.ini` set `curl.cainfo = "C:\path\to\cacert.pem"` and
  `openssl.cafile = "C:\path\to\cacert.pem"`.
- **`API error: ... authentication_error`**: the key in `.env` is wrong or has a stray space.
- **`credit balance is too low`**: add credit in the Console under Billing.
