<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin', 'patron']);
require_post();
verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT id,dossier_id FROM mutual_requests WHERE id=?');
$stmt->execute([$id]);
$request = $stmt->fetch();
if (!$request) {
    flash('danger', 'Demande mutuelle introuvable.');
    redirect('pages/mutuelles.php');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'DELETE FROM tasks WHERE dossier_id=? AND task_type="mutuelle"
         AND status IN ("a_faire","en_cours","reportee")'
    )->execute([$request['dossier_id']]);
    $pdo->prepare('DELETE FROM mutual_requests WHERE id=?')->execute([$id]);
    $pdo->prepare(
        'UPDATE dossiers SET
         mutual_status="non_envoyee",pec_sent_at=NULL,pec_response_at=NULL,pec_reference=NULL,
         status_changed_at=CASE
             WHEN folder_status IN ("demande_mutuelle","pec_acceptee","bloque") THEN NOW()
             ELSE status_changed_at
         END,
         folder_status=CASE
             WHEN folder_status IN ("demande_mutuelle","pec_acceptee","bloque") AND quote_date IS NOT NULL THEN "devis"
             WHEN folder_status IN ("demande_mutuelle","pec_acceptee","bloque") THEN "brouillon"
             ELSE folder_status
         END,
         next_action="Préparer la demande mutuelle"
         WHERE id=?'
    )->execute([$request['dossier_id']]);
    $pdo->commit();
    log_action('mutual_request', (int) $id, 'suppression', 'Suivi mutuelle supprimé');
    flash('success', 'La demande mutuelle a été supprimée. Le dossier client est conservé.');
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', 'La demande mutuelle n’a pas pu être supprimée.');
}
redirect('pages/mutuelles.php');
