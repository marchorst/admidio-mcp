<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/bootstrap.php';

use AdmidioMcp\Config;
use AdmidioMcp\McpServer;

$server = new McpServer(new Config(true, true, 'admidio', 'codex', '', 'change-me', 20, [], dirname(__DIR__)));

$initialize = $server->handle([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-03-26',
        'capabilities' => [],
        'clientInfo' => [
            'name' => 'smoke',
            'version' => '1.0',
        ],
    ],
]);

$tools = $server->handle([
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'tools/list',
]);

if (!is_array($initialize) || !isset($initialize['result']['serverInfo']['name'])) {
    fwrite(STDERR, "initialize failed\n");
    exit(1);
}

if (!is_array($tools) || count($tools['result']['tools'] ?? []) !== 8) {
    fwrite(STDERR, "tools/list failed\n");
    exit(1);
}

echo "MCP smoke test passed\n";
