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
    'username' => 'codex',

    // Preferred: password_hash('your-password', PASSWORD_DEFAULT)
    'password_hash' => '',

    // Fallback for local testing only. Leave empty in production.
    'password' => 'change-me',

    'max_search_results' => 20,
];
