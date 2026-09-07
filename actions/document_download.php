<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/storage.php';
redirect_if_not_logged_in();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare('SELECT * FROM documents WHERE id=? AND deleted_at IS NULL');
$stmt->execute([$id]);
$document = $stmt->fetch();
if (!$document) { http_response_code(404); exit('Document introuvable.'); }
$path = safe_private_file('documents', $document['stored_file_name'] ?: $document['stored_name']);
if (!hash_equals($document['sha256'], hash_file('sha256', $path))) { http_response_code(409); exit('Contrôle d’intégrité échoué.'); }
log_sensitive_access((int)$document['client_id'], 'document_download', 'Document #'.$document['id']);
log_action('document', (int)$document['id'], 'document_download', 'Téléchargement protégé');
stream_private_download($path, $document['mime_type'], $document['original_file_name'] ?: $document['original_name']);
