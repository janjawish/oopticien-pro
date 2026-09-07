<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();

$q = trim((string) ($_GET['q'] ?? ''));
$allowedPerPage = [25, 50, 100, 250];
$requestedPerPage = (int) ($_GET['per_page'] ?? 100);
$perPage = in_array($requestedPerPage, $allowedPerPage, true) ? $requestedPerPage : 100;
$fromSql = ' FROM clients c';
$whereSql = '';
$params = [];
if ($q !== '') {
    $whereSql = ' WHERE c.last_name LIKE ? OR c.first_name LIKE ? OR c.phone LIKE ? OR c.fiche_number LIKE ? OR c.social_security_number LIKE ?';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like, '%' . normalize_nir($q) . '%'];
}

$countStmt = db()->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$countStmt->execute($params);
$totalClients = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalClients / $perPage));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;
$firstResult = $totalClients ? $offset + 1 : 0;
$lastResult = min($totalClients, $offset + $perPage);

$sql = 'SELECT c.*,
          (SELECT COUNT(*) FROM dossiers d WHERE d.client_id = c.id) AS folder_count,
          (SELECT COALESCE(SUM(d.total_amount),0)
           FROM dossiers d
           WHERE d.client_id = c.id
             AND d.folder_status NOT IN ("annule","remis","cloture")) AS active_dossier_total,
          (SELECT COUNT(*)
           FROM credits cr
           WHERE cr.client_id = c.id
              OR EXISTS (
                  SELECT 1 FROM credit_beneficiaries cb
                  WHERE cb.credit_id = cr.id AND cb.client_id = c.id
              )) AS tracked_credit_count,
          (SELECT COALESCE(SUM(cr.remaining_amount),0)
           FROM credits cr
           WHERE cr.client_id = c.id
              OR EXISTS (
                  SELECT 1 FROM credit_beneficiaries cb
                  WHERE cb.credit_id = cr.id AND cb.client_id = c.id
              )) AS tracked_credit_remaining
        ' . $fromSql . $whereSql;
$sql .= ' ORDER BY c.last_name, c.first_name LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll();

$pageTitle = 'Clients';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="copy"><h2>Fichier clients</h2><p>Retrouvez les coordonnées, dossiers et droits disponibles.</p></div>
    <a class="btn btn-primary" href="<?= e(app_url('pages/client_add.php')) ?>">＋ Nouveau client</a>
</section>

<form class="filters" method="get" style="grid-template-columns:minmax(260px,1fr) minmax(150px,190px) auto">
    <div class="search-input"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, prénom, téléphone, fiche ou NIR…"></div>
    <select name="per_page" aria-label="Clients par page"><?php foreach ($allowedPerPage as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> par page</option><?php endforeach; ?></select>
    <button class="btn btn-primary" type="submit">Rechercher</button>
</form>

<section class="card table-card">
    <div class="card-header"><div><h2><?= $totalClients ?> client(s)</h2><p>Affichage <?= $firstResult ?>–<?= $lastResult ?> · page <?= $page ?> sur <?= $totalPages ?></p></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Client</th><th>Fiche</th><th>Contact</th><th>Mutuelle</th><th>Dossiers</th><th>Avoir disponible</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($clients as $client):
                $hasTrackedCredit = (int) $client['tracked_credit_count'] > 0;
                $availableAmount = $hasTrackedCredit ? $client['tracked_credit_remaining'] : $client['active_dossier_total'];
            ?>
                <tr>
                    <td>
                        <a class="cell-title" href="<?= e(app_url('pages/client_view.php?id=' . $client['id'])) ?>"><?= e(mb_strtoupper($client['last_name']) . ' ' . $client['first_name']) ?></a>
                        <span class="cell-subtitle">NIR : <?= e(mask_nir($client['social_security_number'])) ?></span>
                    </td>
                    <td><?= e($client['fiche_number'] ?: '—') ?></td>
                    <td><?= e($client['phone'] ?: '—') ?><span class="cell-subtitle"><?= e($client['email'] ?: '') ?></span></td>
                    <td><?= e($client['mutual_name'] ?: '—') ?></td>
                    <td><span class="badge badge-neutral"><?= (int) $client['folder_count'] ?></span></td>
                    <td class="amount text-success"><?= e(format_euros($availableAmount)) ?><span class="cell-subtitle"><?= $hasTrackedCredit ? 'Avoir suivi ou partagé' : 'Total des dossiers actifs' ?></span></td>
                    <td>
                        <div class="inline-actions">
                            <a class="btn btn-small btn-outline" href="<?= e(app_url('pages/client_view.php?id=' . $client['id'])) ?>">Voir</a>
                            <a class="btn btn-small btn-outline" href="<?= e(app_url('pages/client_edit.php?id=' . $client['id'])) ?>">Modifier</a>
                            <a class="btn btn-small btn-primary" href="<?= e(app_url('pages/dossier_add.php?client_id=' . $client['id'])) ?>">＋ Dossier</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$clients): ?><tr><td colspan="7" class="empty-state"><strong>Aucun client trouvé</strong>Créez une fiche ou modifiez la recherche.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="Pages des clients">
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
