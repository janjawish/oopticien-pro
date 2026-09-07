<?php
$folder = $folder ?? [];
$order = $order ?? [];
$isEdit = !empty($folder['id']);
$selectedClient = (int) ($folder['client_id'] ?? ($_GET['client_id'] ?? 0));
$folderStatuses = ['brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer','facture','teletransmis','commande_a_faire','commande_envoyee','verres_recus','montage','pret','client_prevenu','remis','cloture','bloque','sav','derogation','annule'];
$mutualStatuses = ['non_envoyee','envoyee','en_attente','pec_acceptee','pec_refusee','incomplete'];
$orderStatuses = ['a_preparer','envoyee','confirmee','en_fabrication','expediee','recue','montage','prete','incident','annulee'];
?>
<form class="card" method="post" action="<?= e(app_url('actions/dossier_save.php')) ?>" data-amount-form data-unsaved-warning>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $folder['id'] ?>"><?php endif; ?>
    <?php if (!empty($assistantDraftToken)): ?><input type="hidden" name="assistant_draft_token" value="<?= e($assistantDraftToken) ?>"><?php endif; ?>
    <section class="form-section">
        <h3>Client et pilotage</h3>
        <div class="form-grid form-grid-3">
            <label class="field">
                <span class="required">Client</span>
                <select name="client_id" required>
                    <option value="">Sélectionner un client</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= (int) $client['id'] ?>" <?= $selectedClient === (int) $client['id'] ? 'selected' : '' ?>><?= e(mb_strtoupper($client['last_name']) . ' ' . $client['first_name'] . ($client['phone'] ? ' · ' . $client['phone'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span>Statut dossier</span><select name="folder_status"><?php foreach ($folderStatuses as $item): ?><option value="<?= e($item) ?>" <?= ($folder['folder_status'] ?? 'brouillon') === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Priorité</span><select name="priority"><?php foreach (['basse','normale','haute','urgente'] as $item): ?><option value="<?= e($item) ?>" <?= ($folder['priority'] ?? 'normale') === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Type de dossier</span><select name="dossier_type"><?php foreach (['lunettes','lentilles'] as $item): ?><option value="<?= e($item) ?>" <?= ($folder['dossier_type'] ?? 'lunettes') === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Ordonnance</span><select name="prescription_status"><?php foreach (['oui','non','attente'] as $item): ?><option value="<?= e($item) ?>" <?= ($folder['prescription_status'] ?? 'attente') === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select></label>
            <label class="field field-full"><span>Prochaine action</span><input name="next_action" maxlength="255" value="<?= e($folder['next_action'] ?? '') ?>" placeholder="Ex. Relancer la mutuelle mardi"></label>
        </div>
    </section>

    <section class="form-section">
        <h3>Devis et mutuelle</h3>
        <div class="form-grid form-grid-3">
            <label class="field"><span>Date du devis</span><input type="date" name="quote_date" value="<?= e($folder['quote_date'] ?? '') ?>"></label>
            <label class="field"><span>Statut mutuelle</span><select name="mutual_status"><?php foreach ($mutualStatuses as $item): ?><option value="<?= e($item) ?>" <?= ($folder['mutual_status'] ?? 'non_envoyee') === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Référence PEC</span><input name="pec_reference" maxlength="120" value="<?= e($folder['pec_reference'] ?? '') ?>"></label>
            <label class="field"><span>PEC envoyée le</span><input type="datetime-local" name="pec_sent_at" value="<?= e(!empty($folder['pec_sent_at']) ? date('Y-m-d\TH:i', strtotime($folder['pec_sent_at'])) : '') ?>"></label>
            <label class="field"><span>Réponse PEC le</span><input type="datetime-local" name="pec_response_at" value="<?= e(!empty($folder['pec_response_at']) ? date('Y-m-d\TH:i', strtotime($folder['pec_response_at'])) : '') ?>"></label>
        </div>
    </section>

    <section class="form-section">
        <h3>Facturation et télétransmission</h3>
        <div class="form-grid form-grid-3">
            <label class="field"><span>Date de facture</span><input type="date" name="invoice_date" value="<?= e($folder['invoice_date'] ?? '') ?>"></label>
            <label class="field"><span>Télétransmis</span><select name="teletrans_status"><option value="non" <?= ($folder['teletrans_status'] ?? 'non') === 'non' ? 'selected' : '' ?>>Non</option><option value="oui" <?= ($folder['teletrans_status'] ?? '') === 'oui' ? 'selected' : '' ?>>Oui</option></select></label>
            <label class="field"><span>Date de télétransmission</span><input type="date" name="teletrans_date" value="<?= e($folder['teletrans_date'] ?? '') ?>"></label>
        </div>
    </section>

    <section class="form-section">
        <h3>Montants</h3>
        <div class="form-grid form-grid-3">
            <label class="field"><span>Part RO</span><input type="number" name="ro_amount" min="0" step="0.01" value="<?= e((string) ($folder['ro_amount'] ?? '0.00')) ?>"></label>
            <label class="field"><span>Part RC</span><input type="number" name="rc_amount" min="0" step="0.01" value="<?= e((string) ($folder['rc_amount'] ?? '0.00')) ?>"></label>
            <label class="field"><span>RAC client</span><input type="number" name="rac_amount" min="0" step="0.01" value="<?= e((string) ($folder['rac_amount'] ?? '0.00')) ?>"></label>
            <label class="field"><span>Total déclaré</span><input type="number" name="total_amount" min="0" step="0.01" value="<?= e((string) ($folder['total_amount'] ?? '0.00')) ?>"><small data-total-hint></small></label>
        </div>
    </section>

    <section class="form-section">
        <h3>Commande verrier Ophtalmic</h3>
        <div class="info-banner" style="margin-bottom:14px"><span>i</span><div><strong>Suivi uniquement</strong><p>La commande réelle doit être validée dans Cosium ou dans l’espace sécurisé Ophtalmic. Saisissez ici la référence reçue pour piloter la suite.</p></div></div>
        <label class="field" style="display:flex;align-items:center;margin-bottom:14px"><input type="checkbox" name="with_order" value="1" style="width:auto;min-height:auto" <?= $order ? 'checked' : '' ?>> Suivre une commande verrier pour ce dossier</label>
        <div class="form-grid form-grid-3">
            <label class="field"><span>Fournisseur</span><input name="supplier_name" maxlength="120" value="<?= e($order['supplier_name'] ?? 'Ophtalmic') ?>"></label>
            <label class="field"><span>Référence commande</span><input name="order_reference" maxlength="120" value="<?= e($order['order_reference'] ?? '') ?>"></label>
            <label class="field"><span>Statut commande</span><select name="order_status"><?php foreach ($orderStatuses as $item): ?><option value="<?= e($item) ?>" <?= ($order['status'] ?? 'a_preparer') === $item ? 'selected' : '' ?>><?= e(status_label($item)) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Envoyée le</span><input type="datetime-local" name="order_sent_at" value="<?= e(!empty($order['sent_at']) ? date('Y-m-d\TH:i', strtotime($order['sent_at'])) : '') ?>"></label>
            <label class="field"><span>Réception prévue</span><input type="datetime-local" name="order_expected_at" value="<?= e(!empty($order['expected_at']) ? date('Y-m-d\TH:i', strtotime($order['expected_at'])) : '') ?>"></label>
            <label class="field"><span>Reçue le</span><input type="datetime-local" name="order_received_at" value="<?= e(!empty($order['received_at']) ? date('Y-m-d\TH:i', strtotime($order['received_at'])) : '') ?>"></label>
            <label class="field"><span>Prête le</span><input type="datetime-local" name="order_ready_at" value="<?= e(!empty($order['ready_at']) ? date('Y-m-d\TH:i', strtotime($order['ready_at'])) : '') ?>"></label>
            <label class="field"><span>Client prévenu le</span><input type="datetime-local" name="client_notified_at" value="<?= e(!empty($order['client_notified_at']) ? date('Y-m-d\TH:i', strtotime($order['client_notified_at'])) : '') ?>"></label>
            <label class="field field-full"><span>Commentaire commande</span><textarea name="order_comment"><?= e($order['comment'] ?? '') ?></textarea></label>
        </div>
        <h4 style="margin-top:20px">Prescription et produit à reporter chez le verrier</h4>
        <div class="form-grid form-grid-3">
            <label class="field"><span>OD sphère</span><input type="number" step="0.25" name="lens_right_sphere" value="<?= e((string)($order['lens_right_sphere'] ?? '')) ?>"></label>
            <label class="field"><span>OD cylindre</span><input type="number" step="0.25" name="lens_right_cylinder" value="<?= e((string)($order['lens_right_cylinder'] ?? '')) ?>"></label>
            <label class="field"><span>OD axe (0–180°)</span><input type="number" min="0" max="180" name="lens_right_axis" value="<?= e((string)($order['lens_right_axis'] ?? '')) ?>"></label>
            <label class="field"><span>OD addition</span><input type="number" step="0.25" name="lens_right_addition" value="<?= e((string)($order['lens_right_addition'] ?? '')) ?>"></label>
            <label class="field"><span>OG sphère</span><input type="number" step="0.25" name="lens_left_sphere" value="<?= e((string)($order['lens_left_sphere'] ?? '')) ?>"></label>
            <label class="field"><span>OG cylindre</span><input type="number" step="0.25" name="lens_left_cylinder" value="<?= e((string)($order['lens_left_cylinder'] ?? '')) ?>"></label>
            <label class="field"><span>OG axe (0–180°)</span><input type="number" min="0" max="180" name="lens_left_axis" value="<?= e((string)($order['lens_left_axis'] ?? '')) ?>"></label>
            <label class="field"><span>OG addition</span><input type="number" step="0.25" name="lens_left_addition" value="<?= e((string)($order['lens_left_addition'] ?? '')) ?>"></label>
            <label class="field"><span>Écart pupillaire</span><input name="pupillary_distance" maxlength="40" value="<?= e($order['pupillary_distance'] ?? '') ?>" placeholder="Ex. 31 / 32"></label>
            <label class="field"><span>Produit / gamme</span><input name="lens_product" maxlength="190" value="<?= e($order['lens_product'] ?? '') ?>"></label>
            <label class="field"><span>Indice</span><input name="lens_index" maxlength="40" value="<?= e($order['lens_index'] ?? '') ?>" placeholder="1.5, 1.6…"></label>
            <label class="field"><span>Traitement</span><input name="treatment" maxlength="190" value="<?= e($order['treatment'] ?? '') ?>"></label>
            <label class="field"><span>Teinte</span><input name="tint" maxlength="120" value="<?= e($order['tint'] ?? '') ?>"></label>
            <label class="field"><span>Référence monture</span><input name="frame_reference" maxlength="120" value="<?= e($order['frame_reference'] ?? '') ?>"></label>
            <label class="field field-full"><span>Consignes de montage</span><textarea name="mounting_comment"><?= e($order['mounting_comment'] ?? '') ?></textarea></label>
        </div>
        <div class="inline-actions" style="margin-top:14px"><a class="btn btn-outline" href="<?= e(app_config()['suppliers']['ophtalmic_portal_url']) ?>" target="_blank" rel="noopener noreferrer">Ouvrir Ophtalmic E-Space ↗</a><a class="btn btn-outline" href="<?= e(app_url('pages/guide.php#verrier')) ?>">Voir la procédure</a></div>
    </section>

    <section class="form-section">
        <h3>Commentaire opticien</h3>
        <textarea name="optician_comment" placeholder="Informations internes sur le dossier…"><?= e($folder['optician_comment'] ?? '') ?></textarea>
    </section>
    <div class="form-footer">
        <a class="btn btn-outline" href="<?= e($isEdit ? app_url('pages/dossier_view.php?id=' . $folder['id']) : app_url('pages/dossiers.php')) ?>">Annuler</a>
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Enregistrer les modifications' : 'Créer le dossier' ?></button>
    </div>
</form>
