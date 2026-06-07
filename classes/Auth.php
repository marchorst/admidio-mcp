<?php

declare(strict_types=1);

namespace AdmidioMcp;

final class Auth
{
    public static function checkBasicAuth(Config $config): bool
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;

        if ($username === null || $password === null) {
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

            if (stripos($header, 'Basic ') === 0) {
                $decoded = base64_decode(substr($header, 6), true);

                if (is_string($decoded) && str_contains($decoded, ':')) {
                    [$username, $password] = explode(':', $decoded, 2);
                }
            }
        }

        if (!is_string($username) || !is_string($password)) {
            return false;
        }

        if (!hash_equals($config->username, $username)) {
            return false;
        }

        if ($config->passwordHash !== '') {
            return password_verify($password, $config->passwordHash);
        }

        return $config->password !== '' && hash_equals($config->password, $password);
    }
}
