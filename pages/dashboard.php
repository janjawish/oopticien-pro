<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();

$q = trim((string) ($_GET['q'] ?? ''));
$clientResults = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = db()->prepare(
        'SELECT id, fiche_number, first_name, last_name, phone
         FROM clients
         WHERE last_name LIKE ? OR first_name LIKE ? OR phone LIKE ? OR fiche_number LIKE ?
         ORDER BY last_name, first_name LIMIT 8'
    );
    $stmt->execute([$like, $like, $like, $like]);
    $clientResults = $stmt->fetchAll();
}

$stats = db()->query(
    "SELECT
      (SELECT COUNT(*) FROM dossiers WHERE folder_status = 'en_cours') AS active_folders,
      (SELECT COUNT(*) FROM dossiers WHERE folder_status = 'a_facturer') AS to_invoice,
      (SELECT COUNT(*) FROM glass_orders WHERE ready_at IS NOT NULL AND status = 'prete') AS glasses_ready,
      (SELECT COUNT(*) FROM glass_orders WHERE ready_at IS NOT NULL AND client_notified_at IS NULL) AS clients_to_notify,
      (SELECT COUNT(*) FROM tasks t LEFT JOIN dossiers d ON d.id=t.dossier_id WHERE t.status IN ('a_faire','en_cours','reportee') AND t.task_type <> 'incoherence' AND (t.priority = 'critique' OR t.due_at <= NOW()) AND (d.id IS NULL OR d.folder_status NOT IN ('cloture','annule','sav'))) AS urgent_tasks,
      (SELECT COUNT(*) FROM dossiers WHERE folder_status = 'bloque') AS problematic_folders,
      (SELECT COUNT(*) FROM dossiers WHERE folder_status = 'sav') AS sav_folders"
)->fetch();

$urgentStmt = db()->query(
    "SELECT t.*, c.first_name, c.last_name
     FROM tasks t
     LEFT JOIN clients c ON c.id = t.client_id
     LEFT JOIN dossiers d ON d.id = t.dossier_id
     WHERE t.status IN ('a_faire','en_cours','reportee')
       AND t.task_type <> 'incoherence'
       AND (t.due_at <= DATE_ADD(NOW(), INTERVAL 1 DAY) OR t.priority = 'critique')
       AND (d.id IS NULL OR d.folder_status NOT IN ('cloture','annule','sav'))
     ORDER BY FIELD(t.priority,'critique','haute','normale','basse'), t.due_at
     LIMIT 7"
);
$urgentTasks = $urgentStmt->fetchAll();

$recentFolders = db()->query(
    'SELECT d.id, d.folder_status, d.mutual_status, d.total_amount, d.updated_at,
            c.first_name, c.last_name
     FROM dossiers d JOIN clients c ON c.id = d.client_id
     ORDER BY d.updated_at DESC LIMIT 6'
)->fetchAll();

$pageTitle = 'Tableau de bord';
require dirname(__DIR__) . '/includes/header.php';

$statCards = [
    ['active_folders', '▣', 'Dossiers en cours', 'Lignes grises', '', 'pages/dossiers.php?view=in_progress'],
    ['to_invoice', '⌁', 'À facturer', 'PEC acceptées', 'accent-orange', 'pages/dossiers.php?view=to_invoice'],
    ['problematic_folders', '!', 'Dossiers problématiques', 'Signalés en rouge', 'accent-red', 'pages/dossiers.php?view=problematic'],
    ['sav_folders', '↻', 'SAV', 'Automatisations suspendues', 'accent-orange', 'pages/dossiers.php?view=sav'],
    ['glasses_ready', '◎', 'Lunettes prêtes', 'En boutique', 'accent-green', 'pages/dossiers.php?view=ready'],
    ['clients_to_notify', '☎', 'Clients à prévenir', 'Action requise', 'accent-green', 'pages/dossiers.php?view=to_notify'],
    ['urgent_tasks', '!', 'Tâches urgentes', 'Échues ou critiques', 'accent-red', 'pages/relances.php?urgent=1'],
];
?>
<section class="page-actions">
    <div class="copy">
        <h2>Bonjour <?= e(explode(' ', current_user()['name'])[0]) ?>, voici les priorités.</h2>
        <p>Une vue claire de l’activité boutique et des prochaines actions.</p>
    </div>
    <div class="inline-actions">
        <a class="btn btn-primary" href="<?= e(app_url('pages/client_add.php')) ?>">＋ Nouveau client</a>
        <a class="btn btn-outline" href="<?= e(app_url('pages/dossier_add.php')) ?>">＋ Nouveau dossier</a>
    </div>
</section>

<form class="filters" method="get" style="grid-template-columns:minmax(260px,1fr) auto">
    <div class="search-input">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher rapidement un client…">
    </div>
    <button class="btn btn-primary" type="submit">Rechercher</button>
