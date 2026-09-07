<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();

$dossierId = filter_input(INPUT_POST, 'dossier_id', FILTER_VALIDATE_INT);
$rows = $_POST['payments'] ?? [];
if (!$dossierId || !is_array($rows) || $rows === []) {
    flash('danger', 'Aucun paiement à enregistrer.');
    redirect('pages/dossiers.php');
}

$allowedStatuses = ['attendu','partiel','encaisse','retard','cheque_caution','non_applicable'];
$allowedMethods = ['cb','cheque','especes','virement','autre'];
$pdo = db();

try {
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT id,payer FROM payments WHERE id=? AND dossier_id=?');
    $update = $pdo->prepare('UPDATE payments SET expected_amount=?,paid_amount=?,payment_date=?,payment_method=?,status=? WHERE id=? AND dossier_id=?');

    foreach ($rows as $paymentId => $row) {
        $paymentId = filter_var($paymentId, FILTER_VALIDATE_INT);
        if (!$paymentId || !is_array($row)) {
            throw new RuntimeException('Ligne de paiement invalide.');
        }
        $find->execute([$paymentId, $dossierId]);
        if (!$find->fetch()) {
            throw new RuntimeException('Paiement étranger au dossier.');
        }

        $expected = round(max(0, (float) str_replace(',', '.', (string) ($row['expected_amount'] ?? 0))), 2);
        $paid = round(max(0, (float) str_replace(',', '.', (string) ($row['paid_amount'] ?? 0))), 2);
        $date = trim((string) ($row['payment_date'] ?? '')) ?: null;
        $method = trim((string) ($row['settlement_method'] ?? '')) ?: null;
        $status = trim((string) ($row['status'] ?? 'attendu'));
        if ($method !== null && !in_array($method, $allowedMethods, true)) $method = null;
        if (!in_array($status, $allowedStatuses, true)) $status = 'attendu';
        if ($status === 'cheque_caution' && $method !== 'cheque') $status = 'attendu';
        if ($status === 'encaisse' && !$date) $date = date('Y-m-d');
        if ($status === 'encaisse' && $paid <= 0) $paid = $expected;

        $update->execute([$expected, $paid, $date, $method, $status, $paymentId, $dossierId]);
    }

    $pdo->commit();
    log_action('dossier', (int) $dossierId, 'mise_a_jour', 'Paiements RO, RC et client enregistrés ensemble');
    flash('success', 'Tous les paiements du dossier ont été enregistrés.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('danger', 'Impossible d’enregistrer les paiements. Vérifiez les valeurs.');
}

redirect('pages/dossier_view.php?id=' . (int) $dossierId);
