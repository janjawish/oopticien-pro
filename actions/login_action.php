<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_post();
verify_csrf();

$email = mb_strtolower(post_string('email', 190));
$password = (string) ($_POST['password'] ?? '');
$ipAddress = request_ip();

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    flash('danger', 'Renseignez une adresse e-mail et un mot de passe valides.');
    redirect('login.php');
}

if (login_is_locked($email, $ipAddress)) {
    flash('danger', 'Trop de tentatives. Réessayez après le délai de sécurité.');
    redirect('login.php');
}

$stmt = db()->prepare('SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !(bool) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
    record_login_attempt($email, $ipAddress, false);
    usleep(250000);
    flash('danger', 'Identifiants incorrects ou compte désactivé.');
    redirect('login.php');
}

record_login_attempt($email, $ipAddress, true);
session_regenerate_id(true);
$_SESSION['user'] = [
    'id' => (int) $user['id'],
    'name' => $user['name'],
    'email' => $user['email'],
    'role' => $user['role'],
];
$_SESSION['last_activity'] = time();

$stmt = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
$stmt->execute([$user['id']]);
log_action('user', (int) $user['id'], 'connexion', 'Connexion réussie');
redirect('pages/dashboard.php');
