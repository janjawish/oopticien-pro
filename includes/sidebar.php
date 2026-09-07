<?php
declare(strict_types=1);

$menu = [
    ['dashboard.php', '⌂', 'Tableau de bord', 'main'],
    ['clients.php', '●', 'Clients', 'main'],
    ['dossiers.php', '▣', 'Dossiers', 'main'],
    ['mutuelles.php', 'M', 'Mutuelles / PEC', 'operations'],
    ['ophtalmic.php', 'O', 'Commandes verrier', 'operations'],
    ['documents.php', '▤', 'Documents', 'operations'],
    ['messages.php', '✉', 'Messages', 'operations'],
    ['relances.php', '✓', 'Relances', 'operations'],
    ['avoirs.php', '◎', 'Avoirs', 'finance'],
    ['paiements.php', '€', 'Paiements', 'finance'],
    ['assistant.php', '✦', 'Assistant', 'tools'],
    ['history.php', '↺', 'Historique', 'tools'],
    ['help.php', '?', 'Aide', 'tools'],
];
$sectionLabels=['operations'=>'Opérations','finance'=>'Finances','tools'=>'Outils'];
$lastSection='main';
?>
<aside class="sidebar" data-sidebar>
    <a class="brand" href="<?= e(app_url('pages/dashboard.php')) ?>">
        <span class="brand-mark">O</span>
        <span><strong>Oopticien</strong><small>PRO</small></span>
    </a>
    <nav class="main-nav" aria-label="Navigation principale">
        <?php foreach ($menu as [$file, $icon, $label, $section]): ?>
            <?php if ($section !== $lastSection): ?><p class="nav-section"><?= e($sectionLabels[$section] ?? $section) ?></p><?php $lastSection=$section; endif; ?>
            <a class="<?= $currentPath === $file ? 'active' : '' ?>" href="<?= e(app_url('pages/' . $file)) ?>">
                <span class="nav-icon"><?= e($icon) ?></span><?= e($label) ?>
            </a>
        <?php endforeach; ?>
        <?php if (has_role(['admin','patron'])): ?>
            <p class="nav-section">Pilotage</p>
            <a class="<?= $currentPath === 'statistics.php' ? 'active' : '' ?>" href="<?= e(app_url('pages/statistics.php')) ?>"><span class="nav-icon">Σ</span>Statistiques</a>
            <a class="<?= $currentPath === 'import_excel.php' ? 'active' : '' ?>" href="<?= e(app_url('pages/import_excel.php')) ?>"><span class="nav-icon">⇧</span>Import Excel</a>
            <a class="<?= in_array($currentPath,['import_pdf.php','import_preview.php'],true) ? 'active' : '' ?>" href="<?= e(app_url('pages/import_pdf.php')) ?>"><span class="nav-icon">PDF</span>Import Cosium</a>
        <?php endif; ?>
        <?php if (has_role('admin')): ?>
            <p class="nav-section">Administration</p>
            <a class="<?= $currentPath === 'users.php' ? 'active' : '' ?>" href="<?= e(app_url('pages/users.php')) ?>"><span class="nav-icon">♙</span>Utilisateurs</a>
            <a class="<?= $currentPath === 'settings.php' ? 'active' : '' ?>" href="<?= e(app_url('pages/settings.php')) ?>"><span class="nav-icon">⚙</span>Paramètres</a>
            <a class="<?= $currentPath === 'security.php' ? 'active' : '' ?>" href="<?= e(app_url('pages/security.php')) ?>"><span class="nav-icon">◆</span>Sécurité</a>
            <a class="<?= in_array($currentPath,['backups.php','backup_restore.php'],true) ? 'active' : '' ?>" href="<?= e(app_url('pages/backups.php')) ?>"><span class="nav-icon">↓</span>Sauvegardes</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <p><span class="status-dot"></span> Réseau local</p>
        <a href="<?= e(app_url('logout.php')) ?>">Se déconnecter</a>
    </div>
</aside>
<div class="sidebar-overlay" data-sidebar-overlay></div>
