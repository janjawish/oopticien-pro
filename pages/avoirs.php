<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
$clientFilter = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT) ?: 0;
$editId = filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT) ?: 0;
$editCredit = null;
$selectedBeneficiaryIds = [];
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM credits WHERE id=?');
    $stmt->execute([$editId]);
    $editCredit = $stmt->fetch() ?: null;
    if (!$editCredit) {
        flash('danger', 'Avoir introuvable.');
        $editId = 0;
    } else {
        $stmt = db()->prepare('SELECT client_id FROM credit_beneficiaries WHERE credit_id=?');
        $stmt->execute([$editId]);
        $selectedBeneficiaryIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
$clients = db()->query('SELECT id, first_name, last_name FROM clients ORDER BY last_name, first_name')->fetchAll();
$sql = 'SELECT cr.*, c.first_name, c.last_name FROM credits cr JOIN clients c ON c.id=cr.client_id';
$params = [];
if ($clientFilter) {
    $sql .= ' WHERE cr.client_id=? OR EXISTS (
        SELECT 1 FROM credit_beneficiaries cb
        WHERE cb.credit_id=cr.id AND cb.client_id=?
    )';
    $params=[$clientFilter,$clientFilter];
}
$sql .= ' ORDER BY cr.updated_at DESC';
$stmt=db()->prepare($sql); $stmt->execute($params); $credits=$stmt->fetchAll();
$creditBeneficiaries = [];
if ($credits) {
    $creditIds = array_map(static fn(array $credit): int => (int) $credit['id'], $credits);
    $placeholders = implode(',', array_fill(0, count($creditIds), '?'));
    $stmt = db()->prepare(
        'SELECT cb.credit_id,c.id,c.first_name,c.last_name
         FROM credit_beneficiaries cb
         JOIN clients c ON c.id=cb.client_id
         WHERE cb.credit_id IN ('.$placeholders.')
         ORDER BY c.last_name,c.first_name'
    );
    $stmt->execute($creditIds);
    foreach ($stmt->fetchAll() as $beneficiary) {
        $creditBeneficiaries[(int)$beneficiary['credit_id']][] = $beneficiary;
    }
}
$sum = array_sum(array_map(fn($credit)=>(float)$credit['remaining_amount'],$credits));

