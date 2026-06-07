<?php

declare(strict_types=1);

namespace AdmidioMcp;

use Throwable;

final class McpServer
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? null;

        if (!array_key_exists('id', $request) && is_string($method) && str_starts_with($method, 'notifications/')) {
            return null;
        }

        if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            return JsonRpcResponse::error($id, -32600, 'Invalid Request.');
        }

        try {
            return match ($method) {
                'initialize' => JsonRpcResponse::result($id, $this->initialize()),
                'ping' => JsonRpcResponse::result($id, (object) []),
                'tools/list' => JsonRpcResponse::result($id, ['tools' => $this->tools()]),
                'tools/call' => JsonRpcResponse::result($id, $this->callTool($request['params'] ?? [])),
                'resources/list' => JsonRpcResponse::result($id, ['resources' => []]),
                'prompts/list' => JsonRpcResponse::result($id, ['prompts' => []]),
                default => JsonRpcResponse::error($id, -32601, 'Method not found.'),
            };
        } catch (Throwable $exception) {
            return JsonRpcResponse::error($id, -32603, 'Internal error.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [
                'tools' => (object) [],
            ],
            'serverInfo' => [
                'name' => 'admidio-mcp',
                'version' => '0.1.0',
            ],
            'instructions' => 'Read-only Admidio MCP server. Use tools to inspect health, current Admidio user context, and search users. Do not assume write access; this server intentionally exposes no mutating tools.',
        ];
    }

    private function tools(): array
    {
        return [
            [
                'name' => 'admidio_health',
                'description' => 'Return basic Admidio MCP endpoint health and detected Admidio constants.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'admidio_current_user',
                'description' => 'Return the current Admidio session user if the plugin is executed inside an authenticated Admidio session.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'admidio_search_users',
                'description' => 'Search active Admidio users by name or email and return minimal profile data.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search text. At least two characters.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => $this->config->maxSearchResults,
                        ],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    private function callTool(mixed $params): array
    {
        if (!is_array($params) || !isset($params['name']) || !is_string($params['name'])) {
            return $this->toolError('Missing tool name.');
        }

        $arguments = isset($params['arguments']) && is_array($params['arguments'])
            ? $params['arguments']
            : [];

        return match ($params['name']) {
            'admidio_health' => $this->toolResult(AdmidioGateway::health()),
            'admidio_current_user' => $this->toolResult(AdmidioGateway::currentUser()),
            'admidio_search_users' => $this->toolResult(AdmidioGateway::searchUsers(
                (string) ($arguments['query'] ?? ''),
                isset($arguments['limit']) ? (int) $arguments['limit'] : $this->config->maxSearchResults,
                $this->config->maxSearchResults
            )),
            default => $this->toolError('Unknown tool: ' . $params['name']),
        };
    }

    private function toolResult(array $data): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ],
            'isError' => false,
        ];
    }

    private function toolError(string $message): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
            'isError' => true,
        ];
    }
}
