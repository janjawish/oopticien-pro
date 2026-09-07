<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin', 'patron']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM pdf_imports WHERE id=?');
$stmt->execute([$id]);
$import = $stmt->fetch();
if (!$import) {
    http_response_code(404);
    exit('Import introuvable.');
}
$data = json_decode((string) ($import['extracted_json'] ?: $import['parsed_data']), true) ?: [];
$nir = decrypt_sensitive_value($data['social_security_number_encrypted'] ?? null);
$clients = db()->query('SELECT id,first_name,last_name,fiche_number FROM clients ORDER BY last_name,first_name')->fetchAll();

$duplicates = [];
$duplicateConditions = [];
$duplicateParams = [];
foreach (['fiche_number', 'email', 'phone'] as $field) {
    if (!empty($data[$field])) {
        $duplicateConditions[] = $field . '=?';
        $duplicateParams[] = $data[$field];
    }
}
if ($duplicateConditions) {
    $stmt = db()->prepare(
        'SELECT id,first_name,last_name,fiche_number,email,phone FROM clients
         WHERE ' . implode(' OR ', $duplicateConditions) . ' LIMIT 10'
    );
    $stmt->execute($duplicateParams);
    $duplicates = $stmt->fetchAll();
}
$suggestedClientId = $duplicates ? (int) $duplicates[0]['id'] : 0;
$corrections = is_array($data['corrections'] ?? null) ? implode("\n", $data['corrections']) : '';
$pageTitle = 'Vérifier l’import PDF';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="copy"><h2>Contrôle des informations lues</h2><p><?= e($import['original_file_name'] ?: $import['original_name']) ?> · aucune fiche n’est créée avant votre validation</p></div>
    <a class="btn btn-outline" href="<?= e(app_url('actions/import_pdf_download.php?id=' . $import['id'])) ?>">Télécharger l’original</a>
</section>

<?php if ($import['status'] === 'validated'): ?>
    <div class="alert alert-success">Import déjà validé. Client #<?= (int) $import['client_id'] ?><?= $import['dossier_id'] ? ' · ' . e(dossier_number((int) $import['dossier_id'])) : '' ?></div>
<?php endif; ?>
<?php if ($duplicates): ?>
    <div class="alert alert-warning"><strong>Client possible déjà présent :</strong> <?= e(implode(', ', array_map(static fn(array $client): string => $client['first_name'] . ' ' . $client['last_name'] . ' (' . ($client['fiche_number'] ?: 'sans n°') . ')', $duplicates))) ?>. Vérifiez le choix avant de valider.</div>
<?php endif; ?>

<form class="card" method="post" action="<?= e(app_url('actions/import_pdf_validate.php')) ?>" data-unsaved-warning>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $import['id'] ?>">

    <section class="form-section">
        <h3>Destination</h3>
        <label class="field"><span>Rattacher à un client existant</span><select name="client_id"><option value="">Créer un nouveau client</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>" <?= $suggestedClientId === (int) $client['id'] ? 'selected' : '' ?>><?= e($client['last_name'] . ' ' . $client['first_name'] . ' · ' . ($client['fiche_number'] ?: 'sans n°')) ?></option><?php endforeach; ?></select></label>
    </section>

    <section class="form-section">
        <h3>Identité et coordonnées</h3>
        <div class="form-grid form-grid-3">
            <label class="field"><span>N° fiche Cosium</span><input name="fiche_number" value="<?= e($data['fiche_number'] ?? '') ?>"></label>
            <label class="field"><span>Prénom</span><input name="first_name" required value="<?= e($data['first_name'] ?? '') ?>"></label>
            <label class="field"><span>Nom</span><input name="last_name" required value="<?= e($data['last_name'] ?? '') ?>"></label>
            <label class="field"><span>Date de naissance</span><input type="date" name="birth_date" value="<?= e($data['birth_date'] ?? '') ?>"></label>
            <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= e($data['email'] ?? '') ?>"></label>
            <label class="field"><span>Téléphone</span><input name="phone" value="<?= e($data['phone'] ?? '') ?>"></label>
            <label class="field field-full"><span>Adresse</span><textarea name="address"><?= e($data['address'] ?? '') ?></textarea></label>
        </div>
    </section>

    <section class="form-section">
        <h3>Assurance et mutuelle</h3>
        <div class="form-grid form-grid-3">
            <label class="field"><span>NIR</span><input name="social_security_number" autocomplete="off" placeholder="<?= e(mask_nir($nir)) ?>"><small>Valeur détectée : <?= e(mask_nir($nir)) ?>. Laissez vide pour la conserver chiffrée.</small></label>
            <label class="field"><span>Caisse / régime</span><input name="social_security_scheme" value="<?= e($data['social_security_scheme'] ?? '') ?>"></label>
            <label class="field"><span>Assuré</span><input name="insured_name" value="<?= e($data['insured_name'] ?? '') ?>"></label>
            <label class="field"><span>Taux de remboursement</span><input type="number" min="0" max="100" step="0.01" name="reimbursement_rate" value="<?= e($data['reimbursement_rate'] ?? '') ?>"></label>
            <label class="field"><span>Mutuelle</span><input name="mutual_name" value="<?= e($data['mutual_name'] ?? '') ?>"></label>
            <label class="field"><span>N° adhérent</span><input name="membership_number" value="<?= e($data['membership_number'] ?? '') ?>"></label>
            <label class="field"><span>Droits du</span><input type="date" name="mutual_valid_from" value="<?= e($data['mutual_valid_from'] ?? '') ?>"></label>
            <label class="field"><span>Droits au</span><input type="date" name="mutual_valid_to" value="<?= e($data['mutual_valid_to'] ?? '') ?>"></label>
            <label class="field field-full"><span>Notes client</span><textarea name="notes"><?= e($data['notes'] ?? '') ?></textarea></label>
        </div>
    </section>

    <section class="form-section">
        <h3>Dossier lunettes</h3>
        <label class="check-row"><input type="checkbox" name="create_dossier" value="1" checked><span>Créer aussi un dossier en attente de vérification</span></label>
        <div class="form-grid" style="margin-top:14px">
            <label class="field"><span>Date du dossier Cosium</span><input type="date" name="dossier_date" value="<?= e($data['dossier_date'] ?? '') ?>"></label>
            <label class="field field-full"><span>Corrections lues — à contrôler</span><textarea name="corrections"><?= e($corrections) ?></textarea><small>Le PDF ne distingue pas toujours clairement OD et OG : aucune commande verrier n’est créée automatiquement.</small></label>
        </div>
    </section>

    <div class="form-footer"><a class="btn btn-outline" href="<?= e(app_url('pages/import_pdf.php')) ?>">Annuler</a><button class="btn btn-primary" type="submit" <?= $import['status'] === 'validated' ? 'disabled' : '' ?>>Valider les informations</button></div>
</form>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
