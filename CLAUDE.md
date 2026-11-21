# CLAUDE.md

@AGENTS.md

## Claude Code specifics

- This folder's `.mcp.json` registers the `aldervast` MCP server (`php mcp-server.php`). After you change
  the server, tell Chris to restart Claude Code (or run `/mcp` and reconnect) so the new tools are listed.
  Then try them for real by asking a question that needs them.
- `claude mcp list` checks that the server starts and answers `initialize`. Run it from this folder: the
  server is project-scoped (`.mcp.json`), and Claude Code asks once to approve it the first time `claude` starts here.
- For Messages API details (current model IDs, headers, `stop_reason` values, tool use), use the
  `claude-api` skill rather than memory. The demo uses `claude-sonnet-5-5` and `anthropic-version: 2026-10-21`.
- Don't run `php agent.php` without asking: it spends API credit. Testing the MCP server by piping JSON-RPC
  into it costs nothing.
