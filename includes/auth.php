<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/encryption.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = app_config();
    session_name('OOPTICIEN_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    $timeout = max(60, (int) get_setting(
        'session_timeout_minutes',
        max(1, intdiv((int) ($config['session_timeout'] ?? 1800), 60))
    ) * 60);
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $timeout) {
        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Votre session a expiré. Veuillez vous reconnecter.'];
    }
    $_SESSION['last_activity'] = time();
}

start_secure_session();
enforce_local_network_policy();
require_once __DIR__ . '/csrf.php';

function redirect_if_not_logged_in(): void
{
    if (current_user() === null) {
        redirect('login.php');
    }
}

function require_role(array|string $roles): void
{
    redirect_if_not_logged_in();
    if (!has_role($roles)) {
        http_response_code(403);
        exit('Accès refusé : vos droits ne permettent pas cette action.');
    }
}
