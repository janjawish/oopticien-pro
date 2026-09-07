<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');
require_post();
verify_csrf();

$timeout = max(5, min(720, (int) ($_POST['session_timeout_minutes'] ?? 30)));
$maximumAttempts = max(2, min(20, (int) ($_POST['login_max_attempts'] ?? 5)));
$lockout = max(1, min(1440, (int) ($_POST['login_lockout_minutes'] ?? 15)));
$localOnly = post_string('force_local_network_only') === '1' ? '1' : '0';
$rawSubnets = post_string('allowed_local_subnets', 1000);
$subnets = array_values(array_filter(preg_split('/[\s,;]+/', $rawSubnets) ?: []));

foreach ($subnets as $cidr) {
    [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
    if (filter_var($network, FILTER_VALIDATE_IP) === false || $prefix === null || !ctype_digit($prefix)) {
        flash('danger', 'Sous-réseau invalide : ' . $cidr);
        redirect('pages/security.php');
    }
    $max = str_contains($network, ':') ? 128 : 32;
    if ((int) $prefix < 0 || (int) $prefix > $max) {
        flash('danger', 'Préfixe CIDR invalide : ' . $cidr);
        redirect('pages/security.php');
    }
}
if ($localOnly === '1' && !array_filter($subnets, fn(string $cidr): bool => ip_matches_cidr(request_ip(), $cidr))) {
    flash('danger', 'Réglage refusé : votre adresse IP actuelle doit appartenir à un sous-réseau autorisé.');
    redirect('pages/security.php');
}

$values = [
    'session_timeout_minutes' => (string) $timeout,
    'login_max_attempts' => (string) $maximumAttempts,
    'login_lockout_minutes' => (string) $lockout,
    'force_local_network_only' => $localOnly,
    'allowed_local_subnets' => implode(',', $subnets),
];
$stmt = db()->prepare(
    'INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
);
foreach ($values as $key => $value) {
    $stmt->execute([$key, $value, 'Réglage de sécurité']);
}
log_action('settings', null, 'security_update', 'Mise à jour de la politique de sécurité');
flash('success', 'La politique de sécurité a été enregistrée.');
redirect('pages/security.php');
