<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) {
    http_response_code(404);
    exit('Client introuvable.');
}
$maskedNir = mask_nir(client_nir($client));

$stmt = db()->prepare('SELECT * FROM dossiers WHERE client_id = ? ORDER BY created_at DESC');
$stmt->execute([$id]);
$folders = $stmt->fetchAll();
$stmt = db()->prepare(
    'SELECT cr.*, CONCAT(owner.first_name, " ", owner.last_name) AS owner_name,
            (SELECT COUNT(*) FROM credit_beneficiaries cb WHERE cb.credit_id=cr.id) AS beneficiary_count
     FROM credits cr
     JOIN clients owner ON owner.id=cr.client_id
     WHERE cr.client_id=?
        OR EXISTS (
            SELECT 1 FROM credit_beneficiaries cb
            WHERE cb.credit_id=cr.id AND cb.client_id=?
        )
     ORDER BY cr.created_at DESC'
);
$stmt->execute([$id, $id]);
$credits = $stmt->fetchAll();
$stmt = db()->prepare(
    "SELECT h.*, u.name AS user_name FROM action_history h
     LEFT JOIN users u ON u.id = h.user_id
     WHERE (h.entity_type = 'client' AND h.entity_id = ?)
        OR (h.entity_type = 'dossier' AND h.entity_id IN (SELECT id FROM dossiers WHERE client_id = ?))
     ORDER BY h.created_at DESC LIMIT 12"
);
$stmt->execute([$id, $id]);
$history = $stmt->fetchAll();

$pageTitle = 'Fiche client';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="profile-header">
        <span class="profile-avatar"><?= e(mb_strtoupper(mb_substr($client['first_name'], 0, 1) . mb_substr($client['last_name'], 0, 1))) ?></span>
        <div><h2><?= e($client['first_name'] . ' ' . mb_strtoupper($client['last_name'])) ?></h2><p><?= e($client['fiche_number'] ?: 'Fiche sans numéro') ?> · client depuis <?= e(format_date($client['created_at'])) ?></p></div>
    </div>
    <div class="inline-actions">
        <a class="btn btn-outline" href="<?= e(app_url('pages/client_edit.php?id=' . $client['id'])) ?>">Modifier</a>
        <a class="btn btn-outline" href="<?= e(app_url('pages/documents.php?client_id=' . $client['id'])) ?>">Documents</a>
        <a class="btn btn-primary" href="<?= e(app_url('pages/dossier_add.php?client_id=' . $client['id'])) ?>">＋ Nouveau dossier</a>
    </div>
</section>

