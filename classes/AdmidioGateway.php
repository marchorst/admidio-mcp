<?php

declare(strict_types=1);

namespace AdmidioMcp;

use Throwable;

final class AdmidioGateway
{
    public static function health(): array
    {
        return [
            'ok' => true,
            'admidio_loaded' => defined('ADMIDIO_VERSION') || defined('ADMIDIO_URL'),
            'admidio_version' => defined('ADMIDIO_VERSION') ? ADMIDIO_VERSION : null,
            'admidio_url' => defined('ADMIDIO_URL') ? ADMIDIO_URL : null,
            'php_version' => PHP_VERSION,
        ];
    }

    public static function currentUser(): array
    {
        $user = $GLOBALS['gCurrentUser'] ?? null;

        if (!is_object($user)) {
            return [
                'authenticated' => false,
                'user' => null,
            ];
        }

        return [
            'authenticated' => true,
            'user' => [
                'id' => self::readObjectValue($user, ['getValue'], ['usr_id', 'id']),
                'login_name' => self::readObjectValue($user, ['getValue'], ['usr_login_name', 'login_name']),
                'first_name' => self::readObjectValue($user, ['getValue'], ['FIRST_NAME', 'first_name']),
                'last_name' => self::readObjectValue($user, ['getValue'], ['LAST_NAME', 'last_name']),
                'email' => self::readObjectValue($user, ['getValue'], ['EMAIL', 'email']),
            ],
        ];
    }

    public static function searchUsers(string $query, int $limit, int $maxLimit): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [
                'users' => [],
                'error' => 'Query must contain at least two characters.',
            ];
        }

        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            return [
                'users' => [],
                'error' => 'Admidio database object is not available.',
            ];
        }

        $limit = max(1, min($limit, $maxLimit));
        $tablePrefix = defined('TBL_USERS') ? '' : self::detectTablePrefix();

        if (defined('TBL_USERS')) {
            $usersTable = TBL_USERS;
            $userDataTable = defined('TBL_USER_DATA') ? TBL_USER_DATA : $tablePrefix . 'user_data';
            $profileFieldsTable = defined('TBL_PROFILE_FIELDS') ? TBL_PROFILE_FIELDS : $tablePrefix . 'profile_fields';
        } else {
            $usersTable = $tablePrefix . 'users';
            $userDataTable = $tablePrefix . 'user_data';
            $profileFieldsTable = $tablePrefix . 'profile_fields';
        }

        $escapedLike = self::escapeLike($db, '%' . $query . '%');
        $sql = "
            SELECT DISTINCT
                usr.usr_id,
                usr.usr_login_name,
                MAX(CASE WHEN fields.usf_name_intern = 'FIRST_NAME' THEN data.usd_value END) AS first_name,
                MAX(CASE WHEN fields.usf_name_intern = 'LAST_NAME' THEN data.usd_value END) AS last_name,
                MAX(CASE WHEN fields.usf_name_intern = 'EMAIL' THEN data.usd_value END) AS email
            FROM {$usersTable} usr
            LEFT JOIN {$userDataTable} data
                ON data.usd_usr_id = usr.usr_id
            LEFT JOIN {$profileFieldsTable} fields
                ON fields.usf_id = data.usd_usf_id
            WHERE usr.usr_valid = 1
                AND (
                    usr.usr_login_name LIKE {$escapedLike}
                    OR data.usd_value LIKE {$escapedLike}
                )
            GROUP BY usr.usr_id, usr.usr_login_name
            ORDER BY last_name ASC, first_name ASC, usr.usr_login_name ASC
        ";

        try {
            $rows = self::queryRows($db, $sql, $limit);
        } catch (Throwable $exception) {
            return [
                'users' => [],
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'users' => array_map(static fn (array $row): array => [
                'id' => isset($row['usr_id']) ? (int) $row['usr_id'] : null,
                'login_name' => $row['usr_login_name'] ?? null,
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
                'email' => $row['email'] ?? null,
            ], $rows),
        ];
    }

    private static function readObjectValue(object $object, array $methods, array $keys): mixed
    {
        foreach ($methods as $method) {
            if (!method_exists($object, $method)) {
                continue;
            }

            foreach ($keys as $key) {
                try {
                    $value = $object->{$method}($key);

                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                } catch (Throwable) {
                }
            }
        }

        foreach ($keys as $key) {
            if (isset($object->{$key})) {
                return $object->{$key};
            }
        }

        return null;
    }

    private static function escapeLike(object $db, string $value): string
    {
        if (method_exists($db, 'escapeString')) {
            return "'" . $db->escapeString($value) . "'";
        }

        if (method_exists($db, 'escape')) {
            return "'" . $db->escape($value) . "'";
        }

        return "'" . addslashes($value) . "'";
    }

    private static function queryRows(object $db, string $sql, int $limit): array
    {
        if (method_exists($db, 'queryPrepared')) {
            $statement = $db->queryPrepared($sql . ' LIMIT ' . $limit);
            return self::fetchRows($statement, $limit);
        }

        if (method_exists($db, 'query')) {
            $statement = $db->query($sql . ' LIMIT ' . $limit);
            return self::fetchRows($statement, $limit);
        }

        throw new \RuntimeException('Unsupported Admidio database object.');
    }

    private static function fetchRows(mixed $statement, int $limit): array
    {
        $rows = [];

        if (is_array($statement)) {
            return array_slice($statement, 0, $limit);
        }

        while (count($rows) < $limit && is_object($statement)) {
            if (method_exists($statement, 'fetch')) {
                $row = $statement->fetch();
            } elseif (method_exists($statement, 'fetch_assoc')) {
                $row = $statement->fetch_assoc();
            } else {
                break;
            }

            if ($row === false || $row === null) {
                break;
            }

            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function detectTablePrefix(): string
    {
        foreach (['g_tbl_praefix', 'gTablePrefix', 'gDbPrefix'] as $globalName) {
            if (isset($GLOBALS[$globalName]) && is_string($GLOBALS[$globalName])) {
                return $GLOBALS[$globalName];
            }
        }

        return 'adm_';
    }
}
