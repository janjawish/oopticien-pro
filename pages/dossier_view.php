<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
redirect_if_not_logged_in();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare(
    'SELECT d.*, c.first_name, c.last_name, c.phone, c.email, c.mutual_name, c.fiche_number
     FROM dossiers d JOIN clients c ON c.id=d.client_id WHERE d.id=?'
);
$stmt->execute([$id]);
$folder = $stmt->fetch();
if (!$folder) {
    http_response_code(404);
    exit('Dossier introuvable.');
}
$stmt = db()->prepare('SELECT * FROM payments WHERE dossier_id=? ORDER BY FIELD(payer,"ro","rc","client"), id');
$stmt->execute([$id]);
$payments = $stmt->fetchAll();
$stmt = db()->prepare('SELECT * FROM glass_orders WHERE dossier_id=?');
$stmt->execute([$id]);
$order = $stmt->fetch();
$stmt = db()->prepare('SELECT t.*, u.name AS assigned_name FROM tasks t LEFT JOIN users u ON u.id=t.assigned_to WHERE t.dossier_id=? ORDER BY FIELD(t.status,"a_faire","en_cours","reportee","terminee","annulee"), t.due_at');
$stmt->execute([$id]);
$tasks = $stmt->fetchAll();
$stmt = db()->prepare('SELECT h.*, u.name AS user_name FROM action_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.entity_type="dossier" AND h.entity_id=? ORDER BY h.created_at DESC LIMIT 15');
$stmt->execute([$id]);
$history = $stmt->fetchAll();
$stmt = db()->prepare('SELECT * FROM messages WHERE dossier_id=? ORDER BY created_at DESC LIMIT 8');
$stmt->execute([$id]);
$messages = $stmt->fetchAll();
$isReady = in_array($folder['folder_status'], ['pret','client_prevenu','remis','cloture'], true)
    || !empty($order['ready_at']);
$defaultMessageChannel = $folder['email'] ? 'email' : ($folder['phone'] ? 'whatsapp' : 'email');
$defaultMessage = "Bonjour " . $folder['first_name'] . ",\n\n"
    . "Vos lunettes sont prêtes et disponibles à la boutique.\n"
    . "Vous pouvez passer les récupérer pendant nos horaires d’ouverture.\n\n"
    . "À bientôt,\n" . get_setting('boutique_name', 'Votre opticien');
$smtpStatus = smtp_configuration_status();
$dossierControl = dossier_control($folder);

$pageTitle = 'Dossier ' . dossier_number((int) $folder['id']);
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="copy">
        <div class="inline-actions"><?= status_badge($folder['folder_status']) ?> <?= status_badge($folder['priority']) ?></div>
        <h2 style="margin-top:8px"><?= e($folder['first_name'] . ' ' . mb_strtoupper($folder['last_name'])) ?></h2>
        <p><?= e($folder['fiche_number'] ?: 'Sans n° fiche') ?> · créé le <?= e(format_date($folder['created_at'])) ?></p>
    </div>
    <div class="inline-actions">
        <a class="btn btn-outline" href="<?= e(app_url('pages/client_view.php?id=' . $folder['client_id'])) ?>">Voir le client</a>
        <a class="btn btn-outline" href="<?= e(app_url('pages/documents.php?dossier_id=' . $folder['id'])) ?>">Documents</a>
        <a class="btn btn-primary" href="<?= e(app_url('pages/dossier_edit.php?id=' . $folder['id'])) ?>">Modifier le dossier</a>
        <?php if (has_role(['admin','patron'])): ?><form method="post" action="<?= e(app_url('actions/dossier_delete.php')) ?>" data-confirm="Supprimer définitivement ce dossier et son suivi ? La fiche client sera conservée."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$folder['id'] ?>"><button class="btn btn-danger" type="submit">Supprimer</button></form><?php endif; ?>
    </div>
</section>

<?php if ($folder['total_warning']): ?>
    <div class="alert alert-danger">Incohérence de montant : RO + RC + RAC = <?= e(format_euros(calculate_total((float)$folder['ro_amount'], (float)$folder['rc_amount'], (float)$folder['rac_amount']))) ?>, alors que le total déclaré est <?= e(format_euros($folder['total_amount'])) ?>.</div>
<?php endif; ?>
<?php if (!$dossierControl['ready']): ?>
    <div class="alert alert-warning"><strong>Contrôle du dossier :</strong> <?= e(implode(' · ', $dossierControl['issues'])) ?>. Ce contrôle vérifie la présence et la cohérence des données ; il ne remplace pas la validation métier de l’opticien.</div>
