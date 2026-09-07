<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();

function payment_filter_value(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

$allowedPayers = ['ro', 'rc', 'client'];
$allowedStatuses = ['attendu', 'partiel', 'encaisse', 'retard', 'cheque_caution', 'non_applicable'];
$allowedMethods = ['cb', 'cheque', 'especes', 'virement', 'autre', 'non_renseigne'];
$allowedSorts = ['priority', 'date_desc', 'date_asc', 'updated_desc', 'client_asc'];
$allowedPerPage = [25, 50, 100, 250];

$payer = payment_filter_value('payer');
$status = payment_filter_value('status');
$method = payment_filter_value('method');
$mutual = mb_substr(payment_filter_value('mutual'), 0, 160);
$sort = payment_filter_value('sort');
$q = mb_substr(payment_filter_value('q'), 0, 160);
$requestedPerPage = (int) payment_filter_value('per_page');

if (!in_array($payer, $allowedPayers, true)) { $payer = ''; }
if (!in_array($status, $allowedStatuses, true)) { $status = ''; }
if (!in_array($method, $allowedMethods, true)) { $method = ''; }
if (!in_array($sort, $allowedSorts, true)) { $sort = 'priority'; }
$perPage = in_array($requestedPerPage, $allowedPerPage, true) ? $requestedPerPage : 100;

$conditions = [];
$params = [];
if ($payer !== '') { $conditions[] = 'p.payer = ?'; $params[] = $payer; }
if ($status !== '') { $conditions[] = 'p.status = ?'; $params[] = $status; }
if ($method === 'non_renseigne') {
    $conditions[] = 'p.payment_method IS NULL';
} elseif ($method !== '') {
    $conditions[] = 'p.payment_method = ?';
    $params[] = $method;
}
if ($mutual !== '') { $conditions[] = 'TRIM(c.mutual_name) = ?'; $params[] = $mutual; }
if ($q !== '') {
    $conditions[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR d.id = ?)';
    $like = '%' . $q . '%';
    $params = [...$params, $like, $like, (int) preg_replace('/\D/', '', $q)];
}

$fromSql = ' FROM payments p
              JOIN dossiers d ON d.id = p.dossier_id
              JOIN clients c ON c.id = d.client_id';
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$countStmt = db()->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$countStmt->execute($params);
$totalPayments = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalPayments / $perPage));
$page = max(1, min($totalPages, (int) payment_filter_value('page')));
$offset = ($page - 1) * $perPage;
$firstResult = $totalPayments ? $offset + 1 : 0;
$lastResult = min($totalPayments, $offset + $perPage);

$orderSql = match ($sort) {
    'date_desc' => 'CASE WHEN p.payment_date IS NULL THEN 1 ELSE 0 END, p.payment_date DESC, p.updated_at DESC',
    'date_asc' => 'CASE WHEN p.payment_date IS NULL THEN 1 ELSE 0 END, p.payment_date ASC, p.updated_at DESC',
    'updated_desc' => 'p.updated_at DESC, p.id DESC',
    'client_asc' => 'c.last_name ASC, c.first_name ASC, p.id DESC',
    default => "FIELD(p.status,'retard','cheque_caution','attendu','partiel','encaisse','non_applicable'), p.updated_at DESC",
};

$sql = 'SELECT p.*, d.client_id, c.first_name, c.last_name, c.mutual_name'
     . $fromSql . $whereSql
     . ' ORDER BY ' . $orderSql
     . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$mutuals = db()->query(
    "SELECT DISTINCT TRIM(c.mutual_name) AS mutual_name
       FROM clients c
      WHERE c.mutual_name IS NOT NULL
        AND TRIM(c.mutual_name) <> ''
        AND EXISTS (
            SELECT 1
              FROM dossiers d
              JOIN payments p ON p.dossier_id = d.id
             WHERE d.client_id = c.id
        )
      ORDER BY mutual_name"
)->fetchAll(PDO::FETCH_COLUMN);

