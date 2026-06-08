# Admidio MCP Plugin

An Admidio 5 plugin that exposes a small HTTP MCP server. Codex can connect to
the endpoint as a streamable HTTP MCP server and use tools to interact with
Admidio data.

## Status

This repository is a fresh starting point. The plugin provides:

- HTTP endpoint `mcp.php`
- Basic Auth with Admidio username and password by default
- MCP methods `initialize`, `tools/list`, `tools/call`, `ping`
- Read-only tools:
  - `admidio_health`
  - `admidio_current_user`
  - `admidio_search_users`
  - `admidio_list_users`
  - `admidio_list_roles`
- Mutating tools, only when `mutations_enabled = true` in `config.php`:
  - `admidio_create_user`
  - `admidio_update_user`
  - `admidio_assign_user_roles`
  - `admidio_remove_user_roles`

## Admidio Installation

1. Copy this directory as `admidio-mcp` into `adm_plugins/`.
2. Copy `config.sample.php` to `config.php`.
3. Keep `auth_provider` set to `admidio` to validate Basic Auth credentials against Admidio users.
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
export ADMIDIO_MCP_AUTH="Basic $(printf 'admidio-user:admidio-password' | base64)"
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

If the Admidio account uses TOTP, pass the current code with an additional
header:

```toml
[mcp_servers.admidio]
url = "https://example.org/admidio/adm_plugins/admidio-mcp/mcp.php"
env_http_headers = { Authorization = "ADMIDIO_MCP_AUTH", X-Admidio-Totp = "ADMIDIO_MCP_TOTP" }
enabled = true
```

## Mutation Tools

Profile fields are set with Admidio internal field names. Common fields are
`FIRST_NAME`, `LAST_NAME`, and `EMAIL`.

Mutating tools run as the authenticated Admidio user:

- `admidio_create_user` requires user-management or registration administration rights.
- `admidio_update_user` uses Admidio profile edit permissions.
- `admidio_assign_user_roles` and `admidio_remove_user_roles` use Admidio role assignment permissions, including role leader rules.

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
- The default `auth_provider = "admidio"` does not use plugin-local passwords.
- If `auth_provider = "static"` is used, prefer `password_hash()` in `config.php`.
- Mutating operations are disabled by default and must be explicitly enabled in `config.php`.
- User and role changes use Admidio entity classes so Admidio validation and changelog logic can apply.
- `admidio_search_users` and `admidio_list_users` limit result counts and return only minimal fields.

## User Listing and Search

Use `admidio_search_users` for filtered lookup by login name, profile data,
name, or email. The query must contain at least two characters. Use
`admidio_list_users` for full member exports and walk through pages with
`offset` and `limit`.

Example arguments for `admidio_list_users`:

```json
{
  "limit": 100,
  "offset": 0,
  "include_inactive": false,
  "fields": ["FIRST_NAME", "LAST_NAME", "EMAIL"]
}
```

If enabled in `config.php`, callers can request every profile field:

```json
{
  "limit": 100,
  "offset": 0,
  "fields": "all"
}
```

To list only members of a role/group, pass either `role_id`, `role_ids`,
`role_name`, or `role_names`:

```json
{
  "limit": 100,
  "offset": 0,
  "role_name": "Members",
  "fields": "all"
}
```

By default, role filters only include memberships active today. Set
`membership_active_on` to another `YYYY-MM-DD` date, or set
`include_former_members` to `true` to include former and future memberships for
the selected role/group.

Both tools return a `pagination` object with `limit`, `offset`, `count`,
`has_more`, and `next_offset`.

The default returned profile fields can be configured in `config.php`:

```php
$plgMcpConfig = [
    'user_fields' => [
        'FIRST_NAME',
        'LAST_NAME',
        'EMAIL',
        'phone' => 'PHONE',
    ],
    'allow_all_user_fields' => true,
];
```

To return all profile fields by default, set:

```php
$plgMcpConfig = [
    'user_fields' => '*',
    'allow_all_user_fields' => true,
];
```

Numeric keys generate lowercase JSON keys such as `FIRST_NAME` to `first_name`.
String keys set the JSON output key explicitly, as in `phone`.

## Development

Syntax check:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Smoke test without an Admidio installation:

```bash
ADMIDIO_MCP_TEST=1 php tests/mcp_smoke.php
```
