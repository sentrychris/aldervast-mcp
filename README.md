# aldervast-mcp: watch an agent loop happen

A small PHP project that shows what happens inside tool calling. One command sends a question to
Claude together with a set of tools, and prints every step of the conversation, so that you can see
exactly what is sent and received. The same tools also run as an MCP server, so that apps such as
Claude Desktop can use them.

```
agent.php                runs the agent loop on one question and prints every step
src/AgentLoop.php        the agent loop itself (read it from top to bottom)
mcp-server.php           the same tools, offered as an MCP server (step 2 below)
src/Tools.php            the list of every tool; agent.php, mcp-server.php and the tests all read it
src/ServerTool.php       the list_servers tool: its definition, and the PHP that runs it
src/SearchWikiTool.php   the search_wiki tool: searches the game wiki's index
src/GetWikiEntryTool.php the get_wiki_entry tool: returns one full wiki entry as JSON
src/WikiEntryParser.php  turns the HTML of one wiki entry into an array (get_wiki_entry uses it)
src/ListCategoryTool.php the list_category tool: lists every entry in one category, in pages
src/AldervastSite.php    downloads files from aldervast.online, and keeps copies in cache/ for an hour
src/ToolCallLog.php      writes every tool call that the MCP server runs to logs/tool-calls.jsonl
show-log.php             prints that log as a readable list ("composer log")
src/helpers.php          reads .env, and prints JSON in a readable layout
tests/                   PHPUnit tests, with saved copies of wiki pages in tests/fixtures/ (see "Tests")
evals/                   the eval: test questions, and the script that runs them (see "The eval")
src/EvalScore.php        the eval's grading
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
functions to call next. To add a tool, add its class to the list in `src/Tools.php` (see "How to
add a tool" in AGENTS.md). With several tools, the model can make several calls before it
answers.

The loop itself is in `src/AgentLoop.php`; `agent.php` only runs it and prints each step. Some
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
paragraph (`Tools::INSTRUCTIONS` in `src/Tools.php`), which the client adds to Claude's
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
| `ListCategoryToolTest` | The list of categories and their sizes, pages of results for big categories, category labels, and cut summaries |
| `ServerToolTest` | The server list: each server's fields, the list without the object around it, and a definition with no inputs |
| `ToolDefinitionsTest` | Every tool's definition: the name matches the tool, the description isn't empty, the input schema is a JSON object, every input has a type and a description, and every required input exists |
| `AldervastSiteTest` | Building URLs and file names, and reading saved copies: with or without a leading slash or a part after "#", and an error (not a download) when a saved copy is missing |
| `ToolCallLogTest` | The tool call log: one JSON line per call with every field, lines added to the end, errors kept but shortened, broken text still logged, and a file that can't be written doesn't stop the call |
| `AgentLoopTest` | The agent loop, against recorded API responses (no paid calls): tool calls and results, the history sent back, retries and their waits, time limits, a different model answering, refusals, the five-turn limit |
| `EvalScoreTest` | The eval's grading: a right run passes, "I don't know" fails everything, a right answer from memory doesn't pass, confidence intervals, cost |
| `HelpersTest` | `env()`, `load_env()` and `pretty()` |
| `EveryToolHasTestsTest` | That every tool in `src/Tools.php` has its own test class, with at least three tests that call the tool's `run()`. If someone adds a tool without tests, this test fails and says which file to create |
| `McpServerTest` | The MCP protocol. It starts `mcp-server.php` as a separate process, as Claude Desktop does. Then it checks that the server writes only JSON-RPC to its output, doesn't reply to notifications, sends correct schemas, and reports tool failures with `isError` |

### Saved copies of the pages (fixtures)

The offline tests don't use the live website. They use saved copies of seven wiki pages and
`servers.json` in `tests/fixtures/` (about 900 KB, committed to git). Saved test data like this is called a
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

**The questions** are in `evals/cases.json`: 28 questions written the way that players type them
("whats the crypt boss in aldervast", "aldervast lvl 50 raid?"), 6 of them asked for real in Claude
Desktop or seen in the tool call log. Three ask about things that don't exist (a necromancer class,
a werewolf boss), to check that Claude says no instead of inventing an answer. Every expected
answer was checked against the wiki.

**How each run is scored** (`src/EvalScore.php`), with no extra API calls:

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
move by chance alone: with 28 questions and 2 runs each, about ±13 points. A change that moves the
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
