<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/messaging.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

redirect_if_not_logged_in();
require_post();
verify_csrf();

$messageId = filter_input(INPUT_POST, 'message_id', FILTER_VALIDATE_INT);
if (!$messageId || post_string('confirm_preview') !== '1') {
    flash('danger', 'Prévisualisation invalide.');
    redirect('pages/messages.php');
}

$stmt = db()->prepare(
    'SELECT m.*, CONCAT(c.first_name," ",c.last_name) AS client_name
     FROM messages m JOIN clients c ON c.id=m.client_id WHERE m.id=?'
);
$stmt->execute([$messageId]);
$message = $stmt->fetch();
if (!$message || !in_array($message['status'], ['a_preparer', 'non_envoye', 'echec'], true)) {
    flash('danger', 'Ce message ne peut plus être envoyé.');
    redirect('pages/messages.php');
}
if ($message['channel'] !== 'email') {
    flash('warning', 'Ce canal n’est pas encore disponible pour un envoi automatique.');
    redirect('pages/message_preview.php?id=' . $messageId);
}

$content = post_string('content', 5000);
$subject = post_string('subject', 190);
if ($content === '') {
    flash('danger', 'Le message ne peut pas être vide.');
    redirect('pages/message_preview.php?id=' . $messageId);
}

$status = 'echec';
$error = null;
$sentAt = null;
try {
    send_smtp_email(
        (string) $message['recipient'],
        (string) $message['client_name'],
        $subject,
        $content
    );
    $status = 'envoye';
    $sentAt = date('Y-m-d H:i:s');
} catch (Throwable $exception) {
    $error = mb_substr($exception->getMessage(), 0, 2000);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE messages SET subject=?,content=?,provider="phpmailer",status=?,
         error_message=?,sent_at=?,validated_by=?,validated_at=NOW() WHERE id=?'
    );
    $stmt->execute([
        $subject !== '' ? $subject : null,
        $content,
        $status,
        $error,
        $sentAt,
        current_user()['id'],
        $messageId,
    ]);
    if ($status === 'envoye' && $message['dossier_id']) {
        mark_client_notified($pdo, (int) $message['dossier_id']);
    }
    $pdo->commit();
    log_action('message', $messageId, 'message_envoye', status_label($status));
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', 'Le résultat de l’envoi n’a pas pu être enregistré.');
    redirect('pages/message_preview.php?id=' . $messageId);
}

if ($status === 'envoye') {
    flash('success', 'L’e-mail a été envoyé au client.');
} else {
    flash('danger', 'L’e-mail n’a pas été envoyé. ' . ($error ?: 'Vérifiez la configuration SMTP.'));
}
redirect('pages/message_preview.php?id=' . $messageId);
