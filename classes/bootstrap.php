<?php

declare(strict_types=1);

$autoloadCandidates = [
    __DIR__ . '/../../../system/bootstrap/bootstrap.php',
    __DIR__ . '/../../../system/common.php',
    __DIR__ . '/../../../adm_program/system/bootstrap/bootstrap.php',
    __DIR__ . '/../../../adm_program/system/common.php',
];

foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        break;
    }
}

spl_autoload_register(static function (string $className): void {
    $prefix = 'AdmidioMcp\\';

    if (strncmp($className, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