$totals = db()->query(
    "SELECT
      SUM(CASE WHEN status IN ('attendu','partiel','retard') THEN expected_amount-paid_amount ELSE 0 END) remaining,
      SUM(CASE WHEN status='encaisse' THEN paid_amount ELSE 0 END) collected,
      SUM(CASE WHEN payer='ro' AND status IN ('attendu','partiel','retard') THEN expected_amount-paid_amount ELSE 0 END) ro_remaining,
      SUM(CASE WHEN payer='rc' AND status IN ('attendu','partiel','retard') THEN expected_amount-paid_amount ELSE 0 END) rc_remaining
     FROM payments"
)->fetch();

$queryParams = array_filter([
    'q' => $q,
    'mutual' => $mutual,
    'payer' => $payer,
    'method' => $method,
    'status' => $status,
    'sort' => $sort,
    'per_page' => $perPage,
    'page' => $page,
], static fn ($value): bool => $value !== '');
$returnQuery = http_build_query($queryParams);
$hasActiveFilters = $q !== '' || $mutual !== '' || $payer !== '' || $method !== '' || $status !== '' || $sort !== 'priority' || $perPage !== 100;

$pageTitle = 'Paiements';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Suivi des encaissements</h2><p>Contrôlez les paiements RO, RC et clients. Les indicateurs restent globaux ; les filtres s’appliquent au tableau.</p></div></section>
<div class="stats-grid" style="margin-bottom:18px">
    <article class="stat-card accent-orange"><div class="stat-top"><span class="stat-icon">€</span>Reste à encaisser</div><div class="stat-value" style="font-size:1.35rem"><?= e(format_euros($totals['remaining'] ?? 0)) ?></div><p class="stat-note">Tous payeurs</p></article>
    <article class="stat-card"><div class="stat-top"><span class="stat-icon">RO</span>RO à vérifier</div><div class="stat-value" style="font-size:1.35rem"><?= e(format_euros($totals['ro_remaining'] ?? 0)) ?></div><p class="stat-note">Régime obligatoire</p></article>
    <article class="stat-card"><div class="stat-top"><span class="stat-icon">RC</span>RC à vérifier</div><div class="stat-value" style="font-size:1.35rem"><?= e(format_euros($totals['rc_remaining'] ?? 0)) ?></div><p class="stat-note">Mutuelles</p></article>
    <article class="stat-card accent-green"><div class="stat-top"><span class="stat-icon">✓</span>Déjà encaissé</div><div class="stat-value" style="font-size:1.35rem"><?= e(format_euros($totals['collected'] ?? 0)) ?></div><p class="stat-note">Historique complet</p></article>
</div>

