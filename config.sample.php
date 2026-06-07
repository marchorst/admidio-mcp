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

    // Optional allow-list. Empty means all roles may be assigned/removed.
    'allowed_role_ids' => [],
];