<?php else: ?>
    <div class="alert alert-success"><strong>Données cohérentes.</strong> Les champs obligatoires de suivi sont renseignés. La validation métier reste à faire par l’opticien.</div>
<?php endif; ?>

<div class="content-grid">
    <div>
        <section class="card">
            <div class="card-header"><div><h2>Parcours du dossier</h2><p>Devis, mutuelle et facturation</p></div></div>
            <div class="timeline">
                <div class="timeline-item"><span class="timeline-dot"></span><div><p>Devis <?= $folder['quote_date'] ? 'créé' : 'à renseigner' ?></p><small><?= e(format_date($folder['quote_date'])) ?></small></div></div>
                <div class="timeline-item"><span class="timeline-dot"></span><div><p>Prise en charge · <?= e(status_label($folder['mutual_status'])) ?></p><small>Envoi : <?= e(format_date($folder['pec_sent_at'], true)) ?> · Réponse : <?= e(format_date($folder['pec_response_at'], true)) ?></small></div></div>
                <div class="timeline-item"><span class="timeline-dot"></span><div><p>Facturation</p><small><?= e(format_date($folder['invoice_date'])) ?></small></div></div>
                <div class="timeline-item"><span class="timeline-dot"></span><div><p>Télétransmission · <?= e(status_label($folder['teletrans_status'])) ?></p><small><?= e(format_date($folder['teletrans_date'])) ?></small></div></div>
                <?php if ($order): ?><div class="timeline-item"><span class="timeline-dot"></span><div><p>Commande verrier · <?= e(status_label($order['status'])) ?></p><small><?= e($order['order_reference'] ?: 'Sans référence') ?> · prête le <?= e(format_date($order['ready_at'], true)) ?></small></div></div><?php endif; ?>
            </div>
        </section>

        <section class="card table-card">
            <div class="card-header"><div><h2>Paiements</h2><p>Suivi RO, RC et reste à charge</p></div></div>
            <form method="post" action="<?= e(app_url('actions/payments_bulk_save.php')) ?>" autocomplete="off" data-unsaved-warning>
                <?= csrf_field() ?><input type="hidden" name="dossier_id" value="<?= (int) $folder['id'] ?>">
                <div class="table-wrap">
                <table>
                    <thead><tr><th>Payeur</th><th>Attendu</th><th>Encaissé</th><th>Date</th><th>Mode</th><th>Statut</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <?php $fieldPrefix = 'payments[' . (int) $payment['id'] . ']'; ?>
                        <tr data-payment-row>
                            <td><strong><?= e(status_label($payment['payer'])) ?></strong></td>
                            <td><input type="number" step="0.01" min="0" name="<?= e($fieldPrefix) ?>[expected_amount]" value="<?= e((string)$payment['expected_amount']) ?>" style="min-width:100px"></td>
                            <td><input type="number" step="0.01" min="0" name="<?= e($fieldPrefix) ?>[paid_amount]" value="<?= e((string)$payment['paid_amount']) ?>" style="min-width:100px"></td>
                            <td><input type="date" name="<?= e($fieldPrefix) ?>[payment_date]" value="<?= e($payment['payment_date'] ?? '') ?>" style="min-width:140px"></td>
                            <td><select name="<?= e($fieldPrefix) ?>[settlement_method]" data-payment-method autocomplete="off" style="min-width:130px"><option value="">—</option><?php foreach (['cb','cheque','especes','virement','autre'] as $method): ?><option value="<?= e($method) ?>" <?= $payment['payment_method']===$method?'selected':'' ?>><?= e(status_label($method)) ?></option><?php endforeach; ?></select></td>
                            <td><select name="<?= e($fieldPrefix) ?>[status]" data-payment-status style="min-width:155px"><?php foreach (['attendu','partiel','encaisse','retard','cheque_caution','non_applicable'] as $s): ?><option value="<?= e($s) ?>" data-cheque-only="<?= $s === 'cheque_caution' ? '1' : '0' ?>" <?= $payment['status']===$s?'selected':'' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="form-footer" style="padding:0 20px 20px"><button class="btn btn-primary" type="submit">Enregistrer tous les paiements</button></div>
            </form>
        </section>

        <section class="card">
            <div class="card-header"><div><h2>Tâches liées</h2><p><?= count($tasks) ?> tâche(s)</p></div></div>
            <div class="task-list">
                <?php foreach ($tasks as $task): ?>
                    <div class="task-item">
                        <span class="task-line <?= $task['priority'] === 'critique' ? 'critical' : ($task['priority'] === 'haute' ? 'high' : '') ?>"></span>
                        <div><p><?= e($task['title']) ?> <?= status_badge($task['status']) ?></p><small>Échéance <?= e(format_date($task['due_at'], true)) ?><?= $task['assigned_name'] ? ' · ' . e($task['assigned_name']) : '' ?></small></div>
                        <?php if (in_array($task['status'], ['a_faire','en_cours','reportee'], true)): ?>
                            <form method="post" action="<?= e(app_url('actions/task_update.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$task['id'] ?>"><input type="hidden" name="action" value="complete"><input type="hidden" name="return_to" value="dossier"><button class="btn btn-small btn-success" type="submit">Terminer</button></form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$tasks): ?><div class="empty-state"><strong>Aucune tâche liée</strong>Le générateur de relances en créera selon les échéances.</div><?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <div><h2>Prévenir le client</h2><p>Message « lunettes prêtes » à vérifier avant l’envoi</p></div>
                <?= $isReady ? '<span class="badge badge-success">Dossier prêt</span>' : '<span class="badge badge-warning">Pas encore prêt</span>' ?>
            </div>
            <?php if ($isReady): ?>
                <form method="post" action="<?= e(app_url('actions/message_prepare.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="dossier_id" value="<?= (int) $folder['id'] ?>">
                    <div class="form-grid">
                        <label class="field">
                            <span>Canal</span>
                            <select name="channel">
                                <option value="email" <?= $defaultMessageChannel === 'email' ? 'selected' : '' ?> <?= !$folder['email'] ? 'disabled' : '' ?>>E-mail · <?= e($folder['email'] ?: 'e-mail absent') ?></option>
                                <option value="whatsapp" <?= $defaultMessageChannel === 'whatsapp' ? 'selected' : '' ?> <?= !$folder['phone'] ? 'disabled' : '' ?>>WhatsApp manuel · <?= e($folder['phone'] ?: 'téléphone absent') ?></option>
                                <option value="sms" <?= !$folder['phone'] ? 'disabled' : '' ?>>SMS — prévu plus tard · <?= e($folder['phone'] ?: 'téléphone absent') ?></option>
                                <option value="appel" <?= !$folder['phone'] ? 'disabled' : '' ?>>Téléphone — prévu plus tard · <?= e($folder['phone'] ?: 'téléphone absent') ?></option>
                            </select>
                        </label>
                        <div class="field">
                            <span>Envoi e-mail</span>
                            <div class="mode-indicator <?= $smtpStatus['ready'] ? 'live' : 'simulation' ?>">
                                <span></span><?= $smtpStatus['ready'] ? 'Disponible' : 'À configurer par l’administrateur' ?>
                            </div>
                        </div>
                        <label class="field field-full"><span>Message</span><textarea name="content" maxlength="1600" required><?= e($defaultMessage) ?></textarea></label>
                    </div>
                    <div class="inline-actions" style="margin-top:14px">
                        <button class="btn btn-primary" type="submit">Prévisualiser et valider</button>
                    </div>
                    <p class="form-help" style="margin-top:10px">L’étape suivante permet d’envoyer l’e-mail ou d’ouvrir WhatsApp avec le texte déjà préparé. SMS et téléphone restent indiqués pour une mise en place ultérieure.</p>
                </form>
            <?php else: ?>
                <div class="empty-state"><strong>Le message sera disponible lorsque les lunettes seront prêtes</strong>Passez la commande à « Prête » ou le dossier à « Prêt ».</div>
            <?php endif; ?>

            <?php if ($messages): ?>
                <div class="message-history">
                    <h3>Derniers messages</h3>
                    <?php foreach ($messages as $message): ?>
                        <article>
                            <div><strong><?= e(status_label($message['channel'])) ?></strong> <?= status_badge($message['status']) ?></div>
                            <p><?= nl2br(e($message['content'])) ?></p>
                            <small><?= e(format_date($message['sent_at'] ?: $message['created_at'], true)) ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card-header"><h2>Synthèse</h2></div>
            <dl class="meta-list">
                <div class="meta-row"><dt>Ordonnance</dt><dd><?= status_badge($folder['prescription_status']) ?></dd></div>
                <div class="meta-row"><dt>Type</dt><dd><?= status_badge($folder['dossier_type'] ?? 'lunettes') ?></dd></div>
                <div class="meta-row"><dt>Mutuelle</dt><dd><?= status_badge($folder['mutual_status']) ?><span class="cell-subtitle"><?= e($folder['pec_reference'] ?: '') ?></span></dd></div>
                <div class="meta-row"><dt>Part RO</dt><dd class="amount"><?= e(format_euros($folder['ro_amount'])) ?></dd></div>
                <div class="meta-row"><dt>Part RC</dt><dd class="amount"><?= e(format_euros($folder['rc_amount'])) ?></dd></div>
                <div class="meta-row"><dt>RAC</dt><dd class="amount"><?= e(format_euros($folder['rac_amount'])) ?></dd></div>
                <div class="meta-row"><dt>Total</dt><dd class="amount"><?= e(format_euros($folder['total_amount'])) ?></dd></div>
                <div class="meta-row"><dt>Action suivante</dt><dd><?= e($folder['next_action'] ?: '—') ?></dd></div>
                <div class="meta-row"><dt>Commentaire</dt><dd><?= nl2br(e($folder['optician_comment'] ?: '—')) ?></dd></div>
            </dl>
        </section>

        <section class="card">
            <div class="card-header"><h2>Commande verrier</h2><a class="btn btn-small btn-outline" href="<?= e(app_config()['suppliers']['ophtalmic_portal_url']) ?>" target="_blank" rel="noopener noreferrer">Ouvrir E-Space ↗</a></div>
            <?php if ($order): ?>
                <dl class="meta-list">
                    <div class="meta-row"><dt>Fournisseur</dt><dd><?= e($order['supplier_name']) ?></dd></div>
                    <div class="meta-row"><dt>Référence</dt><dd><?= e($order['order_reference'] ?: '—') ?></dd></div>
                    <div class="meta-row"><dt>Statut</dt><dd><?= status_badge($order['status']) ?></dd></div>
                    <div class="meta-row"><dt>OD</dt><dd><?= e(($order['lens_right_sphere'] ?? '—') . ' / ' . ($order['lens_right_cylinder'] ?? '—') . ' × ' . ($order['lens_right_axis'] ?? '—')) ?></dd></div>
                    <div class="meta-row"><dt>OG</dt><dd><?= e(($order['lens_left_sphere'] ?? '—') . ' / ' . ($order['lens_left_cylinder'] ?? '—') . ' × ' . ($order['lens_left_axis'] ?? '—')) ?></dd></div>
                    <div class="meta-row"><dt>Produit</dt><dd><?= e($order['lens_product'] ?: '—') ?><span class="cell-subtitle"><?= e(trim(($order['lens_index'] ?: '') . ' ' . ($order['treatment'] ?: ''))) ?></span></dd></div>
                    <div class="meta-row"><dt>Réception prévue</dt><dd><?= e(format_date($order['expected_at'], true)) ?></dd></div>
                    <div class="meta-row"><dt>Reçue</dt><dd><?= e(format_date($order['received_at'], true)) ?></dd></div>
                    <div class="meta-row"><dt>Prête</dt><dd><?= e(format_date($order['ready_at'], true)) ?></dd></div>
                    <div class="meta-row"><dt>Client prévenu</dt><dd><?= e(format_date($order['client_notified_at'], true)) ?></dd></div>
                </dl>
                <div class="copy-box" id="order-copy">Dossier <?= e(dossier_number((int)$folder['id'])) ?>
