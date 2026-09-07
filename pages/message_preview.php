<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/messaging.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

redirect_if_not_logged_in();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare(
    'SELECT m.*,CONCAT(c.first_name," ",c.last_name) AS client_name,c.phone,c.email
     FROM messages m JOIN clients c ON c.id=m.client_id WHERE m.id=?'
);
$stmt->execute([$id]);
$message = $stmt->fetch();
if (!$message) {
    http_response_code(404);
    exit('Message introuvable.');
}

$editable = in_array($message['status'], ['a_preparer', 'non_envoye', 'echec'], true);
$whatsappUrl = $message['channel'] === 'whatsapp'
    ? 'https://wa.me/' . rawurlencode(normalized_whatsapp_phone((string) $message['recipient']))
        . '?text=' . rawurlencode((string) $message['content'])
    : null;
$smtpStatus = smtp_configuration_status();
$pageTitle = 'Prévisualiser le message';
require dirname(__DIR__) . '/includes/header.php';
?>
<section class="page-actions">
    <div class="copy"><h2>Vérification avant contact</h2><p>Relisez le message et le destinataire avant de continuer.</p></div>
    <?= status_badge($message['status']) ?>
</section>

<?php if ($message['channel'] === 'email' && !$smtpStatus['ready']): ?>
    <div class="alert alert-warning">L’envoi e-mail doit encore être configuré par l’administrateur : <?= e(implode(', ', $smtpStatus['missing'])) ?>.</div>
<?php elseif (in_array($message['channel'], ['sms', 'appel'], true)): ?>
    <div class="alert alert-info">Ce canal est conservé pour une prochaine étape. Aucun SMS ni appel automatique ne partira depuis l’application pour le moment.</div>
<?php elseif ($message['channel'] === 'whatsapp'): ?>
    <div class="alert alert-info">WhatsApp s’ouvrira avec le texte préparé. Vérifiez-le puis appuyez vous-même sur Envoyer. Ce fonctionnement manuel ne nécessite pas l’API WhatsApp payante.</div>
<?php endif; ?>

<section class="card">
    <dl class="meta-list">
        <div class="meta-row"><dt>Client</dt><dd><?= e($message['client_name']) ?></dd></div>
        <div class="meta-row"><dt>Canal</dt><dd><?= e(status_label($message['channel'])) ?></dd></div>
        <div class="meta-row"><dt>Destinataire</dt><dd><?= e($message['recipient']) ?></dd></div>
        <div class="meta-row"><dt>Modèle</dt><dd><?= e($message['template_name'] ?: '—') ?></dd></div>
    </dl>

    <form method="post" action="<?= e(app_url('actions/message_send.php')) ?>" data-unsaved-warning>
        <?= csrf_field() ?>
        <input type="hidden" name="message_id" value="<?= (int) $message['id'] ?>">
        <input type="hidden" name="confirm_preview" value="1">
        <label class="field"><span>Objet</span><input name="subject" maxlength="190" value="<?= e($message['subject'] ?? '') ?>" <?= !$editable ? 'readonly' : '' ?>></label>
        <label class="field"><span>Message</span><textarea name="content" maxlength="5000" required <?= !$editable ? 'readonly' : '' ?>><?= e($message['content']) ?></textarea></label>
        <?php if ($message['error_message']): ?><div class="alert alert-danger"><?= e($message['error_message']) ?></div><?php endif; ?>

        <?php if ($editable): ?>
            <div class="inline-actions">
                <?php if ($message['channel'] === 'email'): ?>
                    <button class="btn btn-primary" type="submit" <?= !$smtpStatus['ready'] ? 'disabled' : '' ?> data-confirm="Envoyer cet e-mail maintenant ?">Envoyer l’e-mail</button>
                <?php elseif ($whatsappUrl): ?>
                    <a class="btn btn-primary" target="_blank" rel="noopener noreferrer" href="<?= e($whatsappUrl) ?>">Ouvrir WhatsApp ↗</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($editable && in_array($message['channel'], ['whatsapp', 'appel'], true)): ?>
        <form method="post" action="<?= e(app_url('actions/message_mark_manual.php')) ?>" style="margin-top:12px" data-confirm="Confirmer que le contact a réellement été effectué ?">
            <?= csrf_field() ?><input type="hidden" name="message_id" value="<?= (int) $message['id'] ?>">
            <button class="btn btn-success" type="submit"><?= $message['channel'] === 'appel' ? 'Marquer le client appelé' : 'Confirmer le message envoyé' ?></button>
        </form>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
