<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();

$pdo = db();
$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT) ?: null;
$dossierId = filter_input(INPUT_GET, 'dossier_id', FILTER_VALIDATE_INT) ?: null;
$selectedDossier = null;

if ($dossierId) {
    $stmt = $pdo->prepare(
        'SELECT d.id, d.client_id, c.first_name, c.last_name, c.fiche_number
         FROM dossiers d
         JOIN clients c ON c.id = d.client_id
         WHERE d.id = ?'
    );
    $stmt->execute([$dossierId]);
    $selectedDossier = $stmt->fetch() ?: null;

    if ($selectedDossier) {
        // Le dossier est la source de vérité : son client ne peut pas être remplacé par l'URL.
        $clientId = (int) $selectedDossier['client_id'];
    } else {
        flash('danger', 'Le dossier demandé est introuvable.');
        $dossierId = null;
    }
}

$where = ['d.deleted_at IS NULL'];
$params = [];
if ($clientId) { $where[] = 'd.client_id = ?'; $params[] = $clientId; }
if ($dossierId) { $where[] = 'd.dossier_id = ?'; $params[] = $dossierId; }
$stmt = $pdo->prepare(
    'SELECT d.*, CONCAT(c.first_name, " ", c.last_name) AS client_name, u.name AS user_name
     FROM documents d
     LEFT JOIN clients c ON c.id = d.client_id
     LEFT JOIN users u ON u.id = d.uploaded_by
     WHERE ' . implode(' AND ', $where) . ' ORDER BY d.created_at DESC LIMIT 200'
);
$stmt->execute($params);
$documents = $stmt->fetchAll();
$clients = $pdo->query('SELECT id, first_name, last_name, fiche_number FROM clients ORDER BY last_name, first_name')->fetchAll();
$dossiers = $pdo->query(
    'SELECT d.id, d.client_id, c.first_name, c.last_name FROM dossiers d
     JOIN clients c ON c.id=d.client_id ORDER BY d.created_at DESC LIMIT 300'
)->fetchAll();
if ($selectedDossier && !array_filter(
    $dossiers,
    static fn(array $dossier): bool => (int) $dossier['id'] === (int) $selectedDossier['id']
)) {
    array_unshift($dossiers, $selectedDossier);
}
$categories = ['ordonnance'=>'Ordonnance','mutuelle'=>'Mutuelle','identite'=>'Identité','facture'=>'Facture','devis'=>'Devis','cosium'=>'Cosium','autre'=>'Autre'];
$pageTitle = 'Documents';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="copy"><h2>Coffre documentaire</h2><p>PDF, JPG et PNG stockés dans le dossier privé, jamais accessibles par URL directe.</p></div>
</section>
<section class="card">
    <div class="card-header"><div><h2>Ajouter un document</h2><p>10 Mo maximum</p></div></div>
    <?php if ($selectedDossier): ?>
        <div class="alert alert-info">
            Le document sera associé à
            <strong><?= e($selectedDossier['first_name'].' '.$selectedDossier['last_name']) ?></strong>
            et au dossier <strong><?= e(dossier_number((int) $selectedDossier['id'])) ?></strong>.
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(app_url('actions/document_upload.php')) ?>" data-unsaved-warning>
        <?= csrf_field() ?>
        <div class="form-grid form-grid-3">
            <label class="field"><span>Fichier</span><input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></label>
            <label class="field"><span>Catégorie</span><select name="category"><?php foreach ($categories as $key=>$label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
            <?php if ($selectedDossier): ?>
                <input type="hidden" name="client_id" value="<?= (int) $selectedDossier['client_id'] ?>">
                <input type="hidden" name="dossier_id" value="<?= (int) $selectedDossier['id'] ?>">
                <label class="field">
                    <span>Client associé</span>
                    <input value="<?= e($selectedDossier['last_name'].' '.$selectedDossier['first_name'].' · '.($selectedDossier['fiche_number'] ?: 'sans n°')) ?>" readonly>
                </label>
                <label class="field field-full">
                    <span>Dossier associé</span>
                    <input value="<?= e(dossier_number((int) $selectedDossier['id'])) ?>" readonly>
                </label>
            <?php else: ?>
                <label class="field"><span>Client</span><select name="client_id" data-document-client><option value="">—</option><?php foreach ($clients as $client): ?><option value="<?= (int)$client['id'] ?>" <?= $clientId === (int) $client['id'] ? 'selected' : '' ?>><?= e($client['last_name'].' '.$client['first_name'].' · '.($client['fiche_number'] ?: 'sans n°')) ?></option><?php endforeach; ?></select></label>
                <label class="field field-full"><span>Dossier (facultatif)</span><select name="dossier_id" data-document-dossier><option value="">—</option><?php foreach ($dossiers as $dossier): ?><option value="<?= (int)$dossier['id'] ?>" data-client="<?= (int)$dossier['client_id'] ?>" <?= $dossierId === (int) $dossier['id'] ? 'selected' : '' ?>><?= e(dossier_number((int)$dossier['id']).' · '.$dossier['last_name'].' '.$dossier['first_name']) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <label class="field field-full"><span>Description (facultative)</span><input name="description" maxlength="1000" placeholder="Ex. Réponse PEC reçue le…"></label>
        </div>
        <div class="form-footer"><button class="btn btn-primary" type="submit">Déposer le document</button></div>
    </form>
</section>
<section class="card table-card">
    <div class="card-header"><div><h2>Documents actifs</h2><p><?= count($documents) ?> document(s)</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Document</th><th>Catégorie</th><th>Client / dossier</th><th>Taille</th><th></th></tr></thead><tbody>
    <?php foreach ($documents as $document): ?><tr>
        <td><?= e(format_date($document['created_at'], true)) ?></td>
        <td><span class="cell-title"><?= e($document['original_file_name'] ?: $document['original_name']) ?></span><span class="cell-subtitle">Par <?= e($document['user_name'] ?: 'Système') ?></span></td>
        <td><?= e($categories[$document['category']] ?? status_label($document['category'])) ?></td>
        <td><?= e($document['client_name'] ?: '—') ?><?= $document['dossier_id'] ? '<span class="cell-subtitle">'.e(dossier_number((int)$document['dossier_id'])).'</span>' : '' ?></td>
        <td><?= e(number_format((int)($document['file_size'] ?: $document['size_bytes'])/1024, 1, ',', ' ')) ?> Ko</td>
        <td><div class="inline-actions"><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/document_view.php?id='.$document['id'])) ?>">Ouvrir</a><form method="post" action="<?= e(app_url('actions/document_delete.php')) ?>" data-confirm="Retirer ce document ? Le fichier sera conservé pour audit."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$document['id'] ?>"><button class="btn btn-small btn-danger" type="submit">Retirer</button></form></div></td>
    </tr><?php endforeach; ?>
    <?php if (!$documents): ?><tr><td colspan="6" class="empty-state">Aucun document pour ce filtre.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