$pageTitle='Avoirs et droits';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Droits et avoirs disponibles</h2><p>Suivez le montant initial, consommé et restant de chaque client.</p></div></section>
<div class="content-grid">
    <div>
        <section class="card table-card">
            <div class="card-header"><div><h2><?= count($credits) ?> avoir(s)</h2><p>Total restant : <strong class="text-success"><?= e(format_euros($sum)) ?></strong></p></div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Client</th><th>Libellé</th><th>Source</th><th>Initial</th><th>Utilisé</th><th>Restant</th><th>Mise à jour</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach($credits as $credit):
                        $sharedWith = array_values(array_filter(
                            $creditBeneficiaries[(int)$credit['id']] ?? [],
                            static fn(array $beneficiary): bool => (int)$beneficiary['id'] !== (int)$credit['client_id']
                        ));
                    ?>
                        <tr>
                            <td>
                                <a class="cell-title" href="<?= e(app_url('pages/client_view.php?id='.$credit['client_id'])) ?>"><?= e($credit['first_name'].' '.mb_strtoupper($credit['last_name'])) ?></a>
                                <span class="cell-subtitle">Titulaire<?= $sharedWith ? ' · Partagé avec '.e(implode(', ', array_map(static fn(array $beneficiary): string => $beneficiary['first_name'].' '.mb_strtoupper($beneficiary['last_name']), $sharedWith))) : '' ?></span>
                            </td>
                            <td><?= e($credit['label']) ?><span class="cell-subtitle"><?= e($credit['comment'] ?: '') ?></span></td>
                            <td><?= status_badge($credit['source']) ?></td>
                            <td class="amount"><?= e(format_euros($credit['initial_amount'])) ?></td>
                            <td class="amount"><?= e(format_euros($credit['used_amount'])) ?></td>
                            <td class="amount text-success"><?= e(format_euros($credit['remaining_amount'])) ?></td>
                            <td><?= e(format_date($credit['updated_at'])) ?></td>
                            <td>
                                <div class="inline-actions">
                                    <a class="btn btn-small btn-outline" href="?<?= e(http_build_query(['client_id'=>(int)$credit['client_id'],'edit_id'=>(int)$credit['id']])) ?>">Modifier</a>
                                    <form method="post" action="<?= e(app_url('actions/credit_delete.php')) ?>" data-confirm="Supprimer cet avoir ? Cette action est définitive.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$credit['id'] ?>">
                                        <input type="hidden" name="client_id" value="<?= (int)$credit['client_id'] ?>">
                                        <button class="btn btn-small btn-danger" type="submit">Supprimer</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if(!$credits): ?><tr><td colspan="8" class="empty-state"><strong>Aucun avoir enregistré</strong>Utilisez le formulaire pour ajouter le premier.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <aside class="card" style="margin-top:0">
        <div class="card-header"><div><h2><?= $editCredit ? 'Modifier l’avoir' : 'Ajouter un avoir' ?></h2><p>Le restant est recalculé automatiquement : montant initial − montant utilisé.</p></div></div>
        <form method="post" action="<?= e(app_url('actions/credit_save.php')) ?>" data-unsaved-warning>
            <?= csrf_field() ?>
            <?php if ($editCredit): ?><input type="hidden" name="id" value="<?= (int)$editCredit['id'] ?>"><?php endif; ?>
            <?php $selectedClientId = (int)($editCredit['client_id'] ?? $clientFilter); ?>
            <div class="field" style="margin-bottom:12px"><span class="required">Titulaire de l’avoir</span><select name="client_id" required data-credit-owner><option value="">Sélectionner</option><?php foreach($clients as $client): ?><option value="<?= (int)$client['id'] ?>" <?= $selectedClientId===(int)$client['id']?'selected':'' ?>><?= e(mb_strtoupper($client['last_name']).' '.$client['first_name']) ?></option><?php endforeach; ?></select></div>
            <div class="field" style="margin-bottom:12px">
                <span>Membres de la famille autorisés</span>
                <div class="beneficiary-picker" data-beneficiary-picker>
                    <input type="search" placeholder="Rechercher un autre client…" aria-label="Rechercher un bénéficiaire" data-beneficiary-search>
                    <small data-beneficiary-count>0 bénéficiaire supplémentaire</small>
                    <div class="beneficiary-options">
                        <?php foreach($clients as $client): ?>
                            <label data-beneficiary-option data-client-id="<?= (int)$client['id'] ?>">
                                <input type="checkbox" name="beneficiary_ids[]" value="<?= (int)$client['id'] ?>" <?= in_array((int)$client['id'], $selectedBeneficiaryIds, true) && (int)$client['id'] !== $selectedClientId ? 'checked' : '' ?>>
                                <span><?= e(mb_strtoupper($client['last_name']).' '.$client['first_name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <small>Le solde est unique : une utilisation par un membre diminue le même avoir pour toute la famille.</small>
            </div>
            <div class="field" style="margin-bottom:12px"><span class="required">Libellé</span><input name="label" maxlength="180" required placeholder="Ex. Droit mutuelle restant" value="<?= e($editCredit['label'] ?? '') ?>"></div>
            <div class="field" style="margin-bottom:12px"><span>Source</span><select name="source"><?php foreach(['mutuelle','cmu','commercial','autre'] as $item): ?><option value="<?= e($item) ?>" <?= ($editCredit['source'] ?? 'mutuelle') === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select></div>
            <div class="form-grid" style="margin-bottom:12px">
                <label class="field"><span>Montant auquel le client a droit</span><input type="number" name="initial_amount" min="0" step="0.01" required value="<?= e((string)($editCredit['initial_amount'] ?? '')) ?>"></label>
                <label class="field"><span>Déjà utilisé</span><input type="number" name="used_amount" min="0" step="0.01" value="<?= e((string)($editCredit['used_amount'] ?? '0')) ?>"></label>
            </div>
            <label class="field" style="margin-bottom:16px"><span>Commentaire</span><textarea name="comment"><?= e($editCredit['comment'] ?? '') ?></textarea></label>
            <div class="inline-actions">
                <?php if ($editCredit): ?><a class="btn btn-outline" href="<?= e(app_url('pages/avoirs.php?client_id='.(int)$editCredit['client_id'])) ?>">Annuler</a><?php endif; ?>
                <button class="btn btn-primary <?= $editCredit ? '' : 'btn-block' ?>" type="submit"><?= $editCredit ? 'Enregistrer les modifications' : 'Ajouter l’avoir' ?></button>
            </div>
        </form>
    </aside>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
