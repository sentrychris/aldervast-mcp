# aldervast-mcp: watch an agent loop happen

A tiny PHP project that opens the "black box" of tool calling. One command sends a question to
Claude together with one tool, `list_servers`, and prints every step of the conversation so you
can see exactly what goes over the wire.

```
agent.php              the agent loop (about 100 lines, read it top to bottom)
src/ServerTool.php     the list_servers tool: its definition + the PHP that runs it
src/SearchWikiTool.php the search_wiki tool: searches the game wiki's index
src/AldervastSite.php  fetches files from aldervast.online, cached in cache/ for an hour
mcp-server.php         the same tools exposed as an MCP server (optional step 2)
src/helpers.php        .env reader and pretty printing
```

## The tools

| Tool | Input | What it returns |
|---|---|---|
| `list_servers` | none | The public game servers: name, address, port, note. |
| `search_wiki` | `query`, optional `category` and `limit` (default 10, max 25) | The best-matching wiki entries: name, category, short summary, URL. |

`search_wiki` downloads https://aldervast.online/wiki/search.json (about 1,840 entries) and scores
every entry in PHP: a query word in the name counts most, then tags, category and summary, with a
bonus when the whole query is in the name. `category` is case-insensitive and accepts a prefix
(`"hourglass"` matches "Hourglass run" and "Hourglass omen"). An unknown category returns an error
that lists the real ones, so the model can correct itself.

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

Poke it by hand:

```powershell
'{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php mcp-server.php
'{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"list_servers","arguments":{}}}' | php mcp-server.php
'{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search_wiki","arguments":{"query":"hollow crypt","limit":5}}}' | php mcp-server.php
```

Add it to Claude Code (no API key needed for this part; Claude Code is the client):

```powershell
claude mcp add aldervast -- php C:\Users\chris\workspace\aldervast-mcp\mcp-server.php
claude mcp list          # should show: aldervast ... Connected
```

Then start `claude` and ask "which Aldervast servers are there?" or "what level is the Hollow
Crypt?". It will ask permission to use `list_servers` or `search_wiki`, call your PHP, and answer. Remove it with `claude mcp remove aldervast`.

## Troubleshooting

- **`cURL error 60: SSL certificate problem`**: PHP can't find CA certificates. Download
  https://curl.se/ca/cacert.pem, then in `php.ini` set `curl.cainfo = "C:\path\to\cacert.pem"` and
  `openssl.cafile = "C:\path\to\cacert.pem"`.
- **`API error: ... authentication_error`**: the key in `.env` is wrong or has a stray space.
- **`credit balance is too low`**: add credit in the Console under Billing.