</form>

<?php if ($q !== ''): ?>
    <section class="card table-card" style="margin-bottom:18px">
        <div class="card-header">
            <div><h3>Résultats clients</h3><p><?= count($clientResults) ?> résultat(s) pour « <?= e($q) ?> »</p></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Client</th><th>Fiche</th><th>Téléphone</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($clientResults as $client): ?>
                    <tr>
                        <td><span class="cell-title"><?= e(mb_strtoupper($client['last_name']) . ' ' . $client['first_name']) ?></span></td>
                        <td><?= e($client['fiche_number'] ?: '—') ?></td>
                        <td><?= e($client['phone'] ?: '—') ?></td>
                        <td class="text-right"><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/client_view.php?id=' . $client['id'])) ?>">Voir la fiche</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$clientResults): ?><tr><td colspan="4" class="empty-state"><strong>Aucun client trouvé</strong>Essayez avec un autre nom ou numéro.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<div class="stats-grid">
    <?php foreach ($statCards as [$key, $icon, $label, $note, $class, $href]): ?>
        <a class="stat-card stat-card-link <?= e($class) ?>" href="<?= e(app_url($href)) ?>">
            <div class="stat-top"><span class="stat-icon"><?= e($icon) ?></span><?= e($label) ?></div>
            <div class="stat-value"><?= (int) $stats[$key] ?></div>
            <p class="stat-note"><?= e($note) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<div class="content-grid">
    <section class="card">
        <div class="card-header">
            <div><h2>Urgences du jour</h2><p>Les actions qui demandent votre attention</p></div>
            <a href="<?= e(app_url('pages/relances.php')) ?>">Tout voir →</a>
        </div>
        <div class="task-list">
            <?php foreach ($urgentTasks as $task): ?>
                <div class="task-item">
                    <span class="task-line <?= $task['priority'] === 'critique' ? 'critical' : 'high' ?>"></span>
                    <div>
                        <p><?= e($task['title']) ?></p>
                        <small><?= e(trim(($task['first_name'] ?? '') . ' ' . ($task['last_name'] ?? ''))) ?> · échéance <?= e(format_date($task['due_at'], true)) ?></small>
                    </div>
                    <?php if ($task['dossier_id']): ?><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/dossier_view.php?id=' . $task['dossier_id'])) ?>">Ouvrir</a><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$urgentTasks): ?><div class="empty-state"><strong>Aucune urgence</strong>Tout est à jour pour le moment.</div><?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><div><h2>Actions rapides</h2><p>Gagner du temps</p></div></div>
        <div class="quick-actions">
            <a class="quick-action" href="<?= e(app_url('pages/client_add.php')) ?>"><span>＋</span>Nouveau client</a>
            <a class="quick-action" href="<?= e(app_url('pages/dossier_add.php')) ?>"><span>▣</span>Nouveau dossier</a>
            <a class="quick-action" href="<?= e(app_url('pages/documents.php')) ?>"><span>▤</span>Déposer un document</a>
            <a class="quick-action" href="<?= e(app_url('pages/mutuelles.php')) ?>"><span>M</span>Suivi mutuelle</a>
            <a class="quick-action" href="<?= e(app_url('pages/ophtalmic.php')) ?>"><span>O</span>Commandes verrier</a>
            <?php if (has_role(['admin', 'patron'])): ?>
                <a class="quick-action" href="<?= e(app_url('pages/import_excel.php')) ?>"><span>⇧</span>Importer CSV</a>
            <?php else: ?>
                <a class="quick-action" href="<?= e(app_url('pages/relances.php')) ?>"><span>✓</span>Voir les relances</a>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card table-card" style="margin-top:18px">
    <div class="card-header">
        <div><h2>Dossiers récents</h2><p>Les dernières mises à jour</p></div>
        <a href="<?= e(app_url('pages/dossiers.php')) ?>">Tous les dossiers →</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Dossier</th><th>Client</th><th>Statut</th><th>Mutuelle</th><th>Montant</th><th>Mise à jour</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentFolders as $folder): ?>
                <tr>
                    <td><a class="cell-title" href="<?= e(app_url('pages/dossier_view.php?id=' . $folder['id'])) ?>"><?= e(dossier_number((int) $folder['id'])) ?></a></td>
                    <td><?= e($folder['first_name'] . ' ' . mb_strtoupper($folder['last_name'])) ?></td>
                    <td><?= status_badge($folder['folder_status']) ?></td>
                    <td><?= status_badge($folder['mutual_status']) ?></td>
                    <td class="amount"><?= e(format_euros($folder['total_amount'])) ?></td>
                    <td><?= e(format_date($folder['updated_at'], true)) ?></td>
                    <td><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/dossier_view.php?id=' . $folder['id'])) ?>">Voir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
