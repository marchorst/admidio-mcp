<?php

declare(strict_types=1);

namespace AdmidioMcp;

use Throwable;

final class Auth
{
    public static function authenticateBasicAuth(Config $config): bool
    {
        [$username, $password] = self::readBasicCredentials();

        if (!is_string($username) || !is_string($password)) {
            return false;
        }

        if ($config->authProvider === 'static') {
            return self::checkStaticCredentials($config, $username, $password);
        }

        return self::checkAdmidioCredentials($username, $password);
    }

    private static function readBasicCredentials(): array
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;

        if ($username !== null && $password !== null) {
            return [$username, $password];
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (stripos($header, 'Basic ') !== 0) {
            return [null, null];
        }

        $decoded = base64_decode(substr($header, 6), true);

        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return [null, null];
        }

        return explode(':', $decoded, 2);
    }

    private static function checkStaticCredentials(Config $config, string $username, string $password): bool
    {
        if (!hash_equals($config->username, $username)) {
            return false;
        }

        if ($config->passwordHash !== '') {
            return password_verify($password, $config->passwordHash);
        }

        return $config->password !== '' && hash_equals($config->password, $password);
    }

    private static function checkAdmidioCredentials(string $username, string $password): bool
    {
        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db) || !defined('TBL_USERS') || !class_exists(self::userClass())) {
            return false;
        }

        try {
            $statement = $db->queryPrepared(
                'SELECT usr_id FROM ' . TBL_USERS . ' WHERE UPPER(usr_login_name) = UPPER(?)',
                [$username]
            );
            $userId = (int) $statement->fetchColumn();

            if ($userId <= 0) {
                return false;
            }

            $userClass = self::userClass();
            $user = new $userClass($db, $GLOBALS['gProfileFields'] ?? null, $userId);
            $totpCode = self::readTotpCode();

            if (!$user->checkLogin($password, false, false, false, false, $totpCode)) {
                return false;
            }

            $GLOBALS['gCurrentUser'] = $user;
            $GLOBALS['gCurrentUserId'] = $userId;

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function readTotpCode(): ?string
    {
        $totpCode = $_SERVER['HTTP_X_ADMIDIO_TOTP'] ?? null;

        if (!is_string($totpCode) || trim($totpCode) === '') {
            return null;
        }

        return trim($totpCode);
    }

    private static function userClass(): string
    {
        return 'Admidio\\Users\\Entity\\User';
    }
}
