<?php
declare(strict_types=1);

const OOPTICIEN_ENCRYPTION_PREFIX = 'v1:';

function configured_encryption_key(): ?string
{
    $configured = trim((string) (app_config()['encryption_key'] ?? ''));
    if ($configured === '') {
        $configured = trim((string) get_setting('app_encryption_key', get_setting('encryption_key', '')));
    }
    if ($configured === '') {
        $root = rtrim((string) (app_config()['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($root === '') {
            return null;
        }
        $keyDirectory = $root . DIRECTORY_SEPARATOR . 'keys';
        $keyFile = $keyDirectory . DIRECTORY_SEPARATOR . 'nir.key';
        if (is_file($keyFile)) {
            $stored = base64_decode(trim((string) file_get_contents($keyFile)), true);
            return $stored !== false && strlen($stored) === 32 ? $stored : null;
        }
        if (!is_dir($keyDirectory) && !mkdir($keyDirectory, 0750, true) && !is_dir($keyDirectory)) {
            return null;
        }
        $generated = random_bytes(32);
        if (file_put_contents($keyFile, base64_encode($generated), LOCK_EX) === false) {
            return null;
        }
        @chmod($keyFile, 0600);
        return $generated;
    }

    $decoded = base64_decode($configured, true);
    if ($decoded !== false && strlen($decoded) === 32) {
        return $decoded;
    }
    if (ctype_xdigit($configured) && strlen($configured) === 64) {
        return hex2bin($configured) ?: null;
    }
    return hash('sha256', $configured, true);
}

function encrypt_sensitive_value(string $plainText): string
{
    $key = configured_encryption_key();
    if ($key === null) {
        throw new RuntimeException('La clé de chiffrement V2 n’est pas configurée.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipherText === false) {
        throw new RuntimeException('Le chiffrement a échoué.');
    }
    return OOPTICIEN_ENCRYPTION_PREFIX . base64_encode($iv . $tag . $cipherText);
}

function decrypt_sensitive_value(?string $payload): ?string
{
    if ($payload === null || $payload === '') {
        return null;
    }
    if (!str_starts_with($payload, OOPTICIEN_ENCRYPTION_PREFIX)) {
        return null;
    }
    $key = configured_encryption_key();
    $decoded = base64_decode(substr($payload, strlen(OOPTICIEN_ENCRYPTION_PREFIX)), true);
    if ($key === null || $decoded === false || strlen($decoded) < 29) {
        return null;
    }
    $iv = substr($decoded, 0, 12);
    $tag = substr($decoded, 12, 16);
    $cipherText = substr($decoded, 28);
    $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plainText === false ? null : $plainText;
}

function client_nir(array $client): ?string
{
    $encrypted = decrypt_sensitive_value($client['social_security_number_encrypted'] ?? null);
    if ($encrypted !== null && $encrypted !== '') {
        return $encrypted;
    }
    $legacy = normalize_nir((string) ($client['social_security_number'] ?? ''));
    return $legacy !== '' ? $legacy : null;
}