OD : <?= e(($order['lens_right_sphere'] ?? '—').' / '.($order['lens_right_cylinder'] ?? '—').' axe '.($order['lens_right_axis'] ?? '—').' add '.($order['lens_right_addition'] ?? '—')) ?>
OG : <?= e(($order['lens_left_sphere'] ?? '—').' / '.($order['lens_left_cylinder'] ?? '—').' axe '.($order['lens_left_axis'] ?? '—').' add '.($order['lens_left_addition'] ?? '—')) ?>
EP : <?= e($order['pupillary_distance'] ?: '—') ?>
Produit : <?= e($order['lens_product'] ?: '—') ?> · indice <?= e($order['lens_index'] ?: '—') ?>
Traitement : <?= e($order['treatment'] ?: '—') ?> · teinte <?= e($order['tint'] ?: '—') ?>
Monture : <?= e($order['frame_reference'] ?: '—') ?></div>
                <button class="btn btn-small btn-outline" type="button" data-copy-target="#order-copy" style="margin-top:10px">Copier les informations</button>
            <?php else: ?><div class="empty-state"><strong>Pas de commande suivie</strong>Lancez la commande dans Cosium ou E-Space, puis ajoutez sa référence depuis la modification du dossier.</div><?php endif; ?>
            <p class="form-help" style="margin-top:12px">Oopticien suit la commande mais ne la transmet pas au verrier.</p>
        </section>

        <section class="card">
            <div class="card-header"><h2>Historique</h2><a href="<?= e(app_url('pages/history.php?dossier_id=' . $folder['id'])) ?>">Tout voir</a></div>
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