<div class="content-grid">
    <div>
        <section class="card">
            <div class="card-header"><h2>Informations client</h2></div>
            <dl class="meta-list">
                <div class="meta-row"><dt>Téléphone</dt><dd><?= e($client['phone'] ?: '—') ?></dd></div>
                <div class="meta-row"><dt>E-mail</dt><dd><?= e($client['email'] ?: '—') ?></dd></div>
                <div class="meta-row"><dt>Date de naissance</dt><dd><?= e(format_date($client['birth_date'])) ?></dd></div>
                <div class="meta-row"><dt>Adresse</dt><dd><?= nl2br(e($client['address'] ?: '—')) ?></dd></div>
                <div class="meta-row">
                    <dt>NIR</dt>
                    <dd>
                        <span id="client-nir"><?= e($maskedNir) ?></span>
                        <?php if (has_role(['admin', 'patron']) && $maskedNir !== 'Non renseigné'): ?>
                            <button class="btn btn-small btn-outline" type="button"
                                    data-nir-reveal
                                    data-client-id="<?= (int) $client['id'] ?>"
                                    data-endpoint="<?= e(app_url('actions/reveal_nir.php')) ?>"
                                    data-csrf="<?= e(csrf_token()) ?>">Afficher</button>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="meta-row"><dt>Régime</dt><dd><?= e($client['social_security_scheme'] ?: '—') ?></dd></div>
                <div class="meta-row"><dt>Assuré</dt><dd><?= e($client['insured_name'] ?: '—') ?></dd></div>
                <div class="meta-row"><dt>Taux RO</dt><dd><?= $client['reimbursement_rate'] !== null ? e(number_format((float)$client['reimbursement_rate'], 2, ',', ' ') . ' %') : '—' ?></dd></div>
                <div class="meta-row"><dt>Mutuelle</dt><dd><?= e($client['mutual_name'] ?: '—') ?><span class="cell-subtitle"><?= e($client['membership_number'] ?: '') ?></span></dd></div>
                <div class="meta-row"><dt>Droits mutuelle</dt><dd><?= e(format_date($client['mutual_valid_from'])) ?> au <?= e(format_date($client['mutual_valid_to'])) ?></dd></div>
                <div class="meta-row"><dt>Notes</dt><dd><?= nl2br(e($client['notes'] ?: '—')) ?></dd></div>
            </dl>
        </section>

        <section class="card table-card">
            <div class="card-header"><div><h2>Dossiers lunettes</h2><p><?= count($folders) ?> dossier(s)</p></div><a class="btn btn-small btn-primary" href="<?= e(app_url('pages/dossier_add.php?client_id=' . $client['id'])) ?>">＋ Ajouter</a></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Dossier</th><th>Statut</th><th>Mutuelle</th><th>Total</th><th>Prochaine action</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($folders as $folder): ?>
                        <tr class="<?= $folder['total_warning'] ? 'row-warning' : '' ?>">
                            <td><a class="cell-title" href="<?= e(app_url('pages/dossier_view.php?id=' . $folder['id'])) ?>"><?= e(dossier_number((int) $folder['id'])) ?></a><span class="cell-subtitle"><?= e(format_date($folder['created_at'])) ?></span></td>
                            <td><?= status_badge($folder['folder_status']) ?></td>
                            <td><?= status_badge($folder['mutual_status']) ?></td>
                            <td class="amount"><?= e(format_euros($folder['total_amount'])) ?></td>
                            <td><?= e($folder['next_action'] ?: '—') ?></td>
                            <td><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/dossier_view.php?id=' . $folder['id'])) ?>">Ouvrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$folders): ?><tr><td colspan="6" class="empty-state"><strong>Aucun dossier</strong>Créez le premier dossier lunettes de ce client.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card-header"><div><h2>Avoirs et droits</h2><p>Solde encore disponible</p></div><a class="btn btn-small btn-outline" href="<?= e(app_url('pages/avoirs.php?client_id=' . $client['id'])) ?>">＋ Ajouter</a></div>
            <?php foreach ($credits as $credit): ?>
                <?php $percent = $credit['initial_amount'] > 0 ? min(100, ((float) $credit['used_amount'] / (float) $credit['initial_amount']) * 100) : 0; ?>
                <div style="margin-bottom:16px">
                    <div class="card-header" style="margin-bottom:8px"><div><strong><?= e($credit['label']) ?></strong><span class="cell-subtitle"><?= e(status_label($credit['source'])) ?><?= (int)$credit['client_id'] !== (int)$client['id'] ? ' · Titulaire : '.e($credit['owner_name']) : '' ?><?= (int)$credit['beneficiary_count'] > 1 ? ' · Avoir familial' : '' ?></span></div><span class="amount text-success"><?= e(format_euros($credit['remaining_amount'])) ?></span></div>
                    <div class="progress-bar"><span style="width:<?= e((string) $percent) ?>%"></span></div>
                    <span class="cell-subtitle"><?= e(format_euros($credit['used_amount'])) ?> utilisé sur <?= e(format_euros($credit['initial_amount'])) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$credits): ?><div class="empty-state"><strong>Aucun avoir</strong>Aucun droit restant enregistré.</div><?php endif; ?>
        </section>

        <section class="card">
            <div class="card-header"><h2>Historique récent</h2><a href="<?= e(app_url('pages/history.php?client_id=' . $client['id'])) ?>">Tout voir</a></div>
            <div class="timeline">
                <?php foreach ($history as $event): ?>
                    <div class="timeline-item"><span class="timeline-dot"></span><div><p><?= e(status_label($event['action'])) ?></p><small><?= e($event['user_name'] ?: 'Système') ?> · <?= e(format_date($event['created_at'], true)) ?></small></div></div>
                <?php endforeach; ?>
                <?php if (!$history): ?><div class="empty-state"><strong>Pas encore d’historique</strong></div><?php endif; ?>
            </div>
        </section>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