<form class="filters payment-filters" method="get">
    <div class="search-input"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Client ou n° dossier…"></div>
    <select name="mutual" aria-label="Filtrer par mutuelle">
        <option value="">Toutes les mutuelles</option>
        <?php foreach ($mutuals as $mutualName): ?><option value="<?= e((string) $mutualName) ?>" <?= $mutual === $mutualName ? 'selected' : '' ?>><?= e((string) $mutualName) ?></option><?php endforeach; ?>
    </select>
    <select name="payer" aria-label="Filtrer par payeur"><option value="">Tous les payeurs</option><?php foreach ($allowedPayers as $item): ?><option value="<?= e($item) ?>" <?= $payer === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select>
    <select name="method" aria-label="Filtrer par mode de paiement">
        <option value="">Tous les modes</option>
        <?php foreach (['virement','cheque','cb','especes','autre'] as $item): ?><option value="<?= e($item) ?>" <?= $method === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?>
        <option value="non_renseigne" <?= $method === 'non_renseigne' ? 'selected' : '' ?>>Mode non renseigné</option>
    </select>
    <select name="status" aria-label="Filtrer par statut"><option value="">Tous les statuts</option><?php foreach ($allowedStatuses as $item): ?><option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select>
    <select name="sort" aria-label="Trier les paiements">
        <option value="priority" <?= $sort === 'priority' ? 'selected' : '' ?>>Priorité puis mise à jour</option>
        <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Date : plus récente</option>
        <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Date : plus ancienne</option>
        <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>Dernière modification</option>
        <option value="client_asc" <?= $sort === 'client_asc' ? 'selected' : '' ?>>Client : A à Z</option>
    </select>
    <select name="per_page" aria-label="Paiements par page"><?php foreach ($allowedPerPage as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> par page</option><?php endforeach; ?></select>
    <button class="btn btn-primary" type="submit">Filtrer</button>
    <?php if ($hasActiveFilters): ?><a class="btn btn-outline" href="<?= e(app_url('pages/paiements.php')) ?>">Réinitialiser</a><?php endif; ?>
</form>

<section class="card table-card">
    <div class="card-header"><div><h2><?= $totalPayments ?> paiement(s)</h2><p>Affichage <?= $firstResult ?>–<?= $lastResult ?> · page <?= $page ?> sur <?= $totalPages ?>. Modifiez une ligne puis enregistrez-la.</p></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Dossier / Client</th><th>Payeur</th><th>Mutuelle</th><th>Attendu</th><th>Encaissé</th><th>Date</th><th>Mode</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($payments as $payment): ?>
                <?php $paymentFormId = 'payment-form-' . (int) $payment['id']; ?>
                <tr class="<?= $payment['status'] === 'retard' ? 'row-overdue' : '' ?>" data-payment-row>
                    <td>
                        <form id="<?= e($paymentFormId) ?>" method="post" action="<?= e(app_url('actions/payment_save.php')) ?>" autocomplete="off" data-unsaved-warning>
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $payment['id'] ?>"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                        </form>
                        <a class="cell-title" href="<?= e(app_url('pages/dossier_view.php?id=' . $payment['dossier_id'])) ?>"><?= e(dossier_number((int) $payment['dossier_id'])) ?></a><span class="cell-subtitle"><?= e($payment['first_name'] . ' ' . mb_strtoupper($payment['last_name'])) ?></span>
                    </td>
                    <td><?= status_badge($payment['payer']) ?></td>
                    <td><?= e($payment['mutual_name'] ?: '—') ?></td>
                    <td><input form="<?= e($paymentFormId) ?>" type="number" step="0.01" min="0" name="expected_amount" value="<?= e((string) $payment['expected_amount']) ?>" style="min-width:100px"></td>
                    <td><input form="<?= e($paymentFormId) ?>" type="number" step="0.01" min="0" name="paid_amount" value="<?= e((string) $payment['paid_amount']) ?>" style="min-width:100px"></td>
                    <td><input form="<?= e($paymentFormId) ?>" type="date" name="payment_date" value="<?= e($payment['payment_date'] ?? '') ?>" style="min-width:140px"></td>
                    <td><select form="<?= e($paymentFormId) ?>" name="settlement_method" data-payment-method autocomplete="off" style="min-width:130px"><option value="">—</option><?php foreach (['cb','cheque','especes','virement','autre'] as $paymentMethod): ?><option value="<?= e($paymentMethod) ?>" <?= $payment['payment_method'] === $paymentMethod ? 'selected' : '' ?>><?= e(status_label($paymentMethod)) ?></option><?php endforeach; ?></select></td>
                    <td><select form="<?= e($paymentFormId) ?>" name="status" data-payment-status style="min-width:155px"><?php foreach ($allowedStatuses as $item): ?><option value="<?= e($item) ?>" data-cheque-only="<?= $item === 'cheque_caution' ? '1' : '0' ?>" <?= $payment['status'] === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select></td>
                    <td><button form="<?= e($paymentFormId) ?>" class="btn btn-small btn-primary" type="submit">Enregistrer</button></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?><tr><td colspan="9" class="empty-state"><strong>Aucun paiement trouvé</strong>Modifiez les filtres.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="Pages des paiements">
    <?php $paginationQuery = $queryParams; ?>
    <?php if ($page > 1): $paginationQuery['page'] = $page - 1; ?><a class="pagination-arrow" href="?<?= e(http_build_query($paginationQuery)) ?>">← Précédent</a><?php endif; ?>
    <div class="pagination-pages">
        <?php foreach (pagination_items($page, $totalPages) as $pageItem): ?>
            <?php if ($pageItem === null): ?>
                <span class="pagination-ellipsis">…</span>
            <?php elseif ($pageItem === $page): ?>
                <span class="pagination-link active" aria-current="page"><?= $pageItem ?></span>
            <?php else: $paginationQuery['page'] = $pageItem; ?>
                <a class="pagination-link" href="?<?= e(http_build_query($paginationQuery)) ?>"><?= $pageItem ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php if ($page < $totalPages): $paginationQuery['page'] = $page + 1; ?><a class="pagination-arrow" href="?<?= e(http_build_query($paginationQuery)) ?>">Suivant →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
