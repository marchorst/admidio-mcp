<?php

declare(strict_types=1);

namespace AdmidioMcp;

final class Config
{
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $mutationsEnabled,
        public readonly string $authProvider,
        public readonly string $username,
        public readonly string $passwordHash,
        public readonly string $password,
        public readonly int $maxSearchResults,
        public readonly array $userFields,
        public readonly bool $allowAllUserFields,
        public readonly array $allowedRoleIds,
        public readonly string $pluginDir
    ) {
    }

    public static function load(string $pluginDir): self
    {
        $config = [
            'enabled' => true,
            'mutations_enabled' => false,
            'auth_provider' => 'admidio',
            'username' => 'codex',
            'password_hash' => '',
            'password' => '',
            'max_search_results' => 20,
            'user_fields' => ['FIRST_NAME', 'LAST_NAME', 'EMAIL'],
            'allow_all_user_fields' => false,
            'allowed_role_ids' => [],
        ];

        $configFile = $pluginDir . '/config.php';

        if (is_file($configFile)) {
            $plgMcpConfig = [];
            require $configFile;

            if (isset($plgMcpConfig) && is_array($plgMcpConfig)) {
                $config = array_replace($config, $plgMcpConfig);
            }
        }

        return new self(
            (bool) $config['enabled'],
            (bool) $config['mutations_enabled'],
            (string) $config['auth_provider'],
            (string) $config['username'],
            (string) $config['password_hash'],
            (string) $config['password'],
            max(1, min(100, (int) $config['max_search_results'])),
            self::normalizeUserFields($config['user_fields']),
            (bool) $config['allow_all_user_fields'],
            array_values(array_filter(array_map('intval', (array) $config['allowed_role_ids']))),
            $pluginDir
        );
    }

    private static function normalizeUserFields(mixed $fields): array
    {
        if ($fields === '*' || $fields === 'all') {
            return ['*' => '*'];
        }

        $normalized = [];

        foreach ((array) $fields as $alias => $fieldName) {
            $fieldName = trim((string) $fieldName);

            if ($fieldName === '') {
                continue;
            }

            if ($fieldName === '*' || strtolower($fieldName) === 'all') {
                return ['*' => '*'];
            }

            $normalized[$fieldName] = is_string($alias) ? self::normalizeFieldAlias($alias) : self::defaultFieldAlias($fieldName);
        }

        return $normalized !== [] ? $normalized : [
            'FIRST_NAME' => 'first_name',
            'LAST_NAME' => 'last_name',
            'EMAIL' => 'email',
        ];
    }

    private static function defaultFieldAlias(string $fieldName): string
    {
        return strtolower($fieldName);
    }

    private static function normalizeFieldAlias(string $alias): string
    {
        $alias = strtolower(trim($alias));
        $alias = preg_replace('/[^a-z0-9_]+/', '_', $alias) ?? '';
        $alias = trim($alias, '_');

        return $alias !== '' ? $alias : 'field';
    }
}
