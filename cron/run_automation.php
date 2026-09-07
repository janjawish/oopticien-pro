<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

$root = dirname(__DIR__);
require_once $root . '/includes/reminder_engine.php';
require_once $root . '/includes/inbound_mail.php';
app_config();

$reminders = run_reminder_generation();
$mail = process_inbound_delivery_mail();
$summary = ['reminders' => $reminders, 'inbound_mail' => $mail];
automation_setting_write(
    'automation_last_summary',
    json_encode($summary, JSON_UNESCAPED_UNICODE) ?: '{}',
    'Résumé de la dernière exécution automatique'
);

echo '[' . date('Y-m-d H:i:s') . '] ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";
