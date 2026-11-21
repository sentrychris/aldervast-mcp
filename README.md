# aldervast-mcp: watch an agent loop happen

A tiny PHP project that opens the "black box" of tool calling. One command sends a question to
Claude together with one tool, `list_servers`, and prints every step of the conversation so you
can see exactly what goes over the wire.

```
agent.php              the agent loop (about 100 lines, read it top to bottom)
src/ServerTool.php     the list_servers tool: its definition + the PHP that runs it
src/SearchWikiTool.php the search_wiki tool: searches the game wiki's index
src/GetWikiEntryTool.php the get_wiki_entry tool: one full wiki entry as JSON
src/WikiEntryParser.php  turns one entry's HTML into an array (used by get_wiki_entry)
src/ListCategoryTool.php the list_category tool: every entry in one category, in pages
src/AldervastSite.php  fetches files from aldervast.online, cached in cache/ for an hour
mcp-server.php         the same tools exposed as an MCP server (optional step 2)
src/helpers.php        .env reader and pretty printing
```

## The tools

| Tool | Input | What it returns |
|---|---|---|
| `list_servers` | none | The public game servers: name, address, port, note. |
| `search_wiki` | `query`, optional `category` and `limit` (default 10, max 25) | The best-matching wiki entries: name, category, short summary, URL. |
| `get_wiki_entry` | `url_or_name`, optional `category` | One full entry: description, facts, sections, and links to the entries it mentions. |
| `list_category` | optional `category`, `offset`, `limit` (default 50, max 100) | Every entry in a category: name, summary, URL. With no category, the categories and their sizes. |

### When search misses: words versus meaning

`search_wiki` matches **words**, and players don't use the wiki's words. Ask "what's that big deer
boss called?" and the search finds nothing useful: the wiki calls it "Elderhorn, the Moss Stag", and
no entry uses the word "deer" for it. Scoring tweaks can't fix that, because you can't rank a word
that isn't there. (We tried weighting rare words higher; "boss" in a name still outscored "deer" in
a summary.)

Two things close the gap, and both lean on the model, which is good at matching meaning:

1. **`search_wiki`'s description tells Claude to rephrase.** If the results don't fit, search again
   with synonyms or related words. "stag" or "antler" go straight to Elderhorn.
2. **`list_category` lets Claude browse.** Listing the ten world bosses puts "Elderhorn, the Moss
   Stag" in front of Claude, and it recognises the deer itself. It's also handy on its own:
   "every group dungeon and its level", "what mounts are there?"

The heavier fix, if you ever need it, is **semantic search with embeddings**: turn every entry and
every question into vectors and rank by closeness of meaning. It's the core of what people call RAG,
but it needs an embeddings provider (such as Voyage AI), an API key and a precomputed index.

`search_wiki` downloads https://aldervast.online/wiki/search.json (about 1,840 entries) and scores
every entry in PHP: a query word in the name counts most, then tags, category and summary, with a
bonus when the whole query is in the name. The index cuts summaries at 140 characters, often mid-word, so a cut summary is shown ending in
" …" (and HTML entities are decoded). Without that mark the model can't tell a cut summary from a
complete one, and quietly finishes the sentence itself; with it, the server instructions tell Claude
to fetch the full entry instead of guessing. `category` is case-insensitive and accepts a prefix
(`"hourglass"` matches "Hourglass run" and "Hourglass omen"). An unknown category returns an error
that lists the real ones, so the model can correct itself.

### What get_wiki_entry is for

`search_wiki` only sees the search index, and every summary there is one short line. For the Hollow
Crypt dungeon the whole summary is "Level 5 dungeon with 11 rooms". The things a player actually asks
about are only on the full wiki entry:

