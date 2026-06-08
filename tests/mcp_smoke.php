<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/bootstrap.php';

use AdmidioMcp\Config;
use AdmidioMcp\McpServer;

$server = new McpServer(new Config(
    true,
    true,
    'admidio',
    'codex',
    '',
    'change-me',
    20,
    ['FIRST_NAME' => 'first_name', 'LAST_NAME' => 'last_name', 'EMAIL' => 'email'],
    true,
    [],
    dirname(__DIR__)
));

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

if (!is_array($tools) || count($tools['result']['tools'] ?? []) !== 9) {
    fwrite(STDERR, "tools/list failed\n");
    exit(1);
}

$toolNames = array_column($tools['result']['tools'], 'name');

if (!in_array('admidio_list_users', $toolNames, true)) {
    fwrite(STDERR, "admidio_list_users missing\n");
    exit(1);
}

$listUsersTool = $tools['result']['tools'][array_search('admidio_list_users', $toolNames, true)] ?? null;

if (!isset($listUsersTool['inputSchema']['properties']['fields'])) {
    fwrite(STDERR, "admidio_list_users fields schema missing\n");
    exit(1);
}

$listUsers = $server->handle([
    'jsonrpc' => '2.0',
    'id' => 3,
    'method' => 'tools/call',
    'params' => [
        'name' => 'admidio_list_users',
        'arguments' => [
            'limit' => 10,
            'offset' => 0,
        ],
    ],
]);

if (!is_array($listUsers) || ($listUsers['result']['isError'] ?? false) !== true) {
    fwrite(STDERR, "admidio_list_users call failed\n");
    exit(1);
}

echo "MCP smoke test passed\n";
