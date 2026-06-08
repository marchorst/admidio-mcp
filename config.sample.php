<?php

declare(strict_types=1);

/**
 * Copy this file to config.php and adjust the values.
 *
 * Use a dedicated technical account for Codex. Basic Auth should only be used
 * over HTTPS.
 */
$plgMcpConfig = [
    'enabled' => true,
    'mutations_enabled' => false,

    // "admidio" validates Basic Auth credentials against Admidio users and
    // executes tools with that user's Admidio permissions.
    // "static" keeps the legacy plugin-local username/password check below.
    'auth_provider' => 'admidio',

    // Used only when auth_provider is "static".
    'username' => 'codex',
    'password_hash' => '',
    'password' => 'change-me',

    'max_search_results' => 20,

    // Profile fields returned by admidio_search_users and admidio_list_users.
    // Numeric keys use a generated lowercase JSON key, e.g. FIRST_NAME -> first_name.
    // String keys can be used to set an explicit JSON key.
    'user_fields' => [
        'FIRST_NAME',
        'LAST_NAME',
        'EMAIL',
        // 'phone' => 'PHONE',
    ],

    // Allow MCP calls to request all profile fields with fields="all" or fields=["*"].
    // You can also set user_fields to "*" to return all profile fields by default.
    'allow_all_user_fields' => false,

    // Optional allow-list. Empty means all roles may be assigned/removed.
    'allowed_role_ids' => [],
];
