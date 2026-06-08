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
            'instructions' => 'Admidio MCP server. Basic Auth is validated against Admidio users by default, and mutating tools run with that user context. Mutations require mutations_enabled=true and Admidio permissions.',
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
                        'offset' => [
                            'type' => 'integer',
                            'minimum' => 0,
                            'description' => 'Number of matching users to skip for pagination.',
                        ],
                        'fields' => [
                            'description' => 'Optional Admidio profile field names to return, e.g. FIRST_NAME, LAST_NAME, EMAIL. Use "*" or "all" if allow_all_user_fields is enabled.',
                            'oneOf' => [
                                [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                [
                                    'type' => 'string',
                                    'enum' => ['*', 'all'],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'admidio_list_users',
                'description' => 'List Admidio users page by page for exports or full member listings.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => $this->config->maxSearchResults,
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'minimum' => 0,
                            'description' => 'Number of users to skip for pagination.',
                        ],
                        'include_inactive' => [
                            'type' => 'boolean',
                            'description' => 'Include inactive/invalid users. Defaults to false.',
                        ],
                        'role_id' => [
                            'type' => 'integer',
                            'description' => 'Only return users with a current membership in this Admidio role/group.',
                        ],
                        'role_ids' => [
                            'type' => 'array',
                            'description' => 'Only return users with a current membership in one of these Admidio roles/groups.',
                            'items' => ['type' => 'integer'],
                        ],
                        'role_name' => [
                            'type' => 'string',
                            'description' => 'Only return users with a current membership in this Admidio role/group name.',
                        ],
                        'role_names' => [
                            'type' => 'array',
                            'description' => 'Only return users with a current membership in one of these Admidio role/group names.',
                            'items' => ['type' => 'string'],
                        ],
                        'include_former_members' => [
                            'type' => 'boolean',
                            'description' => 'Include former or future memberships for the selected roles. Defaults to false.',
                        ],
                        'membership_active_on' => [
                            'type' => 'string',
                            'description' => 'Date used for current membership filtering in YYYY-MM-DD format. Defaults to today.',
                        ],
                        'fields' => [
                            'description' => 'Optional Admidio profile field names to return, e.g. FIRST_NAME, LAST_NAME, EMAIL. Use "*" or "all" if allow_all_user_fields is enabled.',
                            'oneOf' => [
                                [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                [
                                    'type' => 'string',
                                    'enum' => ['*', 'all'],
                                ],
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'admidio_list_roles',
                'description' => 'List active Admidio roles/groups that can be assigned to users.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Optional role/group name filter.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => $this->config->maxSearchResults,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'admidio_create_user',
                'description' => 'Create an Admidio user, set profile fields, and optionally assign roles/groups.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'login_name' => ['type' => 'string'],
                        'password' => ['type' => 'string'],
                        'profile' => [
                            'type' => 'object',
                            'description' => 'Profile fields keyed by Admidio internal field names, e.g. FIRST_NAME, LAST_NAME, EMAIL.',
                            'additionalProperties' => true,
                        ],
                        'role_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                        ],
                        'role_id' => ['type' => 'integer'],
                        'role_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'role_name' => ['type' => 'string'],
                        'membership_start' => ['type' => 'string'],
                        'membership_end' => ['type' => 'string'],
                    ],
                    'required' => ['login_name', 'profile'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'admidio_update_user',
                'description' => 'Update an Admidio user login, password, active state, and profile fields.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'login_name' => ['type' => 'string'],
                        'password' => ['type' => 'string'],
                        'valid' => ['type' => 'boolean'],
                        'profile' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['user_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'admidio_assign_user_roles',
                'description' => 'Assign or update one or more Admidio role/group memberships for an existing user.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'role_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                        ],
                        'role_id' => ['type' => 'integer'],
                        'role_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'role_name' => ['type' => 'string'],
                        'start_date' => ['type' => 'string'],
                        'end_date' => ['type' => 'string'],
                        'membership_start' => ['type' => 'string'],
                        'membership_end' => ['type' => 'string'],
                        'leader' => ['type' => 'boolean'],
                        'force_period' => ['type' => 'boolean'],
                    ],
                    'required' => ['user_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'admidio_update_user_memberships',
                'description' => 'Update Admidio role/group membership dates and leader state for an existing user.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'role_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                        ],
                        'role_id' => ['type' => 'integer'],
                        'role_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'role_name' => ['type' => 'string'],
                        'membership_start' => [
                            'type' => 'string',
                            'description' => 'Membership start date in YYYY-MM-DD format.',
                        ],
                        'membership_end' => [
                            'type' => 'string',
                            'description' => 'Membership end date in YYYY-MM-DD format.',
                        ],
                        'leader' => [
                            'type' => 'boolean',
                            'description' => 'Set whether the user is a leader in the selected roles/groups.',
                        ],
                        'force_period' => [
                            'type' => 'boolean',
                            'description' => 'Force shortening existing membership periods when needed. Defaults to true.',
                        ],
                    ],
                    'required' => ['user_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'admidio_remove_user_roles',
                'description' => 'Stop current Admidio role/group memberships for an existing user.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'role_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                        ],
                        'role_id' => ['type' => 'integer'],
                        'role_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'role_name' => ['type' => 'string'],
                    ],
                    'required' => ['user_id'],
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
                $this->config->maxSearchResults,
                isset($arguments['offset']) ? (int) $arguments['offset'] : 0,
                $this->userFields($arguments)
            )),
            'admidio_list_users' => $this->toolResult(AdmidioGateway::listUsers(
                isset($arguments['limit']) ? (int) $arguments['limit'] : $this->config->maxSearchResults,
                $this->config->maxSearchResults,
                isset($arguments['offset']) ? (int) $arguments['offset'] : 0,
                isset($arguments['include_inactive']) && (bool) $arguments['include_inactive'],
                $this->userFields($arguments),
                $this->roleIds($arguments),
                $this->roleNames($arguments),
                isset($arguments['include_former_members']) && (bool) $arguments['include_former_members'],
                (string) ($arguments['membership_active_on'] ?? '')
            )),
            'admidio_list_roles' => $this->toolResult(AdmidioGateway::listRoles(
                (string) ($arguments['query'] ?? ''),
                isset($arguments['limit']) ? (int) $arguments['limit'] : $this->config->maxSearchResults,
                $this->config->maxSearchResults,
                $this->config->allowedRoleIds
            )),
            'admidio_create_user' => $this->toolResult(AdmidioGateway::createUser($arguments, $this->config)),
            'admidio_update_user' => $this->toolResult(AdmidioGateway::updateUser($arguments, $this->config)),
            'admidio_assign_user_roles' => $this->toolResult(AdmidioGateway::assignUserRoles($arguments, $this->config)),
            'admidio_update_user_memberships' => $this->toolResult(AdmidioGateway::updateUserMemberships($arguments, $this->config)),
            'admidio_remove_user_roles' => $this->toolResult(AdmidioGateway::removeUserRoles($arguments, $this->config)),
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
            'isError' => isset($data['error']) || ($data['ok'] ?? true) === false,
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

    private function userFields(array $arguments): array
    {
        if (!isset($arguments['fields'])) {
            return $this->config->userFields;
        }

        if ($arguments['fields'] === '*' || $arguments['fields'] === 'all') {
            return $this->config->allowAllUserFields ? ['*' => '*'] : $this->config->userFields;
        }

        if (!is_array($arguments['fields'])) {
            return $this->config->userFields;
        }

        $fields = [];

        foreach ($arguments['fields'] as $fieldName) {
            $fieldName = trim((string) $fieldName);

            if ($fieldName !== '') {
                if ($fieldName === '*' || strtolower($fieldName) === 'all') {
                    return $this->config->allowAllUserFields ? ['*' => '*'] : $this->config->userFields;
                }

                $fields[$fieldName] = $this->fieldAlias($fieldName);
            }
        }

        return $fields !== [] ? $fields : $this->config->userFields;
    }

    private function fieldAlias(string $fieldName): string
    {
        $alias = strtolower(trim($fieldName));
        $alias = preg_replace('/[^a-z0-9_]+/', '_', $alias) ?? '';
        $alias = trim($alias, '_');

        return $alias !== '' ? $alias : 'field';
    }

    private function roleIds(array $arguments): array
    {
        $roleIds = [];

        if (isset($arguments['role_id'])) {
            $roleIds[] = (int) $arguments['role_id'];
        }

        foreach ((array) ($arguments['role_ids'] ?? []) as $roleId) {
            $roleIds[] = (int) $roleId;
        }

        return array_values(array_filter(array_unique($roleIds), static fn (int $roleId): bool => $roleId > 0));
    }

    private function roleNames(array $arguments): array
    {
        $roleNames = [];

        if (isset($arguments['role_name'])) {
            $roleNames[] = (string) $arguments['role_name'];
        }

        foreach ((array) ($arguments['role_names'] ?? []) as $roleName) {
            $roleName = trim((string) $roleName);

            if ($roleName !== '') {
                $roleNames[] = $roleName;
            }
        }

        return array_values(array_unique($roleNames));
    }
}
