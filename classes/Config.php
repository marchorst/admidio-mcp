<?php

declare(strict_types=1);

namespace AdmidioMcp;

final class Config
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $username,
        public readonly string $passwordHash,
        public readonly string $password,
        public readonly int $maxSearchResults,
        public readonly string $pluginDir
    ) {
    }

    public static function load(string $pluginDir): self
    {
        $config = [
            'enabled' => true,
            'username' => 'codex',
            'password_hash' => '',
            'password' => '',
            'max_search_results' => 20,
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
            (string) $config['username'],
            (string) $config['password_hash'],
            (string) $config['password'],
            max(1, min(100, (int) $config['max_search_results'])),
            $pluginDir
        );
    }
}
