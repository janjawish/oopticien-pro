<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
redirect_if_not_logged_in();
require_post();
verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM payments WHERE id=?');
$stmt->execute([$id]);
$payment = $stmt->fetch();
if (!$payment) {
    http_response_code(404);
    exit('Paiement introuvable.');
}

$status = post_string('status');
if (!in_array($status, ['attendu','partiel','encaisse','retard','cheque_caution','non_applicable'], true)) {
    $status = 'attendu';
}
$methodKey = array_key_exists('settlement_method', $_POST) ? 'settlement_method' : 'payment_method';
$method = post_nullable($methodKey);
if ($method && !in_array($method, ['cb','cheque','especes','virement','autre'], true)) {
    $method = null;
}
if ($status === 'cheque_caution' && $method !== 'cheque') {
    $status = 'attendu';
}
$expected = post_decimal('expected_amount');
$paid = post_decimal('paid_amount');
$date = post_nullable('payment_date');
$comment = array_key_exists('comment', $_POST) ? post_nullable('comment') : $payment['comment'];
if ($status === 'encaisse' && !$date) {
    $date = date('Y-m-d');
}
if ($status === 'encaisse' && $paid <= 0) {
    $paid = $expected;
}

$stmt = db()->prepare('UPDATE payments SET expected_amount=?, paid_amount=?, payment_date=?, payment_method=?, status=?, comment=? WHERE id=?');
$stmt->execute([$expected, $paid, $date, $method, $status, $comment, $id]);
log_action('paiement', (int)$id, 'mise_a_jour', 'Paiement ' . status_label($payment['payer']) . ' mis à jour');
flash('success', 'Le paiement a été mis à jour.');

$redirectPath = 'pages/paiements.php';
if (post_string('return_to') === 'dossier') {
    $redirectPath = 'pages/dossier_view.php?id=' . $payment['dossier_id'];
} else {
    $postedReturnQuery = $_POST['return_query'] ?? '';
    $rawReturnQuery = is_string($postedReturnQuery) ? mb_substr(trim($postedReturnQuery), 0, 1000) : '';
    parse_str($rawReturnQuery, $returnValues);
    $safeReturnValues = [];
    $allowedReturnValues = [
        'payer' => ['ro', 'rc', 'client'],
        'status' => ['attendu', 'partiel', 'encaisse', 'retard', 'cheque_caution', 'non_applicable'],
        'method' => ['cb', 'cheque', 'especes', 'virement', 'autre', 'non_renseigne'],
        'sort' => ['priority', 'date_desc', 'date_asc', 'updated_desc', 'client_asc'],
        'per_page' => ['25', '50', '100', '250'],
    ];
    foreach ($allowedReturnValues as $key => $allowedValues) {
        $value = $returnValues[$key] ?? null;
        if (is_string($value) && in_array($value, $allowedValues, true)) {
            $safeReturnValues[$key] = $value;
        }
    }
    foreach (['q' => 160, 'mutual' => 160] as $key => $maxLength) {
        $value = $returnValues[$key] ?? null;
        if (is_string($value) && $value !== '') {
            $safeReturnValues[$key] = mb_substr($value, 0, $maxLength);
        }
    }
    $returnPage = filter_var($returnValues['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($returnPage !== false) {
        $safeReturnValues['page'] = (int) $returnPage;
    }
    if ($safeReturnValues) {
        $redirectPath .= '?' . http_build_query($safeReturnValues);
    }
}

redirect($redirectPath);
