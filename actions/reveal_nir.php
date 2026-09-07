<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role(['admin', 'patron']);
require_post();
header('Content-Type: application/json; charset=utf-8');

try {
    verify_csrf();
    $clientId = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
    if (!$clientId) {
        throw new RuntimeException('Client invalide.');
    }
    $stmt = db()->prepare(
        'SELECT id, social_security_number, social_security_number_encrypted FROM clients WHERE id = ?'
    );
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client) {
        http_response_code(404);
        throw new RuntimeException('Client introuvable.');
    }
    $nir = client_nir($client);
    log_sensitive_access((int) $clientId, 'nir_reveal', 'Affichage explicite du NIR');
    echo json_encode(['ok' => true, 'nir' => $nir ?: 'Non renseigné'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}

