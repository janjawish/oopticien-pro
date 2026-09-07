<?php
declare(strict_types=1);

return [
    'app_name' => 'Oopticien Pro',
    'base_url' => getenv('OOPTICIEN_BASE_URL') ?: '/oopticien-pro',
    'timezone' => 'Europe/Paris',
    'session_timeout' => 1800,
    'storage_path' => getenv('OOPTICIEN_STORAGE_PATH')
        ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'oopticien-pro-storage',
    'encryption_key' => getenv('OOPTICIEN_ENCRYPTION_KEY') ?: '',
    'messaging' => [
        'mode' => 'smtp',
        'from_email' => getenv('OOPTICIEN_SMTP_FROM_EMAIL') ?: '',
        'from_name' => getenv('OOPTICIEN_SMTP_FROM_NAME') ?: 'Oopticien Pro',
        'reply_to' => getenv('OOPTICIEN_SMTP_REPLY_TO') ?: '',
        'smtp' => [
            'host' => getenv('OOPTICIEN_SMTP_HOST') ?: '',
            'port' => (int) (getenv('OOPTICIEN_SMTP_PORT') ?: 587),
            'encryption' => getenv('OOPTICIEN_SMTP_ENCRYPTION') ?: 'tls',
            'username' => getenv('OOPTICIEN_SMTP_USERNAME') ?: '',
            'password' => getenv('OOPTICIEN_SMTP_PASSWORD') ?: '',
            'timeout' => 15,
        ],
    ],
    'inbound_mail' => is_file(__DIR__ . '/inbound_mail.local.php')
        ? (require __DIR__ . '/inbound_mail.local.php')
        : [],
    'suppliers' => [
        'ophtalmic_portal_url' => 'https://www.ophtalmicespace.fr/Espaceclient/',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => getenv('OOPTICIEN_DB_NAME') ?: 'oopticien_pro',
        'user' => getenv('OOPTICIEN_DB_USER') ?: 'root',
        'password' => getenv('OOPTICIEN_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
];
