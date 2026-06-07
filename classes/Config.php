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
            array_values(array_filter(array_map('intval', (array) $config['allowed_role_ids']))),
            $pluginDir
        );
    }
}
