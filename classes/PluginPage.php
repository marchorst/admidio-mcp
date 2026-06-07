<?php

declare(strict_types=1);

namespace AdmidioMcp;

final class PluginPage
{
    public static function render(): never
    {
        $config = Config::load(dirname(__DIR__));
        $endpoint = self::endpointUrl();

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Admidio MCP</title>';
        echo '<style>body{font-family:system-ui,sans-serif;max-width:860px;margin:2rem auto;padding:0 1rem;line-height:1.5}code,pre{background:#f4f4f4;border-radius:4px}code{padding:.1rem .25rem}pre{padding:1rem;overflow:auto}.ok{color:#146c2e}.warn{color:#9a3412}</style>';
        echo '</head><body>';
        echo '<h1>Admidio MCP</h1>';
        echo '<p>Status: <strong class="' . ($config->enabled ? 'ok' : 'warn') . '">';
        echo $config->enabled ? 'aktiv' : 'deaktiviert';
        echo '</strong></p>';
        echo '<p>MCP-Endpunkt: <code>' . htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') . '</code></p>';
        echo '<h2>Codex config.toml</h2>';
        echo '<pre>[mcp_servers.admidio]' . "\n";
        echo 'url = "' . htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') . '"' . "\n";
        echo 'env_http_headers = { Authorization = "ADMIDIO_MCP_AUTH" }' . "\n";
        echo 'enabled = true</pre>';
        echo '</body></html>';
        exit;
    }

    private static function endpointUrl(): string
    {
        if (defined('ADMIDIO_URL')) {
            return rtrim(ADMIDIO_URL, '/') . '/adm_plugins/admidio-mcp/mcp.php';
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/adm_plugins/admidio-mcp/index.php');

        return $scheme . '://' . $host . rtrim($path, '/') . '/mcp.php';
    }
}