- **"Who's the boss of the Hollow Crypt?"** Warden Ossric, the Hollowed (the dungeon's facts).
- **"Can I do it solo?"** Yes, with smaller packs and weaker bosses (its "Walked alone" fact).
- **"How do I beat Ossric?"** His phases and encounter journal (sections on the boss entry).
- **"What does the quest give me?"** 900 experience, 80 gold, a rare item... (the quest's Rewards fact).

It also lets Claude **follow the wiki**, the way you'd click through it. Every entry comes back with
`links`: the other entries it mentions, name to URL. So "What does the Hollow Crypt boss drop?" can
be answered in a chain: `search_wiki` finds the dungeon, `get_wiki_entry` on the dungeon gives the
boss, and `get_wiki_entry` on the boss's link gives its details. With only `search_wiki`, Claude
would have to say it doesn't know, or worse, guess.

So the pair works like a search box and a page view: `search_wiki` to find, `get_wiki_entry` to read.

### How get_wiki_entry works

You (or the model) pass either a URL from `search_wiki`, or a name. A URL is the reliable way. With a
name, it looks the name up in the search index, ignoring case and a leading "The ". Many names are
shared (the Hollow Crypt is a place, a quest, a dungeon and an Hourglass run), so then it returns
the list of matches instead of guessing, and the model calls again with a URL or adds a `category`.
A name with no exact match returns the closest search results.

It fetches the page (cached, like `search.json`), finds the element whose `id` is the URL's
`#fragment`, and turns it into JSON. `src/WikiEntryParser.php` handles the two shapes the wiki uses:

- **Articles** (most pages): `name`, `sub` (kind and level), `description` (the lore), `facts` (the
  label/value list), `sections` (each `<h4>` heading, such as "Objectives" or "Phases", with its
  lines), and `links`.
- **Table rows** (base items, achievements): the column headings become the `facts`, such as
  Level, Requires and Base stats, and the heading above the table becomes `sub`.

Links inside the text become plain names, so the facts read naturally, and the URLs go in `links`.
Pictures and layout markup are dropped. To keep a result small (it all lands in the model's context
window), long values are cut at 600 characters, sections at 30 lines and `links` at 40, and each cut
says so. A typical entry is 1 to 3 KB; the biggest (whole main-story chapters) are about 9 KB.

The download is cached in `cache/` (gitignored) for an hour, because the site only changes on a game
release. Delete `cache/` to force a fresh copy.

## Prerequisites

- **PHP 8.1+** on your PATH (`php -v`). Herd, Laragon or the windows.php.net zip all work.
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

**TURN 1: REQUEST.** The JSON we POST to `https://api.anthropic.com/v1/messages`. Note the two
halves: `messages` (the conversation so far, just your question) and `tools` (the menu of functions
the model may ask for). The tool is only a name, a description and a JSON Schema for its input. The
description is how the model decides *when* to use it.

**TURN 1: RESPONSE, `stop_reason = "tool_use"`.** The model didn't answer. It replied with a
`tool_use` block: "please run `list_servers` with input `{}`", plus an `id` like `toolu_...`.
`stop_reason` is the single field your loop branches on:

| stop_reason | meaning | what the loop does |
|---|---|---|
| `tool_use` | the model wants a tool run | run it, send the result, loop again |
| `end_turn` | the model has finished | print the answer, stop |
| `max_tokens` | the reply hit the length cap | stop (raise `max_tokens`) |
| `refusal` | a safety check declined it | stop |

You may also see a `thinking` block with an empty `thinking` string. That's the model's reasoning,
hidden by default. We pass it back untouched; you can ignore it.

**TOOL CALL.** Our PHP picks out the `tool_use` block and runs the matching PHP function,
`ServerTool::run()`, which fetches https://aldervast.online/servers.json. **The model never runs
code or touches the network itself.** Your code does, and you decide what's allowed.

**TOOL RESULT.** We wrap the output in a `tool_result` block whose `tool_use_id` matches the request,
and append it to `messages` as a *user* message.

**TURN 2: REQUEST.** Look at `messages`: it now holds the question, the model's tool_use reply and
our tool_result. The API keeps no state between calls, so every request carries the whole history.

**TURN 2: RESPONSE, `stop_reason = "end_turn"`.** With the data in hand the model writes a normal
text answer, printed as **FINAL ANSWER**.

That's the entire "agent": a `for` loop around one HTTP call, where the model chooses which of your
functions to call next. Add more tools to the `$tools` array and a `match` arm for each, and it can
chain several calls before it answers.

A few settings in `agent.php` worth knowing: `claude-sonnet-5-5` is the model; `effort: low` keeps a
simple question quick and cheap; `fallbacks: "default"` (with its `anthropic-beta` header) lets the
API retry on another model if a safety check declines. You can delete both lines safely.

## Step 2: the same tool as an MCP server

In `agent.php` *you* own the loop. MCP (Model Context Protocol) is the standard plug for when an app
that already has a loop, like Claude Code, should be able to use your tool. `mcp-server.php` reuses
the same `ServerTool` class and just speaks MCP: the app starts the script and exchanges one JSON-RPC
message per line over stdin/stdout. It handles `initialize`, `tools/list` (returns the same definition)
and `tools/call` (runs the same PHP).

### Server instructions

Besides the tools, the server sends **instructions** in its `initialize` reply: a short paragraph
(the `INSTRUCTIONS` constant in `mcp-server.php`) that the client adds to Claude's context in every
chat where the server is connected. Tool descriptions say what each tool does; the instructions say
when to use the server at all: Claude's own knowledge of Aldervast is unreliable, so look things up
rather than answer from memory, and here is the usual order of tools.

Because the text lives in the server's code, everyone who installs the server gets the same guidance,
whatever their chat history or memory settings. It's guidance, not a guarantee, and the MCP spec lets
clients ignore it (Claude Desktop and Claude Code use it). Keep it general: facts about specific game
content belong in the wiki, not in instructions or descriptions, or they leak answers into your tests.

Poke it by hand:

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

## Troubleshooting

- **`cURL error 60: SSL certificate problem`**: PHP can't find CA certificates. Download
  https://curl.se/ca/cacert.pem, then in `php.ini` set `curl.cainfo = "C:\path\to\cacert.pem"` and
  `openssl.cafile = "C:\path\to\cacert.pem"`.
- **`API error: ... authentication_error`**: the key in `.env` is wrong or has a stray space.
- **`credit balance is too low`**: add credit in the Console under Billing.
