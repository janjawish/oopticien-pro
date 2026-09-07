<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();

function mutual_datetime(string $key): ?string
{
    $value = post_nullable($key);
    return $value ? str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '') : null;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
$dossierId = filter_input(INPUT_POST, 'dossier_id', FILTER_VALIDATE_INT);
$portalId = filter_input(INPUT_POST, 'portal_id', FILTER_VALIDATE_INT) ?: null;
$check = db()->prepare('SELECT client_id,folder_status FROM dossiers WHERE id=?');
$check->execute([$dossierId]);
$dossier = $check->fetch();
if (!$dossierId || !$dossier) {
    flash('danger', 'Dossier invalide.');
    redirect('pages/mutuelles.php');
}
$clientId = (int) $dossier['client_id'];

if (isset($_POST['create'])) {
    try {
        $stmt = db()->prepare('INSERT INTO mutual_requests (dossier_id,portal_id,created_by) VALUES (?,?,?)');
        $stmt->execute([$dossierId, $portalId, current_user()['id']]);
        $id = (int) db()->lastInsertId();
        log_action('mutual_request', $id, 'creation', 'Suivi mutuelle créé');
        redirect('pages/mutuelle_request.php?id=' . $id);
    } catch (Throwable) {
        flash('danger', 'Un suivi existe déjà pour ce dossier.');
        redirect('pages/mutuelles.php');
    }
}

$statuses = ['a_preparer','envoyee','en_attente','acceptee','partielle','refusee','expiree','annulee'];
$status = post_string('status');
if (!$id || !in_array($status, $statuses, true)) {
    flash('danger', 'Demande invalide.');
    redirect('pages/mutuelles.php');
}

$sentAt = mutual_datetime('sent_at');
$responseAt = mutual_datetime('response_at');
if (in_array($status, ['envoyee', 'en_attente'], true) && !$sentAt) {
    $sentAt = date('Y-m-d H:i:s');
}
if (in_array($status, ['acceptee', 'partielle', 'refusee'], true) && !$responseAt) {
    $responseAt = date('Y-m-d H:i:s');
}
$dueAt = mutual_datetime('response_due_at');
if ($sentAt && in_array($status, ['envoyee', 'en_attente'], true) && !$dueAt) {
    $dueAt = add_business_hours($sentAt, (int) get_setting('mutual_followup_hours_open', 48));
}

$reference = post_nullable('reference');
$ro = post_decimal('ro_amount');
$rc = post_decimal('rc_amount');
$rac = post_decimal('rac_amount');
$accepted = post_decimal('accepted_amount');
$comment = post_nullable('comment');
$documentId = filter_input(INPUT_POST, 'document_id', FILTER_VALIDATE_INT) ?: null;
if ($documentId) {
    $doc = db()->prepare('SELECT id FROM documents WHERE id=? AND dossier_id=? AND deleted_at IS NULL');
    $doc->execute([$documentId, $dossierId]);
    if (!$doc->fetch()) {
        $documentId = null;
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE mutual_requests SET portal_id=?,status=?,external_reference=?,reference=?,
         requested_amount=?,ro_amount=?,rc_amount=?,rac_amount=?,approved_amount=?,accepted_amount=?,
         sent_at=?,response_due_at=?,response_received_at=?,response_at=?,valid_until=?,refusal_reason=?,
         document_id=?,has_identity=?,has_prescription=?,has_rights_certificate=?,has_quote=?,notes=?,comment=?
         WHERE id=? AND dossier_id=?'
    );
    $stmt->execute([
        $portalId, $status, $reference, $reference, $ro + $rc + $rac, $ro, $rc, $rac,
        $accepted, $accepted, $sentAt, $dueAt, $responseAt, $responseAt,
        post_nullable('valid_until'), post_nullable('refusal_reason'), $documentId,
        isset($_POST['has_identity']) ? 1 : 0,
        isset($_POST['has_prescription']) ? 1 : 0,
        isset($_POST['has_rights_certificate']) ? 1 : 0,
        isset($_POST['has_quote']) ? 1 : 0,
        $comment, $comment, $id, $dossierId,
    ]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Demande mutuelle introuvable.');
    }

    $dossierMutualStatus = match ($status) {
        'envoyee' => 'envoyee',
        'en_attente' => 'en_attente',
        'acceptee', 'partielle' => 'pec_acceptee',
        'refusee' => 'pec_refusee',
        default => 'non_envoyee',
    };
    $folderStatus = match ($status) {
        'envoyee', 'en_attente' => 'demande_mutuelle',
        'acceptee', 'partielle' => 'a_facturer',
        'refusee' => 'bloque',
        default => null,
    };
    $nextAction = match ($status) {
        'envoyee', 'en_attente' => 'Vérifier la réponse mutuelle',
        'acceptee' => 'Facturer le dossier',
        'partielle' => 'Vérifier l’accord partiel puis facturer',
        'refusee' => 'Traiter le refus de prise en charge',
        default => 'Préparer la demande mutuelle',
    };
    $stmt = $pdo->prepare(
        'UPDATE dossiers SET mutual_status=?,
         pec_sent_at=CASE WHEN ? IS NULL THEN pec_sent_at ELSE ? END,
         pec_response_at=CASE WHEN ? IS NULL THEN pec_response_at ELSE ? END,
         pec_reference=COALESCE(?,pec_reference),
         status_changed_at=CASE WHEN ? IS NULL OR folder_status=? THEN status_changed_at ELSE NOW() END,
         folder_status=CASE
             WHEN ? IS NULL THEN folder_status
             WHEN folder_status IN ("brouillon","devis","demande_mutuelle","pec_acceptee","a_facturer","bloque") THEN ?
             ELSE folder_status
         END,
         next_action=?
         WHERE id=?'
    );
    $stmt->execute([
        $dossierMutualStatus,
        $sentAt, $sentAt,
        $responseAt, $responseAt,
        $reference,
        $folderStatus, $folderStatus,
        $folderStatus, $folderStatus,
        $nextAction,
        $dossierId,
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', 'La demande n’a pas pu être enregistrée : ' . $exception->getMessage());
    redirect('pages/mutuelle_request.php?id=' . $id);
}

if ($dueAt && in_array($status, ['envoyee', 'en_attente'], true)) {
    create_task_if_not_exists($dossierId, $clientId, 'Relancer la demande mutuelle à 48 h', 'mutuelle', $dueAt, 'haute');
}
if (in_array($status, ['acceptee', 'partielle'], true) && $responseAt) {
    $invoiceDueAt = add_business_days($responseAt, (int) get_setting('invoice_followup_days_open', 3));
    create_task_if_not_exists($dossierId, $clientId, 'Facturer le dossier', 'facturation', $invoiceDueAt, 'haute');
}

log_action('mutual_request', $id, 'mise_a_jour', 'Statut ' . $status);
flash('success', 'La demande mutuelle et le dossier ont été mis à jour.');
redirect('pages/mutuelle_request.php?id=' . $id);
