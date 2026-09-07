<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$mutual = trim((string) ($_GET['mutual'] ?? ''));
$priority = trim((string) ($_GET['priority'] ?? ''));
$late = isset($_GET['late']) && $_GET['late'] === '1';
$view = trim((string) ($_GET['view'] ?? ''));
$allowedViews = ['in_progress','mutual_waiting','to_invoice','problematic','sav','ready','to_notify','inconsistencies'];
if (!in_array($view, $allowedViews, true)) $view = '';

$conditions = [];
$params = [];
if ($q !== '') {
    $conditions[] = '(c.last_name LIKE ? OR c.first_name LIKE ? OR c.phone LIKE ? OR d.id = ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, (int) preg_replace('/\D/', '', $q)];
}
if ($status !== '') { $conditions[] = 'd.folder_status = ?'; $params[] = $status; }
if ($mutual !== '') { $conditions[] = 'd.mutual_status = ?'; $params[] = $mutual; }
if ($priority !== '') { $conditions[] = 'd.priority = ?'; $params[] = $priority; }
if ($late) {
    $conditions[] = "EXISTS (SELECT 1 FROM tasks t WHERE t.dossier_id = d.id AND t.status IN ('a_faire','en_cours','reportee') AND t.due_at < NOW())";
}
if ($view === 'in_progress') {
    $conditions[] = "d.folder_status='en_cours'";
} elseif ($view === 'mutual_waiting') {
    $conditions[] = "d.mutual_status IN ('envoyee','en_attente')";
} elseif ($view === 'to_invoice') {
    $conditions[] = "d.folder_status='a_facturer'";
} elseif ($view === 'problematic') {
    $conditions[] = "d.folder_status='bloque'";
} elseif ($view === 'sav') {
    $conditions[] = "d.folder_status='sav'";
} elseif ($view === 'ready') {
    $conditions[] = "(d.folder_status IN ('pret','client_prevenu') OR EXISTS (SELECT 1 FROM glass_orders go WHERE go.dossier_id=d.id AND (go.status='prete' OR go.ready_at IS NOT NULL)))";
} elseif ($view === 'to_notify') {
    $conditions[] = "EXISTS (SELECT 1 FROM glass_orders go WHERE go.dossier_id=d.id AND (go.status='prete' OR go.ready_at IS NOT NULL) AND go.client_notified_at IS NULL)";
} elseif ($view === 'inconsistencies') {
    $conditions[] = 'd.total_warning=1';
}

$fromSql = ' FROM dossiers d JOIN clients c ON c.id=d.client_id';
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$countStmt = db()->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$countStmt->execute($params);
$totalFolders = (int) $countStmt->fetchColumn();
$allowedPerPage = [25, 50, 100, 250];
$requestedPerPage = (int) ($_GET['per_page'] ?? 100);
$perPage = in_array($requestedPerPage, $allowedPerPage, true) ? $requestedPerPage : 100;
$totalPages = max(1, (int) ceil($totalFolders / $perPage));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$sql = 'SELECT d.*, c.first_name, c.last_name, c.phone,
          (SELECT COUNT(*) FROM tasks t WHERE t.dossier_id=d.id AND t.status IN ("a_faire","en_cours","reportee")) AS open_tasks
        ' . $fromSql . $whereSql;
$sql .= " ORDER BY FIELD(d.priority,'urgente','haute','normale','basse'), d.updated_at DESC LIMIT "
    . $perPage . ' OFFSET ' . $offset;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$folders = $stmt->fetchAll();

$folderStatuses = ['brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer','facture','teletransmis','commande_a_faire','commande_envoyee','verres_recus','montage','pret','client_prevenu','remis','cloture','bloque','sav','derogation','annule'];
$mutualStatuses = ['non_envoyee','envoyee','en_attente','pec_acceptee','pec_refusee','incomplete'];
$viewLabels = [
    'in_progress' => 'Dossiers en cours', 'mutual_waiting' => 'Attente mutuelle',
    'to_invoice' => 'Dossiers à facturer', 'problematic' => 'Dossiers problématiques', 'sav' => 'Dossiers en SAV',
    'ready' => 'Lunettes prêtes', 'to_notify' => 'Clients à prévenir',
    'inconsistencies' => 'Incohérences de montant',
];

$pageTitle = 'Dossiers';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="copy"><h2><?= e($viewLabels[$view] ?? 'Suivi des dossiers') ?></h2><p>Filtrez et ouvrez rapidement chaque dossier opérationnel.</p></div>
    <a class="btn btn-primary" href="<?= e(app_url('pages/dossier_add.php')) ?>">＋ Nouveau dossier</a>
</section>

