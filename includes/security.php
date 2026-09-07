<?php
declare(strict_types=1);

function request_ip(): string
{
    return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 0, 45);
}

function ip_matches_cidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if ($cidr === '' || $ip === 'CLI') {
        return $ip === 'CLI';
    }
    [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
    if ($prefix === null) {
        return hash_equals($network, $ip);
    }
    $ipBinary = inet_pton($ip);
    $networkBinary = inet_pton($network);
    if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
        return false;
    }
    $bits = (int) $prefix;
    $maximumBits = strlen($ipBinary) * 8;
    if ($bits < 0 || $bits > $maximumBits) {
        return false;
    }
    $bytes = intdiv($bits, 8);
    $remaining = $bits % 8;
    if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
        return false;
    }
    if ($remaining === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remaining)) & 0xFF;
    return (ord($ipBinary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
}

function is_local_network_ip(string $ip): bool
{
    $allowed = preg_split(
        '/[\s,;]+/',
        (string) get_setting(
            'allowed_local_subnets',
            '127.0.0.1/32,::1/128,192.168.0.0/16,10.0.0.0/8,172.16.0.0/12'
        )
    ) ?: [];
    foreach ($allowed as $cidr) {
        if ($cidr !== '' && ip_matches_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

function enforce_local_network_policy(): void
{
    if ((string) get_setting('force_local_network_only', '0') !== '1') {
        return;
    }
    if (is_local_network_ip(request_ip())) {
        return;
    }
    http_response_code(403);
    exit('Accès refusé : cette application est limitée au réseau local autorisé.');
}

function login_is_locked(string $email, string $ipAddress): bool
{
    $maximumAttempts = max(1, (int) get_setting('login_max_attempts', 5));
    $lockoutMinutes = max(1, (int) get_setting('login_lockout_minutes', 15));
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND ip_address = ? AND success = 0
               AND attempted_at >= ?'
        );
        $cutoff = (new DateTimeImmutable())->modify('-' . $lockoutMinutes . ' minutes')->format('Y-m-d H:i:s');
        $stmt->execute([$email, $ipAddress, $cutoff]);
        return (int) $stmt->fetchColumn() >= $maximumAttempts;
    } catch (Throwable) {
        return false;
    }
}

function record_login_attempt(string $email, string $ipAddress, bool $successful): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO login_attempts (email, ip_address, was_successful, success) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$email, $ipAddress, $successful ? 1 : 0, $successful ? 1 : 0]);
        if ($successful) {
            $cleanup = db()->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ? AND success = 0');
            $cleanup->execute([$email, $ipAddress]);
        }
    } catch (Throwable) {
        // Compatibilité V1 avant migration : l’authentification continue de fonctionner.
    }
}

function log_sensitive_access(?int $clientId, string $action, string $details = ''): void
{
    try {
        $user = current_user();
        $stmt = db()->prepare(
            'INSERT INTO sensitive_access_logs
             (user_id, client_id, entity_type, entity_id, sensitive_field, reason, action, ip_address, details)
             VALUES (?, ?, "client", ?, "nir", ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['id'] ?? null,
            $clientId,
            $clientId,
            mb_substr($details, 0, 500),
            mb_substr($action, 0, 80),
            request_ip(),
            mb_substr($details, 0, 500),
        ]);
    } catch (Throwable) {
        // La journalisation V2 ne doit pas exposer de donnée ni casser la page en V1.
    }
}
