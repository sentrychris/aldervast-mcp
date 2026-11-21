**[`tools/list`](../mcp-server.php#L59) is a protocol operation, not a tool Claude chooses to call**. The Claude app—the harness/MCP client—uses it to discover what your server offers.

`$method` comes from the incoming JSON-RPC message:

```php
$method = $msg['method'] ?? '';
```

For example, the harness sends this over your PHP process’s stdin:

```json
{"jsonrpc":"2.0","id":1,"method":"tools/list"}
```

`case 'tools/list'` responds with the tools’ names, descriptions, and input schemas. **It doesn’t execute any tools.**

The usual sequence is:

| Incoming `$method` | What your PHP server does |
|---|---|
| `initialize` | Announces its identity and capabilities |
| `notifications/initialized` | Receives confirmation that initialization finished; sends no reply |
| `tools/list` | Returns the available tool definitions |
| `tools/call` | Executes the tool named in `params.name` |

When Claude decides to use `list_servers`, the harness translates that decision into something like:

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "list_servers",
    "arguments": {}
  }
}
```

**`$method` identifies the MCP protocol operation sent by the harness.** The model sees the discovered tools and requests their use; the harness handles the protocol messages.