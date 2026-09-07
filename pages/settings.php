<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/inbound_mail.php';
require_role('admin');

$rows = db()->query('SELECT setting_key,setting_value,description FROM settings ORDER BY id')->fetchAll();
$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row;
}
$fields = [
    'mutual_followup_hours_open' => 'Relance mutuelle (heures ouvrées)',
    'pec_auto_accept_days_open' => 'Passage automatique en PEC acceptée (jours ouvrés)',
    'invoice_followup_days_open' => 'Facturation après PEC (jours ouvrés)',
    'ro_payment_followup_days_open' => 'Contrôle paiement RO (jours ouvrés)',
    'rc_payment_followup_days_open' => 'Contrôle paiement RC (jours ouvrés)',
    'client_not_come_followup_1_days' => 'Client non venu — relance 1 (jours)',
    'client_not_come_followup_2_days' => 'Client non venu — relance 2 (jours)',
    'client_not_come_followup_3_days' => 'Client non venu — relance 3 (jours)',
];
$smtpStatus = smtp_configuration_status();
$inboundStatus = inbound_mail_status();
$deliveryNotes = [];
try {
    $deliveryNotes = db()->query(
        'SELECT id,subject,attachment_name,dossier_id,processing_status,match_reason,error_message,processed_at
         FROM inbound_delivery_notes ORDER BY id DESC LIMIT 8'
    )->fetchAll();
} catch (Throwable) {
    // La migration V8 sera proposée lors de l'installation.
}
$pageTitle = 'Paramètres';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions"><div class="copy"><h2>Configuration de l’application</h2><p>Réglages utiles à l’équipe et état de l’envoi des e-mails.</p></div></section>
<div class="content-grid">
    <div>
        <form class="card" method="post" action="<?= e(app_url('actions/settings_save.php')) ?>" data-unsaved-warning>
            <?= csrf_field() ?>
            <div class="card-header"><div><h2>Délais de relance</h2><p>Les week-ends sont ignorés pour les délais ouvrés.</p></div></div>
            <div class="info-banner" style="margin-bottom:14px"><span>↻</span><div><strong>Cycle automatique</strong><p>À la demande mutuelle, le compteur démarre. Après le délai de suivi, le dossier passe en attente de réponse. Après le délai PEC, il passe automatiquement en « À facturer ». Un dossier en SAV est entièrement suspendu.</p></div></div>
            <div class="form-grid">
                <?php foreach ($fields as $key => $label): ?>
                    <label class="field"><span><?= e($label) ?></span><input type="number" name="<?= e($key) ?>" min="1" max="365" required value="<?= e($settings[$key]['setting_value'] ?? '') ?>"></label>
                <?php endforeach; ?>
            </div>
            <div class="form-footer"><button class="btn btn-primary" type="submit">Enregistrer les paramètres</button></div>
        </form>

        <section class="card">
            <div class="card-header"><div><h2>Automatisations</h2><p>Relances, transitions PEC, contrôles RO/RC et boîte des bons de livraison</p></div></div>
            <dl class="meta-list">
                <div class="meta-row"><dt>Dernière exécution</dt><dd><?= e(get_setting('automation_last_run_at','Jamais') ?: 'Jamais') ?></dd></div>
                <div class="meta-row"><dt>Lecture des e-mails</dt><dd><?= $inboundStatus['ready'] ? '<span class="badge badge-success">Prête</span>' : '<span class="badge badge-warning">À configurer</span>' ?></dd></div>
                <div class="meta-row"><dt>Dernière lecture IMAP</dt><dd><?= e(get_setting('inbound_mail_last_run_at','Jamais') ?: 'Jamais') ?></dd></div>
            </dl>
            <?php if (!$inboundStatus['ready']): ?>
                <p class="form-help">Copiez <code>config/inbound_mail.local.example.php</code> vers <code>config/inbound_mail.local.php</code>, renseignez la boîte IMAP puis passez <code>enabled</code> à <code>true</code>.</p>
            <?php endif; ?>
            <?php if (get_setting('inbound_mail_last_error','')): ?><div class="alert alert-danger"><?= e(get_setting('inbound_mail_last_error','')) ?></div><?php endif; ?>
            <form method="post" action="<?= e(app_url('actions/automation_run.php')) ?>" data-confirm="Lancer maintenant toutes les automatisations ?">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit">↻ Lancer et vérifier maintenant</button>
            </form>
        </section>

        <?php if ($deliveryNotes): ?>
        <section class="card table-card">
            <div class="card-header"><div><h2>Derniers bons de livraison lus</h2><p>Une correspondance ambiguë reste à vérifier et ne change aucun dossier.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>Date</th><th>Pièce jointe</th><th>Résultat</th><th>Dossier</th><th>Détail</th></tr></thead><tbody>
            <?php foreach ($deliveryNotes as $note): ?>
                <tr><td><?= e(format_date($note['processed_at'],true)) ?></td><td><?= e($note['attachment_name'] ?: $note['subject']) ?></td><td><?= status_badge($note['processing_status']) ?></td><td><?php if($note['dossier_id']): ?><a href="<?= e(app_url('pages/dossier_view.php?id='.$note['dossier_id'])) ?>"><?= e(dossier_number((int)$note['dossier_id'])) ?></a><?php else: ?>—<?php endif; ?></td><td><?= e($note['error_message'] ?: $note['match_reason']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <?php endif; ?>

        <section class="card">
            <div class="card-header">
                <div><h2>Envoi des e-mails</h2><p>PHPMailer avec connexion SMTP sécurisée</p></div>
                <?= $smtpStatus['ready'] ? '<span class="badge badge-success">Prêt</span>' : '<span class="badge badge-warning">À configurer</span>' ?>
            </div>
            <?php if ($smtpStatus['ready']): ?>
                <p>Serveur : <strong><?= e($smtpStatus['host'] . ':' . $smtpStatus['port']) ?></strong></p>
                <p>Adresse d’expédition : <strong><?= e($smtpStatus['from_email']) ?></strong></p>
            <?php else: ?>
                <p>Éléments manquants : <strong><?= e(implode(', ', $smtpStatus['missing'])) ?></strong></p>
            <?php endif; ?>
            <p class="form-help">La configuration privée se trouve dans <code>config/mail.local.php</code>. Le mot de passe SMTP n’est jamais affiché dans le site.</p>
        </section>

        <section class="card">
            <div class="card-header"><div><h2>Canaux clients</h2><p>Disponibilité actuelle</p></div></div>
            <dl class="meta-list">
                <div class="meta-row"><dt>E-mail</dt><dd><?= $smtpStatus['ready'] ? 'Disponible' : 'À configurer' ?></dd></div>
                <div class="meta-row"><dt>WhatsApp</dt><dd>Ouverture manuelle gratuite, puis envoi confirmé par l’opticien</dd></div>
                <div class="meta-row"><dt>SMS</dt><dd>Prévu plus tard</dd></div>
                <div class="meta-row"><dt>Téléphone</dt><dd>Prévu plus tard</dd></div>
            </dl>
        </section>

        <form class="card" method="post" action="<?= e(app_url('actions/boutique_settings_save.php')) ?>" data-unsaved-warning>
            <?= csrf_field() ?>
            <div class="card-header"><div><h2>Informations de la boutique</h2><p>Utilisées dans les messages adressés aux clients</p></div></div>
            <div class="form-grid">
                <label class="field"><span>Nom de la boutique</span><input name="boutique_name" maxlength="160" required value="<?= e(get_setting('boutique_name','Oopticien Pro')) ?>"></label>
                <label class="field"><span>Lien Google Maps</span><input type="url" name="google_maps_link" maxlength="500" value="<?= e(get_setting('google_maps_link','')) ?>"></label>
            </div>
            <div class="form-footer"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
        </form>

        <section class="card">
            <div class="card-header"><div><h2>Sauvegarde et export</h2><p>Exports opérationnels et sauvegardes complètes</p></div></div>
            <div class="inline-actions"><a class="btn btn-outline" href="<?= e(app_url('actions/export_csv.php?type=clients')) ?>">Exporter les clients</a><a class="btn btn-outline" href="<?= e(app_url('actions/export_csv.php?type=dossiers')) ?>">Exporter les dossiers</a></div>
            <p class="form-help" style="margin-top:12px">Les exports CSV ne remplacent pas une sauvegarde SQL et documentaire complète.</p>
            <div class="inline-actions"><a class="btn btn-primary" href="<?= e(app_url('pages/backups.php')) ?>">Sauvegardes</a><a class="btn btn-outline" href="<?= e(app_url('pages/security.php')) ?>">Sécurité</a></div>
        </section>
    </div>

    <aside>
        <section class="card" style="margin-top:0">
            <div class="card-header"><h2>Accès réseau local</h2></div>
            <div class="info-banner"><span>⌂</span><div><strong>Usage interne uniquement</strong><p>N’exposez pas cette application directement sur Internet.</p></div></div>
            <dl class="meta-list" style="margin-top:12px">
                <div class="meta-row"><dt>Depuis le serveur</dt><dd><code>http://localhost/oopticien-pro</code></dd></div>
                <div class="meta-row"><dt>Depuis un autre PC</dt><dd><code>http://IP_DU_PC/oopticien-pro</code></dd></div>
            </dl>
        </section>
        <section class="card">
            <div class="card-header"><h2>Génération des relances</h2></div>
            <p class="muted">Le calcul automatique des relances doit être activé une fois par l’administrateur du poste. L’équipe retrouvera ensuite les tâches à traiter chaque matin dans « Relances ».</p>
        </section>
    </aside>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
