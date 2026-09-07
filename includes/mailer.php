<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function smtp_configuration(): array
{
    $messaging = app_config()['messaging'] ?? [];
    $smtp = $messaging['smtp'] ?? [];

    return [
        'host' => trim((string) ($smtp['host'] ?? '')),
        'port' => max(1, (int) ($smtp['port'] ?? 587)),
        'encryption' => strtolower(trim((string) ($smtp['encryption'] ?? 'tls'))),
        'username' => trim((string) ($smtp['username'] ?? '')),
        'password' => (string) ($smtp['password'] ?? ''),
        'timeout' => max(5, min(60, (int) ($smtp['timeout'] ?? 15))),
        'from_email' => trim((string) ($messaging['from_email'] ?? '')),
        'from_name' => trim((string) ($messaging['from_name'] ?? 'Oopticien Pro')),
        'reply_to' => trim((string) ($messaging['reply_to'] ?? '')),
    ];
}

function smtp_configuration_status(): array
{
    $config = smtp_configuration();
    $missing = [];
    if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
        $missing[] = 'bibliothèque PHPMailer';
    }
    if ($config['host'] === '') {
        $missing[] = 'serveur SMTP';
    }
    if (!filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
        $missing[] = 'adresse d’expédition';
    }
    if (!in_array($config['encryption'], ['tls', 'ssl', 'smtps', 'none', ''], true)) {
        $missing[] = 'sécurité SMTP';
    }

    return [
        'ready' => $missing === [],
        'missing' => $missing,
        'host' => $config['host'],
        'port' => $config['port'],
        'from_email' => $config['from_email'],
    ];
}

function send_smtp_email(string $recipient, string $recipientName, string $subject, string $plainText): void
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('L’adresse e-mail du client est invalide.');
    }
    $status = smtp_configuration_status();
    if (!$status['ready']) {
        throw new RuntimeException('Configuration e-mail incomplète : ' . implode(', ', $status['missing']) . '.');
    }

    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $config = smtp_configuration();
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $config['host'];
    $mail->Port = $config['port'];
    $mail->Timeout = $config['timeout'];
    $mail->SMTPAuth = $config['username'] !== '';
    $mail->Username = $config['username'];
    $mail->Password = $config['password'];
    $mail->SMTPDebug = 0;
    $mail->CharSet = PHPMailer::CHARSET_UTF8;

    if (in_array($config['encryption'], ['ssl', 'smtps'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($config['encryption'] === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom($config['from_email'], $config['from_name']);
    if ($config['reply_to'] !== '' && filter_var($config['reply_to'], FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($config['reply_to'], $config['from_name']);
    }
    $mail->addAddress($recipient, $recipientName);
    $mail->Subject = $subject !== '' ? $subject : 'Message de votre opticien';
    $mail->isHTML(true);
    $mail->Body = '<div style="font-family:Arial,sans-serif;font-size:16px;line-height:1.55;color:#18233b">'
        . nl2br(htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        . '</div>';
    $mail->AltBody = $plainText;
    $mail->send();
}
