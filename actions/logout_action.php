<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
if (current_user()) {
    log_action('user', (int) current_user()['id'], 'deconnexion', 'Déconnexion');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
}
session_destroy();
session_start();
flash('success', 'Vous êtes déconnecté.');
redirect('login.php');
