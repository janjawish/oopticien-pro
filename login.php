<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
if (current_user()) {
    redirect('pages/dashboard.php');
}
$flashes = consume_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion · Oopticien Pro</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
</head>
<body class="login-page">
<main class="login-layout">
    <section class="login-brand-panel">
        <div class="login-brand">
            <span class="brand-mark">O</span>
            <span><strong>Oopticien</strong><small>PRO</small></span>
        </div>
        <div>
            <p class="eyebrow">Votre boutique, bien suivie</p>
            <h1>Chaque dossier.<br>La bonne action.<br>Au bon moment.</h1>
            <p>Le cockpit interne pour suivre clients, mutuelles, paiements, commandes et relances.</p>
        </div>
        <div class="login-feature"><span>✓</span> Suivi opérationnel centralisé</div>
    </section>
    <section class="login-form-panel">
        <form class="login-card" method="post" action="<?= e(app_url('actions/login_action.php')) ?>">
            <?= csrf_field() ?>
            <div class="login-mobile-brand"><span class="brand-mark">O</span><strong>Oopticien Pro</strong></div>
            <p class="eyebrow">Bienvenue</p>
            <h2>Connexion à votre espace</h2>
            <p class="muted">Utilisez votre compte boutique pour continuer.</p>
            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
            <label class="field">
                <span>Adresse e-mail</span>
                <input type="email" name="email" autocomplete="username" required autofocus placeholder="vous@oopticien.local">
            </label>
            <label class="field">
                <span>Mot de passe</span>
                <span class="password-wrap">
                    <input type="password" name="password" autocomplete="current-password" required data-password>
                    <button type="button" class="input-action" data-password-toggle>Afficher</button>
                </span>
            </label>
            <button class="btn btn-primary btn-block" type="submit">Se connecter <span>→</span></button>
            <p class="login-help">Accès réservé à l’équipe · Réseau local uniquement</p>
        </form>
    </section>
</main>
<script src="<?= e(app_url('assets/js/app.js')) ?>"></script>
</body>
</html>
