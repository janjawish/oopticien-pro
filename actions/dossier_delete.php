<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin', 'patron']);
require_post();
verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare(
    'SELECT d.id,d.client_id,CONCAT(c.first_name," ",c.last_name) AS client_name
     FROM dossiers d JOIN clients c ON c.id=d.client_id WHERE d.id=?'
);
$stmt->execute([$id]);
$dossier = $stmt->fetch();
if (!$dossier) {
    flash('danger', 'Dossier introuvable.');
    redirect('pages/dossiers.php');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM messages WHERE dossier_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM dossiers WHERE id=?')->execute([$id]);
    $pdo->commit();
    log_action('dossier', (int) $id, 'suppression', 'Dossier supprimé pour ' . $dossier['client_name']);
    flash('success', 'Le dossier a été supprimé. La fiche client et ses documents restent disponibles.');
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', 'Le dossier n’a pas pu être supprimé.');
}
redirect('pages/client_view.php?id=' . (int) $dossier['client_id']);
