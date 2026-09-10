<?php
declare(strict_types=1);

/*
 * Configuration locale par défaut pour XAMPP/Laragon.
 * Les secrets SMTP sont lus dans config/mail.local.php ou dans les variables
 * d'environnement OOPTICIEN_SMTP_*.
 * La base peut être surchargée via config/database.local.php ou les variables
 * OOPTICIEN_DB_* (utile pour un hébergement distant comme alwaysdata).
 */
$envBaseUrl = getenv('OOPTICIEN_BASE_URL');
$envDbHost = getenv('OOPTICIEN_DB_HOST');
$envDbPort = getenv('OOPTICIEN_DB_PORT');
$envDbName = getenv('OOPTICIEN_DB_NAME');
$envDbUser = getenv('OOPTICIEN_DB_USER');
$envDbPassword = getenv('OOPTICIEN_DB_PASSWORD');
$envStoragePath = getenv('OOPTICIEN_STORAGE_PATH');
$envEncryptionKey = getenv('OOPTICIEN_ENCRYPTION_KEY');

$databaseLocalPath = __DIR__ . '/database.local.php';
$databaseLocal = is_file($databaseLocalPath) ? (require $databaseLocalPath) : [];
$databaseLocal = is_array($databaseLocal) ? $databaseLocal : [];
$mailLocalPath = __DIR__ . '/mail.local.php';
$mailLocal = is_file($mailLocalPath) ? (require $mailLocalPath) : [];
$mailLocal = is_array($mailLocal) ? $mailLocal : [];
$inboundMailLocalPath = __DIR__ . '/inbound_mail.local.php';
$inboundMailLocal = is_file($inboundMailLocalPath) ? (require $inboundMailLocalPath) : [];
$inboundMailLocal = is_array($inboundMailLocal) ? $inboundMailLocal : [];
$mailValue = static function (string $environmentName, string $localKey, mixed $default = '') use ($mailLocal): mixed {
    $environmentValue = getenv($environmentName);
    if ($environmentValue !== false && $environmentValue !== '') {
        return $environmentValue;
    }
    return $mailLocal[$localKey] ?? $default;
};
$dbValue = static function (mixed $environmentValue, string $localKey, mixed $default) use ($databaseLocal): mixed {
    if ($environmentValue !== false && $environmentValue !== '') {
        return $environmentValue;
    }
    return $databaseLocal[$localKey] ?? $default;
};

return [
    'app_name' => 'Oopticien Pro',
    'base_url' => $envBaseUrl !== false ? $envBaseUrl : '/oopticien-pro',
    'timezone' => 'Europe/Paris',
    'session_timeout' => 1800,
    'storage_path' => $envStoragePath !== false
        ? $envStoragePath
        : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'oopticien-pro-storage',
    'encryption_key' => $envEncryptionKey !== false ? $envEncryptionKey : '',
    'messaging' => [
        'mode' => 'smtp',
        'from_email' => (string) $mailValue('OOPTICIEN_SMTP_FROM_EMAIL', 'from_email'),
        'from_name' => (string) $mailValue('OOPTICIEN_SMTP_FROM_NAME', 'from_name', 'Oopticien Pro'),
        'reply_to' => (string) $mailValue('OOPTICIEN_SMTP_REPLY_TO', 'reply_to'),
        'smtp' => [
            'host' => (string) $mailValue('OOPTICIEN_SMTP_HOST', 'host'),
            'port' => (int) $mailValue('OOPTICIEN_SMTP_PORT', 'port', 587),
            'encryption' => (string) $mailValue('OOPTICIEN_SMTP_ENCRYPTION', 'encryption', 'tls'),
            'username' => (string) $mailValue('OOPTICIEN_SMTP_USERNAME', 'username'),
            'password' => (string) $mailValue('OOPTICIEN_SMTP_PASSWORD', 'password'),
            'timeout' => (int) $mailValue('OOPTICIEN_SMTP_TIMEOUT', 'timeout', 15),
        ],
    ],
    'inbound_mail' => $inboundMailLocal,
    'suppliers' => [
        'ophtalmic_portal_url' => 'https://www.ophtalmicespace.fr/Espaceclient/',
    ],
    'db' => [
        'host' => (string) $dbValue($envDbHost, 'host', '127.0.0.1'),
        'port' => (int) $dbValue($envDbPort, 'port', 3306),
        'name' => (string) $dbValue($envDbName, 'name', 'oopticien_pro'),
        'user' => (string) $dbValue($envDbUser, 'user', 'root'),
        'password' => (string) $dbValue($envDbPassword, 'password', ''),
        'charset' => 'utf8mb4',
    ],
];