<form class="filters" method="get" style="grid-template-columns:minmax(230px,2fr) repeat(4,minmax(140px,1fr)) auto">
    <?php if ($view !== ''): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
    <div class="search-input"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Client, téléphone ou n° dossier…"></div>
    <select name="status"><option value="">Tous les statuts</option><?php foreach ($folderStatuses as $item): ?><option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select>
    <select name="mutual"><option value="">Toutes les mutuelles</option><?php foreach ($mutualStatuses as $item): ?><option value="<?= e($item) ?>" <?= $mutual === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select>
    <select name="priority"><option value="">Toutes priorités</option><?php foreach (['basse','normale','haute','urgente'] as $item): ?><option value="<?= e($item) ?>" <?= $priority === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select>
    <select name="per_page" aria-label="Dossiers par page"><?php foreach ($allowedPerPage as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> par page</option><?php endforeach; ?></select>
    <button class="btn btn-primary" type="submit">Filtrer</button>
    <label class="field" style="display:flex;align-items:center;grid-column:1/-1"><input type="checkbox" name="late" value="1" style="width:auto;min-height:auto" <?= $late ? 'checked' : '' ?>> Afficher uniquement les dossiers avec relance en retard</label>
</form>

<section class="card table-card">
    <div class="card-header"><div><h2><?= $totalFolders ?> dossier(s)</h2><p>Page <?= $page ?> sur <?= $totalPages ?> · <?= $perPage ?> dossiers par page</p></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Dossier</th><th>Client</th><th>Type</th><th>Statut</th><th>Mutuelle</th><th>Priorité</th><th>Total</th><th>Action suivante</th><th>Relances</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($folders as $folder): $control=dossier_control($folder); ?>
                <tr class="<?= $folder['folder_status'] === 'bloque' ? 'row-overdue' : (($folder['folder_status'] === 'sav' || $folder['total_warning']) ? 'row-warning' : '') ?>">
                    <td><a class="cell-title" href="<?= e(app_url('pages/dossier_view.php?id=' . $folder['id'])) ?>"><?= e(dossier_number((int) $folder['id'])) ?></a><span class="cell-subtitle"><?= e(format_date($folder['created_at'])) ?></span></td>
                    <td><a class="cell-title" href="<?= e(app_url('pages/client_view.php?id=' . $folder['client_id'])) ?>"><?= e($folder['first_name'] . ' ' . mb_strtoupper($folder['last_name'])) ?></a><span class="cell-subtitle"><?= e($folder['phone'] ?: '') ?></span></td>
                    <td><?= status_badge($folder['dossier_type'] ?? 'lunettes') ?></td>
                    <td><?= status_badge($folder['folder_status']) ?><span class="cell-subtitle <?= $control['ready']?'text-success':'text-danger' ?>"><?= e($control['label']) ?></span></td>
                    <td><?= status_badge($folder['mutual_status']) ?></td>
                    <td><?= status_badge($folder['priority']) ?></td>
                    <td class="amount <?= $folder['total_warning'] ? 'text-danger' : '' ?>"><?= e(format_euros($folder['total_amount'])) ?><?= $folder['total_warning'] ? '<span class="cell-subtitle text-danger">Incohérent</span>' : '' ?></td>
                    <td><?= e($folder['next_action'] ?: '—') ?></td>
                    <td><span class="badge <?= $folder['open_tasks'] ? 'badge-warning' : 'badge-neutral' ?>"><?= (int) $folder['open_tasks'] ?></span></td>
                    <td><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/dossier_view.php?id=' . $folder['id'])) ?>">Ouvrir</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$folders): ?><tr><td colspan="10" class="empty-state"><strong>Aucun dossier trouvé</strong>Modifiez les filtres ou créez un dossier.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="Pages des dossiers">
    <?php $query = $_GET; ?>
    <?php if ($page > 1): $query['page'] = $page - 1; ?><a class="pagination-arrow" href="?<?= e(http_build_query($query)) ?>">← Précédent</a><?php endif; ?>
    <div class="pagination-pages">
        <?php foreach (pagination_items($page, $totalPages) as $pageItem): ?>
            <?php if ($pageItem === null): ?>
                <span class="pagination-ellipsis">…</span>
            <?php elseif ($pageItem === $page): ?>
                <span class="pagination-link active" aria-current="page"><?= $pageItem ?></span>
            <?php else: $query['page'] = $pageItem; ?>
                <a class="pagination-link" href="?<?= e(http_build_query($query)) ?>"><?= $pageItem ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php if ($page < $totalPages): $query['page'] = $page + 1; ?><a class="pagination-arrow" href="?<?= e(http_build_query($query)) ?>">Suivant →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
