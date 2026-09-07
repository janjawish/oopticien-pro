<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
redirect_if_not_logged_in();
$pageTitle = $pageTitle ?? app_config()['app_name'];
$currentPath = basename($_SERVER['PHP_SELF'] ?? '');
$flashes = consume_flashes();
$styleVersion = filemtime(dirname(__DIR__) . '/assets/css/style.css') ?: 1;
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Oopticien Pro, cockpit interne de suivi optique.">
    <title><?= e($pageTitle) ?> · Oopticien Pro</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css?v='.$styleVersion)) ?>">
    <script>document.documentElement.classList.add('js');</script>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <button class="menu-toggle" type="button" aria-label="Ouvrir le menu" data-menu-toggle>☰</button>
            <div>
                <p class="eyebrow">Cockpit de suivi</p>
                <h1><?= e($pageTitle) ?></h1>
            </div>
            <form class="topbar-search" method="get" action="<?= e(app_url('pages/search.php')) ?>">
                <input type="search" name="q" placeholder="Recherche globale…" aria-label="Recherche globale">
            </form>
            <div class="user-chip">
                <span class="avatar"><?= e(mb_strtoupper(mb_substr(current_user()['name'], 0, 1))) ?></span>
                <span><strong><?= e(current_user()['name']) ?></strong><small><?= e(status_label(current_user()['role'])) ?></small></span>
            </div>
        </header>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <div class="page-content">
