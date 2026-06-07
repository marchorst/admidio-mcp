# Admidio MCP Plugin

Ein Admidio-5-Plugin, das einen einfachen MCP-Server per HTTP bereitstellt.
Codex kann den Server als Streamable-HTTP-MCP-Server einbinden und ueber Tools
read-only auf Admidio-Kontext zugreifen.

## Status

Dieses Repository ist ein frischer Startpunkt. Das Plugin liefert:

- HTTP-Endpunkt `mcp.php`
- Basic-Auth per Username und Passwort
- MCP-Methoden `initialize`, `tools/list`, `tools/call`, `ping`
- Read-only Tools:
  - `admidio_health`
  - `admidio_current_user`
  - `admidio_search_users`
  - `admidio_list_roles`
- Schreibende Tools, nur wenn `mutations_enabled = true` in `config.php`:
  - `admidio_create_user`
  - `admidio_update_user`
  - `admidio_assign_user_roles`
  - `admidio_remove_user_roles`

## Installation in Admidio

1. Dieses Verzeichnis als `admidio-mcp` nach `adm_plugins/` kopieren.
2. `config.sample.php` nach `config.php` kopieren.
3. Username und Passwort in `config.php` setzen.
4. Fuer Benutzeranlage/-aenderung in `config.php` `mutations_enabled` auf `true` setzen.
5. Optional `allowed_role_ids` setzen, um Gruppenzuordnungen auf bestimmte Rollen zu begrenzen.
6. In Admidio den Plugin Manager oeffnen und das Plugin installieren.
7. Den MCP-Endpunkt testen:

```bash
curl -u codex:change-me \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}' \
  https://example.org/admidio/adm_plugins/admidio-mcp/mcp.php
```

## Codex-Konfiguration

Codex unterstuetzt fuer Streamable-HTTP-MCP-Server statische oder aus der
Umgebung geladene HTTP-Header. Fuer Basic Auth wird der Header als
Umgebungsvariable gesetzt.

```bash
export ADMIDIO_MCP_AUTH="Basic $(printf 'codex:change-me' | base64)"
```

`~/.codex/config.toml` oder projektbezogen `.codex/config.toml`:

```toml
[mcp_servers.admidio]
url = "https://example.org/admidio/adm_plugins/admidio-mcp/mcp.php"
env_http_headers = { Authorization = "ADMIDIO_MCP_AUTH" }
startup_timeout_sec = 10
tool_timeout_sec = 30
enabled = true
```

Danach Codex neu starten und mit `/mcp` pruefen, ob der Server erreichbar ist.

## Schreibwerkzeuge

Profilfelder werden mit Admidios internen Feldnamen gesetzt. Typische Felder
sind `FIRST_NAME`, `LAST_NAME` und `EMAIL`.

Beispielargumente fuer `admidio_create_user`:

```json
{
  "login_name": "max.mustermann",
  "password": "initial-secret",
  "profile": {
    "FIRST_NAME": "Max",
    "LAST_NAME": "Mustermann",
    "EMAIL": "max@example.org"
  },
  "role_names": ["Mitglieder"]
}
```

Beispielargumente fuer `admidio_assign_user_roles`:

```json
{
  "user_id": 123,
  "role_ids": [4, 7],
  "start_date": "2026-06-07",
  "end_date": "9999-12-31",
  "leader": false
}
```

## Sicherheit

- Nur ueber HTTPS betreiben, da Basic Auth sonst im Klartext uebertragen wird.
- In `config.php` bevorzugt `password_hash()` verwenden.
- Schreiboperationen sind standardmaessig deaktiviert und muessen in `config.php` explizit aktiviert werden.
- Benutzer- und Rollen-Aenderungen verwenden Admidios Entity-Klassen, damit Admidio-seitige Validierung und Changelog-Logik greifen.
- `admidio_search_users` begrenzt Ergebnisse und gibt nur minimale Felder aus.

## Entwicklung

Syntaxcheck:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Smoke-Test ohne Admidio-Installation:

```bash
ADMIDIO_MCP_TEST=1 php tests/mcp_smoke.php
```
