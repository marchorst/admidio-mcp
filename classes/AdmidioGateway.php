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
                'rights' => self::currentUserRights($user),
            ],
        ];
    }

    public static function getUser(array $arguments, array $fields = []): array
    {
        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            return [
                'user' => null,
                'error' => 'Admidio database object is not available.',
            ];
        }

        $tablePrefix = self::detectTablePrefix();
        $usersTable = defined('TBL_USERS') ? TBL_USERS : $tablePrefix . 'users';
        $userDataTable = defined('TBL_USER_DATA') ? TBL_USER_DATA : $tablePrefix . 'user_data';
        [$profileSelects, $selectParams, $fieldAliases] = self::userProfileSelects($fields);
        $where = [];
        $whereParams = [];

        if (isset($arguments['user_id']) && (int) $arguments['user_id'] > 0) {
            $where[] = 'usr.usr_id = ?';
            $whereParams[] = (int) $arguments['user_id'];
        } elseif (isset($arguments['login_name']) && trim((string) $arguments['login_name']) !== '') {
            $where[] = 'usr.usr_login_name = ?';
            $whereParams[] = trim((string) $arguments['login_name']);
        } elseif (isset($arguments['email']) && trim((string) $arguments['email']) !== '') {
            $emailFieldIds = self::profileFieldIds(['EMAIL']);
            $emailFieldId = $emailFieldIds['EMAIL'] ?? 0;

            if ($emailFieldId <= 0) {
                return [
                    'user' => null,
                    'error' => 'EMAIL profile field is not available.',
                ];
            }

            $where[] = 'email_lookup.usd_usf_id = ?';
            $where[] = 'email_lookup.usd_value = ?';
            $whereParams[] = $emailFieldId;
            $whereParams[] = trim((string) $arguments['email']);
        } else {
            return [
                'user' => null,
                'error' => 'One of user_id, login_name, or email is required.',
            ];
        }

        if (!isset($arguments['include_inactive']) || !(bool) $arguments['include_inactive']) {
            $where[] = 'usr.usr_valid = 1';
        }

        $emailJoin = isset($arguments['email']) ? '
            LEFT JOIN ' . $userDataTable . ' email_lookup
                ON email_lookup.usd_usr_id = usr.usr_id' : '';

        $sql = "
            SELECT DISTINCT
                usr.usr_id,
                usr.usr_login_name,
                usr.usr_valid,
                " . implode(",\n                ", $profileSelects) . "
            FROM {$usersTable} usr
            LEFT JOIN {$userDataTable} data
                ON data.usd_usr_id = usr.usr_id
            {$emailJoin}
            WHERE " . implode(' AND ', $where) . "
            GROUP BY usr.usr_id, usr.usr_login_name, usr.usr_valid
            ORDER BY usr.usr_id ASC
        ";

        try {
            $rows = self::queryRowsPrepared($db, $sql, array_merge($selectParams, $whereParams), 2);
        } catch (Throwable $exception) {
            return [
                'user' => null,
                'error' => $exception->getMessage(),
            ];
        }

        if (count($rows) === 0) {
            return [
                'user' => null,
                'error' => 'User not found.',
            ];
        }

        if (count($rows) > 1) {
            return [
                'user' => null,
                'error' => 'User lookup is ambiguous.',
            ];
        }

        $user = self::mapUserRow($rows[0], $fieldAliases);

        if (isset($arguments['include_memberships']) && (bool) $arguments['include_memberships']) {
            $memberships = self::listUserMemberships([
                'user_id' => $user['id'],
                'include_former_members' => true,
            ]);
            $user['memberships'] = $memberships['memberships'] ?? [];
        }

        return [
            'user' => $user,
            'fields' => array_values($fieldAliases),
        ];
    }

    public static function searchUsers(string $query, int $limit, int $maxLimit, int $offset = 0, array $fields = []): array
    {
        $query = trim($query);

        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            return [
                'users' => [],
                'error' => 'Admidio database object is not available.',
            ];
        }

        $limit = max(1, min($limit, $maxLimit));
        $offset = max(0, $offset);
        $fetchLimit = $limit + 1;
        $tablePrefix = self::detectTablePrefix();
        $usersTable = defined('TBL_USERS') ? TBL_USERS : $tablePrefix . 'users';
        $userDataTable = defined('TBL_USER_DATA') ? TBL_USER_DATA : $tablePrefix . 'user_data';
        [$profileSelects, $selectParams, $fieldAliases] = self::userProfileSelects($fields);

        $where = ['usr.usr_valid = 1'];
        $whereParams = [];

        if (mb_strlen($query) < 2) {
            return [
                'users' => [],
                'error' => 'Query must contain at least two characters.',
            ];
        }

        $where[] = '(usr.usr_login_name LIKE ? OR data.usd_value LIKE ?)';
        $whereParams[] = '%' . $query . '%';
        $whereParams[] = '%' . $query . '%';

        $sql = "
            SELECT DISTINCT
                usr.usr_id,
                usr.usr_login_name,
                usr.usr_valid,
                " . implode(",\n                ", $profileSelects) . "
            FROM {$usersTable} usr
            LEFT JOIN {$userDataTable} data
                ON data.usd_usr_id = usr.usr_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY usr.usr_id, usr.usr_login_name, usr.usr_valid
            ORDER BY usr.usr_id ASC
        ";

        try {
            $rows = self::queryRowsPrepared($db, $sql, array_merge($selectParams, $whereParams), $fetchLimit, $offset);
        } catch (Throwable $exception) {
            return [
                'users' => [],
                'error' => $exception->getMessage(),
            ];
        }

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'users' => array_map(static fn (array $row): array => self::mapUserRow($row, $fieldAliases), $rows),
            'pagination' => self::pagination($limit, $offset, count($rows), $hasMore),
            'fields' => array_values($fieldAliases),
        ];
    }

    public static function listUsers(
        int $limit,
        int $maxLimit,
        int $offset = 0,
        bool $includeInactive = false,
        array $fields = [],
        array $roleIds = [],
        array $roleNames = [],
        bool $includeFormerMembers = false,
        string $membershipActiveOn = ''
    ): array {
        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            return [
                'users' => [],
                'error' => 'Admidio database object is not available.',
            ];
        }

        $limit = max(1, min($limit, $maxLimit));
        $offset = max(0, $offset);
        $fetchLimit = $limit + 1;
        $tablePrefix = self::detectTablePrefix();
        $usersTable = defined('TBL_USERS') ? TBL_USERS : $tablePrefix . 'users';
        $userDataTable = defined('TBL_USER_DATA') ? TBL_USER_DATA : $tablePrefix . 'user_data';
        $membersTable = defined('TBL_MEMBERS') ? TBL_MEMBERS : $tablePrefix . 'members';
        [$profileSelects, $selectParams, $fieldAliases] = self::userProfileSelects($fields);
        $resolvedRoleIds = self::resolveOptionalRoleIds($roleIds, $roleNames);
        $join = '';
        $joinParams = [];
        $where = $includeInactive ? ['1 = 1'] : ['usr.usr_valid = 1'];

        if ($resolvedRoleIds !== []) {
            $join = '
            INNER JOIN ' . $membersTable . ' mem
                ON mem.mem_usr_id = usr.usr_id
               AND mem.mem_rol_id IN (' . implode(', ', array_fill(0, count($resolvedRoleIds), '?')) . ')';
            array_push($joinParams, ...$resolvedRoleIds);

            if (!$includeFormerMembers) {
                $membershipActiveOn = $membershipActiveOn !== '' ? $membershipActiveOn : self::today();
                self::assertDate($membershipActiveOn, 'membership_active_on');
                $join .= '
               AND mem.mem_begin <= ?
               AND mem.mem_end >= ?';
                $joinParams[] = $membershipActiveOn;
                $joinParams[] = $membershipActiveOn;
            }
        }

        $sql = "
            SELECT DISTINCT
                usr.usr_id,
                usr.usr_login_name,
                usr.usr_valid,
                " . implode(",\n                ", $profileSelects) . "
            FROM {$usersTable} usr
            LEFT JOIN {$userDataTable} data
                ON data.usd_usr_id = usr.usr_id
            {$join}
            WHERE " . implode(' AND ', $where) . "
            GROUP BY usr.usr_id, usr.usr_login_name, usr.usr_valid
            ORDER BY usr.usr_id ASC
        ";

        try {
            $rows = self::queryRowsPrepared($db, $sql, array_merge($selectParams, $joinParams), $fetchLimit, $offset);
        } catch (Throwable $exception) {
            return [
                'users' => [],
                'error' => $exception->getMessage(),
            ];
        }

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'users' => array_map(static fn (array $row): array => self::mapUserRow($row, $fieldAliases), $rows),
            'pagination' => self::pagination($limit, $offset, count($rows), $hasMore),
            'include_inactive' => $includeInactive,
            'fields' => array_values($fieldAliases),
            'role_ids' => $resolvedRoleIds,
            'include_former_members' => $includeFormerMembers,
            'membership_active_on' => $resolvedRoleIds !== [] && !$includeFormerMembers ? $membershipActiveOn : null,
        ];
    }

    public static function listRoles(string $query, int $limit, int $maxLimit, array $allowedRoleIds = []): array
    {
        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            return [
                'roles' => [],
                'error' => 'Admidio database object is not available.',
            ];
        }

        $limit = max(1, min($limit, $maxLimit));
        $tablePrefix = defined('TBL_ROLES') ? '' : self::detectTablePrefix();
        $rolesTable = defined('TBL_ROLES') ? TBL_ROLES : $tablePrefix . 'roles';
        $categoriesTable = defined('TBL_CATEGORIES') ? TBL_CATEGORIES : $tablePrefix . 'categories';
        $where = ['rol.rol_valid = 1'];
        $params = [];

        if ($query !== '') {
            $where[] = 'rol.rol_name LIKE ?';
            $params[] = '%' . $query . '%';
        }

        if ($allowedRoleIds !== []) {
            $where[] = 'rol.rol_id IN (' . implode(', ', array_fill(0, count($allowedRoleIds), '?')) . ')';
            array_push($params, ...$allowedRoleIds);
        }

        $sql = '
            SELECT rol.rol_id, rol.rol_uuid, rol.rol_name, rol.rol_description, rol.rol_administrator,
                   cat.cat_id, cat.cat_name
              FROM ' . $rolesTable . ' rol
         LEFT JOIN ' . $categoriesTable . ' cat
                ON cat.cat_id = rol.rol_cat_id
             WHERE ' . implode(' AND ', $where) . '
          ORDER BY cat.cat_sequence ASC, rol.rol_name ASC';

        try {
            $rows = self::queryRowsPrepared($db, $sql, $params, $limit);
        } catch (Throwable $exception) {
            return [
                'roles' => [],
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'roles' => array_map(static fn (array $row): array => [
                'id' => isset($row['rol_id']) ? (int) $row['rol_id'] : null,
                'uuid' => $row['rol_uuid'] ?? null,
                'name' => $row['rol_name'] ?? null,
                'description' => $row['rol_description'] ?? null,
                'administrator' => isset($row['rol_administrator']) ? (bool) $row['rol_administrator'] : false,
                'category' => [
                    'id' => isset($row['cat_id']) ? (int) $row['cat_id'] : null,
                    'name' => $row['cat_name'] ?? null,
                ],
            ], $rows),
        ];
    }

    public static function getRole(array $arguments, int $limit, int $maxLimit, int $offset = 0): array
    {
        $roleIds = self::resolveOptionalRoleIds(self::argumentRoleIds($arguments), self::argumentRoleNames($arguments));

        if ($roleIds === []) {
            return [
                'role' => null,
                'error' => 'One of role_id or role_name is required.',
            ];
        }

        if (count($roleIds) > 1) {
            return [
                'role' => null,
                'error' => 'Role lookup must resolve to exactly one role.',
            ];
        }

        $role = self::roleById($roleIds[0]);

        if ($role === null) {
            return [
                'role' => null,
                'error' => 'Role not found.',
            ];
        }

        if (isset($arguments['include_memberships']) && (bool) $arguments['include_memberships']) {
            $memberships = self::listRoleMemberships(
                ['role_id' => $roleIds[0], 'include_former_members' => true],
                $limit,
                $maxLimit,
                $offset
            );
            $role['memberships'] = $memberships['memberships'] ?? [];
            $role['pagination'] = $memberships['pagination'] ?? null;
        }

        return [
            'role' => $role,
        ];
    }

    private static function roleById(int $roleId): ?array
    {
        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            return null;
        }

        $tablePrefix = defined('TBL_ROLES') ? '' : self::detectTablePrefix();
        $rolesTable = defined('TBL_ROLES') ? TBL_ROLES : $tablePrefix . 'roles';
        $categoriesTable = defined('TBL_CATEGORIES') ? TBL_CATEGORIES : $tablePrefix . 'categories';
        $sql = '
            SELECT rol.rol_id, rol.rol_uuid, rol.rol_name, rol.rol_description, rol.rol_administrator,
                   cat.cat_id, cat.cat_name
              FROM ' . $rolesTable . ' rol
         LEFT JOIN ' . $categoriesTable . ' cat
                ON cat.cat_id = rol.rol_cat_id
             WHERE rol.rol_id = ?
               AND rol.rol_valid = 1';

        $rows = self::queryRowsPrepared($db, $sql, [$roleId], 1);

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return [
            'id' => isset($row['rol_id']) ? (int) $row['rol_id'] : null,
            'uuid' => $row['rol_uuid'] ?? null,
            'name' => $row['rol_name'] ?? null,
            'description' => $row['rol_description'] ?? null,
            'administrator' => isset($row['rol_administrator']) ? (bool) $row['rol_administrator'] : false,
            'category' => [
                'id' => isset($row['cat_id']) ? (int) $row['cat_id'] : null,
                'name' => $row['cat_name'] ?? null,
            ],
        ];
    }

    public static function listUserMemberships(array $arguments): array
    {
        $userId = (int) ($arguments['user_id'] ?? 0);

        if ($userId <= 0) {
            return [
                'memberships' => [],
                'error' => 'user_id must be a positive integer.',
            ];
        }

        return self::membershipRows(
            ['mem.mem_usr_id = ?'],
            [$userId],
            isset($arguments['include_former_members']) && (bool) $arguments['include_former_members'],
            (string) ($arguments['membership_active_on'] ?? ''),
            1000,
            0,
            []
        );
    }

    public static function listRoleMemberships(
        array $arguments,
        int $limit,
        int $maxLimit,
        int $offset = 0,
        array $fields = []
    ): array {
        $roleIds = self::resolveOptionalRoleIds(self::argumentRoleIds($arguments), self::argumentRoleNames($arguments));

        if ($roleIds === []) {
            return [
                'memberships' => [],
                'error' => 'One of role_id or role_name is required.',
            ];
        }

        $limit = max(1, min($limit, $maxLimit));
        $offset = max(0, $offset);

        return self::membershipRows(
            ['mem.mem_rol_id IN (' . implode(', ', array_fill(0, count($roleIds), '?')) . ')'],
            $roleIds,
            isset($arguments['include_former_members']) && (bool) $arguments['include_former_members'],
            (string) ($arguments['membership_active_on'] ?? ''),
            $limit,
            $offset,
            $fields
        );
    }

    public static function listProfileFields(): array
    {
        $profileFields = $GLOBALS['gProfileFields'] ?? null;
        $fields = [];

        if (is_object($profileFields) && method_exists($profileFields, 'getProfileFields')) {
            try {
                foreach ($profileFields->getProfileFields() as $fieldName => $field) {
                    if (!is_object($field) || !method_exists($field, 'getValue')) {
                        continue;
                    }

                    $fields[] = [
                        'internal_name' => (string) $fieldName,
                        'output_key' => self::normalizeOutputAlias('', (string) $fieldName),
                        'name' => self::safeGetValue($field, 'usf_name'),
                        'type' => self::safeGetValue($field, 'usf_type'),
                        'category_id' => self::safeGetInt($field, 'usf_cat_id'),
                        'sequence' => self::safeGetInt($field, 'usf_sequence'),
                        'required' => self::safeGetBool($field, 'usf_required_input'),
                        'system' => self::safeGetBool($field, 'usf_system'),
                    ];
                }
            } catch (Throwable) {
            }
        }

        if ($fields === []) {
            $fields = self::profileFieldsFromDatabase();
        }

        return [
            'fields' => $fields,
        ];
    }

    public static function createUser(array $arguments, Config $config): array
    {
        if ($error = self::mutationPreflight($config)) {
            return $error;
        }

        $loginName = trim((string) ($arguments['login_name'] ?? ''));

        if ($loginName === '') {
            return ['created' => false, 'error' => 'login_name is required.'];
        }

        try {
            $dryRun = self::dryRun($arguments);
            self::assertCanCreateUsers();
            $user = self::newAdmidioUser();
            $user->setValue('usr_login_name', $loginName);
            $user->setValue('usr_valid', 1);
            self::applyProfileFields($user, $arguments['profile'] ?? []);

            if ($dryRun) {
                return [
                    'created' => false,
                    'dry_run' => true,
                    'user' => self::userSummary($user),
                    'roles' => self::hasRoleInput($arguments)
                        ? self::assignRolesToUser(
                            0,
                            self::argumentRoleIds($arguments),
                            self::argumentRoleNames($arguments),
                            (string) ($arguments['membership_start'] ?? self::today()),
                            (string) ($arguments['membership_end'] ?? self::dateMax()),
                            null,
                            $config,
                            false,
                            true
                        )
                        : [],
                ];
            }

            $user->save();

            if (isset($arguments['password']) && (string) $arguments['password'] !== '') {
                $user->setPassword((string) $arguments['password']);
                $user->save();
            }

            $userId = (int) $user->getValue('usr_id');
            $roleResult = [];

            if (self::hasRoleInput($arguments)) {
                $roleResult = self::assignRolesToUser(
                    $userId,
                    self::argumentRoleIds($arguments),
                    self::argumentRoleNames($arguments),
                    (string) ($arguments['membership_start'] ?? self::today()),
                    (string) ($arguments['membership_end'] ?? self::dateMax()),
                    null,
                    $config
                );
            }

            return [
                'created' => true,
                'user' => self::userSummary($user),
                'roles' => $roleResult,
            ];
        } catch (Throwable $exception) {
            return [
                'created' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public static function updateUser(array $arguments, Config $config): array
    {
        if ($error = self::mutationPreflight($config)) {
            return $error;
        }

        $userId = (int) ($arguments['user_id'] ?? 0);

        if ($userId <= 0) {
            return ['updated' => false, 'error' => 'user_id must be a positive integer.'];
        }

        try {
            $dryRun = self::dryRun($arguments);
            $user = self::newAdmidioUser($userId);

            if (isset($arguments['login_name'])) {
                $user->setValue('usr_login_name', trim((string) $arguments['login_name']));
            }

            if (array_key_exists('valid', $arguments)) {
                $user->setValue('usr_valid', (bool) $arguments['valid'] ? 1 : 0);
            }

            self::applyProfileFields($user, $arguments['profile'] ?? []);

            if (isset($arguments['password']) && (string) $arguments['password'] !== '') {
                $user->setPassword((string) $arguments['password']);
            }

            if ($dryRun) {
                return [
                    'updated' => false,
                    'dry_run' => true,
                    'user' => self::userSummary($user),
                ];
            }

            $user->save();

            return [
                'updated' => true,
                'user' => self::userSummary($user),
            ];
        } catch (Throwable $exception) {
            return [
                'updated' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public static function assignUserRoles(array $arguments, Config $config): array
    {
        if ($error = self::mutationPreflight($config)) {
            return $error;
        }

        $userId = (int) ($arguments['user_id'] ?? 0);

        if ($userId <= 0) {
            return ['assigned' => false, 'error' => 'user_id must be a positive integer.'];
        }

        try {
            $dryRun = self::dryRun($arguments);
            return [
                'assigned' => !$dryRun,
                'dry_run' => $dryRun,
                'roles' => self::assignRolesToUser(
                    $userId,
                    self::argumentRoleIds($arguments),
                    self::argumentRoleNames($arguments),
                    (string) ($arguments['membership_start'] ?? $arguments['start_date'] ?? self::today()),
                    (string) ($arguments['membership_end'] ?? $arguments['end_date'] ?? self::dateMax()),
                    array_key_exists('leader', $arguments) ? (bool) $arguments['leader'] : null,
                    $config,
                    isset($arguments['force_period']) && (bool) $arguments['force_period'],
                    $dryRun
                ),
            ];
        } catch (Throwable $exception) {
            return [
                'assigned' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public static function updateUserMemberships(array $arguments, Config $config): array
    {
        if ($error = self::mutationPreflight($config)) {
            return $error;
        }

        $userId = (int) ($arguments['user_id'] ?? 0);

        if ($userId <= 0) {
            return ['updated' => false, 'error' => 'user_id must be a positive integer.'];
        }

        try {
            $dryRun = self::dryRun($arguments);
            return [
                'updated' => !$dryRun,
                'dry_run' => $dryRun,
                'roles' => self::assignRolesToUser(
                    $userId,
                    self::argumentRoleIds($arguments),
                    self::argumentRoleNames($arguments),
                    (string) ($arguments['membership_start'] ?? self::today()),
                    (string) ($arguments['membership_end'] ?? self::dateMax()),
                    array_key_exists('leader', $arguments) ? (bool) $arguments['leader'] : null,
                    $config,
                    array_key_exists('force_period', $arguments) ? (bool) $arguments['force_period'] : true,
                    $dryRun
                ),
            ];
        } catch (Throwable $exception) {
            return [
                'updated' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public static function removeUserRoles(array $arguments, Config $config): array
    {
        if ($error = self::mutationPreflight($config)) {
            return $error;
        }

        $userId = (int) ($arguments['user_id'] ?? 0);

        if ($userId <= 0) {
            return ['removed' => false, 'error' => 'user_id must be a positive integer.'];
        }

        try {
            $dryRun = self::dryRun($arguments);
            self::ensureRoleClass();
            self::ensureSessionStub();
            $db = self::admidioDb();
            $roleIds = self::resolveRoleIds(self::argumentRoleIds($arguments), self::argumentRoleNames($arguments), $config);
            $removed = [];

            foreach ($roleIds as $roleId) {
                $roleClass = self::roleClass();
                $role = new $roleClass($db, $roleId);
                self::assertCanAssignRole($role);

                if (!$dryRun) {
                    $role->stopMembership($userId);
                }

                $removed[] = $roleId;
            }

            return [
                'removed' => !$dryRun,
                'dry_run' => $dryRun,
                'role_ids' => $removed,
            ];
        } catch (Throwable $exception) {
            return [
                'removed' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public static function deactivateUser(array $arguments, Config $config): array
    {
        if ($error = self::mutationPreflight($config)) {
            return $error;
        }

        $userId = (int) ($arguments['user_id'] ?? 0);

        if ($userId <= 0) {
            return ['deactivated' => false, 'error' => 'user_id must be a positive integer.'];
        }

        try {
            $dryRun = self::dryRun($arguments);
            $user = self::newAdmidioUser($userId);
            $user->setValue('usr_valid', 0);

            if (!$dryRun) {
                $user->save();
            }

            return [
                'deactivated' => !$dryRun,
                'dry_run' => $dryRun,
                'user' => self::userSummary($user),
            ];
        } catch (Throwable $exception) {
            return [
                'deactivated' => false,
                'error' => $exception->getMessage(),
            ];
        }
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

    private static function profileFieldIds(array $fieldNames): array
    {
        $profileFields = $GLOBALS['gProfileFields'] ?? null;
        $fieldIds = [];

        if (!is_object($profileFields) || !method_exists($profileFields, 'getProperty')) {
            return $fieldIds;
        }

        foreach ($fieldNames as $fieldName) {
            try {
                $fieldId = (int) $profileFields->getProperty($fieldName, 'usf_id');

                if ($fieldId > 0) {
                    $fieldIds[$fieldName] = $fieldId;
                }
            } catch (Throwable) {
            }
        }

        return $fieldIds;
    }

    private static function profileFieldSelect(string $alias, ?int $fieldId, array &$params): string
    {
        if ($fieldId === null || $fieldId <= 0) {
            return 'NULL AS ' . $alias;
        }

        $params[] = $fieldId;

        return 'MAX(CASE WHEN data.usd_usf_id = ? THEN data.usd_value END) AS ' . $alias;
    }

    private static function membershipRows(
        array $where,
        array $whereParams,
        bool $includeFormerMembers,
        string $membershipActiveOn,
        int $limit,
        int $offset,
        array $fields
    ): array {
        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            return [
                'memberships' => [],
                'error' => 'Admidio database object is not available.',
            ];
        }

        if (!$includeFormerMembers) {
            $membershipActiveOn = $membershipActiveOn !== '' ? $membershipActiveOn : self::today();
            self::assertDate($membershipActiveOn, 'membership_active_on');
            $where[] = 'mem.mem_begin <= ?';
            $where[] = 'mem.mem_end >= ?';
            $whereParams[] = $membershipActiveOn;
            $whereParams[] = $membershipActiveOn;
        }

        $fetchLimit = $limit + 1;
        $tablePrefix = self::detectTablePrefix();
        $membersTable = defined('TBL_MEMBERS') ? TBL_MEMBERS : $tablePrefix . 'members';
        $rolesTable = defined('TBL_ROLES') ? TBL_ROLES : $tablePrefix . 'roles';
        $usersTable = defined('TBL_USERS') ? TBL_USERS : $tablePrefix . 'users';
        $userDataTable = defined('TBL_USER_DATA') ? TBL_USER_DATA : $tablePrefix . 'user_data';
        [$profileSelects, $selectParams, $fieldAliases] = self::userProfileSelects($fields);

        $sql = "
            SELECT
                mem.mem_id,
                mem.mem_uuid,
                mem.mem_rol_id,
                mem.mem_usr_id,
                mem.mem_begin,
                mem.mem_end,
                mem.mem_leader,
                mem.mem_approved,
                mem.mem_comment,
                mem.mem_count_guests,
                rol.rol_name,
                rol.rol_uuid,
                usr.usr_login_name,
                usr.usr_valid,
                " . implode(",\n                ", $profileSelects) . "
            FROM {$membersTable} mem
            INNER JOIN {$rolesTable} rol
                ON rol.rol_id = mem.mem_rol_id
            INNER JOIN {$usersTable} usr
                ON usr.usr_id = mem.mem_usr_id
            LEFT JOIN {$userDataTable} data
                ON data.usd_usr_id = usr.usr_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY mem.mem_id, mem.mem_uuid, mem.mem_rol_id, mem.mem_usr_id, mem.mem_begin, mem.mem_end,
                     mem.mem_leader, mem.mem_approved, mem.mem_comment, mem.mem_count_guests,
                     rol.rol_name, rol.rol_uuid, usr.usr_login_name, usr.usr_valid
            ORDER BY mem.mem_begin DESC, mem.mem_id DESC
        ";

        try {
            $rows = self::queryRowsPrepared($db, $sql, array_merge($selectParams, $whereParams), $fetchLimit, $offset);
        } catch (Throwable $exception) {
            return [
                'memberships' => [],
                'error' => $exception->getMessage(),
            ];
        }

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'memberships' => array_map(static fn (array $row): array => self::mapMembershipRow($row, $fieldAliases), $rows),
            'pagination' => self::pagination($limit, $offset, count($rows), $hasMore),
            'include_former_members' => $includeFormerMembers,
            'membership_active_on' => $includeFormerMembers ? null : $membershipActiveOn,
            'fields' => array_values($fieldAliases),
        ];
    }

    private static function mapMembershipRow(array $row, array $fieldAliases): array
    {
        return [
            'id' => isset($row['mem_id']) ? (int) $row['mem_id'] : null,
            'uuid' => $row['mem_uuid'] ?? null,
            'membership_start' => $row['mem_begin'] ?? null,
            'membership_end' => $row['mem_end'] ?? null,
            'leader' => isset($row['mem_leader']) ? (bool) $row['mem_leader'] : false,
            'approved' => isset($row['mem_approved']) ? (int) $row['mem_approved'] : null,
            'comment' => $row['mem_comment'] ?? null,
            'guest_count' => isset($row['mem_count_guests']) ? (int) $row['mem_count_guests'] : null,
            'role' => [
                'id' => isset($row['mem_rol_id']) ? (int) $row['mem_rol_id'] : null,
                'uuid' => $row['rol_uuid'] ?? null,
                'name' => $row['rol_name'] ?? null,
            ],
            'user' => self::mapUserRow([
                'usr_id' => $row['mem_usr_id'] ?? null,
                'usr_login_name' => $row['usr_login_name'] ?? null,
                'usr_valid' => $row['usr_valid'] ?? null,
            ] + $row, $fieldAliases),
        ];
    }

    private static function userProfileSelects(array $fields): array
    {
        $fields = self::normalizeUserFields($fields);
        $profileFieldIds = self::profileFieldIds(array_keys($fields));
        $params = [];
        $selects = [];
        $aliases = [];
        $index = 0;

        foreach ($fields as $fieldName => $outputAlias) {
            $sqlAlias = 'field_' . $index;
            $selects[] = self::profileFieldSelect($sqlAlias, $profileFieldIds[$fieldName] ?? null, $params);
            $aliases[$sqlAlias] = $outputAlias;
            $index++;
        }

        return [$selects, $params, $aliases];
    }

    private static function normalizeUserFields(array $fields): array
    {
        if (isset($fields['*']) && $fields['*'] === '*') {
            return self::allUserProfileFields();
        }

        if ($fields === []) {
            return [
                'FIRST_NAME' => 'first_name',
                'LAST_NAME' => 'last_name',
                'EMAIL' => 'email',
            ];
        }

        $normalized = [];

        foreach ($fields as $fieldName => $outputAlias) {
            $fieldName = strtoupper(trim((string) $fieldName));

            if ($fieldName === '') {
                continue;
            }

            if ($fieldName === '*' || $fieldName === 'ALL') {
                return self::allUserProfileFields();
            }

            $normalized[$fieldName] = self::normalizeOutputAlias((string) $outputAlias, $fieldName);
        }

        return $normalized !== [] ? $normalized : [
            'FIRST_NAME' => 'first_name',
            'LAST_NAME' => 'last_name',
            'EMAIL' => 'email',
        ];
    }

    private static function allUserProfileFields(): array
    {
        $fields = [];
        $profileFields = $GLOBALS['gProfileFields'] ?? null;

        if (is_object($profileFields) && method_exists($profileFields, 'getProfileFields')) {
            try {
                foreach (array_keys($profileFields->getProfileFields()) as $fieldName) {
                    $fieldName = strtoupper(trim((string) $fieldName));

                    if ($fieldName !== '') {
                        $fields[$fieldName] = self::uniqueOutputAlias(self::normalizeOutputAlias('', $fieldName), $fields);
                    }
                }
            } catch (Throwable) {
            }
        }

        if ($fields === []) {
            $fields = self::allUserProfileFieldsFromDatabase();
        }

        return $fields !== [] ? $fields : [
            'FIRST_NAME' => 'first_name',
            'LAST_NAME' => 'last_name',
            'EMAIL' => 'email',
        ];
    }

    private static function allUserProfileFieldsFromDatabase(): array
    {
        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            return [];
        }

        $tablePrefix = defined('TBL_USER_FIELDS') ? '' : self::detectTablePrefix();
        $userFieldsTable = defined('TBL_USER_FIELDS') ? TBL_USER_FIELDS : $tablePrefix . 'user_fields';
        $sql = '
            SELECT usf_name_intern
              FROM ' . $userFieldsTable . '
             WHERE usf_name_intern <> \'\'
          ORDER BY usf_sequence ASC, usf_name_intern ASC';

        try {
            $rows = self::queryRowsPrepared($db, $sql, [], 1000);
        } catch (Throwable) {
            return [];
        }

        $fields = [];

        foreach ($rows as $row) {
            $fieldName = strtoupper(trim((string) ($row['usf_name_intern'] ?? '')));

            if ($fieldName !== '') {
                $fields[$fieldName] = self::uniqueOutputAlias(self::normalizeOutputAlias('', $fieldName), $fields);
            }
        }

        return $fields;
    }

    private static function profileFieldsFromDatabase(): array
    {
        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            return [];
        }

        $tablePrefix = defined('TBL_USER_FIELDS') ? '' : self::detectTablePrefix();
        $userFieldsTable = defined('TBL_USER_FIELDS') ? TBL_USER_FIELDS : $tablePrefix . 'user_fields';
        $sql = '
            SELECT usf_id, usf_name_intern, usf_name, usf_type, usf_cat_id, usf_sequence,
                   usf_required_input, usf_system
              FROM ' . $userFieldsTable . '
             WHERE usf_name_intern <> \'\'
          ORDER BY usf_sequence ASC, usf_name_intern ASC';

        try {
            $rows = self::queryRowsPrepared($db, $sql, [], 1000);
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'id' => isset($row['usf_id']) ? (int) $row['usf_id'] : null,
            'internal_name' => $row['usf_name_intern'] ?? null,
            'output_key' => self::normalizeOutputAlias('', (string) ($row['usf_name_intern'] ?? '')),
            'name' => $row['usf_name'] ?? null,
            'type' => $row['usf_type'] ?? null,
            'category_id' => isset($row['usf_cat_id']) ? (int) $row['usf_cat_id'] : null,
            'sequence' => isset($row['usf_sequence']) ? (int) $row['usf_sequence'] : null,
            'required' => isset($row['usf_required_input']) ? (bool) $row['usf_required_input'] : null,
            'system' => isset($row['usf_system']) ? (bool) $row['usf_system'] : null,
        ], $rows);
    }

    private static function safeGetValue(object $object, string $key): mixed
    {
        if (!method_exists($object, 'getValue')) {
            return null;
        }

        try {
            $value = $object->getValue($key);
            return $value === '' ? null : $value;
        } catch (Throwable) {
            return null;
        }
    }

    private static function safeGetInt(object $object, string $key): ?int
    {
        $value = self::safeGetValue($object, $key);

        return $value === null ? null : (int) $value;
    }

    private static function safeGetBool(object $object, string $key): ?bool
    {
        $value = self::safeGetValue($object, $key);

        return $value === null ? null : (bool) $value;
    }

    private static function normalizeOutputAlias(string $outputAlias, string $fieldName): string
    {
        $outputAlias = strtolower(trim($outputAlias));
        $outputAlias = preg_replace('/[^a-z0-9_]+/', '_', $outputAlias) ?? '';
        $outputAlias = trim($outputAlias, '_');

        return $outputAlias !== '' ? $outputAlias : strtolower($fieldName);
    }

    private static function uniqueOutputAlias(string $outputAlias, array $fields): string
    {
        if (!in_array($outputAlias, $fields, true)) {
            return $outputAlias;
        }

        $suffix = 2;

        while (in_array($outputAlias . '_' . $suffix, $fields, true)) {
            $suffix++;
        }

        return $outputAlias . '_' . $suffix;
    }


    private static function mapUserRow(array $row, array $fieldAliases): array
    {
        $user = [
            'id' => isset($row['usr_id']) ? (int) $row['usr_id'] : null,
            'login_name' => $row['usr_login_name'] ?? null,
            'valid' => isset($row['usr_valid']) ? (bool) $row['usr_valid'] : null,
        ];

        foreach ($fieldAliases as $sqlAlias => $outputAlias) {
            $user[$outputAlias] = $row[$sqlAlias] ?? null;
        }

        return $user;
    }

    private static function pagination(int $limit, int $offset, int $count, bool $hasMore): array
    {
        return [
            'limit' => $limit,
            'offset' => $offset,
            'count' => $count,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $count : null,
        ];
    }

    private static function queryRowsPrepared(object $db, string $sql, array $params, int $limit, int $offset = 0): array
    {
        $sqlWithLimit = $sql . ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset);

        if (method_exists($db, 'queryPrepared')) {
            $statement = $db->queryPrepared($sqlWithLimit, $params);
            return self::fetchRows($statement, $limit);
        }

        throw new \RuntimeException('Prepared queries are not supported by the Admidio database object.');
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

    private static function mutationPreflight(Config $config): ?array
    {
        if (!$config->mutationsEnabled) {
            return [
                'ok' => false,
                'error' => 'Mutating MCP tools are disabled. Set mutations_enabled to true in config.php.',
            ];
        }

        if (!is_object($GLOBALS['gDb'] ?? null)) {
            return [
                'ok' => false,
                'error' => 'Admidio database object is not available.',
            ];
        }

        if (!class_exists(self::userClass()) || !class_exists(self::roleClass())) {
            return [
                'ok' => false,
                'error' => 'Admidio User and Role entity classes are not available.',
            ];
        }

        if (!is_object($GLOBALS['gCurrentUser'] ?? null) || (int) ($GLOBALS['gCurrentUserId'] ?? 0) <= 0) {
            return [
                'ok' => false,
                'error' => 'No authenticated Admidio user is available for this request.',
            ];
        }

        return null;
    }

    private static function admidioDb(): object
    {
        $db = $GLOBALS['gDb'] ?? null;

        if (!is_object($db)) {
            throw new \RuntimeException('Admidio database object is not available.');
        }

        return $db;
    }

    private static function newAdmidioUser(int $userId = 0): object
    {
        self::ensureUserClass();
        $userClass = self::userClass();

        return new $userClass(self::admidioDb(), $GLOBALS['gProfileFields'] ?? null, $userId);
    }

    private static function applyProfileFields(object $user, mixed $profile): void
    {
        if (!is_array($profile)) {
            return;
        }

        foreach ($profile as $fieldName => $value) {
            if (!is_string($fieldName) || str_starts_with($fieldName, 'usr_')) {
                continue;
            }

            if (!is_scalar($value) && !is_array($value) && $value !== null) {
                continue;
            }

            $user->setValue($fieldName, $value ?? '');
        }
    }

    private static function assignRolesToUser(
        int $userId,
        mixed $roleIds,
        mixed $roleNames,
        string $startDate,
        string $endDate,
        ?bool $leader,
        Config $config,
        bool $forcePeriod = false,
        bool $dryRun = false
    ): array {
        self::ensureRoleClass();
        self::ensureSessionStub();
        $db = self::admidioDb();
        $resolvedRoleIds = self::resolveRoleIds($roleIds, $roleNames, $config);

        if ($resolvedRoleIds === []) {
            return [];
        }

        self::assertDate($startDate, 'start_date');
        self::assertDate($endDate, 'end_date');

        $assigned = [];

        foreach ($resolvedRoleIds as $roleId) {
            $roleClass = self::roleClass();
            $role = new $roleClass($db, $roleId);
            self::assertCanAssignRole($role);

            if (!$dryRun) {
                $role->setMembership($userId, $startDate, $endDate, $leader, $forcePeriod);
            }

            $assigned[] = [
                'role_id' => $roleId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'leader' => $leader,
                'force_period' => $forcePeriod,
                'dry_run' => $dryRun,
            ];
        }

        return $assigned;
    }

    private static function resolveRoleIds(mixed $roleIds, mixed $roleNames, Config $config): array
    {
        $ids = [];

        foreach ((array) $roleIds as $roleId) {
            $roleId = (int) $roleId;

            if ($roleId > 0) {
                $ids[] = $roleId;
            }
        }

        foreach ((array) $roleNames as $roleName) {
            $roleName = trim((string) $roleName);

            if ($roleName === '') {
                continue;
            }

            $ids[] = self::roleIdByName($roleName);
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            throw new \InvalidArgumentException('At least one role_id or role_name is required.');
        }

        if ($config->allowedRoleIds !== []) {
            $blocked = array_values(array_diff($ids, $config->allowedRoleIds));

            if ($blocked !== []) {
                throw new \InvalidArgumentException('Role assignment is not allowed for role IDs: ' . implode(', ', $blocked));
            }
        }

        return $ids;
    }

    private static function resolveOptionalRoleIds(array $roleIds, array $roleNames): array
    {
        $ids = [];

        foreach ($roleIds as $roleId) {
            $roleId = (int) $roleId;

            if ($roleId > 0) {
                $ids[] = $roleId;
            }
        }

        foreach ($roleNames as $roleName) {
            $roleName = trim((string) $roleName);

            if ($roleName !== '') {
                $ids[] = self::roleIdByName($roleName);
            }
        }

        return array_values(array_unique($ids));
    }

    private static function hasRoleInput(array $arguments): bool
    {
        return !empty($arguments['role_id'])
            || !empty($arguments['role_ids'])
            || !empty($arguments['role_name'])
            || !empty($arguments['role_names']);
    }

    private static function dryRun(array $arguments): bool
    {
        return isset($arguments['dry_run']) && (bool) $arguments['dry_run'];
    }

    private static function argumentRoleIds(array $arguments): array
    {
        $roleIds = [];

        if (isset($arguments['role_id'])) {
            $roleIds[] = (int) $arguments['role_id'];
        }

        foreach ((array) ($arguments['role_ids'] ?? []) as $roleId) {
            $roleId = (int) $roleId;

            if ($roleId > 0) {
                $roleIds[] = $roleId;
            }
        }

        return array_values(array_unique(array_filter($roleIds, static fn (int $roleId): bool => $roleId > 0)));
    }

    private static function argumentRoleNames(array $arguments): array
    {
        $roleNames = [];

        if (isset($arguments['role_name'])) {
            $roleNames[] = trim((string) $arguments['role_name']);
        }

        foreach ((array) ($arguments['role_names'] ?? []) as $roleName) {
            $roleNames[] = trim((string) $roleName);
        }

        return array_values(array_unique(array_filter($roleNames, static fn (string $roleName): bool => $roleName !== '')));
    }

    private static function roleIdByName(string $roleName): int
    {
        $tablePrefix = defined('TBL_ROLES') ? '' : self::detectTablePrefix();
        $rolesTable = defined('TBL_ROLES') ? TBL_ROLES : $tablePrefix . 'roles';
        $sql = 'SELECT rol_id FROM ' . $rolesTable . ' WHERE rol_name = ? AND rol_valid = 1';
        $rows = self::queryRowsPrepared(self::admidioDb(), $sql, [$roleName], 2);

        if (count($rows) === 0) {
            throw new \InvalidArgumentException('Role not found: ' . $roleName);
        }

        if (count($rows) > 1) {
            throw new \InvalidArgumentException('Role name is ambiguous: ' . $roleName);
        }

        return (int) $rows[0]['rol_id'];
    }

    private static function userSummary(object $user): array
    {
        return [
            'id' => (int) $user->getValue('usr_id'),
            'login_name' => $user->getValue('usr_login_name'),
            'valid' => (bool) $user->getValue('usr_valid'),
            'first_name' => self::readObjectValue($user, ['getValue'], ['FIRST_NAME']),
            'last_name' => self::readObjectValue($user, ['getValue'], ['LAST_NAME']),
            'email' => self::readObjectValue($user, ['getValue'], ['EMAIL']),
        ];
    }

    private static function currentUserRights(object $user): array
    {
        return [
            'administrator' => self::callBool($user, 'isAdministrator'),
            'manage_users' => self::callBool($user, 'isAdministratorUsers'),
            'manage_roles' => self::callBool($user, 'isAdministratorRoles'),
            'approve_users' => self::callBool($user, 'isAdministratorRegistration'),
        ];
    }

    private static function callBool(object $object, string $method): bool
    {
        if (!method_exists($object, $method)) {
            return false;
        }

        try {
            return (bool) $object->{$method}();
        } catch (Throwable) {
            return false;
        }
    }

    private static function currentAdmidioUser(): object
    {
        $user = $GLOBALS['gCurrentUser'] ?? null;

        if (!is_object($user)) {
            throw new \RuntimeException('No authenticated Admidio user is available for this request.');
        }

        return $user;
    }

    private static function assertCanCreateUsers(): void
    {
        $currentUser = self::currentAdmidioUser();

        if (
            !self::callBool($currentUser, 'isAdministratorUsers')
            && !self::callBool($currentUser, 'isAdministratorRegistration')
        ) {
            throw new \RuntimeException('The authenticated Admidio user is not allowed to create users.');
        }
    }

    private static function assertCanAssignRole(object $role): void
    {
        $currentUser = self::currentAdmidioUser();

        if (!method_exists($role, 'allowedToAssignMembers') || !$role->allowedToAssignMembers($currentUser)) {
            throw new \RuntimeException('The authenticated Admidio user is not allowed to assign members to this role.');
        }
    }

    private static function ensureUserClass(): void
    {
        if (!class_exists(self::userClass())) {
            throw new \RuntimeException('Admidio User entity class is not available.');
        }
    }

    private static function ensureRoleClass(): void
    {
        if (!class_exists(self::roleClass())) {
            throw new \RuntimeException('Admidio Role entity class is not available.');
        }
    }

    private static function userClass(): string
    {
        return 'Admidio\\Users\\Entity\\User';
    }

    private static function roleClass(): string
    {
        return 'Admidio\\Roles\\Entity\\Role';
    }

    private static function ensureSessionStub(): void
    {
        if (!is_object($GLOBALS['gCurrentSession'] ?? null)) {
            $GLOBALS['gCurrentSession'] = new class {
                public function reload(int $userId): void
                {
                }
            };
        }
    }

    private static function today(): string
    {
        return defined('DATE_NOW') ? DATE_NOW : date('Y-m-d');
    }

    private static function dateMax(): string
    {
        return defined('DATE_MAX') ? DATE_MAX : '9999-12-31';
    }

    private static function assertDate(string $date, string $field): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException($field . ' must use YYYY-MM-DD format.');
        }
    }
}
