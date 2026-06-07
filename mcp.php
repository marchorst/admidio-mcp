<?php

declare(strict_types=1);

require_once __DIR__ . '/classes/bootstrap.php';

use AdmidioMcp\Auth;
use AdmidioMcp\Config;
use AdmidioMcp\JsonRpcResponse;
use AdmidioMcp\McpServer;

$config = Config::load(__DIR__);

if (!$config->enabled) {
    JsonRpcResponse::sendHttpError(503, 'MCP server is disabled.');
}

if (!Auth::authenticateBasicAuth($config)) {
    header('WWW-Authenticate: Basic realm="Admidio MCP", charset="UTF-8"');
    JsonRpcResponse::sendHttpError(401, 'Authentication required.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonRpcResponse::sendHttpError(405, 'Only POST is supported.');
}

$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, true);

if (!is_array($payload)) {
    JsonRpcResponse::sendJson(JsonRpcResponse::error(null, -32700, 'Parse error.'));
}

$server = new McpServer($config);
$response = $server->handle($payload);

if ($response === null) {
    http_response_code(204);
    exit;
}

JsonRpcResponse::sendJson($response);
