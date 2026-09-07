<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$returnClientId = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT) ?: 0;
if (!$id) {
    flash('danger', 'Avoir invalide.');
    redirect('pages/avoirs.php'.($returnClientId ? '?client_id='.$returnClientId : ''));
}

$stmt = db()->prepare('SELECT id,client_id,label,remaining_amount FROM credits WHERE id=?');
$stmt->execute([$id]);
$credit = $stmt->fetch();
if (!$credit) {
    flash('danger', 'Avoir introuvable.');
    redirect('pages/avoirs.php'.($returnClientId ? '?client_id='.$returnClientId : ''));
}

$stmt = db()->prepare('DELETE FROM credits WHERE id=?');
$stmt->execute([$id]);
log_action(
    'avoir',
    (int) $id,
    'suppression',
    'Avoir supprimé : '.$credit['label'].' · restant '.format_euros($credit['remaining_amount'])
);
flash('success', 'L’avoir a été supprimé.');
redirect('pages/avoirs.php?client_id='.(int)$credit['client_id']);
