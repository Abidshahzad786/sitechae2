<?php
declare(strict_types=1);

/**
 * Simple PDO connection helper.
 * Reads configuration from environment variables with sensible defaults.
 *
 * ENV:
 * - DB_HOST (default: localhost)
 * - DB_NAME (required)
 * - DB_USER (required)
 * - DB_PASS (default: empty)
 * - DB_CHARSET (default: utf8mb4)
 */

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbHost = getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost';
    $dbName = getenv('DB_NAME') !== false ? getenv('DB_NAME') : '';
    $dbUser = getenv('DB_USER') !== false ? getenv('DB_USER') : '';
    $dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
    $dbCharset = getenv('DB_CHARSET') !== false ? getenv('DB_CHARSET') : 'utf8mb4';

    if ($dbName === '' || $dbUser === '') {
        throw new RuntimeException('Database not configured. Please set DB_NAME and DB_USER environment variables.');
    }

    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    return $pdo;
}

?>

