# Admidio MCP Plugin

An Admidio 5 plugin that exposes a small HTTP MCP server. Codex can connect to
the endpoint as a streamable HTTP MCP server and use tools to interact with
Admidio data.

## Status

This repository is a fresh starting point. The plugin provides:

- HTTP endpoint `mcp.php`
- Basic Auth with username and password
- MCP methods `initialize`, `tools/list`, `tools/call`, `ping`
- Read-only tools:
  - `admidio_health`
  - `admidio_current_user`
  - `admidio_search_users`
  - `admidio_list_roles`
- Mutating tools, only when `mutations_enabled = true` in `config.php`:
  - `admidio_create_user`
  - `admidio_update_user`
  - `admidio_assign_user_roles`
  - `admidio_remove_user_roles`

## Admidio Installation

1. Copy this directory as `admidio-mcp` into `adm_plugins/`.
2. Copy `config.sample.php` to `config.php`.
3. Set the username and password in `config.php`.
4. To create or update users, set `mutations_enabled` to `true` in `config.php`.
5. Optionally set `allowed_role_ids` to restrict role assignments.
6. Open the Admidio Plugin Manager and install the plugin.
7. Test the MCP endpoint:

```bash
curl -u codex:change-me \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}' \
  https://example.org/admidio/adm_plugins/admidio-mcp/mcp.php
```

## Codex Configuration

Codex supports static HTTP headers and environment-backed HTTP headers for
streamable HTTP MCP servers. For Basic Auth, set the `Authorization` header via
an environment variable.

```bash
export ADMIDIO_MCP_AUTH="Basic $(printf 'codex:change-me' | base64)"
```

`~/.codex/config.toml` or project-scoped `.codex/config.toml`:

```toml
[mcp_servers.admidio]
url = "https://example.org/admidio/adm_plugins/admidio-mcp/mcp.php"
env_http_headers = { Authorization = "ADMIDIO_MCP_AUTH" }
startup_timeout_sec = 10
tool_timeout_sec = 30
enabled = true
```

Restart Codex and use `/mcp` to verify that the server is reachable.

## Mutation Tools

Profile fields are set with Admidio internal field names. Common fields are
`FIRST_NAME`, `LAST_NAME`, and `EMAIL`.

Example arguments for `admidio_create_user`:

```json
{
  "login_name": "max.mustermann",
  "password": "initial-secret",
  "profile": {
    "FIRST_NAME": "Max",
    "LAST_NAME": "Mustermann",
    "EMAIL": "max@example.org"
  },
  "role_names": ["Members"]
}
```

Example arguments for `admidio_assign_user_roles`:

```json
{
  "user_id": 123,
  "role_ids": [4, 7],
  "start_date": "2026-06-07",
  "end_date": "9999-12-31",
  "leader": false
}
```

## Security

- Run this endpoint only over HTTPS, otherwise Basic Auth credentials are sent in clear text.
- Prefer `password_hash()` in `config.php`.
- Mutating operations are disabled by default and must be explicitly enabled in `config.php`.
- User and role changes use Admidio entity classes so Admidio validation and changelog logic can apply.
- `admidio_search_users` limits result counts and returns only minimal fields.

## Development

Syntax check:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Smoke test without an Admidio installation:

```bash
ADMIDIO_MCP_TEST=1 php tests/mcp_smoke.php
```
